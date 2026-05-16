<?php
session_start();
if (!isset($_SESSION['customer']) && !isset($_SESSION['admin'])) {
    header('Location: index.php');
    exit();
}
include 'db.php';

if (!isset($_GET['invoice_no'])) {
    die("Invoice number not provided.");
}

$invoice_no = $_GET['invoice_no'];

// Fetch order details first to get customer_id if not in session
$stmt = mysqli_prepare($conn, "SELECT * FROM orders WHERE invoice_no = ?");
mysqli_stmt_bind_param($stmt, "s", $invoice_no);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$items = [];
$total_sum = 0;
while($row = mysqli_fetch_assoc($result)) {
    $items[] = $row;
    $total_sum += $row['total_price'];
}

if (count($items) === 0) {
    die("Invoice not found.");
}

$customer_id = $items[0]['customer_id'];
// Security: If customer session exists, ensure they only see their own invoice
if (isset($_SESSION['customer']) && !isset($_SESSION['admin'])) {
    if ($customer_id != $_SESSION['customer_id']) {
        die("Access denied.");
    }
}

// Fetch customer details
$cust_stmt = mysqli_prepare($conn, "SELECT * FROM customers WHERE id = ?");
mysqli_stmt_bind_param($cust_stmt, "i", $customer_id);
mysqli_stmt_execute($cust_stmt);
$customer = mysqli_fetch_assoc(mysqli_stmt_get_result($cust_stmt));

$invoice_date = date('d-m-Y', strtotime($items[0]['order_date']));
$status = $items[0]['status'];

$cgst_rate = 9;
$sgst_rate = 9;
$cgst_amt = ($total_sum * $cgst_rate) / 100;
$sgst_amt = ($total_sum * $sgst_rate) / 100;
$grand_total = $total_sum + $cgst_amt + $sgst_amt;

// Generate QR Code URL
$verify_url = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/view_invoice.php?invoice_no=" . urlencode($invoice_no);
$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=" . urlencode($verify_url);
?>

