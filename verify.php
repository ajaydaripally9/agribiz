<?php
include 'db.php';
// Check if category column exists
$res = mysqli_query($conn, "DESCRIBE fertilizers");
echo "=== FERTILIZERS TABLE STRUCTURE ===\n";
while($row = mysqli_fetch_assoc($res)) {
    echo $row['Field'] . " | " . $row['Type'] . " | Default: " . $row['Default'] . "\n";
}
// Show all products with categories
echo "\n=== CURRENT PRODUCTS ===\n";
$res2 = mysqli_query($conn, "SELECT id, fertilizer_name, category, quantity, price FROM fertilizers");
while($row = mysqli_fetch_assoc($res2)) {
    echo "[" . $row['id'] . "] " . $row['fertilizer_name'] . " => Category: " . $row['category'] . " | Qty: " . $row['quantity'] . " | Price: " . $row['price'] . "\n";
}
echo "\n✅ All good!";
?>
