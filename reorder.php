<?php
session_start();
if (!isset($_SESSION['customer'])) { header('Location: index.php'); exit(); }
include 'db.php';

$customer_id = intval($_SESSION['customer_id']);
$invoice_no = isset($_GET['invoice_no']) ? trim($_GET['invoice_no']) : '';

if (!$invoice_no) { header('Location: customer_shop.php'); exit(); }

// Fetch original order items
$stmt = mysqli_prepare($conn, "SELECT fertilizer_id, fertilizer_name, quantity FROM orders WHERE customer_id=? AND invoice_no=? AND status='Accepted'");
mysqli_stmt_bind_param($stmt, "is", $customer_id, $invoice_no);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$items = [];
while ($row = mysqli_fetch_assoc($result)) { $items[] = $row; }

if (empty($items)) { header('Location: customer_shop.php?status=error&msg=' . urlencode('Could not reorder: original order not found.')); exit(); }

// Generate new sequential invoice
$seq_result = mysqli_query($conn, "SELECT COALESCE(MAX(id), 0) + 1 AS next_seq FROM orders");
$seq_row = mysqli_fetch_assoc($seq_result);
$seq = str_pad($seq_row['next_seq'], 4, '0', STR_PAD_LEFT);
$new_invoice = 'ORD-' . date('Y') . '-' . date('m') . '-' . $seq;

$customer_name = $_SESSION['customer_name'];
$insert = mysqli_prepare($conn, "INSERT INTO orders (customer_id, customer_name, fertilizer_id, fertilizer_name, quantity, total_price, order_date, status, invoice_no) SELECT ?, ?, fertilizer_id, fertilizer_name, ?, price * ?, CURDATE(), 'Pending', ? FROM fertilizers WHERE id=?");

$success = true;
foreach ($items as $item) {
    $fid = $item['fertilizer_id'];
    $qty = $item['quantity'];
    // Check stock
    $stock_check = mysqli_fetch_assoc(mysqli_query($conn, "SELECT quantity FROM fertilizers WHERE id=$fid"));
    if (!$stock_check || $stock_check['quantity'] < $qty) {
        $success = false;
        header('Location: customer_shop.php?status=error&msg=' . urlencode('Reorder failed: Insufficient stock for ' . $item['fertilizer_name']));
        exit();
    }
    mysqli_stmt_bind_param($insert, "isiisi", $customer_id, $customer_name, $qty, $qty, $new_invoice, $fid);
    if (!mysqli_stmt_execute($insert)) { $success = false; break; }
}

if ($success) {
    header('Location: customer_shop.php?status=success&invoice=' . urlencode($new_invoice));
} else {
    header('Location: customer_shop.php?status=error&msg=' . urlencode('Reorder failed. Please try again.'));
}
exit();
?>
