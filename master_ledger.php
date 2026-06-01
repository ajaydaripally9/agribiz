<?php
session_start();
include 'db.php';
checkRole(['Admin', 'Accountant']);

// Fetch Search and Filter parameters
$search = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build Query for Customer Summary
$summary_query = "
    SELECT c.id, c.customer_name, c.mobile, c.address,
        COALESCE(SUM(o.total_price), 0) as total_bill,
        COALESCE(SUM(o.paid_amount), 0) as total_paid
    FROM customers c
    LEFT JOIN (
        SELECT customer_id, invoice_no, MAX(total_price) as total_price, MAX(paid_amount) as paid_amount 
        FROM orders 
        GROUP BY customer_id, invoice_no
    ) o ON o.customer_id = c.id
    WHERE c.customer_name LIKE '%$search%' OR c.mobile LIKE '%$search%'
    GROUP BY c.id
    ORDER BY total_bill DESC
";
$summary_res = mysqli_query($conn, $summary_query);

// Build Query for Detailed Transactions
$details_where = "WHERE (c.customer_name LIKE '%$search%' OR c.mobile LIKE '%$search%')";
if ($date_from) $details_where .= " AND o.order_date >= '$date_from'";
if ($date_to) $details_where .= " AND o.order_date <= '$date_to'";

$details_query = "
    SELECT o.*, c.customer_name as c_name, c.mobile as c_mobile
    FROM orders o
    JOIN customers c ON o.customer_id = c.id
    $details_where
    ORDER BY o.created_at DESC
