<?php
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');

$conn = db_connect();

$sale = null;
$saleResult = $conn->query("SELECT * FROM sales WHERE status='active' AND (start_date IS NULL OR start_date <= CURDATE()) AND (end_date IS NULL OR end_date >= CURDATE()) ORDER BY id DESC LIMIT 1");
if ($saleResult) {
    $sale = $saleResult->fetch_assoc();
}

$discount = $sale ? (float)$sale['discount_percent'] : 0;
$result = $conn->query("SELECT id, name, price, stock, image, description, category FROM product ORDER BY id DESC");

$data = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $original = (float)$row['price'];
        $row['price'] = $original;
        $row['original_price'] = $original;
        $row['discount'] = $discount;
        $row['discounted_price'] = $discount > 0 ? round($original * (1 - $discount / 100), 2) : null;
        $data[] = $row;
    }
}

$conn->close();
echo json_encode([
    'sale' => $sale,
    'products' => $data
]);
