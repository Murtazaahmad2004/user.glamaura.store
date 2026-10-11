<?php
ini_set('display_errors', 1);
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
    NULL,       // client key
    NULL,       // client certificate
    $ssl_ca,    // CA certificate
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
    echo json_encode(["error" => $conn->connect_error]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
$productIds = $input["product_ids"] ?? [];

if (empty($productIds)) {
    echo json_encode([]);
    exit;
}

$idList = implode(",", array_map("intval", $productIds));

/* -------- CHECK ACTIVE SALE -------- */
$saleQuery = "
SELECT discount_percent 
FROM sales
WHERE status='active'
AND (start_date IS NULL OR start_date <= CURDATE())
AND (end_date IS NULL OR end_date >= CURDATE())
LIMIT 1
";
$saleResult = $conn->query($saleQuery);
$saleRow = $saleResult ? $saleResult->fetch_assoc() : null;
$discount = $saleRow ? (int)$saleRow['discount_percent'] : 0;

/* -------- PRODUCTS -------- */
$query = "SELECT id, name, price, stock, image FROM product WHERE id IN ($idList)";
$result = $conn->query($query);

$data = [];
while ($row = $result->fetch_assoc()) {

    $originalPrice = (float)$row['price'];
    $finalPrice = ($discount > 0)
        ? $originalPrice - ($originalPrice * $discount / 100)
        : $originalPrice;

    $data[$row["id"]] = [
        "name" => $row["name"],
        "price" => round($finalPrice),          // FINAL PRICE
        "original_price" => $originalPrice,     // FOR DISPLAY
        "discount" => $discount,
        "stock" => $row["stock"],
        "image" => $row["image"]
    ];
}

header("Content-Type: application/json");
echo json_encode($data);