<!DOCTYPE html>
<html>
<head>
<title>Invoice - <?php echo htmlspecialchars($invoice_no); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>
body { background-color: #f5f6fa; font-family: 'Inter', sans-serif; margin: 0; padding: 0; }
.invoice-container { background: #fff; padding: 40px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); max-width: 850px; margin: 40px auto; position: relative; }
.table-invoice { width: 100%; border-collapse: collapse; margin-top: 25px; margin-bottom: 25px; }
.table-invoice th, .table-invoice td { border: 1px solid #eee; padding: 14px; font-size: 14px; }
.table-invoice th { background: #f8f9fa; text-align: left; color: #666; text-transform: uppercase; letter-spacing: 0.5px; font-size: 11px; }
.text-right { text-align: right; }
.badge { padding: 4px 12px; border-radius: 20px; color: white; font-size: 12px; font-weight: bold; text-transform: uppercase; }
.badge-pending { background-color: #f39c12; }
.badge-accepted { background-color: #2ecc71; }
.badge-shipped { background-color: #3498db; }
.badge-delivered { background-color: #059669; }
.badge-rejected { background-color: #e74c3c; }

@media print {
    body { background: white; }
    .invoice-container { box-shadow: none; margin: 0; width: 100%; max-width: 100%; padding: 0; }
    .no-print { display: none !important; }
}
</style>
</head>
<body <?php if(isset($_GET['print'])) echo 'onload="window.print()"'; ?>>

<div class="invoice-container">
    <div style="display:flex; justify-content:space-between; border-bottom:2px solid #333; padding-bottom:20px; margin-bottom:30px; align-items: center;">
        <div>
            <h1 style="margin:0; color:#1a1a1a; letter-spacing: -1px;">GREEN GROW AGRI</h1>
            <p style="margin:5px 0 0 0; color:#666; font-size: 13px;">Premium Fertilizer Marketplace & Supply</p>
        </div>
        <div style="text-align: right;">
            <img src="<?php echo htmlspecialchars($qr_url); ?>" alt="QR Code" style="border: 1px solid #eee; padding: 5px; border-radius: 8px; width: 90px;">
        </div>
    </div>

    <div style="display:flex; justify-content:space-between; margin-bottom:30px;">
        <div>
            <div style="color: #888; font-size: 11px; text-transform: uppercase; margin-bottom: 5px;">Invoice Details</div>
            <div style="font-size: 14px; line-height: 1.6;">
                <strong>No:</strong> <?php echo htmlspecialchars($invoice_no); ?><br>
                <strong>Date:</strong> <?php echo htmlspecialchars($invoice_date); ?><br>
                <strong>Status:</strong> 
                <?php 
                $b_class = 'badge-pending';
                if($status == 'Accepted') $b_class = 'badge-accepted';
                if($status == 'Out for Delivery') $b_class = 'badge-shipped';
                if($status == 'Delivered') $b_class = 'badge-delivered';
                if($status == 'Rejected') $b_class = 'badge-rejected';
                ?>
                <span class="badge <?php echo $b_class; ?>"><?php echo htmlspecialchars($status); ?></span>
            </div>
        </div>
        <div style="text-align:right;">
            <div style="color: #888; font-size: 11px; text-transform: uppercase; margin-bottom: 5px;">Billed To</div>
            <div style="font-size: 14px; line-height: 1.6;">
                <strong><?php echo htmlspecialchars($customer['customer_name']); ?></strong><br>
                <?php echo htmlspecialchars($customer['mobile']); ?><br>
                <div style="max-width: 250px; display: inline-block;"><?php echo htmlspecialchars($customer['address']); ?></div>
            </div>
        </div>
    </div>

    <table class="table-invoice">
        <thead>
            <tr>
                <th>Product Description</th>
                <th>Quantity</th>
                <th>Unit Price</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): 
                $unit_price = $item['total_price'] / $item['quantity'];
            ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($item['fertilizer_name']); ?></strong></td>
                <td><?php echo htmlspecialchars($item['quantity']); ?></td>
                <td>₹<?php echo number_format($unit_price, 2); ?></td>
                <td>₹<?php echo number_format($item['total_price'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3" class="text-right">Subtotal</td>
                <td>₹<?php echo number_format($total_sum, 2); ?></td>
            </tr>
            <tr>
                <td colspan="3" class="text-right">CGST (9%)</td>
                <td>₹<?php echo number_format($cgst_amt, 2); ?></td>
            </tr>
            <tr>
                <td colspan="3" class="text-right">SGST (9%)</td>
                <td>₹<?php echo number_format($sgst_amt, 2); ?></td>
            </tr>
            <tr style="background: #f8f9fa;">
                <td colspan="3" class="text-right" style="font-size:18px;"><strong>GRAND TOTAL</strong></td>
                <td style="font-size:18px; color: #22c55e;"><strong>₹<?php echo number_format($grand_total, 2); ?></strong></td>
            </tr>
        </tfoot>
    </table>

    <div style="margin-top: 40px; border-top: 1px solid #eee; padding-top: 20px; font-size: 12px; color: #999; text-align: center;">
        <p>This is a computer generated invoice. No signature required.</p>
        <p>Thank you for choosing Green Grow Agri for your farming needs!</p>
    </div>

    <div style="text-align:center; margin-top:30px;" class="no-print">
        <button onclick="window.print()" style="background:#22c55e; color:white; border:none; padding:12px 30px; font-size:15px; font-weight: 600; border-radius:8px; cursor:pointer;">🖨️ Print Invoice</button>
        <br><br>
        <a href="<?php echo isset($_SESSION['admin']) ? 'dashboard.php' : 'customer_shop.php'; ?>" style="color: #666; text-decoration: none; font-size: 13px;">&larr; Back to <?php echo isset($_SESSION['admin']) ? 'Dashboard' : 'Shop'; ?></a>
    </div>
</div>

</body>
</html>

