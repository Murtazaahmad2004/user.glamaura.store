<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Database connection
$servername = "gateway01.ap-northeast-1.prod.aws.tidbcloud.com";
$username = getenv("DB_USERNAME");
$password = getenv("DB_PASSWORD");
$dbname = "ecommerece";
$dbport = 4000;

// TiDB Cloud TLS configuration
$ssl_ca = __DIR__ . "/ca.pem";

$conn = mysqli_init();

mysqli_ssl_set(
    $conn,
    NULL,
    NULL,
    $ssl_ca,
    NULL,
    NULL
);

mysqli_real_connect(
    $conn,
    $servername,
    $username,
    $password,
    $dbname,
    $dbport,
    NULL,
    MYSQLI_CLIENT_SSL
);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Supabase Storage configuration
$supabase_url = getenv("SUPABASE_URL");
$supabase_url = rtrim($supabase_url, "/");
$supabaseBucket = "watches";

// GET ACTIVE SALE
$active_sale_query = "SELECT * FROM sales WHERE status='active' LIMIT 1";
$active_sale_result = $conn->query($active_sale_query);
$active_sale = $active_sale_result
    ? $active_sale_result->fetch_assoc()
    : null;

// GET ALL PRODUCTS
$product_query = "SELECT * FROM product";
$product_result = $conn->query($product_query);

$product = [];

