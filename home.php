<?php
session_start();
set_time_limit(300);
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Redirect back to THIS page (so the error message is always visible)
function uploadError($message)
{
    $_SESSION['message'] = $message;
    $self = strtok($_SERVER['REQUEST_URI'], '?');
    header("Location: " . $self);
    exit;
}

// Shrink big photos before upload (faster, avoids timeouts). Falls back to original file.
function prepareImage($path, $mime, $maxSide = 1600)
{
    $data = file_get_contents($path);
    if ($data === false) {
        return [false, $mime];
    }

    if ($mime === 'image/gif' || !function_exists('imagecreatefromstring')) {
        return [$data, $mime];
    }

    $img = @imagecreatefromstring($data);
    if (!$img) {
        return [$data, $mime];
    }

    $w = imagesx($img);
    $h = imagesy($img);

    if ($w <= $maxSide && $h <= $maxSide && strlen($data) < 1024 * 1024) {
        imagedestroy($img);
        return [$data, $mime];
    }

    $scale = min(1, $maxSide / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));

    $dst = imagecreatetruecolor($nw, $nh);

    if ($mime === 'image/png' || $mime === 'image/webp') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    }

    imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

    ob_start();
    if ($mime === 'image/png') {
        imagepng($dst, null, 6);
    } elseif ($mime === 'image/webp' && function_exists('imagewebp')) {
        imagewebp($dst, null, 85);
    } else {
        imagejpeg($dst, null, 85);
        $mime = 'image/jpeg';
    }
    $out = ob_get_clean();

    imagedestroy($img);
    imagedestroy($dst);

    if ($out === false || $out === '') {
        return [$data, $mime];
    }

    return [$out, $mime];
}

// Database connection
$servername = "gateway01.ap-northeast-1.prod.aws.tidbcloud.com";
$username = getenv("DB_USERNAME");
$password = getenv("DB_PASSWORD");
$dbname = "ecommerece";
$dbport = 4000;

$ssl_ca = __DIR__ . "/ca.pem";

$conn = mysqli_init();

mysqli_ssl_set($conn, NULL, NULL, $ssl_ca, NULL, NULL);

if (!mysqli_real_connect(
    $conn,
    $servername,
    $username,
    $password,
    $dbname,
    $dbport,
    NULL,
    MYSQLI_CLIENT_SSL
)) {
    die("Database connection failed: " . mysqli_connect_error());
}

// Supabase configuration
$supabase_url = rtrim(getenv("SUPABASE_URL") ?: "", "/");
$supabase_key = getenv("SUPABASE_SECRET_KEY") ?: "";
$bucket_name = "watches";

$allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$allowed_mime_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($supabase_url === "" || $supabase_key === "") {
        uploadError("Supabase environment variables are missing.");
    }

    // PHP drops everything when the total upload is bigger than post_max_size
    if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        uploadError(
            "Total upload size is too big for the server (post_max_size = "
            . ini_get('post_max_size') . "). Select smaller images."
        );
    }

    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
    $stock = filter_var($_POST['stock'] ?? null, FILTER_VALIDATE_INT);

    if (
        $name === '' ||
        $description === '' ||
        $price === false || $price <= 0 ||
        $stock === false || $stock < 0 ||
        !isset($_FILES['images']['name']) ||
        !is_array($_FILES['images']['name'])
    ) {
        uploadError("Please enter valid product details and select images.");
    }

    $files = $_FILES['images'];
    $file_count = count($files['name']);

    if ($file_count < 1) {
        uploadError("Please select at least one product image.");
    }

    $uploaded_image_urls = [];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);

    if (!$finfo) {
        uploadError("Could not validate image files.");
    }

    // Upload every selected image to Supabase
    for ($i = 0; $i < $file_count; $i++) {

        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            finfo_close($finfo);
            uploadError(
                "Image " . ($i + 1) . " failed to upload (PHP error code "
                . $files['error'][$i] . "). upload_max_filesize = "
                . ini_get('upload_max_filesize') . ". Select it again."
            );
        }

        $tmp_name = $files['tmp_name'][$i];
        $original_name = $files['name'][$i];
        $file_size = (int) $files['size'][$i];

        if (!is_uploaded_file($tmp_name)) {
            finfo_close($finfo);
            uploadError("Invalid uploaded image.");
        }

        $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        $mime_type = finfo_file($finfo, $tmp_name);

        if (
            !in_array($extension, $allowed_extensions, true) ||
            !in_array($mime_type, $allowed_mime_types, true)
        ) {
            finfo_close($finfo);
            uploadError("Only JPG, JPEG, PNG, GIF and WEBP images are allowed.");
        }

        if ($file_size <= 0 || $file_size > 10 * 1024 * 1024) {
            finfo_close($finfo);
            uploadError("Each image must be smaller than 10 MB.");
        }

        // Resize/compress before sending to Supabase
        [$file_contents, $send_mime] = prepareImage($tmp_name, $mime_type);

        if ($file_contents === false) {
            finfo_close($finfo);
            uploadError("Could not read an image file.");
        }

        // Keep the extension in sync with the final mime type
        if ($send_mime === 'image/jpeg') {
            $extension = 'jpg';
        } elseif ($send_mime === 'image/png') {
            $extension = 'png';
        } elseif ($send_mime === 'image/webp') {
            $extension = 'webp';
        } elseif ($send_mime === 'image/gif') {
            $extension = 'gif';
        }

        $filename = "Product_" . bin2hex(random_bytes(16)) . "." . $extension;

        $upload_url = $supabase_url
            . "/storage/v1/object/"
            . $bucket_name . "/"
            . $filename;

        // New "sb_secret_..." keys are not JWTs, so they must NOT be sent as Bearer token
        $headers = [
            "apikey: " . $supabase_key,
            "Content-Type: " . $send_mime,
            "Cache-Control: max-age=3600"
        ];

        if (strpos($supabase_key, 'sb_') !== 0) {
            $headers[] = "Authorization: Bearer " . $supabase_key;
        }

        $ch = curl_init($upload_url);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $file_contents,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => $headers
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);

        curl_close($ch);

        if ($response === false || $curl_error !== '') {
            finfo_close($finfo);
            error_log("Supabase curl error: " . $curl_error);
            uploadError("Upload failed (image " . ($i + 1) . "): " . $curl_error);
        }

        if ($http_code < 200 || $http_code >= 300) {
            finfo_close($finfo);
            error_log("Supabase upload error: " . $response);
            uploadError("Supabase error " . $http_code . ": " . $response);
        }

        $uploaded_image_urls[] = $supabase_url
            . "/storage/v1/object/public/"
            . $bucket_name . "/"
            . $filename;
    }

    finfo_close($finfo);

    // Keep the first image in product.image for compatibility
    $main_image = $uploaded_image_urls[0];

    try {
        $conn->begin_transaction();

        $stmt = $conn->prepare(
            "INSERT INTO product (name, image, description, price, stock)
             VALUES (?, ?, ?, ?, ?)"
        );

        if (!$stmt) {
            throw new Exception("Product query preparation failed: " . $conn->error);
        }

        $stmt->bind_param("sssdi", $name, $main_image, $description, $price, $stock);

        if (!$stmt->execute()) {
            throw new Exception("Could not save product: " . $stmt->error);
        }

        $product_id = $conn->insert_id;
        $stmt->close();

        $image_stmt = $conn->prepare(
            "INSERT INTO product_images (product_id, image_url) VALUES (?, ?)"
        );

        if (!$image_stmt) {
            throw new Exception("Image query preparation failed: " . $conn->error);
        }

        foreach ($uploaded_image_urls as $image_url) {
            $image_stmt->bind_param("is", $product_id, $image_url);

            if (!$image_stmt->execute()) {
                throw new Exception("Could not save all product images: " . $image_stmt->error);
            }
        }

        $image_stmt->close();
        $conn->commit();

        $_SESSION['message'] =
            "Product added successfully with "
            . count($uploaded_image_urls)
            . " images!";

        $conn->close();
        header("Location: admin.php");
        exit;

    } catch (Throwable $e) {

        $conn->rollback();
        error_log("Product save error: " . $e->getMessage());

        uploadError("Product could not be saved: " . $e->getMessage());
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add product</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        body {
            font-family: "Poppins", sans-serif;
            margin: 0;
            padding: 40px 20px;
            min-height: 100vh;
            background: linear-gradient(135deg, #1b2735, #2c3e50, #243b55);
            background-size: 400% 400%;
            animation: gradientFlow 18s ease infinite;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            color: #eaeaea;
            overflow-x: hidden;
        }

        @keyframes gradientFlow {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        .form-container {
            margin-top: 50px;
            background: rgba(255, 255, 255, 0.06);
            backdrop-filter: blur(14px);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 18px;
            padding: 40px 45px;
            max-width: 750px;
            width: 100%;
            box-shadow: 0 10px 35px rgba(0, 0, 0, 0.6);
            animation: fadeIn 0.6s ease-in-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        h2 {
            text-align: center;
            color: #00c6ff;
            font-size: 28px;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 25px;
            text-shadow: 0 0 18px rgba(0, 198, 255, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 20px 25px;
        }

        label {
            font-size: 13px;
            font-weight: 600;
            color: #f0f0f0;
            display: block;
            margin-bottom: 6px;
        }

        input,
        textarea,
        select {
            width: 350px;
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.18);
            background: rgba(255, 255, 255, 0.08);
            color: #fff;
            font-size: 14px;
            outline: none;
            transition: 0.3s;
        }

        input:focus,
        textarea:focus,
        select:focus {
            border-color: #00c6ff;
            box-shadow: 0 0 6px rgba(0, 198, 255, 0.4);
        }

        textarea {
            resize: none;
            height: 90px;
        }

        .btn-group {
            grid-column: span 2;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 15px;
            margin-top: 10px;
        }

        .btn-submit {
            flex: 1;
            max-width: 250px;
            text-align: center;
            padding: 12px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            cursor: pointer;
            border: none;
            background: linear-gradient(90deg, #00c6ff, #0072ff);
            color: #fff;
            box-shadow: 0 5px 20px rgba(0, 198, 255, 0.35);
            transition: 0.3s;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 198, 255, 0.45);
        }

        .btn-submit:disabled {
            opacity: 0.6;
            cursor: wait;
        }

        .btn-back {
            flex: 1;
            max-width: 250px;
            text-align: center;
            padding: 12px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            cursor: pointer;
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
            text-decoration: none;
            transition: 0.3s;
        }

        .btn-back:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }

        @media(max-width: 600px) {
            form { grid-template-columns: 1fr; }

            .btn-group {
                grid-column: span 1;
                flex-direction: column;
            }

            .btn-submit,
            .btn-back {
                max-width: 100%;
                width: 100%;
            }

            input,
            textarea,
            select {
                width: 100%;
                box-sizing: border-box;
            }
        }
    </style>
</head>

<body>

    <?php
    if (isset($_SESSION['message'])) {
        echo '<div class="alert" style="position:fixed;top:20px;right:20px;max-width:360px;background:rgba(0,0,0,0.85);color:#00ff00;padding:12px 18px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.3);z-index:1000;word-break:break-word;">' .
            htmlspecialchars($_SESSION['message']) .
            '</div>';

        unset($_SESSION['message']);
    }
    ?>

    <div class="form-container">
        <h2>
            <i class="fa-solid fa-clock"></i>
            Add New Product
        </h2>

        <form method="POST" enctype="multipart/form-data" id="productForm">
            <div>
                <label for="name">
                    <i class="fa-solid fa-font"></i>
                    Product Name
                </label>
                <input type="text" name="name" id="name" placeholder="Enter Product Name" required>
            </div>

            <div>
                <label for="price">
                    <i class="fa-solid fa-tag"></i>
                    Price
                </label>
                <input type="number" step="0.01" name="price" id="price" placeholder="Enter Price" required>
            </div>

            <div>
                <label for="stock">
                    <i class="fa-solid fa-boxes-stacked"></i>
                    Stock
                </label>
                <input type="number" name="stock" id="stock" placeholder="Enter Stock Quantity" required>
            </div>

            <div style="grid-column: span 2;">
                <label for="description">
                    <i class="fa-solid fa-align-left"></i>
                    Description
                </label>
                <textarea name="description" id="description" placeholder="Enter Description" required></textarea>
            </div>

            <div style="grid-column: span 2;">
                <label for="images">
                    <i class="fa-solid fa-image"></i>
                    Product Images (you can select more than one)
                </label>
                <input
                    type="file"
                    name="images[]"
                    id="images"
                    accept="image/jpeg,image/png,image/gif,image/webp"
                    multiple
                    required
                >
            </div>

            <div class="btn-group">
                <button type="submit" class="btn-submit" id="submitBtn">
                    <i class="fa-solid fa-upload"></i>
                    Upload Product
                </button>

                <a href="admin.php" class="btn-back">
                    <i class="fa-solid fa-arrow-left"></i>
                    Back to List
                </a>
            </div>
        </form>
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", () => {
            // Messages stay a bit longer so errors can be read
            document.querySelectorAll(".alert").forEach(alert => {
                setTimeout(() => alert.remove(), 10000);
            });

            // Prevent double submit while images are uploading
            const form = document.getElementById("productForm");
            const btn = document.getElementById("submitBtn");

            form.addEventListener("submit", () => {
                btn.disabled = true;
                btn.textContent = "Uploading... please wait";
            });
        });
    </script>
</body>
</html>