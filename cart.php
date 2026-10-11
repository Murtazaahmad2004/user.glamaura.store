<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cart - Glamaura</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="static/styling/user_styling/cart.css">
    <link rel="icon" type="image/png" sizes="32x32" href="/static/icon.webp">
<link rel="icon" type="image/png" sizes="16x16" href="/static/icon.webp">
<link rel="apple-touch-icon" sizes="180x180" href="/static/icon.webp">
</head>

<body>

    <!-- Navbar -->
    <nav>
        <div class="logo"><a href="home.php"><img src="static/logo.webp" alt="wrist-Win"></a></div>
        <div class="nav-links">
            <a class="buttons" href="home.php">Home</a>
            <a class="buttons" href="shop.php">Shop</a>
            <a class="buttons" href="contact.php">Contact</a>
            <a class="buttons" href="cart.php"><i class="fa-solid fa-cart-shopping"></i></a>
        </div>
    </nav>

    <!-- Cart Container -->
    <div class="cart-container">
        <h2>Your Shopping Cart</h2>
        <div id="cartItems"></div>
        <div class="cart-summary">
            <p><strong>Total:</strong> <span id="cartTotal">PKR 0</span></p>
            <button class="checkout-btn" onclick="window.location.href='checkout.php'">Proceed to Checkout</button>
        </div>
    </div>

    <!-- Footer -->
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
                <p>Beauty, skincare, makeup & more — discover your beauty, enhance your glow, all in one place.</p>
            </div>
            <div>
                <h3>Follow Us</h3>
                <a href="https://wa.me/923372513067" target="_blank" style="color:#25D366;"><i
                        class="fab fa-whatsapp"></i> Whatsapp</a>
                <a href="https://wa.me/923712421804" target="_blank" style="color:#25D366;"><i class="fab fa-whatsapp"></i> Whatsapp</a>
                <a href="#" target="_blank" style="color:#E1306C;"><i
                        class="fab fa-instagram"></i> Instagram</a>
                <a href="#" target="_blank"
                    style="color:#1877F2;"><i class="fab fa-facebook"></i> Facebook</a>
                <a href="#" target="_blank"><i class="fab fa-tiktok"></i> TikTok</a>
                <a href="mailto: glamauraastore@gmail.com" style="color:#D14836;"><i class="fa-solid fa-envelope"></i>
                    Gmail</a>
            </div>
        </div>
        <p>© 2026 Glamaura product — Crafted with elegance & love.</p>
    </footer>

    <script>
        // ----------------------
        // Format PKR
        // ----------------------
        function formatPKR(amount) {
            return "PKR " + Number(amount || 0).toLocaleString();
        }

        let stockData = {};

        // ----------------------
        // Load stock from server
        // ----------------------
        async function loadStock() {
            const cart = JSON.parse(localStorage.getItem("cart")) || [];

            if (cart.length === 0) {
                renderCart();
                return;
            }

            const productIds = cart.map(item => item.id);

            try {
                const response = await fetch("getStock.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ product_ids: productIds })
                });

                stockData = await response.json();
            } catch (err) {
                console.error("Stock load failed:", err);
            }

            renderCart();
        }

        // ----------------------
        // Render Cart
        // ----------------------
        function renderCart() {
            const cart = JSON.parse(localStorage.getItem("cart")) || [];
            const cartContainer = document.getElementById("cartItems");
            const cartTotal = document.getElementById("cartTotal");
            const checkoutBtn = document.querySelector(".checkout-btn");
            const cartSummary = document.querySelector(".cart-summary");

            cartContainer.innerHTML = "";

            if (cart.length === 0) {
                cartContainer.innerHTML =
                    "<p style='text-align:center;color:#fff;'>Your cart is empty.</p>";
                cartTotal.textContent = "PKR 0";
                checkoutBtn.style.display = "none";
                cartSummary.style.display = "none";
                return;
            }

            checkoutBtn.style.display = "inline-block";
            cartSummary.style.display = "block";

            let total = 0;

            cart.forEach((item, index) => {
                const p = stockData[item.id] || item;

                if (item.quantity > p.stock) item.quantity = p.stock;

                total += p.price * item.quantity;

                cartContainer.innerHTML += `
                <div class="cart-item">
                    <img src="${p.image}" alt="${p.name}">

                    <div class="cart-details">
                        <h3>${p.name}</h3>
                        <p>
                            ${p.discount > 0
                                        ? `<del>${formatPKR(p.original_price)}</del>
                                <strong style="color:#0f0;"> ${formatPKR(p.price)}</strong>`
                                        : formatPKR(p.price)}
                        </p>
                        <p style="color:yellow;">
                            ${p.stock > 0 ? "Stock: " + p.stock : "Out of Stock"}
                        </p>
                    </div>

                    <div class="cart-quantity">
                        <button onclick="changeQty(${index}, -1)">−</button>
                        <span id="qty-${index}">${item.quantity}</span>
                        <button onclick="changeQty(${index}, 1)" 
                            ${item.quantity >= p.stock ? "disabled" : ""}>+</button>
                    </div>
                </div>`;
            });

            cartTotal.textContent = formatPKR(total);
        }

        // ----------------------
        // Change Quantity
        // ----------------------
        function changeQty(index, change) {
            let cart = JSON.parse(localStorage.getItem("cart")) || [];
            const item = cart[index];
            const p = stockData[item.id];

            if (change === -1 && item.quantity === 1) {
                cart.splice(index, 1);
            } else if (change === 1 && item.quantity >= p.stock) {
                return;
            } else {
                item.quantity += change;
            }

            localStorage.setItem("cart", JSON.stringify(cart));
            renderCart();
        }

        // ----------------------
        // Initial Load
        // ----------------------
        loadStock();
    </script>

</body>

</html>