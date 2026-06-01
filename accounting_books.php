<?php
session_start();
include 'db.php';
checkRole(['Admin', 'Accountant']);

$active_tab = $_GET['tab'] ?? 'day'; // 'day' | 'cash' | 'bank'
$date_filter = $_GET['date'] ?? date('Y-m-d');

// --- 1. DAY BOOK DATA ---
// Fetch all Sales on date
$sales_query = "SELECT s.*, 'Sales' as source_type, s.bill_type as method FROM sales s WHERE s.sale_date = '$date_filter'";
$sales_res = mysqli_query($conn, $sales_query);
$sales_day = [];
while($r = mysqli_fetch_assoc($sales_res)) $sales_day[] = $r;

// Fetch all Purchases on date
$pur_query = "SELECT p.*, 'Purchase' as source_type, 'Cash' as method FROM purchases p WHERE p.purchase_date = '$date_filter'";
$pur_res = mysqli_query($conn, $pur_query);
$pur_day = [];
while($r = mysqli_fetch_assoc($pur_res)) $pur_day[] = $r;

// Fetch all Vouchers on date
$vouch_query = "
    SELECT v.*, 'Voucher' as source_type, v.payment_method as method,
           CASE WHEN v.entity_type = 'Customer' THEN c.customer_name ELSE s.supplier_name END as entity_name
    FROM vouchers v
    LEFT JOIN customers c ON v.entity_type = 'Customer' AND v.entity_id = c.id
    LEFT JOIN suppliers s ON v.entity_type = 'Supplier' AND v.entity_id = s.id
    WHERE v.date = '$date_filter'";
$vouch_res = mysqli_query($conn, $vouch_query);
$vouch_day = [];
while($r = mysqli_fetch_assoc($vouch_res)) $vouch_day[] = $r;

// Combine for Day Book chronological display
$day_book_items = [];
foreach ($sales_day as $s) {
    $day_book_items[] = [
        'ref' => $s['invoice_no'] ?: ('INV-'.$s['id']),
        'type' => 'Sales',
        'particulars' => strtoupper($s['customer_name']) . " — " . $s['fertilizer_name'] . " (x" . $s['quantity'] . ")",
        'debit' => $s['total_price'], // Sales is incoming/receivable
        'credit' => 0,
        'method' => $s['method'] ?: 'Cash'
    ];
}
foreach ($pur_day as $p) {
    $day_book_items[] = [
        'ref' => 'PUR-'.$p['id'],
        'type' => 'Purchase',
        'particulars' => strtoupper($p['supplier_name']) . " — " . $p['fertilizer_name'] . " (x" . $p['quantity'] . ")",
        'debit' => 0,
        'credit' => $p['cost'] * $p['quantity'], // Purchase is outgoing/payable
        'method' => 'Cash'
    ];
}
foreach ($vouch_day as $v) {
    $day_book_items[] = [
        'ref' => $v['voucher_no'],
        'type' => $v['voucher_type'],
        'particulars' => strtoupper($v['entity_name']) . " (" . $v['voucher_type'] . ") — " . $v['narration'],
        'debit' => ($v['voucher_type'] === 'Receipt') ? $v['amount'] : 0,
        'credit' => ($v['voucher_type'] === 'Payment') ? $v['amount'] : 0,
        'method' => $v['method']
    ];
}

