<!DOCTYPE html>
<html lang="en">
   <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Privacy Policy - Glamaura product</title>
      <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
      <link rel="stylesheet" href="static/styling/user_styling/privacy_policy.css" />
      <link rel="icon" type="image/png" sizes="32x32" href="/static/icon.webp">
      <link rel="icon" type="image/png" sizes="16x16" href="/static/icon.webp">
      <link rel="apple-touch-icon" sizes="180x180" href="/static/icon.webp">
   </head>

   <body>

      <!-- 🌸 Navbar -->
      <nav>
         <div class="logo">
            <a href="home.php">
               <img src="static/logo.webp" alt="wrist-Win">
            </a>
         </div>

         <div class="nav-links">
            <a class="buttons" href="home.php">Home</a>
            <a class="buttons" href="shop.php">Shop</a>
            <a class="buttons" href="contact.php">Contact</a>
            <a class="buttons" href="cart.php"><i class="fa-solid fa-cart-shopping"></i></a>
         </div>
      </nav>

      <!-- 🌿 Privacy Policy Section -->
      <div class="policy">
         <h1>Privacy Policy</h1>
         <p><i class="fa-solid fa-shield-halved"></i> Your privacy is our top priority.</p>
         <p>We collect only the necessary information to process your orders and provide you with the best experience.</p>
         <p>All personal data such as your name, contact details, and address are kept strictly confidential and are never shared with third parties.</p>
         <p>We use secure payment gateways and encrypted data protection to ensure your online safety.</p>
         <p>By using our website, you consent to our privacy practices as described here.</p>
      </div>

      <!-- 🌺 Footer -->
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
               <a href="https://wa.me/923372513067" target="_blank" style="color:#25D366;"><i class="fab fa-whatsapp"></i> Whatsapp</a>
               <a href="https://wa.me/923712421804" target="_blank" style="color:#25D366;"><i class="fab fa-whatsapp"></i> Whatsapp</a>
               <a href="#" target="_blank" style="color:#E1306C;"><i class="fab fa-instagram"></i> Instagram</a>
               <a href="#" target="_blank" style="color:#1877F2;"><i class="fab fa-facebook"></i> Facebook</a>
               <a href="#" target="_blank" style="color:#ffffff;"><i class="fab fa-tiktok"></i> TikTok</a>
               <a href="mailto: glamauraastore@gmail.com" style="color:#D14836;"><i class="fa-solid fa-envelope"></i> Gmail</a>
            </div>

         </div>

         <p>© 2026 Glamaura product — Crafted with elegance & love.</p>
      </footer>

      <script>
         const toggle = document.getElementById("menu-toggle");
         const navLinks = document.getElementById("nav-links");

         if (toggle) {
            toggle.addEventListener("click", () => {
               navLinks.classList.toggle("show");
            });
         }
      </script>
      
   </body>
</html>