if ($product_result && $product_result->num_rows > 0) {
    while ($row = $product_result->fetch_assoc()) {

        // Fetch image from Supabase Storage
        $imageValue = trim($row['image'] ?? '');
        $row['image_url'] = '';

        if ($imageValue !== '') {

            // If database already contains a full URL, use it.
            if (
                filter_var($imageValue, FILTER_VALIDATE_URL) &&
                in_array(
                    strtolower(parse_url($imageValue, PHP_URL_SCHEME) ?? ''),
                    ['http', 'https'],
                    true
                )
            ) {
                $row['image_url'] = $imageValue;
            } else {
                // Otherwise, use the filename stored in the database.
                // Bucket files are assumed to be in the root.
                $row['image_url'] =
                    rtrim($supabase_url , '/') .
                    '/storage/v1/object/public/' .
                    rawurlencode($supabaseBucket) . '/' .
                    str_replace(
                        '%2F',
                        '/',
                        rawurlencode($imageValue)
                    );
            }
        }

        // Calculate sale price
        if ($active_sale && isset($active_sale['discount_percent'])) {
            $discount = (float) $active_sale['discount_percent'];

            $row['discounted_price'] = round(
                (float) $row['price'] * (1 - $discount / 100),
                2
            );

            $row['discount'] = $discount;
        } else {
            $row['discounted_price'] = null;
            $row['discount'] = 0;
        }

        $product[] = $row;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Glamaura - Home</title>

<link rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<link rel="stylesheet"
      href="static/styling/user_styling/home.css">

<link rel="icon" type="image/webp" sizes="32x32" href="/static/icon.webp">
<link rel="apple-touch-icon" sizes="180x180" href="/static/icon.webp">
</head>

<body>

<!-- Navbar -->
<nav class="nav">
    <div class="nav-left">
        <a href="/">
            <img class="logo" src="static/logo.webp" alt="Glamaura">
        </a>
    </div>

    <div class="nav-right">
        <div class="nav-links">
            <a class="buttons" href="home.php">Home</a>
            <a class="buttons" href="shop.php">Shop</a>
            <a class="buttons" href="contact.php">Contact</a>

            <a href="cart.php" class="cart-icon" id="cart-icon">
                <i class="fa-solid fa-cart-shopping"></i>
                <span class="cart-badge" id="cart-count">0</span>
            </a>
        </div>
    </div>
</nav>

<!-- Hero Slider -->
<div class="slider">
    <div class="slides">
        <div class="slide">
            <div class="banner">
                <img src="static/banner.webp" alt="Glamaura cosmetics">
            </div>
        </div>
    </div>
</div>

<!-- Products Section -->
<div class="home-header">
    <h1>Selling Products</h1>

    <?php if (
        $active_sale &&
        isset($active_sale['sale_name'], $active_sale['discount_percent'])
    ): ?>
        <h2 class="gradient-text">
            🔥 <?= htmlspecialchars($active_sale['sale_name']) ?>
            - <?= htmlspecialchars($active_sale['discount_percent']) ?>% OFF!
        </h2>
    <?php endif; ?>
</div>

<section class="products">
    <div class="product-grid">

        <?php if (count($product) > 0): ?>

            <?php foreach ($product as $watch): ?>

                <div class="product-card">

                    <div class="image-container">

                        <?php if ($watch['discounted_price'] !== null): ?>
                            <span class="sale-badge">SALE</span>
                        <?php endif; ?>

                        <?php if ($watch['image_url'] !== ''): ?>
                            <img
                                src="<?= htmlspecialchars(
                                    $watch['image_url'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                alt="<?= htmlspecialchars(
                                    $watch['name'] ?? '',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                loading="lazy"
                            >
                        <?php endif; ?>

                    </div>

                    <div class="product-info">

                        <h3>
                            <?= htmlspecialchars($watch['name'] ?? '') ?>
                        </h3>

                        <p>
                            <?= htmlspecialchars($watch['description'] ?? '') ?>
                        </p>

                        <?php if ($watch['discounted_price'] !== null): ?>

                            <div class="price">
                                <span class="original-price">
                                    Rs. <?= number_format((float) $watch['price'], 2) ?>
                                </span>

                                <span class="discounted-price">
                                    Rs. <?= number_format(
                                        (float) $watch['discounted_price'],
                                        2
                                    ) ?>
                                </span>
                            </div>

                        <?php else: ?>

                            <div class="price">
                                <span class="discounted-price">
                                    PKR. <?= number_format((float) $watch['price'], 2) ?>
                                </span>
                            </div>

                        <?php endif; ?>

                        <a href="shop.php">
                            <button type="button">Shop Now</button>
                        </a>

                    </div>
                </div>

            <?php endforeach; ?>

        <?php else: ?>

            <p style="color:#ffffff;">No product available yet.</p>

        <?php endif; ?>

    </div>
</section>

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
            <p>
                Beauty, skincare, makeup & more — discover your beauty,
                enhance your glow, all in one place.
            </p>
        </div>

        <div>
            <h3>Follow Us</h3>
            <a href="https://wa.me/923372513067"
               target="_blank" rel="noopener noreferrer"
               style="color:#25D366;">
                <i class="fab fa-whatsapp"></i> WhatsApp
            </a>

            <a href="https://wa.me/923712421804"
               target="_blank" rel="noopener noreferrer"
               style="color:#25D366;">
                <i class="fab fa-whatsapp"></i> WhatsApp
            </a>

            <a href="#" style="color:#E1306C;">
                <i class="fab fa-instagram"></i> Instagram
            </a>

            <a href="#" style="color:#1877F2;">
                <i class="fab fa-facebook"></i> Facebook
            </a>

            <a href="#" style="color:#ffffff;">
                <i class="fab fa-tiktok"></i> TikTok
            </a>

            <a href="mailto:glamauraastore@gmail.com"
               style="color:#D14836;">
                <i class="fa-solid fa-envelope"></i> Gmail
            </a>
        </div>

    </div>

    <p>© 2026 Glamaura — Crafted with elegance & love.</p>
</footer>

<script>
function updateCartCount() {
    const cart = JSON.parse(localStorage.getItem("cart")) || [];

    const count = cart.reduce(
        (acc, item) => acc + (Number(item.quantity) || 0),
        0
    );

    const cartCount = document.getElementById("cart-count");

    if (cartCount) {
        cartCount.textContent = count;
    }
}

document.addEventListener("DOMContentLoaded", updateCartCount);
window.addEventListener("storage", updateCartCount);

// Product scroll animation
const products = document.querySelectorAll(".product-card");

if ("IntersectionObserver" in window) {
    const observer = new IntersectionObserver((entries, obs) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add("show");
                obs.unobserve(entry.target);
            }
        });
    }, { threshold: 0.2 });

    products.forEach(product => observer.observe(product));
} else {
    products.forEach(product => product.classList.add("show"));
}
</script>

</body>
</html>