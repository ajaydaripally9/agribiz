<?php
session_start();
if (!isset($_SESSION['admin'])) { header('Location: index.php'); exit(); }
include 'db.php';
checkRole(['Admin', 'Manager', 'Accountant']);

// Auto-migrate
add_column_if_not_exists($conn, 'customers', 'credit_limit', "DECIMAL(10,2) DEFAULT 0");
add_column_if_not_exists($conn, 'customers', 'due_date', "DATE NULL");

$msg = ''; $msg_type = 'success';
$filter = $_GET['filter'] ?? 'all'; // all | overdue | week

// ─── Handle Quick Payment ─────────────────────────────────────────────────────
if (isset($_POST['quick_pay'])) {
    $cid     = intval($_POST['customer_id']);
    $amount  = floatval($_POST['pay_amount']);
    $method  = mysqli_real_escape_string($conn, $_POST['pay_method'] ?? 'Cash');
    if ($cid > 0 && $amount > 0) {
        // Record as receipt voucher
        $v_no = 'RCV-'.date('Ymd').'-'.rand(100,999);
        $date = date('Y-m-d');
        $user = $_SESSION['admin_username'] ?? 'admin';
        mysqli_query($conn, "INSERT INTO vouchers (voucher_no, voucher_type, entity_type, entity_id, amount, payment_method, narration, date) VALUES ('$v_no', 'Receipt', 'Customer', $cid, $amount, '$method', 'Quick payment from collection dashboard', '$date')");
        // Also update orders paid_amount (distribute to oldest unpaid invoices)
        $pending_invs = mysqli_query($conn, "SELECT invoice_no, SUM(total_price)-MAX(paid_amount) as due FROM orders WHERE customer_id=$cid AND status!='Voided' GROUP BY invoice_no HAVING due > 0 ORDER BY MIN(id) ASC");
        $remaining = $amount;
        while ($inv = mysqli_fetch_assoc($pending_invs)) {
            if ($remaining <= 0) break;
            $pay_this = min($remaining, $inv['due']);
            $inv_safe = mysqli_real_escape_string($conn, $inv['invoice_no']);
            mysqli_query($conn, "UPDATE orders SET paid_amount = paid_amount + $pay_this WHERE invoice_no='$inv_safe'");
            $remaining -= $pay_this;
        }
        $msg = "Payment of ₹".number_format($amount,2)." recorded via $method. Voucher: $v_no";
    }
}


// ─── Update Due Date ─────────────────────────────────────────────────────────
if (isset($_POST['set_due_date'])) {
    $cid = intval($_POST['customer_id']);
    $dd  = mysqli_real_escape_string($conn, $_POST['due_date']);
    $cl  = floatval($_POST['credit_limit'] ?? 0);
    mysqli_query($conn, "UPDATE customers SET due_date='$dd', credit_limit=$cl WHERE id=$cid");
    $msg = "Due date and credit limit updated.";
}

// Build WHERE for filter
$today = date('Y-m-d');
$filter_extra = '';
if ($filter === 'overdue') {
    $filter_extra = "AND c.due_date IS NOT NULL AND c.due_date < '$today'";
} elseif ($filter === 'week') {
    $filter_extra = "AND c.due_date IS NOT NULL AND c.due_date <= DATE_ADD('$today', INTERVAL 7 DAY)";
}