";
$details_res = mysqli_query($conn, $details_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Master Customer Ledger — AgriBiz</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    :root {
        --bg: #0d1117; --card: #161b22; --border: #30363d;
        --green: #22c55e; --blue: #3b82f6; --red: #ef4444;
        --text: #e6edf3; --muted: #8b949e;
    }
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
    body { background: var(--bg); color: var(--text); padding: 20px; }
    
    .container { max-width: 1200px; margin: 0 auto; }
    .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
    .header h1 { font-size: 24px; font-weight: 700; }
    .header h1 span { color: var(--green); }

    .card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 20px; margin-bottom: 24px; }
    .card-title { font-size: 16px; font-weight: 600; margin-bottom: 15px; display: flex; align-items: center; gap: 10px; }
    
    .filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; }
    input, select { background: #0d1117; border: 1px solid var(--border); border-radius: 8px; padding: 10px; color: #fff; width: 100%; outline: none; }
    input:focus { border-color: var(--green); }
    
    .btn { padding: 10px 20px; border-radius: 8px; border: none; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; font-size: 14px; }
    .btn-green { background: var(--green); color: #fff; }
    .btn-blue { background: var(--blue); color: #fff; }
    .btn-outline { background: transparent; border: 1px solid var(--border); color: var(--text); }

    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th, td { padding: 12px; text-align: left; border-bottom: 1px solid var(--border); font-size: 14px; }
    th { color: var(--muted); font-weight: 600; text-transform: uppercase; font-size: 12px; }
    
    .badge { padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 700; }
    .badge-green { background: rgba(34,197,94,0.1); color: var(--green); }
    .badge-red { background: rgba(239,68,68,0.1); color: var(--red); }
    
    .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
    .stat-card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 20px; }
    .stat-val { font-size: 22px; font-weight: 700; margin: 5px 0; }
    .stat-label { color: var(--muted); font-size: 12px; text-transform: uppercase; }

    @media print { .no-print { display: none; } body { background: #fff; color: #000; padding: 0; } .card { border: none; } }
</style>
</head>
<body>

<div class="container">
    <div class="header no-print">
        <h1>Master <span>Customer Ledger</span></h1>
        <div style="display:flex; gap:10px;">
            <button onclick="window.print()" class="btn btn-outline"><i class="fas fa-print"></i> Print Report</button>
            <a href="dashboard.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Dashboard</a>
        </div>
    </div>

    <!-- Filters Section -->
    <div class="card no-print">
        <form method="GET" class="filters">
            <input type="text" name="search" placeholder="Search customer name or mobile..." value="<?php echo htmlspecialchars($search); ?>">
            <input type="date" name="date_from" value="<?php echo $date_from; ?>">
            <input type="date" name="date_to" value="<?php echo $date_to; ?>">
            <button type="submit" class="btn btn-green"><i class="fas fa-filter"></i> Apply Filters</button>
        </form>
    </div>

    <?php
    // Calculate totals for stat cards
    $total_bill_all = 0;
    $total_paid_all = 0;
    $cust_summaries = [];
    while($row = mysqli_fetch_assoc($summary_res)) {
        $cust_summaries[] = $row;
        $total_bill_all += $row['total_bill'];
        $total_paid_all += $row['total_paid'];
    }
    $total_due_all = $total_bill_all - $total_paid_all;
    ?>

    <div class="summary-grid">
        <div class="stat-card">
            <div class="stat-label">Total Business</div>
            <div class="stat-val" style="color:var(--blue);">₹<?php echo number_format($total_bill_all, 2); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Total Collected</div>
            <div class="stat-val" style="color:var(--green);">₹<?php echo number_format($total_paid_all, 2); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Total Outstanding Due</div>
            <div class="stat-val" style="color:var(--red);">₹<?php echo number_format($total_due_all, 2); ?></div>
        </div>
    </div>

    <!-- Customer Summary Table -->
    <div class="card">
        <div class="card-title"><i class="fas fa-users" style="color:var(--blue);"></i> Customer Balances</div>
        <table>
            <thead>
                <tr>
                    <th>Customer Name</th>
                    <th>Mobile</th>
                    <th>Total Bill</th>
                    <th>Total Paid</th>
                    <th>Remaining Due</th>
                    <th class="no-print">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($cust_summaries as $cust): $due = $cust['total_bill'] - $cust['total_paid']; ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($cust['customer_name']); ?></strong></td>
                    <td><?php echo $cust['mobile']; ?></td>
                    <td>₹<?php echo number_format($cust['total_bill'], 2); ?></td>
                    <td style="color:var(--green);">₹<?php echo number_format($cust['total_paid'], 2); ?></td>
                    <td style="color:<?php echo $due > 0 ? 'var(--red)' : 'var(--muted)'; ?>; font-weight:700;">
                        ₹<?php echo number_format($due, 2); ?>
                    </td>
                    <td class="no-print">
                        <a href="customer_ledger.php?id=<?php echo $cust['id']; ?>" class="btn btn-outline" style="padding: 4px 10px; font-size: 11px;">View Ledger</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Detailed Transaction Log -->
    <div class="card">
        <div class="card-title"><i class="fas fa-history" style="color:var(--green);"></i> Detailed Transaction History (Product-wise)</div>
        <table>
            <thead>
                <tr>
                    <th>Date & Time</th>
                    <th>Customer</th>
                    <th>Product</th>
                    <th>Qty</th>
                    <th>Unit Price</th>
                    <th>Total</th>
                    <th>Paid</th>
                    <th>Type</th>
                    <th>Invoice</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = mysqli_fetch_assoc($details_res)): 
                    $unit_price = $row['total_price'] / ($row['quantity'] ?: 1);
                ?>
                <tr>
                    <td style="font-size:12px; color:var(--muted);"><?php echo date('d M Y, h:i A', strtotime($row['created_at'])); ?></td>
                    <td><?php echo htmlspecialchars($row['c_name']); ?></td>
                    <td><strong><?php echo htmlspecialchars($row['fertilizer_name']); ?></strong></td>
                    <td><?php echo $row['quantity']; ?></td>
                    <td>₹<?php echo number_format($unit_price, 2); ?></td>
                    <td>₹<?php echo number_format($row['total_price'], 2); ?></td>
                    <td style="color:var(--green);">₹<?php echo number_format($row['paid_amount'], 2); ?></td>
                    <td><span class="badge <?php echo $row['bill_type']=='Credit'?'badge-red':'badge-green'; ?>"><?php echo $row['bill_type']; ?></span></td>
                    <td><a href="view_invoice.php?invoice_no=<?php echo $row['invoice_no']; ?>" target="_blank" style="color:var(--blue); text-decoration:none; font-size:11px;">#<?php echo $row['invoice_no']; ?></a></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>
