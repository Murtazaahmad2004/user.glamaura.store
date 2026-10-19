<?php
// =============================================
// GLAMAURA CHECKOUT - PHP + TiDB + Gmail SMTP
// =============================================

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// JSON response helper
function sendJson(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

// =============================================
// HANDLE CHECKOUT POST REQUEST
// =============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Never display PHP warnings inside JSON responses
    header('Content-Type: application/json; charset=utf-8');

    $conn = null;
    $transactionStarted = false;

    try {

        // -----------------------------------------
        // 1. DATABASE CONNECTION
        // -----------------------------------------

        $servername = "gateway01.ap-northeast-1.prod.aws.tidbcloud.com";
        $username   = getenv("DB_USERNAME");
        $password   = getenv("DB_PASSWORD");
        $dbname     = "ecommerece";
        $dbport     = 4000;

        if (!$username || !$password) {
            throw new RuntimeException(
                "Database environment variables are missing."
            );
        }

        $ssl_ca = __DIR__ . "/ca.pem";

        if (!file_exists($ssl_ca)) {
            throw new RuntimeException(
                "Database CA certificate was not found."
            );
        }

        $conn = mysqli_init();

        mysqli_ssl_set(
            $conn,
            null,
            null,
            $ssl_ca,
            null,
            null
        );

        mysqli_real_connect(
            $conn,
            $servername,
            $username,
            $password,
            $dbname,
            $dbport,
            null,
            MYSQLI_CLIENT_SSL
        );

        $conn->set_charset("utf8mb4");

        // -----------------------------------------
        // 2. CUSTOMER INFORMATION
        // -----------------------------------------

        $name    = trim($_POST['name'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $city    = trim($_POST['city'] ?? '');
        $country = trim($_POST['country'] ?? '');
        $postal  = trim($_POST['postal'] ?? '');
        $address = trim($_POST['address'] ?? '');

        $payment_method = "Cash on Delivery";

        if (
            $name === '' ||
            $email === '' ||
            $phone === '' ||
            $city === '' ||
            $country === '' ||
            $postal === '' ||
            $address === ''
        ) {
            sendJson([
                "status" => "error",
                "message" => "Please complete all checkout fields."
            ], 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sendJson([
                "status" => "error",
                "message" => "Please enter a valid email address."
            ], 400);
        }

        // -----------------------------------------
        // 3. DECODE AND VALIDATE CART
        // -----------------------------------------

        $items_json = $_POST['items'] ?? '[]';
        $cart = json_decode($items_json, true);

        if (
            !is_array($cart) ||
            empty($cart) ||
            json_last_error() !== JSON_ERROR_NONE
        ) {
            sendJson([
                "status" => "error",
                "message" => "Your cart is empty or invalid."
            ], 400);
        }

        // Validate item IDs and quantities.
        // Combine duplicate product IDs to avoid overselling.
        $quantities = [];

        foreach ($cart as $item) {

            if (!is_array($item)) {
                sendJson([
                    "status" => "error",
                    "message" => "Invalid cart item."
                ], 400);
            }

            $pid = filter_var(
                $item['id'] ?? null,
                FILTER_VALIDATE_INT,
                ["options" => ["min_range" => 1]]
            );

            $qty = filter_var(
                $item['quantity'] ?? null,
                FILTER_VALIDATE_INT,
                ["options" => ["min_range" => 1]]
            );

            if ($pid === false || $qty === false) {
                sendJson([
                    "status" => "error",
                    "message" => "Invalid product ID or quantity."
                ], 400);
            }

            if (!isset($quantities[$pid])) {
                $quantities[$pid] = 0;
            }

            $quantities[$pid] += $qty;

            if ($quantities[$pid] > 1000000) {
                sendJson([
                    "status" => "error",
                    "message" => "Invalid product quantity."
                ], 400);
            }
        }

        $ids = array_keys($quantities);

        // -----------------------------------------
        // 4. START TRANSACTION AND LOCK PRODUCTS
        // -----------------------------------------

        $conn->begin_transaction();
        $transactionStarted = true;

        $placeholders = implode(
            ',',
            array_fill(0, count($ids), '?')
        );

        $stmt = $conn->prepare(
            "SELECT id, name, price, stock
             FROM product
             WHERE id IN ($placeholders)
             FOR UPDATE"
        );

        $types = str_repeat('i', count($ids));
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();

        $result = $stmt->get_result();

        $products = [];

        while ($row = $result->fetch_assoc()) {
            $products[(int)$row['id']] = $row;
        }

        $stmt->close();

        // -----------------------------------------
        // 5. VALIDATE STOCK AND CALCULATE TOTAL
        // -----------------------------------------

        $total = 0;
        $orderItems = [];
        $item_text = "";

        foreach ($quantities as $pid => $qty) {

            if (!isset($products[$pid])) {
                throw new RuntimeException(
                    "A product in your cart no longer exists."
                );
            }

            $product = $products[$pid];
            $stock = (int)$product['stock'];
            $price = (float)$product['price'];

            if ($qty > $stock) {
                throw new RuntimeException(
                    "Insufficient stock for " . $product['name']
                );
            }

            if ($price < 0 || !is_finite($price)) {
                throw new RuntimeException(
                    "Invalid price for " . $product['name']
                );
            }

            $subtotal = $price * $qty;
            $total += $subtotal;

            // Store server-verified product information in the order.
            $orderItems[] = [
                "id" => (int)$pid,
                "name" => $product['name'],
                "price" => $price,
                "quantity" => $qty
            ];

            $item_text .=
                $product['name'] .
                " (x" . $qty . ")" .
                " - Rs " . number_format($price, 2) .
                " each; Subtotal: Rs " .
                number_format($subtotal, 2) . "\n";
        }

        if (!is_finite($total)) {
            throw new RuntimeException("Invalid order total.");
        }

        // -----------------------------------------
        // 6. CHECK ACTIVE SALE
        // -----------------------------------------

        $sale_status = "Inactive";

        $sale_result = $conn->query(
            "SELECT id
             FROM sales
             WHERE status = 'active'
             ORDER BY id DESC
             LIMIT 1"
        );

        if ($sale_result->num_rows > 0) {
            $sale_status = "Active";
        }

        // -----------------------------------------
        // 7. UPDATE STOCK
        // -----------------------------------------

        $update_stmt = $conn->prepare(
            "UPDATE product SET stock = ? WHERE id = ?"
        );

        foreach ($quantities as $pid => $qty) {

            $new_stock =
                (int)$products[$pid]['stock'] - $qty;

            $update_stmt->bind_param(
                "ii",
                $new_stock,
                $pid
            );

            $update_stmt->execute();
        }

        $update_stmt->close();

        // -----------------------------------------
        // 8. INSERT ORDER
        // -----------------------------------------

        $items_json_encoded = json_encode(
            $orderItems,
            JSON_UNESCAPED_UNICODE |
            JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($items_json_encoded === false) {
            throw new RuntimeException(
                "Could not encode order items."
            );
        }

        $insert_stmt = $conn->prepare(
            "INSERT INTO orders
            (
                user_name,
                email,
                phone,
                country,
                city,
                postal,
                address,
                total,
                payment_method,
                items,
                sale_status
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $insert_stmt->bind_param(
            "sssssssdsss",
            $name,
            $email,
            $phone,
            $country,
            $city,
            $postal,
            $address,
            $total,
            $payment_method,
            $items_json_encoded,
            $sale_status
        );

        $insert_stmt->execute();
        $insert_stmt->close();

        // -----------------------------------------
        // 9. COMMIT ORDER BEFORE SENDING EMAIL
        // -----------------------------------------

        $conn->commit();
        $transactionStarted = false;

        // The order is now saved.
        // Notification failures must not cancel the order.

        // -----------------------------------------
        // 10. CREATE WHATSAPP NOTIFICATION
        // -----------------------------------------

        $admin_phone = getenv("ADMIN_PHONE") ?: "";

        // Use international format without + or spaces.
        $admin_phone = preg_replace(
            '/\D+/',
            '',
            $admin_phone
        );

        $whatsapp_message =
            "New Glamaura Order Received!\n\n" .
            "Customer: $name\n" .
            "Email: $email\n" .
            "Phone: $phone\n" .
            "Address: $address, $city, $postal, $country\n\n" .
            "Order Items:\n$item_text\n" .
            "Total: Rs " . number_format($total, 2) . "\n" .
            "Payment: $payment_method\n" .
            "Sale Status: $sale_status\n\n" .
            "Please confirm the order.";

        $whatsapp_url = "";

        if ($admin_phone !== "") {
            $whatsapp_url =
                "https://wa.me/" .
                $admin_phone .
                "?text=" .
                rawurlencode($whatsapp_message);
        }

        // -----------------------------------------
        // 11. SEND ADMIN EMAIL USING GMAIL SMTP
        // -----------------------------------------

        $mailSent = false;

        $admin_app_mail =
            trim(getenv("ADMIN_APP_EMAIL") ?: "");

        // Google displays spaces in some App Password views.
        $admin_app_password = preg_replace(
            '/\s+/',
            '',
            getenv("ADMIN_APP_PASSWORD") ?: ""
        );

        $admin_email = trim(
            getenv("ADMIN_MAIL") ?: ""
        );

        $subject = "New Order Received - Glamaura";

        $email_body =
            "A new order has been placed on Glamaura.\n\n" .
            "CUSTOMER DETAILS\n" .
            "-------------------------\n" .
            "Name: $name\n" .
            "Email: $email\n" .
            "Phone: $phone\n" .
            "City: $city\n" .
            "Country: $country\n" .
            "Postal Code: $postal\n" .
            "Address: $address\n\n" .
            "ORDER ITEMS\n" .
            "-------------------------\n" .
            $item_text . "\n" .
            "Total Amount: Rs " .
            number_format($total, 2) . "\n" .
            "Payment Method: $payment_method\n" .
            "Sale Status: $sale_status\n";

        if (
            $admin_app_mail !== "" &&
            $admin_app_password !== "" &&
            filter_var($admin_app_mail, FILTER_VALIDATE_EMAIL) &&
            filter_var($admin_email, FILTER_VALIDATE_EMAIL)
        ) {

            try {

                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;

                $mail->Username = $admin_app_mail;
                $mail->Password = $admin_app_password;

                $mail->SMTPSecure =
                    \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

                $mail->Port = 587;
                $mail->CharSet = 'UTF-8';

                $mail->setFrom(
                    $admin_app_mail,
                    'Glamaura Orders'
                );

                $mail->addAddress($admin_email);

                $mail->isHTML(false);
                $mail->Subject = $subject;
                $mail->Body = $email_body;

                $mail->send();

                $mailSent = true;

            } catch (\Throwable $mailException) {

                // Do not expose credentials or SMTP details to customers.
                error_log(
                    "Glamaura order email failed: " .
                    $mailException->getMessage()
                );
            }

        } else {

            error_log(
                "Glamaura order email failed: " .
                "Email environment variables are missing or invalid."
            );
        }

        // -----------------------------------------
        // 12. RETURN SUCCESS RESPONSE
        // -----------------------------------------

        if ($conn) {
            $conn->close();
        }

        sendJson([
            "status" => "success",
            "message" => "Your order has been placed successfully.",
            "whatsapp" => $whatsapp_url,
            "email_sent" => $mailSent
        ]);

    } catch (\Throwable $e) {

        // Roll back only if the transaction has not committed.
        if ($conn && $transactionStarted) {
            try {
                $conn->rollback();
            } catch (\Throwable $rollbackError) {
                error_log(
                    "Checkout rollback failed: " .
                    $rollbackError->getMessage()
                );
            }
        }

        error_log(
            "Glamaura checkout error: " . $e->getMessage()
        );

        if ($conn) {
            try {
                $conn->close();
            } catch (\Throwable $closeError) {
                // Connection may already be closed.
            }
        }

        sendJson([
            "status" => "error",
            "message" =>
                "Checkout could not be completed. Please try again."
        ], 500);
    }
}

// =============================================
// SHOW CHECKOUT PAGE FOR GET REQUESTS
// =============================================
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Checkout - Glamaura</title>

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
    >

    <link
        rel="stylesheet"
        href="static/styling/user_styling/checkout.css"
    >

    <link rel="icon" type="image/webp" href="/static/icon.webp">
</head>

<body>

    <nav>
        <div class="logo">
            <a href="home.php">
                <img src="static/logo.webp" alt="Glamaura">
            </a>
        </div>

        <div class="nav-links">
            <a class="buttons" href="home.php">Home</a>
            <a class="buttons" href="shop.php">Shop</a>
            <a class="buttons" href="contact.php">Contact</a>
            <a class="buttons" href="cart.php">
                <i class="fa-solid fa-cart-shopping"></i>
            </a>
        </div>
    </nav>

    <div class="banner">
        <marquee>
            <h1>🎁 Cash on Delivery Available</h1>
        </marquee>
    </div>

    <div class="checkout-container">

        <h2>Checkout</h2>

        <form id="checkoutForm" method="POST" action="checkout.php">

            <div class="form-row">
                <div class="form-group">
                    <label for="name">Full Name</label>
                    <input
                        type="text"
                        name="name"
                        id="name"
                        placeholder="Enter your full name"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input
                        type="email"
                        name="email"
                        id="email"
                        placeholder="Enter your email address"
                        required
                    >
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="phone">Phone</label>
                    <input
                        type="tel"
                        name="phone"
                        id="phone"
                        placeholder="03XX-XXXXXXX"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="city">City</label>
                    <input
                        type="text"
                        name="city"
                        id="city"
                        placeholder="Enter your city"
                        required
                    >
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="country">Country</label>
                    <select name="country" id="country" required>
                        <option value="Pakistan">Pakistan</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="postal">Postal Code</label>
                    <input
                        type="text"
                        name="postal"
                        id="postal"
                        placeholder="Enter postal code"
                        required
                    >
                </div>
            </div>

            <div class="form-group">
                <label for="address">Shipping Address</label>
                <textarea
                    name="address"
                    id="address"
                    rows="3"
                    placeholder="House No, Street, Area, etc."
                    required
                ></textarea>
            </div>

            <div class="order-section">
                <div class="order-summary" id="orderSummary"></div>

                <div class="payment-box">
                    <h3>Payment Method</h3>

                    <div class="cod-method">
                        <i class="fa-solid fa-truck"></i>
                        <span>
                            <b>Cash on Delivery</b> — Pay when your order arrives.
                        </span>
                    </div>

                    <p>You’ll receive a confirmation call before delivery.</p>
                </div>
            </div>

            <input type="hidden" name="items" id="itemsInput">

            <div class="btn-group">
                <button
                    type="submit"
                    id="placeOrderBtn"
                    class="btn confirm-btn"
                >
                    Place Order
                </button>

                <button
                    type="button"
                    class="btn cancel-btn"
                    onclick="window.location.href='cart.php'"
                >
                    Cancel
                </button>
            </div>

            <p id="checkoutMessage" role="status"></p>

        </form>
    </div>

    <div id="orderModal" class="modal">
        <div class="modal-content">

            <span class="close" role="button" aria-label="Close">&times;</span>

            <h3>✅ Order Placed!</h3>

            <h3>🎉 Thank You for Your Purchase!</h3>

            <p class="thankyou-text">
                <strong>Your order has been placed successfully!</strong><br>
                You will receive a confirmation call shortly.<br>
                Your order will be processed after confirmation.<br>
                Standard delivery time is 3–6 business days.
            </p>

            <button
                class="btn confirm-btn"
                onclick="window.location.href='shop.php'"
            >
                Continue Shopping
            </button>

        </div>
    </div>

    <footer>
        <div class="footer-container">

            <div>
                <h3>Customer Support</h3>
                <a href="shipping_policy.php">Shipping Policy</a>
                <a href="refund_policy.php">Refund Policy</a>
                <a href="privacy_policy.php">Privacy Policy</a>
                <a href="terms_of_service.php">Terms of Service</a>
                <a href="contact.php">Contact Information</a>
            </div>

            <div>
                <h3>About Glamaura</h3>
                <p>
                    Beauty, skincare, makeup & more —
                    discover your beauty and enhance your glow.
                </p>
            </div>

            <div>
                <h3>Follow Us</h3>
                <a href="https://wa.me/923372513067" target="_blank"
                   rel="noopener noreferrer">WhatsApp</a>

                <a href="https://wa.me/923712421804" target="_blank"
                   rel="noopener noreferrer">WhatsApp</a>

                <a href="mailto:glamauraastore@gmail.com">Gmail</a>
            </div>

        </div>

        <p>© 2026 Glamaura — Crafted with elegance & love.</p>
    </footer>

    <script>
    document.addEventListener("DOMContentLoaded", function () {

        const cart = JSON.parse(localStorage.getItem("cart") || "[]");

        const summary = document.getElementById("orderSummary");
        const itemsInput = document.getElementById("itemsInput");
        const form = document.getElementById("checkoutForm");
        const modal = document.getElementById("orderModal");
        const closeButton = modal.querySelector(".close");
        const placeOrderBtn = document.getElementById("placeOrderBtn");
        const message = document.getElementById("checkoutMessage");

        // Show cart summary.
        // These prices are for display only; the server verifies prices.
        if (cart.length === 0) {

            summary.innerHTML =
                "<p style='text-align:center;font-weight:bold;'>" +
                "Your cart is empty.</p>";

            placeOrderBtn.disabled = true;

        } else {

            let displayTotal = 0;

            summary.innerHTML = "<h3>Order Summary</h3>";

            cart.forEach(item => {

                const name = String(item.name || "Product");
                const image = String(item.image || "");
                const price = Number(item.display_price ?? item.price ?? 0);
                const qty = Number(item.quantity || 1);

                const itemTotal = price * qty;

                displayTotal += itemTotal;

                // Build elements safely instead of inserting product names
                // directly into HTML markup.
                const row = document.createElement("div");
                row.className = "order-item";

                const img = document.createElement("img");
                img.src = image;
                img.alt = name;
                img.onerror = function () {
                    this.style.display = "none";
                };

                const productName = document.createElement("span");
                productName.textContent = name + " (x" + qty + ")";

                const productTotal = document.createElement("span");
                productTotal.textContent =
                    "₨ " + itemTotal.toLocaleString();

                row.append(img, productName, productTotal);
                summary.appendChild(row);
            });

            const shippingRow = document.createElement("div");
            shippingRow.className = "order-item";

            const shippingLabel = document.createElement("span");
            shippingLabel.textContent = "Shipping";

            const shippingPrice = document.createElement("span");
            shippingPrice.textContent = "Free";
            shippingPrice.style.color = "#28a745";

            shippingRow.append(shippingLabel, shippingPrice);
            summary.appendChild(shippingRow);

            const totalRow = document.createElement("div");
            totalRow.className = "order-total";
            totalRow.textContent =
                "Total: ₨ " + displayTotal.toLocaleString();

            summary.appendChild(totalRow);

            itemsInput.value = JSON.stringify(cart);
        }

        closeButton.addEventListener("click", function () {
            modal.style.display = "none";
        });

        window.addEventListener("click", function (event) {
            if (event.target === modal) {
                modal.style.display = "none";
            }
        });

        // Submit order
        form.addEventListener("submit", async function (event) {

            event.preventDefault();

            if (cart.length === 0) {
                alert("Your cart is empty.");
                return;
            }

            placeOrderBtn.disabled = true;
            placeOrderBtn.textContent = "Processing...";
            message.textContent = "";

            try {

                const formData = new FormData(form);

                const response = await fetch("checkout.php", {
                    method: "POST",
                    body: formData,
                    headers: {
                        "Accept": "application/json"
                    }
                });

                const responseText = await response.text();

                console.log("Checkout HTTP Status:", response.status);
                console.log("Checkout response:", responseText);

                let result;

                try {
                    result = JSON.parse(responseText);
                } catch (parseError) {
                    console.error("Invalid JSON response:", parseError);

                    throw new Error(
                        "Server returned an invalid response. " +
                        "Check the Vercel Runtime Logs."
                    );
                }

                if (!response.ok || result.status !== "success") {
                    throw new Error(
                        result.message || "Unable to place your order."
                    );
                }

                // The server confirms that the order has been saved.
                localStorage.removeItem("cart");

                // Report notification status separately from order status.
                if (result.email_sent) {
                    message.textContent =
                        "Order saved. Admin email notification sent.";
                } else {
                    message.textContent =
                        "Order saved, but the admin email notification " +
                        "could not be sent. Please check server logs.";
                }

                modal.style.display = "flex";

                // Open WhatsApp if an admin phone number is configured.
                if (result.whatsapp) {
                    window.open(result.whatsapp, "_blank");
                }

            } catch (error) {

                console.error("Checkout error:", error);

                alert(
                    error.message ||
                    "Checkout failed. Please try again."
                );

            } finally {

                placeOrderBtn.disabled = true;

                // The order may have been saved even if the response
                // was lost. Do not immediately enable another order
                // submission, which could create a duplicate.
                placeOrderBtn.textContent = "Order Submitted";

            }
        });
    });
    </script>

</body>
</html>
