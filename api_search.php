<?php
session_start();
if (!isset($_SESSION['admin'])) { echo json_encode([]); exit(); }
include 'db.php';

$q = mysqli_real_escape_string($conn, $_GET['q'] ?? '');
if (strlen($q) < 2) { echo json_encode([]); exit(); }

$results = [];

// Search Customers
$cust_res = mysqli_query($conn, "SELECT id, customer_name, mobile FROM customers WHERE customer_name LIKE '%$q%' OR mobile LIKE '%$q%' LIMIT 5");
while($row = mysqli_fetch_assoc($cust_res)) {
    $results[] = [
        'title' => $row['customer_name'] . " (" . $row['mobile'] . ")",
        'url' => "update_customer.php?id=" . $row['id'],
        'icon' => 'fa-user'
    ];
}

// Search Orders (Invoices)
$order_res = mysqli_query($conn, "SELECT DISTINCT invoice_no FROM orders WHERE invoice_no LIKE '%$q%' LIMIT 5");
while($row = mysqli_fetch_assoc($order_res)) {
    $results[] = [
        'title' => "Invoice: " . $row['invoice_no'],
        'url' => "view_invoice.php?invoice_no=" . urlencode($row['invoice_no']),
        'icon' => 'fa-file-invoice'
    ];
}

// Search Fertilizers
$fert_res = mysqli_query($conn, "SELECT id, fertilizer_name FROM fertilizers WHERE fertilizer_name LIKE '%$q%' LIMIT 5");
while($row = mysqli_fetch_assoc($fert_res)) {
    $results[] = [
        'title' => "Product: " . $row['fertilizer_name'],
        'url' => "update_fertilizer.php?id=" . $row['id'],
        'icon' => 'fa-flask'
    ];
}

echo json_encode($results);
?>