$debt_res = mysqli_query($conn, "
    SELECT c.id, c.customer_name, c.mobile, c.address, c.due_date, c.credit_limit,
        COALESCE(SUM(o.total_price), 0) as total_bill,
        COALESCE(SUM(o.paid_amount), 0) as total_paid,
        (COALESCE(SUM(o.total_price), 0) - COALESCE(SUM(o.paid_amount), 0)) as total_due
    FROM customers c
    JOIN (
        SELECT customer_id, invoice_no, MAX(total_price) as total_price, MAX(paid_amount) as paid_amount
        FROM orders WHERE status != 'Voided'
        GROUP BY customer_id, invoice_no
    ) o ON o.customer_id = c.id
    WHERE 1=1 $filter_extra
    GROUP BY c.id
    HAVING total_due > 0.01
    ORDER BY total_due DESC
");

$stats = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(DISTINCT c.id) as debtor_count,
           COALESCE(SUM(o.total_price - o.paid_amount), 0) as total_outstanding
    FROM customers c
    JOIN (SELECT customer_id, MAX(total_price) as total_price, MAX(paid_amount) as paid_amount FROM orders WHERE status!='Voided' GROUP BY customer_id, invoice_no) o ON o.customer_id = c.id
"));

$total_outstanding = 0; $debtor_count = 0;
// Recalculate
$ts2 = mysqli_query($conn, "SELECT SUM(o.total_price - o.paid_amount) as s, COUNT(DISTINCT c.id) as c FROM customers c JOIN (SELECT customer_id, SUM(total_price) as total_price, SUM(paid_amount) as paid_amount FROM orders WHERE status!='Voided' GROUP BY customer_id) o ON o.customer_id=c.id WHERE (o.total_price-o.paid_amount)>0.01");
$ts3 = mysqli_fetch_assoc($ts2);
$total_outstanding = $ts3['s'] ?? 0;
$debtor_count = $ts3['c'] ?? 0;

$overdue_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM customers WHERE due_date IS NOT NULL AND due_date < '$today'"))['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Outstanding Collection — AgriBiz ERP</title>
<script>document.documentElement.setAttribute('data-theme',localStorage.getItem('admin-theme')||'dark');</script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif;}
:root{--bg:#0d1117;--sidebar:#0d1117;--card:#161b22;--card2:#1c2333;--green:#22c55e;--green-dark:#16a34a;--blue:#3b82f6;--orange:#f59e0b;--red:#ef4444;--text:#e6edf3;--text-muted:#8b949e;--border:#30363d;}
[data-theme="light"]{--bg:#f8fafc;--sidebar:#fff;--card:#fff;--card2:#f1f5f9;--green:#16a34a;--green-dark:#15803d;--blue:#2563eb;--orange:#ea580c;--red:#dc2626;--text:#0f172a;--text-muted:#64748b;--border:#e2e8f0;}
body{background:var(--bg);color:var(--text);display:flex;min-height:100vh;}
.sidebar{width:220px;min-height:100vh;background:var(--sidebar);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;left:0;top:0;bottom:0;z-index:100;}
.sidebar-logo{padding:18px 16px;border-bottom:1px solid var(--border);}
.sidebar-nav{flex:1;padding:10px 0;overflow-y:auto;}
.nav-section-label{padding:8px 16px 4px;font-size:10px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;}
.nav-item{display:flex;align-items:center;gap:10px;padding:8px 16px;color:var(--text-muted);text-decoration:none;font-size:12.5px;font-weight:500;transition:all .2s;border-left:3px solid transparent;}
.nav-item:hover,.nav-item.active{background:rgba(34,197,94,.08);color:var(--green);border-left-color:var(--green);}
.nav-item i{width:15px;font-size:13px;}
.main{margin-left:220px;flex:1;display:flex;flex-direction:column;}
.topbar{background:var(--sidebar);border-bottom:1px solid var(--border);padding:14px 28px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:50;}
.content{padding:24px 28px;}
.stats-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px;}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px;}
.stat-card .lbl{font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
.stat-card .val{font-size:22px;font-weight:800;}
.card{background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:20px;}
.card-header{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;}
.card-header h3{font-size:14px;font-weight:700;}
.table{width:100%;border-collapse:collapse;}
.table th{padding:10px 14px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;color:var(--text-muted);background:rgba(255,255,255,.02);}
.table td{padding:12px 14px;font-size:13px;border-top:1px solid var(--border);vertical-align:middle;}
.table tr:hover td{background:rgba(255,255,255,.02);}
.badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-red{background:rgba(239,68,68,.15);color:var(--red);}
.badge-orange{background:rgba(245,158,11,.15);color:var(--orange);}
.badge-green{background:rgba(34,197,94,.15);color:var(--green);}
.btn{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:8px;font-size:11px;font-weight:700;cursor:pointer;border:none;text-decoration:none;transition:.2s;}
.btn-green{background:var(--green);color:#fff;}.btn-green:hover{background:var(--green-dark);}
.btn-ghost{background:var(--card2);color:var(--text);border:1px solid var(--border);}
.btn-wa{background:#25D366;color:#fff;}
.btn-blue{background:var(--blue);color:#fff;}
.tab-nav{display:flex;gap:6px;background:var(--card);border:1px solid var(--border);padding:4px;border-radius:10px;width:fit-content;margin-bottom:16px;}
.tab-btn{padding:7px 16px;border-radius:7px;font-size:12px;font-weight:700;color:var(--text-muted);text-decoration:none;transition:.2s;}
.tab-btn.active{background:var(--red);color:#fff;}
.msg{padding:11px 16px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.msg-success{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.25);color:var(--green);}
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:400;backdrop-filter:blur(4px);align-items:center;justify-content:center;}
.overlay.open{display:flex;}
.modal{background:var(--card);border:1px solid var(--border);border-radius:14px;width:100%;max-width:380px;padding:24px;}
.form-control{width:100%;background:var(--card2);border:1px solid var(--border);border-radius:8px;padding:8px 12px;color:var(--text);font-size:13px;outline:none;margin-bottom:10px;}
</style>
</head>
<body>
<?php include '_sidebar.php'; ?>
<div class="main">
  <div class="topbar">
    <div>
      <p style="font-size:11px;color:var(--text-muted);">Reports</p>
      <h1 style="font-size:18px;font-weight:800;"><i class="fas fa-money-bill-trend-up" style="color:var(--red);margin-right:6px;"></i>Outstanding Collection</h1>
    </div>
    <button class="btn btn-ghost" onclick="toggleTheme()"><i class="fas fa-sun" id="themeIcon"></i></button>
  </div>
  <div class="content">
    <?php if ($msg): ?><div class="msg msg-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

    <div class="stats-grid">
      <div class="stat-card" style="border-left:4px solid var(--red);"><div class="lbl">Total Outstanding</div><div class="val" style="color:var(--red);">₹<?php echo number_format($total_outstanding,0); ?></div></div>
      <div class="stat-card" style="border-left:4px solid var(--orange);"><div class="lbl">Customers with Due</div><div class="val" style="color:var(--orange);"><?php echo $debtor_count; ?></div></div>
      <div class="stat-card" style="border-left:4px solid var(--red);"><div class="lbl">Overdue Accounts</div><div class="val" style="color:var(--red);"><?php echo $overdue_count; ?></div></div>
    </div>

    <div class="tab-nav">
      <a href="?filter=all" class="tab-btn <?php echo $filter==='all'?'active':''; ?>">All Outstanding</a>
      <a href="?filter=overdue" class="tab-btn <?php echo $filter==='overdue'?'active':''; ?>">Overdue Only</a>
      <a href="?filter=week" class="tab-btn <?php echo $filter==='week'?'active':''; ?>">Due This Week</a>
    </div>

    <div class="card">
      <div class="card-header">
        <h3><i class="fas fa-users" style="color:var(--red);"></i> Customers with Outstanding Dues</h3>
        <span style="font-size:12px;color:var(--text-muted);">Click "Pay" to record quick payment</span>
      </div>
      <table class="table">
        <thead><tr><th>Customer</th><th>Mobile</th><th>Total Billed</th><th>Total Paid</th><th>Amount Due</th><th>Due Date</th><th>Credit Limit</th><th>Actions</th></tr></thead>
        <tbody>
        <?php
        $today = date('Y-m-d');
        if ($debt_res && mysqli_num_rows($debt_res) > 0):
            while ($row = mysqli_fetch_assoc($debt_res)):
                $is_overdue = $row['due_date'] && $row['due_date'] < $today;
                $due_soon   = $row['due_date'] && $row['due_date'] <= date('Y-m-d', strtotime('+7 days'));
                $wa_msg = "Hello " . strtoupper($row['customer_name']) . ", you have an outstanding balance of ₹" . number_format($row['total_due'], 2) . " at AgriBiz. Please clear your dues. Thank you!";
                $wa_url = "https://wa.me/91" . preg_replace('/\D/', '', $row['mobile']) . "?text=" . urlencode($wa_msg);
        ?>
        <tr style="<?php echo $is_overdue ? 'background:rgba(239,68,68,.04);' : ''; ?>">
          <td>
            <strong><?php echo htmlspecialchars($row['customer_name']); ?></strong>
            <?php if ($is_overdue): ?><span class="badge badge-red" style="margin-left:6px;">OVERDUE</span><?php elseif($due_soon): ?><span class="badge badge-orange" style="margin-left:6px;">Due Soon</span><?php endif; ?>
            <br><small style="color:var(--text-muted);"><?php echo htmlspecialchars($row['address']); ?></small>
          </td>
          <td><?php echo htmlspecialchars($row['mobile']); ?></td>
          <td>₹<?php echo number_format($row['total_bill'],2); ?></td>
          <td style="color:var(--green);">₹<?php echo number_format($row['total_paid'],2); ?></td>
          <td style="color:var(--red);font-size:15px;font-weight:800;">₹<?php echo number_format($row['total_due'],2); ?></td>
          <td style="color:<?php echo $is_overdue?'var(--red)':($due_soon?'var(--orange)':'var(--text-muted)'); ?>;">
            <?php echo $row['due_date'] ? date('d-M-Y',strtotime($row['due_date'])) : '—'; ?>
          </td>
          <td style="color:var(--text-muted);"><?php echo $row['credit_limit']>0?'₹'.number_format($row['credit_limit'],0):'—'; ?></td>
          <td>
            <div style="display:flex;gap:6px;flex-wrap:wrap;">
              <button onclick="openPayModal(<?php echo $row['id']; ?>, '<?php echo addslashes($row['customer_name']); ?>', <?php echo $row['total_due']; ?>)" class="btn btn-green"><i class="fas fa-rupee-sign"></i> Pay</button>
              <a href="<?php echo $wa_url; ?>" target="_blank" class="btn btn-wa"><i class="fab fa-whatsapp"></i></a>
              <a href="customer_ledger.php?id=<?php echo $row['id']; ?>" class="btn btn-blue"><i class="fas fa-book"></i></a>
              <button onclick="openDueDateModal(<?php echo $row['id']; ?>, '<?php echo $row['due_date']??''; ?>', <?php echo $row['credit_limit']; ?>)" class="btn btn-ghost"><i class="fas fa-calendar"></i></button>
            </div>
          </td>
        </tr>
        <?php endwhile; else: ?>
        <tr><td colspan="8" style="text-align:center;padding:36px;color:var(--text-muted);">
          <i class="fas fa-check-circle" style="font-size:32px;color:var(--green);display:block;margin-bottom:8px;"></i>
          <?php echo $filter==='all' ? 'No outstanding dues! All accounts are cleared.' : 'No records match this filter.'; ?>
        </td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Quick Pay Modal -->
<div class="overlay" id="payModal">
  <div class="modal">
    <h3 style="font-size:15px;font-weight:700;margin-bottom:16px;"><i class="fas fa-rupee-sign" style="color:var(--green);"></i> Quick Payment: <span id="payCustomerName"></span></h3>
    <form method="POST">
      <input type="hidden" name="customer_id" id="payCustomerId">
      <label style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;">Amount (₹)</label>
      <input type="number" name="pay_amount" id="payAmount" class="form-control" step="0.01" min="0.01" required>
      <label style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;">Payment Method</label>
      <select name="pay_method" class="form-control">
        <option value="Cash">Cash</option>
        <option value="Bank">Bank / UPI</option>
        <option value="Cheque">Cheque</option>
      </select>
      <div style="display:flex;gap:10px;margin-top:8px;">
        <button type="button" onclick="document.getElementById('payModal').classList.remove('open')" class="btn btn-ghost" style="flex:1;justify-content:center;padding:10px;">Cancel</button>
        <button type="submit" name="quick_pay" class="btn btn-green" style="flex:2;justify-content:center;padding:10px;"><i class="fas fa-check"></i> Record Payment</button>
      </div>
    </form>
  </div>
</div>

<!-- Due Date Modal -->
<div class="overlay" id="dueDateModal">
  <div class="modal">
    <h3 style="font-size:15px;font-weight:700;margin-bottom:16px;"><i class="fas fa-calendar" style="color:var(--orange);"></i> Set Due Date & Credit Limit</h3>
    <form method="POST">
      <input type="hidden" name="customer_id" id="ddCustomerId">
      <label style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;">Due Date</label>
      <input type="date" name="due_date" id="ddDate" class="form-control">
      <label style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;">Credit Limit (₹)</label>
      <input type="number" name="credit_limit" id="ddLimit" class="form-control" step="100">
      <div style="display:flex;gap:10px;margin-top:8px;">
        <button type="button" onclick="document.getElementById('dueDateModal').classList.remove('open')" class="btn btn-ghost" style="flex:1;justify-content:center;padding:10px;">Cancel</button>
        <button type="submit" name="set_due_date" class="btn" style="background:var(--orange);color:#fff;flex:2;justify-content:center;padding:10px;"><i class="fas fa-save"></i> Save</button>
      </div>
    </form>
  </div>
</div>

<script>
function openPayModal(id, name, due) {
  document.getElementById('payModal').classList.add('open');
  document.getElementById('payCustomerId').value = id;
  document.getElementById('payCustomerName').textContent = name;
  document.getElementById('payAmount').value = due.toFixed(2);
}
function openDueDateModal(id, date, limit) {
  document.getElementById('dueDateModal').classList.add('open');
  document.getElementById('ddCustomerId').value = id;
  document.getElementById('ddDate').value = date;
  document.getElementById('ddLimit').value = limit;
}
function toggleTheme(){const t=document.documentElement.getAttribute('data-theme')==='dark'?'light':'dark';document.documentElement.setAttribute('data-theme',t);localStorage.setItem('admin-theme',t);document.getElementById('themeIcon').className=t==='light'?'fas fa-moon':'fas fa-sun';}
(function(){const t=localStorage.getItem('admin-theme')||'dark';document.getElementById('themeIcon').className=t==='light'?'fas fa-moon':'fas fa-sun';})();
</script>
</body></html>