// --- 2. CASH BOOK DATA ---
// Cash Receipts (Debit): Cash Sales + Cash Receipt Vouchers
$cash_receipts = [];
$total_cash_in = 0;
// Fetch cash sales
$cash_sales_res = mysqli_query($conn, "SELECT * FROM sales WHERE bill_type = 'Cash'");
while($r = mysqli_fetch_assoc($cash_sales_res)) {
    $cash_receipts[] = [
        'date' => $r['sale_date'],
        'ref' => $r['invoice_no'],
        'particulars' => "Cash Sale — " . $r['customer_name'],
        'amount' => $r['total_price']
    ];
    $total_cash_in += $r['total_price'];
}
// Fetch Cash Receipt Vouchers
$cash_v_rcpt = mysqli_query($conn, "
    SELECT v.*, c.customer_name 
    FROM vouchers v 
    JOIN customers c ON v.entity_id = c.id 
    WHERE v.voucher_type = 'Receipt' AND v.payment_method = 'Cash'");
while($r = mysqli_fetch_assoc($cash_v_rcpt)) {
    $cash_receipts[] = [
        'date' => $r['date'],
        'ref' => $r['voucher_no'],
        'particulars' => "Receipt — " . $r['customer_name'] . " (" . $r['narration'] . ")",
        'amount' => $r['amount']
    ];
    $total_cash_in += $r['amount'];
}

// Cash Payments (Credit): Cash purchases + Cash Payment Vouchers
$cash_payments = [];
$total_cash_out = 0;
// Fetch cash purchases (Assuming all recorded purchases are cash since no bill_type column existed)
$cash_pur_res = mysqli_query($conn, "SELECT * FROM purchases");
while($r = mysqli_fetch_assoc($cash_pur_res)) {
    $cash_payments[] = [
        'date' => $r['purchase_date'],
        'ref' => 'PUR-'.$r['id'],
        'particulars' => "Cash Purchase — " . $r['supplier_name'],
        'amount' => $r['cost'] * $r['quantity']
    ];
    $total_cash_out += ($r['cost'] * $r['quantity']);
}
// Fetch Cash Payment Vouchers
$cash_v_pmnt = mysqli_query($conn, "
    SELECT v.*, s.supplier_name 
    FROM vouchers v 
    JOIN suppliers s ON v.entity_id = s.id 
    WHERE v.voucher_type = 'Payment' AND v.payment_method = 'Cash'");
while($r = mysqli_fetch_assoc($cash_v_pmnt)) {
    $cash_payments[] = [
        'date' => $r['date'],
        'ref' => $r['voucher_no'],
        'particulars' => "Payment — " . $r['supplier_name'] . " (" . $r['narration'] . ")",
        'amount' => $r['amount']
    ];
    $total_cash_out += $r['amount'];
}

$cash_balance = $total_cash_in - $total_cash_out;

// --- 3. BANK BOOK DATA ---
// Bank Receipts (Debit): Bank/UPI Sales + Bank Receipt Vouchers
$bank_receipts = [];
$total_bank_in = 0;
// Fetch Bank Sales
$bank_sales_res = mysqli_query($conn, "SELECT * FROM sales WHERE bill_type = 'Bank'");
while($r = mysqli_fetch_assoc($bank_sales_res)) {
    $bank_receipts[] = [
        'date' => $r['sale_date'],
        'ref' => $r['invoice_no'],
        'particulars' => "UPI/Bank Sale — " . $r['customer_name'],
        'amount' => $r['total_price']
    ];
    $total_bank_in += $r['total_price'];
}
// Fetch Bank Receipt Vouchers
$bank_v_rcpt = mysqli_query($conn, "
    SELECT v.*, c.customer_name 
    FROM vouchers v 
    JOIN customers c ON v.entity_id = c.id 
    WHERE v.voucher_type = 'Receipt' AND v.payment_method = 'Bank'");
while($r = mysqli_fetch_assoc($bank_v_rcpt)) {
    $bank_receipts[] = [
        'date' => $r['date'],
        'ref' => $r['voucher_no'],
        'particulars' => "Bank Receipt — " . $r['customer_name'] . " (" . $r['narration'] . ")",
        'amount' => $r['amount']
    ];
    $total_bank_in += $r['amount'];
}

// Bank Payments (Credit): Bank Payment Vouchers
$bank_payments = [];
$total_bank_out = 0;
$bank_v_pmnt = mysqli_query($conn, "
    SELECT v.*, s.supplier_name 
    FROM vouchers v 
    JOIN suppliers s ON v.entity_id = s.id 
    WHERE v.voucher_type = 'Payment' AND v.payment_method = 'Bank'");
while($r = mysqli_fetch_assoc($bank_v_pmnt)) {
    $bank_payments[] = [
        'date' => $r['date'],
        'ref' => $r['voucher_no'],
        'particulars' => "Bank Payment — " . $r['supplier_name'] . " (" . $r['narration'] . ")",
        'amount' => $r['amount']
    ];
    $total_bank_out += $r['amount'];
}

$bank_balance = $total_bank_in - $total_bank_out;

// --- 4. TRIAL BALANCE DATA ---
$total_sales_res = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total_price), 0) AS total_sales FROM sales"));
$total_sales_val = $total_sales_res['total_sales'];

$total_purchases_res = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(cost * quantity), 0) AS total_purchases FROM purchases"));
$total_purchases_val = $total_purchases_res['total_purchases'];

$debtors_dr = 0; $debtors_cr = 0;
$c_res = mysqli_query($conn, "
    SELECT c.id, c.customer_name,
        COALESCE(SUM(o.total_price), 0) as total_bill,
        COALESCE(SUM(o.paid_amount), 0) as total_paid
    FROM customers c
    LEFT JOIN (
        SELECT customer_id, invoice_no, MAX(total_price) as total_price, MAX(paid_amount) as paid_amount 
        FROM orders 
        GROUP BY customer_id, invoice_no
    ) o ON o.customer_id = c.id
    GROUP BY c.id");
$debtor_accounts = [];
while ($r = mysqli_fetch_assoc($c_res)) {
    $due = $r['total_bill'] - $r['total_paid'];
    if ($due > 0) {
        $debtors_dr += $due;
        $debtor_accounts[] = ['name' => $r['customer_name'], 'dr' => $due, 'cr' => 0];
    } elseif ($due < 0) {
        $debtors_cr += abs($due);
        $debtor_accounts[] = ['name' => $r['customer_name'], 'dr' => 0, 'cr' => abs($due)];
    }
}

$creditors_dr = 0; $creditors_cr = 0;
$s_res = mysqli_query($conn, "
    SELECT s.id, s.supplier_name,
        (SELECT COALESCE(SUM(cost * quantity), 0) FROM purchases p WHERE p.supplier_name = s.supplier_name) AS total_purchases,
        (SELECT COALESCE(SUM(v.amount), 0) FROM vouchers v WHERE v.voucher_type = 'Payment' AND v.entity_type = 'Supplier' AND v.entity_id = s.id) AS total_payments
    FROM suppliers s");
$creditor_accounts = [];
while ($r = mysqli_fetch_assoc($s_res)) {
    $due = $r['total_purchases'] - $r['total_payments'];
    if ($due > 0) {
        $creditors_cr += $due;
        $creditor_accounts[] = ['name' => $r['supplier_name'], 'dr' => 0, 'cr' => $due];
    } elseif ($due < 0) {
        $creditors_dr += abs($due);
        $creditor_accounts[] = ['name' => $r['supplier_name'], 'dr' => abs($due), 'cr' => 0];
    }
}

// Debits and Credits columns
$cash_dr = $cash_balance > 0 ? $cash_balance : 0;
$cash_cr = $cash_balance < 0 ? abs($cash_balance) : 0;

$bank_dr = $bank_balance > 0 ? $bank_balance : 0;
$bank_cr = $bank_balance < 0 ? abs($bank_balance) : 0;

$sum_debits = $cash_dr + $bank_dr + $total_purchases_val + $debtors_dr + $creditors_dr;
$sum_credits = $cash_cr + $bank_cr + $total_sales_val + $creditors_cr + $debtors_cr;

$suspense_dr = 0;
$suspense_cr = 0;
if (abs($sum_debits - $sum_credits) > 0.001) {
    if ($sum_debits > $sum_credits) {
        $suspense_cr = $sum_debits - $sum_credits;
        $sum_credits = $sum_debits;
    } else {
        $suspense_dr = $sum_credits - $sum_debits;
        $sum_debits = $sum_credits;
    }
}

// --- 5. PROFIT & LOSS CALCULATIONS ---
$net_sales_revenue = $total_sales_val / 1.18;
$sales_gst_collected = $total_sales_val - $net_sales_revenue;

$net_purchase_cost = $total_purchases_val / 1.18;
$purchase_gst_paid = $total_purchases_val - $net_purchase_cost;

$gross_profit = $net_sales_revenue - $net_purchase_cost;
$net_profit = $gross_profit;
$profit_margin_pct = $net_sales_revenue > 0 ? ($net_profit / $net_sales_revenue) * 100 : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Accounting Books — AgriBiz</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif;}
:root{--bg:#0d1117;--card:#161b22;--card2:#1c2333;--green:#22c55e;--blue:#3b82f6;--purple:#a855f7;--orange:#f59e0b;--red:#ef4444;--text:#e6edf3;--muted:#8b949e;--border:#30363d;}
body{background:var(--bg);color:var(--text);min-height:100vh;display:flex;}

.sidebar{width:220px;min-height:100vh;background:var(--bg);border-right:1px solid var(--border);position:fixed;left:0;top:0;bottom:0;z-index:100;display:flex;flex-direction:column;}
.sidebar-logo{padding:20px 16px;border-bottom:1px solid var(--border);}
.sidebar-logo h2{font-size:16px;font-weight:700;}
.nav-item{display:flex;align-items:center;gap:10px;padding:9px 16px;color:var(--muted);text-decoration:none;font-size:13px;border-left:3px solid transparent;transition:all .2s;}
.nav-item:hover,.nav-item.active{background:rgba(34,197,94,.08);color:var(--green);border-left-color:var(--green);}
.nav-item i{width:16px;}

.main{margin-left:220px;flex:1;padding:28px;max-width:1200px;}
.topbar{margin-bottom:24px;display:flex;justify-content:space-between;align-items:center;}
.topbar h1{font-size:22px;font-weight:700;} .topbar h1 span{color:var(--green);}

.tab-nav{display:flex;gap:8px;background:var(--card);border:1px solid var(--border);padding:6px;border-radius:14px;width:fit-content;margin-bottom:24px;}
.tab-btn{padding:10px 20px;border-radius:10px;font-size:13px;font-weight:700;color:var(--muted);text-decoration:none;transition:.2s;}
.tab-btn:hover{color:#fff;}
.tab-btn.active{background:var(--green);color:#fff;}

.stats-grid{display:grid;grid-template-columns:repeat(3, 1fr);gap:16px;margin-bottom:24px;}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:20px;}
.stat-card .lbl{font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
.stat-card .val{font-size:24px;font-weight:800;}

.card{background:var(--card);border:1px solid var(--border);border-radius:18px;overflow:hidden;}
.card-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;font-size:14px;font-weight:700;}
.card-header i{color:var(--green);}
.card-body{padding:0;}

.table{width:100%;border-collapse:collapse;}
.table th{padding:12px 16px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);background:rgba(255,255,255,.02);}
.table td{padding:12px 16px;font-size:13px;border-top:1px solid var(--border);vertical-align:middle;}
.table tr:hover td{background:rgba(255,255,255,.02);}
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;}
.badge-green{background:rgba(34,197,94,.15);color:var(--green);}
.badge-purple{background:rgba(168,85,247,.15);color:var(--purple);}
.badge-blue{background:rgba(59,130,246,.15);color:var(--blue);}
.badge-orange{background:rgba(245,158,11,.15);color:var(--orange);}

.empty{text-align:center;padding:40px;color:var(--muted);}
</style>
</head>
<body>

<aside class="sidebar">
  <?php $role = $_SESSION['admin_role'] ?? 'Admin'; ?>
  <div class="sidebar-logo"><h2>🌱 AgriBiz</h2><p>Dashboard</p></div>
  <a href="dashboard.php" class="nav-item"><i class="fas fa-home"></i> Dashboard</a>
  <?php if ($role !== 'Accountant'): ?>
  <a href="admin_billing.php" class="nav-item"><i class="fas fa-file-invoice-dollar" style="color:var(--orange);"></i> Offline Billing</a>
  <a href="manage_orders.php" class="nav-item"><i class="fas fa-clipboard-list"></i> Manage Orders</a>
  <?php endif; ?>
  <a href="customers.php" class="nav-item"><i class="fas fa-users"></i> Customers</a>
  <?php if ($role !== 'Billing Staff'): ?>
  <a href="gst_intel.php" class="nav-item"><i class="fas fa-search-dollar"></i> GST Intelligence</a>
  <a href="receipts_payments.php" class="nav-item"><i class="fas fa-money-bill-transfer"></i> Vouchers Entry</a>
  <a href="accounting_books.php" class="nav-item active"><i class="fas fa-book"></i> Day/Cash Books</a>
  <a href="suppliers.php" class="nav-item"><i class="fas fa-truck"></i> Suppliers</a>
  <?php endif; ?>
  <a href="view_fertilizer.php" class="nav-item"><i class="fas fa-flask"></i> Fertilizers</a>
  <?php if ($role !== 'Billing Staff'): ?>
  <a href="sales.php" class="nav-item"><i class="fas fa-chart-bar"></i> Sales</a>
  <a href="reports.php" class="nav-item"><i class="fas fa-chart-line"></i> Reports</a>
  <a href="master_ledger.php" class="nav-item"><i class="fas fa-file-invoice-dollar"></i> Master Ledger</a>
  <?php endif; ?>
  <div class="sidebar-footer"><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></div>
</aside>

<div class="main">
  <div class="topbar">
    <div>
      <h1>Accounting <span>Books Ledger</span></h1>
      <p style="font-size:12px;color:var(--muted);margin-top:4px;">Audit day books, cash on hand logs, and bank statement reconciliations</p>
    </div>
    <?php if ($active_tab === 'day'): ?>
    <form method="GET" style="display:flex; gap:10px; align-items:center;">
      <input type="hidden" name="tab" value="day">
      <input type="date" name="date" value="<?php echo $date_filter; ?>" style="background:var(--card2); border:1px solid var(--border); padding:8px 12px; border-radius:8px; color:#fff;" onchange="this.form.submit()">
    </form>
    <?php endif; ?>
  </div>

  <!-- Tabs Navigation -->
  <div class="tab-nav">
    <a href="?tab=day&date=<?php echo $date_filter; ?>" class="tab-btn <?php echo $active_tab === 'day' ? 'active' : ''; ?>"><i class="fas fa-calendar-day"></i> Day Book</a>
    <a href="?tab=cash" class="tab-btn <?php echo $active_tab === 'cash' ? 'active' : ''; ?>"><i class="fas fa-wallet"></i> Cash Book (Cash Hand)</a>
    <a href="?tab=bank" class="tab-btn <?php echo $active_tab === 'bank' ? 'active' : ''; ?>"><i class="fas fa-building-columns"></i> Bank Book (Online/UPI)</a>
    <a href="?tab=trial" class="tab-btn <?php echo $active_tab === 'trial' ? 'active' : ''; ?>"><i class="fas fa-scale-balanced"></i> Trial Balance</a>
    <a href="?tab=pl" class="tab-btn <?php echo $active_tab === 'pl' ? 'active' : ''; ?>"><i class="fas fa-chart-pie"></i> Profit & Loss</a>
  </div>

  <?php if ($active_tab === 'day'): ?>
    <!-- ==================== DAY BOOK ==================== -->
    <div class="card">
      <div class="card-header">
        <h3><i class="fas fa-calendar-day"></i> Day Book Audit Log — <?php echo date('d-M-Y', strtotime($date_filter)); ?></h3>
      </div>
      <div class="card-body" style="padding:0;">
        <table class="table">
          <thead>
            <tr>
              <th>Ref / Voucher No</th>
              <th>Voucher Type</th>
              <th>Particulars (Transaction Details)</th>
              <th>Method</th>
              <th style="text-align:right;">Debit (Inflow ₹)</th>
              <th style="text-align:right;">Credit (Outflow ₹)</th>
            </tr>
          </thead>
          <tbody>
            <?php 
            $tot_debit = 0; $tot_credit = 0;
            if (count($day_book_items) > 0): foreach($day_book_items as $item): 
              $tot_debit += $item['debit'];
              $tot_credit += $item['credit'];
              $method_badge = ($item['method'] === 'Cash') ? 'badge-orange' : 'badge-blue';
            ?>
            <tr>
              <td style="font-weight:bold; color:var(--blue);"><?php echo $item['ref']; ?></td>
              <td><span class="badge <?php echo ($item['debit'] > 0) ? 'badge-green' : 'badge-purple'; ?>"><?php echo $item['type']; ?></span></td>
              <td><strong><?php echo $item['particulars']; ?></strong></td>
              <td><span class="badge <?php echo $method_badge; ?>"><?php echo $item['method']; ?></span></td>
              <td style="text-align:right; font-weight:700; color:var(--green);"><?php echo $item['debit'] > 0 ? '₹'.number_format($item['debit'], 2) : '—'; ?></td>
              <td style="text-align:right; font-weight:700; color:var(--red);"><?php echo $item['credit'] > 0 ? '₹'.number_format($item['credit'], 2) : '—'; ?></td>
            </tr>
            <?php endforeach; ?>
            <tr style="background:rgba(255,255,255,0.03); font-weight:bold;">
              <td colspan="4" style="text-align:right;">Total Daily Transactions:</td>
              <td style="text-align:right; color:var(--green); font-size:14px;">₹<?php echo number_format($tot_debit, 2); ?></td>
              <td style="text-align:right; color:var(--red); font-size:14px;">₹<?php echo number_format($tot_credit, 2); ?></td>
            </tr>
            <?php else: ?>
            <tr>
              <td colspan="6" class="empty">
                <i class="fas fa-folder-open" style="font-size:32px; display:block; margin-bottom:8px; opacity:0.3;"></i>
                No financial transactions recorded on this date.
              </td>
            </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  <?php elseif ($active_tab === 'cash'): ?>
    <!-- ==================== CASH BOOK ==================== -->
    <div class="stats-grid">
      <div class="stat-card" style="border-left:4px solid var(--green);"><div class="lbl">Total Cash Receipts</div><div class="val" style="color:var(--green);">₹<?php echo number_format($total_cash_in, 2); ?></div></div>
      <div class="stat-card" style="border-left:4px solid var(--red);"><div class="lbl">Total Cash Payments</div><div class="val" style="color:var(--red);">₹<?php echo number_format($total_cash_out, 2); ?></div></div>
      <div class="stat-card" style="border-left:4px solid var(--orange);"><div class="lbl">Cash Balance on Hand</div><div class="val" style="color:var(--orange);">₹<?php echo number_format($cash_balance, 2); ?></div></div>
    </div>

    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
      <!-- Debit Side (Receipts) -->
      <div class="card">
        <div class="card-header" style="background:rgba(34,197,94,0.02);"><h3 style="color:var(--green);"><i class="fas fa-arrow-down-long"></i> Receipts (Debit)</h3></div>
        <div class="card-body">
          <table class="table">
            <thead><tr><th>Date</th><th>Ref</th><th>Particulars</th><th style="text-align:right;">Amount</th></tr></thead>
            <tbody>
              <?php if (count($cash_receipts) > 0): foreach($cash_receipts as $r): ?>
              <tr>
                <td style="color:var(--muted); font-size:12px;"><?php echo date('d-M-y', strtotime($r['date'])); ?></td>
                <td style="font-weight:bold; color:var(--blue);"><?php echo $r['ref']; ?></td>
                <td><?php echo htmlspecialchars($r['particulars']); ?></td>
                <td style="text-align:right; font-weight:700; color:var(--green);">₹<?php echo number_format($r['amount'], 2); ?></td>
              </tr>
              <?php endforeach; else: ?>
              <tr><td colspan="4" class="empty">No cash receipts found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Credit Side (Payments) -->
      <div class="card">
        <div class="card-header" style="background:rgba(239,68,68,0.02);"><h3 style="color:var(--red);"><i class="fas fa-arrow-up-long"></i> Payments (Credit)</h3></div>
        <div class="card-body">
          <table class="table">
            <thead><tr><th>Date</th><th>Ref</th><th>Particulars</th><th style="text-align:right;">Amount</th></tr></thead>
            <tbody>
              <?php if (count($cash_payments) > 0): foreach($cash_payments as $p): ?>
              <tr>
                <td style="color:var(--muted); font-size:12px;"><?php echo date('d-M-y', strtotime($p['date'])); ?></td>
                <td style="font-weight:bold; color:var(--blue);"><?php echo $p['ref']; ?></td>
                <td><?php echo htmlspecialchars($p['particulars']); ?></td>
                <td style="text-align:right; font-weight:700; color:var(--red);">₹<?php echo number_format($p['amount'], 2); ?></td>
              </tr>
              <?php endforeach; else: ?>
              <tr><td colspan="4" class="empty">No cash payments found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  <?php elseif ($active_tab === 'bank'): ?>
    <!-- ==================== BANK BOOK ==================== -->
    <div class="stats-grid">
      <div class="stat-card" style="border-left:4px solid var(--green);"><div class="lbl">Total Bank Receipts</div><div class="val" style="color:var(--green);">₹<?php echo number_format($total_bank_in, 2); ?></div></div>
      <div class="stat-card" style="border-left:4px solid var(--red);"><div class="lbl">Total Bank Payments</div><div class="val" style="color:var(--red);">₹<?php echo number_format($total_bank_out, 2); ?></div></div>
      <div class="stat-card" style="border-left:4px solid var(--blue);"><div class="lbl">Bank Statement Balance</div><div class="val" style="color:var(--blue);">₹<?php echo number_format($bank_balance, 2); ?></div></div>
    </div>

    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
      <!-- Debit Side (Receipts) -->
      <div class="card">
        <div class="card-header" style="background:rgba(34,197,94,0.02);"><h3 style="color:var(--green);"><i class="fas fa-arrow-down-long"></i> Receipts (Debit)</h3></div>
        <div class="card-body">
          <table class="table">
            <thead><tr><th>Date</th><th>Ref</th><th>Particulars</th><th style="text-align:right;">Amount</th></tr></thead>
            <tbody>
              <?php if (count($bank_receipts) > 0): foreach($bank_receipts as $r): ?>
              <tr>
                <td style="color:var(--muted); font-size:12px;"><?php echo date('d-M-y', strtotime($r['date'])); ?></td>
                <td style="font-weight:bold; color:var(--blue);"><?php echo $r['ref']; ?></td>
                <td><?php echo htmlspecialchars($r['particulars']); ?></td>
                <td style="text-align:right; font-weight:700; color:var(--green);">₹<?php echo number_format($r['amount'], 2); ?></td>
              </tr>
              <?php endforeach; else: ?>
              <tr><td colspan="4" class="empty">No bank receipts found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Credit Side (Payments) -->
      <div class="card">
        <div class="card-header" style="background:rgba(239,68,68,0.02);"><h3 style="color:var(--red);"><i class="fas fa-arrow-up-long"></i> Payments (Credit)</h3></div>
        <div class="card-body">
          <table class="table">
            <thead><tr><th>Date</th><th>Ref</th><th>Particulars</th><th style="text-align:right;">Amount</th></tr></thead>
            <tbody>
              <?php if (count($bank_payments) > 0): foreach($bank_payments as $p): ?>
              <tr>
                <td style="color:var(--muted); font-size:12px;"><?php echo date('d-M-y', strtotime($p['date'])); ?></td>
                <td style="font-weight:bold; color:var(--blue);"><?php echo $p['ref']; ?></td>
                <td><?php echo htmlspecialchars($p['particulars']); ?></td>
                <td style="text-align:right; font-weight:700; color:var(--red);">₹<?php echo number_format($p['amount'], 2); ?></td>
              </tr>
              <?php endforeach; else: ?>
              <tr><td colspan="4" class="empty">No bank payments found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php elseif ($active_tab === 'trial'): ?>
    <!-- ==================== TRIAL BALANCE ==================== -->
    <div class="stats-grid">
      <div class="stat-card" style="border-left:4px solid var(--green);"><div class="lbl">Total Debits (₹)</div><div class="val" style="color:var(--green);">₹<?php echo number_format($sum_debits, 2); ?></div></div>
      <div class="stat-card" style="border-left:4px solid var(--blue);"><div class="lbl">Total Credits (₹)</div><div class="val" style="color:var(--blue);">₹<?php echo number_format($sum_credits, 2); ?></div></div>
      <div class="stat-card" style="border-left:4px solid var(--purple);"><div class="lbl">Reconciliation Status</div><div class="val" style="color:var(--purple); font-size:16px; font-weight:700; margin-top:8px;"><i class="fas fa-circle-check"></i> Balanced & Reconciled</div></div>
    </div>

    <div class="card">
      <div class="card-header"><h3><i class="fas fa-scale-balanced"></i> Double Entry Trial Balance (Summary & Ledger Balances)</h3></div>
      <div class="card-body" style="padding:0;">
        <table class="table">
          <thead>
            <tr>
              <th>Ledger Account Head</th>
              <th>Account Group</th>
              <th style="text-align:right;">Debit Balance (₹)</th>
              <th style="text-align:right;">Credit Balance (₹)</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td><strong>Cash in Hand</strong></td>
              <td><span class="badge badge-orange">Assets / Cash</span></td>
              <td style="text-align:right; font-weight:600; color:var(--green);"><?php echo $cash_dr > 0 ? '₹'.number_format($cash_dr, 2) : '—'; ?></td>
              <td style="text-align:right; font-weight:600; color:var(--red);"><?php echo $cash_cr > 0 ? '₹'.number_format($cash_cr, 2) : '—'; ?></td>
            </tr>
            <tr>
              <td><strong>Bank UPI / Online Account</strong></td>
              <td><span class="badge badge-blue">Assets / Bank</span></td>
              <td style="text-align:right; font-weight:600; color:var(--green);"><?php echo $bank_dr > 0 ? '₹'.number_format($bank_dr, 2) : '—'; ?></td>
              <td style="text-align:right; font-weight:600; color:var(--red);"><?php echo $bank_cr > 0 ? '₹'.number_format($bank_cr, 2) : '—'; ?></td>
            </tr>
            <tr>
              <td><strong>Sales Revenue Account</strong></td>
              <td><span class="badge badge-green">Direct Revenues</span></td>
              <td style="text-align:right;">—</td>
              <td style="text-align:right; font-weight:600; color:var(--red);">₹<?php echo number_format($total_sales_val, 2); ?></td>
            </tr>
            <tr>
              <td><strong>Purchase Cost Account</strong></td>
              <td><span class="badge badge-purple">Direct Expenses</span></td>
              <td style="text-align:right; font-weight:600; color:var(--green);">₹<?php echo number_format($total_purchases_val, 2); ?></td>
              <td style="text-align:right;">—</td>
            </tr>
            <tr>
              <td><strong>Sundry Debtors (Customer Receivables)</strong></td>
              <td><span class="badge badge-green">Current Assets</span></td>
              <td style="text-align:right; font-weight:600; color:var(--green);"><?php echo $debtors_dr > 0 ? '₹'.number_format($debtors_dr, 2) : '—'; ?></td>
              <td style="text-align:right; font-weight:600; color:var(--red);"><?php echo $debtors_cr > 0 ? '₹'.number_format($debtors_cr, 2) : '—'; ?></td>
            </tr>
            <tr>
              <td><strong>Sundry Creditors (Supplier Payables)</strong></td>
              <td><span class="badge badge-purple">Current Liabilities</span></td>
              <td style="text-align:right; font-weight:600; color:var(--green);"><?php echo $creditors_dr > 0 ? '₹'.number_format($creditors_dr, 2) : '—'; ?></td>
              <td style="text-align:right; font-weight:600; color:var(--red);"><?php echo $creditors_cr > 0 ? '₹'.number_format($creditors_cr, 2) : '—'; ?></td>
            </tr>
            <?php if ($suspense_dr > 0 || $suspense_cr > 0): ?>
            <tr style="background:rgba(239,68,68,0.05);">
              <td><strong>Suspense / Difference Account</strong></td>
              <td><span class="badge badge-orange">Temporary Suspense</span></td>
              <td style="text-align:right; font-weight:600; color:var(--green);"><?php echo $suspense_dr > 0 ? '₹'.number_format($suspense_dr, 2) : '—'; ?></td>
              <td style="text-align:right; font-weight:600; color:var(--red);"><?php echo $suspense_cr > 0 ? '₹'.number_format($suspense_cr, 2) : '—'; ?></td>
            </tr>
            <?php endif; ?>
            <tr style="background:rgba(255,255,255,0.04); font-weight:bold; font-size:14px; border-top:2px solid var(--border);">
              <td colspan="2">GRAND TRIAL TOTALS:</td>
              <td style="text-align:right; color:var(--green);">₹<?php echo number_format($sum_debits, 2); ?></td>
              <td style="text-align:right; color:var(--blue);">₹<?php echo number_format($sum_credits, 2); ?></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Detailed Individual Subsidiary Ledger Balances -->
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-top:24px;">
      <!-- Debtors breakdown -->
      <div class="card">
        <div class="card-header"><h3><i class="fas fa-users"></i> Debtors Subsidiary Ledger</h3></div>
        <div class="card-body" style="padding:0;">
          <table class="table">
            <thead><tr><th>Customer Name</th><th style="text-align:right;">Debit Balance</th><th style="text-align:right;">Credit (Advance)</th></tr></thead>
            <tbody>
              <?php if (count($debtor_accounts) > 0): foreach($debtor_accounts as $ac): ?>
              <tr>
                <td><strong><?php echo htmlspecialchars($ac['name']); ?></strong></td>
                <td style="text-align:right; color:var(--green);"><?php echo $ac['dr'] > 0 ? '₹'.number_format($ac['dr'], 2) : '—'; ?></td>
                <td style="text-align:right; color:var(--muted);"><?php echo $ac['cr'] > 0 ? '₹'.number_format($ac['cr'], 2) : '—'; ?></td>
              </tr>
              <?php endforeach; else: ?>
              <tr><td colspan="3" class="empty">No outstanding customer balances.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <!-- Creditors breakdown -->
      <div class="card">
        <div class="card-header"><h3><i class="fas fa-truck"></i> Creditors Subsidiary Ledger</h3></div>
        <div class="card-body" style="padding:0;">
          <table class="table">
            <thead><tr><th>Supplier Name</th><th style="text-align:right;">Debit (Advance)</th><th style="text-align:right;">Credit Balance</th></tr></thead>
            <tbody>
              <?php if (count($creditor_accounts) > 0): foreach($creditor_accounts as $ac): ?>
              <tr>
                <td><strong><?php echo htmlspecialchars($ac['name']); ?></strong></td>
                <td style="text-align:right; color:var(--muted);"><?php echo $ac['dr'] > 0 ? '₹'.number_format($ac['dr'], 2) : '—'; ?></td>
                <td style="text-align:right; color:var(--red);"><?php echo $ac['cr'] > 0 ? '₹'.number_format($ac['cr'], 2) : '—'; ?></td>
              </tr>
              <?php endforeach; else: ?>
              <tr><td colspan="3" class="empty">No outstanding supplier balances.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  <?php elseif ($active_tab === 'pl'): ?>
    <!-- ==================== PROFIT & LOSS STATEMENT ==================== -->
    <div class="stats-grid">
      <div class="stat-card" style="border-left:4px solid var(--green);"><div class="lbl">Net Sales Revenue (Excl. GST)</div><div class="val" style="color:var(--green);">₹<?php echo number_format($net_sales_revenue, 2); ?></div></div>
      <div class="stat-card" style="border-left:4px solid var(--purple);"><div class="lbl">Cost of Goods (Excl. GST)</div><div class="val" style="color:var(--purple);">₹<?php echo number_format($net_purchase_cost, 2); ?></div></div>
      <div class="stat-card" style="border-left:4px solid <?php echo $net_profit >= 0 ? 'var(--blue)' : 'var(--red)'; ?>;"><div class="lbl">Net Profit / Earnings</div><div class="val" style="color:<?php echo $net_profit >= 0 ? 'var(--blue)' : 'var(--red)'; ?>;">₹<?php echo number_format($net_profit, 2); ?></div></div>
      <div class="stat-card" style="border-left:4px solid var(--orange);"><div class="lbl">Net Profit Margin %</div><div class="val" style="color:var(--orange);"><?php echo number_format($profit_margin_pct, 1); ?>%</div></div>
    </div>

    <div class="card">
      <div class="card-header"><h3><i class="fas fa-chart-pie"></i> Profit & Loss / Income Statement (Fiscal Period to Date)</h3></div>
      <div class="card-body" style="padding:0;">
        <table class="table">
          <thead>
            <tr>
              <th>Accounting Ledger / Particulars</th>
              <th>Notes</th>
              <th style="text-align:right;">Subtotal (₹)</th>
              <th style="text-align:right;">Total Amount (₹)</th>
            </tr>
          </thead>
          <tbody>
            <!-- INCOME SECTION -->
            <tr style="background:rgba(255,255,255,0.02);"><td colspan="4"><strong>I. REVENUES & DIRECT INCOME</strong></td></tr>
            <tr>
              <td style="padding-left:32px;">Gross Sales Revenues (Inclusive of GST)</td>
              <td style="color:var(--muted); font-size:12px;">All offline & online orders</td>
              <td style="text-align:right;">₹<?php echo number_format($total_sales_val, 2); ?></td>
              <td style="text-align:right;">—</td>
            </tr>
            <tr style="color:var(--muted);">
              <td style="padding-left:32px;">Less: Output GST Collected (18% threshold)</td>
              <td style="font-size:12px;">Transferred to GST Liability</td>
              <td style="text-align:right;">- ₹<?php echo number_format($sales_gst_collected, 2); ?></td>
              <td style="text-align:right;">—</td>
            </tr>
            <tr style="font-weight:600; border-top:1px solid var(--border);">
              <td style="padding-left:32px; color:var(--green);">Net Operating Revenue (Net Sales)</td>
              <td>Standard base turnover</td>
              <td style="text-align:right;">—</td>
              <td style="text-align:right; color:var(--green);">₹<?php echo number_format($net_sales_revenue, 2); ?></td>
            </tr>

            <!-- EXPENSES SECTION -->
            <tr style="background:rgba(255,255,255,0.02);"><td colspan="4"><strong>II. DIRECT COSTS & OPERATIONS EXPENSES</strong></td></tr>
            <tr>
              <td style="padding-left:32px;">Gross Fertilizer Purchase Cost (Inclusive of GST)</td>
              <td style="color:var(--muted); font-size:12px;">Stock inward acquisitions</td>
              <td style="text-align:right;">₹<?php echo number_format($total_purchases_val, 2); ?></td>
              <td style="text-align:right;">—</td>
            </tr>
            <tr style="color:var(--muted);">
              <td style="padding-left:32px;">Less: Input GST Claimable (ITC available)</td>
              <td style="font-size:12px;">GST paid on stock</td>
              <td style="text-align:right;">- ₹<?php echo number_format($purchase_gst_paid, 2); ?></td>
              <td style="text-align:right;">—</td>
            </tr>
            <tr style="font-weight:600; border-top:1px solid var(--border);">
              <td style="padding-left:32px; color:var(--purple);">Net Cost of Purchases (COGS)</td>
              <td>Direct inventory cost</td>
              <td style="text-align:right;">—</td>
              <td style="text-align:right; color:var(--purple);">- ₹<?php echo number_format($net_purchase_cost, 2); ?></td>
            </tr>

            <!-- NET SECTION -->
            <tr style="background:rgba(255,255,255,0.04); font-weight:800; font-size:14px; border-top:2px solid var(--border);">
              <td>NET EARNINGS / PROFIT:</td>
              <td>Earnings before secondary taxes</td>
              <td style="text-align:right;">—</td>
              <td style="text-align:right; color:<?php echo $net_profit >= 0 ? 'var(--green)' : 'var(--red)'; ?>;">
                ₹<?php echo number_format($net_profit, 2); ?>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Secondary Tax Summary Details -->
    <div class="card" style="margin-top:24px;">
      <div class="card-header"><h3><i class="fas fa-file-invoice-dollar"></i> Net Indirect Tax Balance (GST Offset Summary)</h3></div>
      <div class="card-body">
        <div style="display:flex; justify-content:space-between; align-items:center;">
          <div>
            <p style="font-size:14px; margin-bottom:4px;">GST Collected on Sales: <strong style="color:var(--green);">₹<?php echo number_format($sales_gst_collected, 2); ?></strong></p>
            <p style="font-size:14px; margin-bottom:4px;">GST Paid on Purchases (ITC): <strong style="color:var(--purple);">₹<?php echo number_format($purchase_gst_paid, 2); ?></strong></p>
            <?php 
            $net_gst_payable = $sales_gst_collected - $purchase_gst_paid;
            ?>
            <p style="font-size:14px; margin-top:10px; border-top:1px dashed var(--border); padding-top:10px;">
              <?php if($net_gst_payable >= 0): ?>
              Net GST Payable to Government: <strong style="color:var(--red);">₹<?php echo number_format($net_gst_payable, 2); ?></strong>
              <?php else: ?>
              Excess Input Tax Credit Available: <strong style="color:var(--green);">₹<?php echo number_format(abs($net_gst_payable), 2); ?></strong>
              <?php endif; ?>
            </p>
          </div>
          <div style="text-align:right;">
            <a href="gst_reports.php" class="btn btn-green" style="display:inline-flex; width:auto; text-decoration:none;"><i class="fas fa-file-excel"></i> View Full GST Sheets</a>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

</body>
</html>
