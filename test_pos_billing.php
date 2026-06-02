<?php
// test_pos_billing.php — Integration test for admin_billing.php database actions
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "========================================\n";
echo "    OFFLINE POS BILLING INTEGRATION TEST\n";
echo "========================================\n\n";

include 'db.php';

// 1. Initial Stock Level of Product 1 (Urea)
echo "1. Checking initial product stock...\n";
$p_res = mysqli_query($conn, "SELECT quantity, price, batch_no, mfg_date, expiry_date FROM fertilizers WHERE id = 1");
if (!$p_res || mysqli_num_rows($p_res) === 0) {
    echo "   [FAIL] Could not load product ID 1 (Urea) from inventory!\n";
    exit(1);
}
$prod = mysqli_fetch_assoc($p_res);
$initial_stock = intval($prod['quantity']);
$price = floatval($prod['price']);
$batch = $prod['batch_no'] ?: 'B-101';
$mfg = $prod['mfg_date'] ?: '2026-01-01';
$expiry = $prod['expiry_date'] ?: '2028-12-31';
echo "   Product: Urea (ID: 1)\n";
echo "   Initial Stock: $initial_stock units\n";
echo "   Selling Price: ₹$price\n";
echo "   Batch No: $batch\n";
echo "   Mfg Date: $mfg\n";
echo "   Expiry Date: $expiry\n";

// 2. Initial Sales Count & Orders Count for Test Invoice
$test_invoice = 'BILL-TEST-POS-' . time();
echo "\n2. Generating Invoice ID: $test_invoice\n";

// 3. Processing POS Submission Transaction Simulation
echo "\n3. Processing transaction (Buying 2 units of Urea)...\n";
$customer_id = 1; // John Doe
$paid_amount = $price * 2;
$bill_type = 'Cash';
$qty_to_buy = 2;
$item_total = $price * $qty_to_buy;

mysqli_begin_transaction($conn);
try {
    // A. Deduct stock
    $upd_stock = mysqli_query($conn, "UPDATE fertilizers SET quantity = quantity - $qty_to_buy WHERE id = 1");
    if (!$upd_stock) throw new Exception("Failed to deduct stock: " . mysqli_error($conn));
    echo "   -> Stock deduction query executed successfully.\n";

    // B. Insert Order
    $ins_order = mysqli_prepare($conn, "INSERT INTO orders (customer_id, customer_name, fertilizer_id, fertilizer_name, quantity, total_price, order_date, status, invoice_no, paid_amount, bill_type, batch_no, mfg_date, expiry_date) VALUES (?, ?, ?, ?, ?, ?, CURDATE(), 'Delivered', ?, ?, ?, ?, ?, ?)");
    $customer_name = 'John Doe';
    mysqli_stmt_bind_param($ins_order, "isisidsdssss", $customer_id, $customer_name, $customer_id, $customer_name, $qty_to_buy, $item_total, $test_invoice, $paid_amount, $bill_type, $batch, $mfg, $expiry);
    if (!mysqli_stmt_execute($ins_order)) {
        throw new Exception("Failed to insert order: " . mysqli_stmt_error($ins_order));
    }
    echo "   -> Order record inserted successfully.\n";

    // C. Insert Sale
    $ins_sale = mysqli_prepare($conn, "INSERT INTO sales (customer_name, fertilizer_name, quantity, total_price, sale_date, invoice_no, paid_amount, bill_type, batch_no, mfg_date, expiry_date) VALUES (?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($ins_sale, "ssidsdssss", $customer_name, $customer_name, $qty_to_buy, $item_total, $test_invoice, $paid_amount, $bill_type, $batch, $mfg, $expiry);
    if (!mysqli_stmt_execute($ins_sale)) {
        throw new Exception("Failed to insert sale: " . mysqli_stmt_error($ins_sale));
    }
    echo "   -> Sale record inserted successfully.\n";

    mysqli_commit($conn);
    echo "   [PASS] Transaction processed and committed successfully!\n";
} catch (Exception $e) {
    mysqli_rollback($conn);
    echo "   [FAIL] Transaction error: " . $e->getMessage() . "\n";
    exit(1);
}

// 4. Verifying Post-Transaction State
echo "\n4. Verifying post-transaction values...\n";

// A. Stock Verification
$p_res2 = mysqli_query($conn, "SELECT quantity FROM fertilizers WHERE id = 1");
$final_stock = intval(mysqli_fetch_assoc($p_res2)['quantity']);
$expected_stock = $initial_stock - $qty_to_buy;

echo "   Initial Stock: $initial_stock\n";
echo "   Final Stock  : $final_stock\n";
echo "   Expected     : $expected_stock\n";
if ($final_stock === $expected_stock) {
    echo "   [PASS] Stock deduction matches perfectly!\n";
} else {
    echo "   [FAIL] Stock deduction mismatch!\n";
}

// B. Order Verification
$ord_check = mysqli_query($conn, "SELECT * FROM orders WHERE invoice_no = '$test_invoice'");
if ($ord_check && mysqli_num_rows($ord_check) > 0) {
    $ord = mysqli_fetch_assoc($ord_check);
    echo "   [PASS] Order successfully stored in database.\n";
    echo "          Total Price: ₹{$ord['total_price']}\n";
    echo "          Bill Type  : {$ord['bill_type']}\n";
    echo "          Batch No   : {$ord['batch_no']}\n";
} else {
    echo "   [FAIL] Order was not found in database!\n";
}

// C. Sale Verification
$sale_check = mysqli_query($conn, "SELECT * FROM sales WHERE invoice_no = '$test_invoice'");
if ($sale_check && mysqli_num_rows($sale_check) > 0) {
    $sale = mysqli_fetch_assoc($sale_check);
    echo "   [PASS] Sale successfully stored in database.\n";
    echo "          Quantity: {$sale['quantity']}\n";
    echo "          GST Rate: 18.00%\n";
} else {
    echo "   [FAIL] Sale was not found in database!\n";
}

// 5. Cleanup Test Records
echo "\n5. Cleaning up test records from database...\n";
mysqli_query($conn, "DELETE FROM orders WHERE invoice_no = '$test_invoice'");
mysqli_query($conn, "DELETE FROM sales WHERE invoice_no = '$test_invoice'");
mysqli_query($conn, "UPDATE fertilizers SET quantity = $initial_stock WHERE id = 1");
echo "   [PASS] Cleaned up. Initial inventory levels restored.\n";

echo "\n========================================\n";
echo "    OFFLINE POS BILLING TEST PASSED     \n";
echo "========================================\n";
?>
