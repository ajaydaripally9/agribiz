<?php
session_start();
if (!isset($_SESSION['admin'])) {
    header('Location: index.php');
    exit();
}
include 'db.php';

// Fetch Debtors (Customers with total bill > total paid)
$debt_query = "
    SELECT c.id, c.customer_name, c.mobile, c.address,
        COALESCE(SUM(o.total_price), 0) as total_bill,
        COALESCE(SUM(o.paid_amount), 0) as total_paid,
        (COALESCE(SUM(o.total_price), 0) - COALESCE(SUM(o.paid_amount), 0)) as total_due
    FROM customers c
    JOIN (
        SELECT customer_id, invoice_no, MAX(total_price) as total_price, MAX(paid_amount) as paid_amount 
        FROM orders 
        GROUP BY customer_id, invoice_no
    ) o ON o.customer_id = c.id
    GROUP BY c.id
    HAVING total_due > 0
    ORDER BY total_due DESC
";
$debt_res = mysqli_query($conn, $debt_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Debt Collection — AgriBiz</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    :root {
        --bg: #0d1117; --card: #161b22; --border: #30363d;
        --green: #22c55e; --red: #ef4444; --text: #e6edf3; --muted: #8b949e;
    }
    body { background: var(--bg); color: var(--text); font-family: 'Inter', sans-serif; padding: 20px; }
    .container { max-width: 1000px; margin: 0 auto; }
    .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
    .card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 20px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 15px; text-align: left; border-bottom: 1px solid var(--border); }
    th { color: var(--muted); text-transform: uppercase; font-size: 11px; letter-spacing: 1px; }
    .due-amt { color: var(--red); font-weight: 800; font-size: 16px; }
    .btn { padding: 8px 16px; border-radius: 6px; border: none; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; font-size: 12px; }
    .btn-wa { background: #25D366; color: #fff; }
    .btn-view { background: #3b82f6; color: #fff; }
</style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>Debt <span>Collection Dashboard</span></h1>
        <a href="dashboard.php" style="color:var(--muted); text-decoration:none;"><i class="fas fa-arrow-left"></i> Back</a>
    </div>

    <div class="card">
        <p style="color:var(--muted); margin-bottom: 20px; font-size: 14px;">The following customers have outstanding payments. You can send a WhatsApp reminder with a single click.</p>
        <table>
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Mobile</th>
                    <th>Total Spent</th>
                    <th>Current Due</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = mysqli_fetch_assoc($debt_res)): 
                    $msg = "Hello ".strtoupper($row['customer_name']).", this is a friendly reminder from TIRUMALA FERTILIZERS. You have an outstanding balance of ₹".number_format($row['total_due'], 2).". Please visit the shop or pay via UPI. Thank you!";
                    $wa_url = "https://wa.me/91".$row['mobile']."?text=".urlencode($msg);
                ?>
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars($row['customer_name']); ?></strong><br>
                        <span style="font-size:11px; color:var(--muted);"><?php echo htmlspecialchars($row['address']); ?></span>
                    </td>
                    <td><?php echo $row['mobile']; ?></td>
                    <td>₹<?php echo number_format($row['total_bill'], 2); ?></td>
                    <td class="due-amt">₹<?php echo number_format($row['total_due'], 2); ?></td>
                    <td>
                        <a href="<?php echo $wa_url; ?>" target="_blank" class="btn btn-wa"><i class="fab fa-whatsapp"></i> Remind</a>
                        <a href="customer_ledger.php?id=<?php echo $row['id']; ?>" class="btn btn-view"><i class="fas fa-list"></i> Ledger</a>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>
