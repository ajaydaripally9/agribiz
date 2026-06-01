<?php
session_start();
if (!isset($_SESSION['admin'])) { header('Location: index.php'); exit(); }
include 'db.php';

$message = '';
if (!isset($_GET['id'])) { die("Customer ID required"); }
$customer_id = intval($_GET['id']);

// Handle New Payment
if (isset($_POST['record_payment'])) {
    $amount = floatval($_POST['payment_amount']);
    $invoice_no = $_POST['invoice_no'];
    
    if ($amount > 0) {
        // We update the orders and sales table. 
        // Note: For simplicity, we add the new payment to the existing paid_amount.
        mysqli_query($conn, "UPDATE orders SET paid_amount = paid_amount + $amount WHERE invoice_no = '$invoice_no'");
        mysqli_query($conn, "UPDATE sales SET paid_amount = paid_amount + $amount WHERE invoice_no = '$invoice_no'");
        $message = "Payment of ₹$amount recorded for $invoice_no";
    }
}

// Fetch Customer
$cust_stmt = mysqli_prepare($conn, "SELECT * FROM customers WHERE id = ?");
mysqli_stmt_bind_param($cust_stmt, "i", $customer_id);
mysqli_stmt_execute($cust_stmt);
$customer = mysqli_fetch_assoc(mysqli_stmt_get_result($cust_stmt));

// Fetch Orders (Ledger)
$ledger_query = "
    SELECT invoice_no, MAX(order_date) as order_date, 
           SUM(total_price) as total_bill, 
           MAX(paid_amount) as total_paid
    FROM orders 
    WHERE customer_id = $customer_id 
    GROUP BY invoice_no 
    ORDER BY MAX(order_date) DESC
";

$ledger_result = mysqli_query($conn, $ledger_query);

$overall_total = 0;
$overall_paid = 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Customer Ledger — AgriBiz</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--bg:#0d1117;--card:#161b22;--card2:#1c2333;--green:#22c55e;--blue:#3b82f6;--orange:#f59e0b;--red:#ef4444;--text:#e6edf3;--muted:#8b949e;--border:#30363d;}
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif;}
body{background:var(--bg);color:var(--text);padding:40px;}
.container{max-width:900px;margin:0 auto;}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:30px;}
.card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:24px;margin-bottom:24px;}
.table{width:100%;border-collapse:collapse;margin-top:15px;}
.table th{text-align:left;font-size:12px;color:var(--muted);padding:12px;border-bottom:1px solid var(--border);text-transform:uppercase;}
.table td{padding:14px 12px;border-bottom:1px solid var(--border);font-size:14px;}
.total-summary{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-top:20px;}
.summary-box{background:var(--card2);padding:20px;border-radius:12px;border:1px solid var(--border);text-align:center;}
.summary-box .val{font-size:24px;font-weight:700;margin-bottom:5px;}
.summary-box .lbl{font-size:11px;color:var(--muted);text-transform:uppercase;}
.btn{padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:none;text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
.btn-green{background:var(--green);color:#fff;}
.btn-outline{border:1px solid var(--border);color:var(--text);background:none;}
.badge{padding:3px 8px;border-radius:6px;font-size:11px;font-weight:700;}
.badge-red{background:rgba(239,68,68,0.1);color:var(--red);}
.badge-green{background:rgba(34,197,94,0.1);color:var(--green);}
input{background:var(--card2);border:1px solid var(--border);border-radius:6px;padding:6px 10px;color:var(--text);width:100px;outline:none;}
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1 style="font-size:24px;">Customer <span style="color:var(--green);">Ledger</span></h1>
            <p style="color:var(--muted);font-size:13px;margin-top:4px;"><?php echo htmlspecialchars($customer['customer_name']); ?> — <?php echo $customer['mobile']; ?></p>
        </div>
        <a href="customers.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to Customers</a>
    </div>

    <?php if($message): ?>
    <div style="background:rgba(34,197,94,0.1);color:var(--green);padding:15px;border-radius:10px;margin-bottom:20px;border:1px solid rgba(34,197,94,0.2);">
        <i class="fas fa-check-circle"></i> <?php echo $message; ?>
    </div>
    <?php endif; ?>

    <div class="card">
        <h3>Order History & Payments</h3>
        <table class="table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Invoice No</th>
                    <th>Bill Amount</th>
                    <th>Paid</th>
                    <th>Due</th>
                    <th>Quick Pay</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = mysqli_fetch_assoc($ledger_result)): 
                    $due = $row['total_bill'] - $row['total_paid'];
                    $overall_total += $row['total_bill'];
                    $overall_paid += $row['total_paid'];
                ?>
                <tr>
                    <td style="color:var(--muted);"><?php echo date('d M Y', strtotime($row['order_date'])); ?></td>
                    <td><strong><?php echo $row['invoice_no']; ?></strong></td>
                    <td>₹<?php echo number_format($row['total_bill'], 2); ?></td>
                    <td style="color:var(--green);">₹<?php echo number_format($row['total_paid'], 2); ?></td>
                    <td>
                        <?php if($due > 0): ?>
                        <span class="badge badge-red">₹<?php echo number_format($due, 2); ?></span>
                        <?php else: ?>
                        <span class="badge badge-green">PAID</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if($due > 0): ?>
                        <form method="POST" style="display:flex;gap:5px;">
                            <input type="hidden" name="invoice_no" value="<?php echo $row['invoice_no']; ?>">
                            <input type="number" step="0.01" name="payment_amount" max="<?php echo $due; ?>" placeholder="Amt" required>
                            <button type="submit" name="record_payment" class="btn btn-green" style="padding:4px 8px;"><i class="fas fa-check"></i></button>
                        </form>
                        <?php else: ?>
                        <span style="color:var(--muted);font-size:12px;">Settled</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>

        <div class="total-summary">
            <div class="summary-box">
                <div class="val">₹<?php echo number_format($overall_total, 2); ?></div>
                <div class="lbl">Total Billing</div>
            </div>
            <div class="summary-box">
                <div class="val" style="color:var(--green);">₹<?php echo number_format($overall_paid, 2); ?></div>
                <div class="lbl">Total Collected</div>
            </div>
            <div class="summary-box">
                <div class="val" style="color:var(--red);">₹<?php echo number_format($overall_total - $overall_paid, 2); ?></div>
                <div class="lbl">Outstanding Due</div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
