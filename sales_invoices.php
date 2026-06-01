<?php
session_start();
if (!isset($_SESSION['admin'])) { header('Location: index.php'); exit(); }
include 'db.php';
checkRole(['Admin', 'Manager', 'Billing Staff']);

// Auto-migrate
add_column_if_not_exists($conn, 'sales', 'invoice_no', "VARCHAR(50) DEFAULT ''");
add_column_if_not_exists($conn, 'sales', 'paid_amount', "DECIMAL(10,2) DEFAULT 0");
add_column_if_not_exists($conn, 'sales', 'bill_type', "VARCHAR(20) DEFAULT 'Cash'");
add_column_if_not_exists($conn, 'sales', 'discount', "DECIMAL(10,2) DEFAULT 0");
add_column_if_not_exists($conn, 'sales', 'gst_rate', "DECIMAL(5,2) DEFAULT 18.00");
add_column_if_not_exists($conn, 'sales', 'notes', "TEXT");
add_column_if_not_exists($conn, 'sales', 'is_return', "TINYINT DEFAULT 0");
add_column_if_not_exists($conn, 'orders', 'invoice_no', "VARCHAR(50) DEFAULT ''");
add_column_if_not_exists($conn, 'orders', 'paid_amount', "DECIMAL(10,2) DEFAULT 0");
add_column_if_not_exists($conn, 'orders', 'bill_type', "VARCHAR(20) DEFAULT 'Cash'");
add_column_if_not_exists($conn, 'orders', 'discount', "DECIMAL(10,2) DEFAULT 0");
add_column_if_not_exists($conn, 'orders', 'customer_name', "VARCHAR(100) DEFAULT ''");
add_column_if_not_exists($conn, 'orders', 'fertilizer_name', "VARCHAR(100) DEFAULT ''");

$role = $_SESSION['admin_role'] ?? 'Admin';
$username = $_SESSION['admin_username'] ?? 'admin';

$msg = ''; $msg_type = 'success';

// ─── Handle Delete/Void Invoice ─────────────────────────────────────────────
if (isset($_POST['void_invoice'])) {
    $inv = mysqli_real_escape_string($conn, $_POST['invoice_no']);
    // Restore stock for each item
    $items_res = mysqli_query($conn, "SELECT fertilizer_id, quantity FROM orders WHERE invoice_no='$inv' AND is_return IS NULL OR is_return=0");
    if ($items_res) {
        while ($it = mysqli_fetch_assoc($items_res)) {
            mysqli_query($conn, "UPDATE fertilizers SET quantity = quantity + {$it['quantity']} WHERE id = {$it['fertilizer_id']}");
        }
    }
    mysqli_query($conn, "UPDATE orders SET status='Voided' WHERE invoice_no='$inv'");
    mysqli_query($conn, "DELETE FROM sales WHERE invoice_no='$inv'");
    $msg = "Invoice #{$inv} has been voided and stock restored.";
    $msg_type = 'success';
}

// ─── Handle Mark as Paid ─────────────────────────────────────────────────────
if (isset($_POST['mark_paid'])) {
    $inv = mysqli_real_escape_string($conn, $_POST['invoice_no']);
    $pay = floatval($_POST['pay_amount']);
    mysqli_query($conn, "UPDATE orders SET paid_amount = paid_amount + $pay WHERE invoice_no='$inv'");
    mysqli_query($conn, "UPDATE sales SET paid_amount = paid_amount + $pay WHERE invoice_no='$inv'");
    $msg = "Payment of ₹" . number_format($pay, 2) . " recorded for #{$inv}.";
}

// ─── Filters ─────────────────────────────────────────────────────────────────
$filter_from   = $_GET['from']    ?? date('Y-m-d', strtotime('-30 days'));
$filter_to     = $_GET['to']      ?? date('Y-m-d');
$filter_status = $_GET['status']  ?? '';
$filter_search = trim($_GET['q']  ?? '');
$view_inv      = $_GET['inv']     ?? '';

// ─── Invoice Detail View ──────────────────────────────────────────────────────
$invoice_detail = null;
$invoice_items  = [];
if ($view_inv) {
    $inv_safe = mysqli_real_escape_string($conn, $view_inv);
    $invoice_detail = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT o.invoice_no, o.customer_name, o.order_date, o.status, o.bill_type, o.paid_amount, o.discount,
               SUM(o.total_price) as grand_total, c.mobile, c.address, c.gstin
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        WHERE o.invoice_no = '$inv_safe'
        GROUP BY o.invoice_no
    "));
    $items_res = mysqli_query($conn, "SELECT fertilizer_name, quantity, (total_price/quantity) as unit_price, total_price FROM orders WHERE invoice_no='$inv_safe'");
    while ($r = mysqli_fetch_assoc($items_res)) $invoice_items[] = $r;
}

// ─── Invoice List ─────────────────────────────────────────────────────────────
$where_parts = ["o.order_date BETWEEN '$filter_from' AND '$filter_to'", "o.invoice_no != ''"];
if ($filter_status) $where_parts[] = "o.status = '" . mysqli_real_escape_string($conn, $filter_status) . "'";
if ($filter_search) {
    $qs = mysqli_real_escape_string($conn, $filter_search);
    $where_parts[] = "(o.customer_name LIKE '%$qs%' OR o.invoice_no LIKE '%$qs%')";
}
$where = implode(' AND ', $where_parts);

$invoices_res = mysqli_query($conn, "
    SELECT o.invoice_no, o.customer_name, o.order_date, o.status, o.bill_type,
           MAX(o.paid_amount) as paid_amount, MAX(o.discount) as discount,
           SUM(o.total_price) as grand_total, COUNT(o.id) as item_count
    FROM orders o
    WHERE $where
    GROUP BY o.invoice_no
    ORDER BY o.order_date DESC, o.id DESC
");

// ─── Summary Stats ────────────────────────────────────────────────────────────
$stats = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(DISTINCT invoice_no) as total_inv,
           COALESCE(SUM(total_price),0) as total_amount,
           COALESCE(SUM(paid_amount),0) as total_paid
    FROM orders WHERE order_date BETWEEN '$filter_from' AND '$filter_to' AND invoice_no != ''
"));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sales Invoices — AgriBiz ERP</title>
<script>document.documentElement.setAttribute('data-theme', localStorage.getItem('admin-theme') || 'dark');</script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif;}
:root{--bg:#0d1117;--sidebar:#0d1117;--card:#161b22;--card2:#1c2333;--green:#22c55e;--green-dark:#16a34a;--purple:#a855f7;--blue:#3b82f6;--orange:#f59e0b;--red:#ef4444;--teal:#14b8a6;--text:#e6edf3;--text-muted:#8b949e;--border:#30363d;}
[data-theme="light"]{--bg:#f8fafc;--sidebar:#fff;--card:#fff;--card2:#f1f5f9;--green:#16a34a;--green-dark:#15803d;--purple:#7c3aed;--blue:#2563eb;--orange:#ea580c;--red:#dc2626;--teal:#0d9488;--text:#0f172a;--text-muted:#64748b;--border:#e2e8f0;}
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
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px;}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px;}
.stat-card .lbl{font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
.stat-card .val{font-size:22px;font-weight:800;}
.card{background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:20px;}
.card-header{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;}
.card-header h3{font-size:14px;font-weight:700;}
.table{width:100%;border-collapse:collapse;}
.table th{padding:10px 14px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);background:rgba(255,255,255,.02);}
.table td{padding:11px 14px;font-size:13px;border-top:1px solid var(--border);}
.table tr:hover td{background:rgba(255,255,255,.02);}
.badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-green{background:rgba(34,197,94,.15);color:var(--green);}
.badge-red{background:rgba(239,68,68,.15);color:var(--red);}
.badge-orange{background:rgba(245,158,11,.15);color:var(--orange);}
.badge-blue{background:rgba(59,130,246,.15);color:var(--blue);}
.badge-gray{background:rgba(139,148,158,.15);color:var(--text-muted);}
.btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;border:none;text-decoration:none;transition:.2s;}
.btn-green{background:var(--green);color:#fff;} .btn-green:hover{background:var(--green-dark);}
.btn-blue{background:var(--blue);color:#fff;} .btn-blue:hover{background:#2563eb;}
.btn-red{background:var(--red);color:#fff;} .btn-red:hover{background:#dc2626;}
.btn-ghost{background:var(--card2);color:var(--text);border:1px solid var(--border);}
.btn-ghost:hover{border-color:var(--green);color:var(--green);}
.filter-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px;}
.filter-bar input,.filter-bar select{background:var(--card2);border:1px solid var(--border);border-radius:8px;padding:7px 12px;color:var(--text);font-size:12px;outline:none;}
.filter-bar input:focus,.filter-bar select:focus{border-color:var(--green);}
.msg{padding:11px 16px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.msg-success{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.25);color:var(--green);}
.msg-error{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);color:var(--red);}
/* Print styles */
@media print {
  .sidebar,.topbar,.filter-bar,.no-print{display:none!important;}
  .main{margin:0!important;} .content{padding:0!important;}
  .card{border:none!important;box-shadow:none!important;}
  body{background:#fff!important;color:#000!important;}
}
/* Modal */
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:400;backdrop-filter:blur(4px);align-items:center;justify-content:center;}
.overlay.open{display:flex;}
.modal{background:var(--card);border:1px solid var(--border);border-radius:18px;width:100%;max-width:640px;max-height:90vh;overflow-y:auto;animation:mi .2s ease;}
@keyframes mi{from{opacity:0;transform:scale(.95);}to{opacity:1;transform:scale(1);}}
.modal-head{padding:20px 24px 16px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;}
.modal-body{padding:22px 24px;}
.modal-foot{padding:14px 24px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end;}
.invoice-print-area{font-family:'Inter',sans-serif;}
.inv-header{text-align:center;margin-bottom:16px;}
.inv-header h2{font-size:20px;font-weight:800;}
.inv-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px;font-size:12px;}
.inv-table{width:100%;border-collapse:collapse;font-size:12px;}
.inv-table th,.inv-table td{padding:8px 10px;border:1px solid #ddd;text-align:left;}
.inv-table th{background:#f5f5f5;font-weight:700;}
</style>
</head>
<body>
<?php include '_sidebar.php'; ?>
<div class="main">
  <div class="topbar">
    <div>
      <p style="font-size:11px;color:var(--text-muted);">Transactions</p>
      <h1 style="font-size:18px;font-weight:800;"><i class="fas fa-file-invoice" style="color:var(--green);margin-right:6px;"></i>Sales Invoices</h1>
    </div>
    <div style="display:flex;gap:10px;align-items:center;">
      <button class="btn btn-ghost" onclick="toggleTheme()"><i class="fas fa-sun" id="themeIcon"></i></button>
      <a href="admin_billing.php" class="btn btn-green"><i class="fas fa-plus"></i> New Invoice</a>
    </div>
  </div>

  <div class="content">
    <?php if ($msg): ?><div class="msg msg-<?php echo $msg_type; ?>"><i class="fas fa-<?php echo $msg_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i> <?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

    <?php if ($view_inv && $invoice_detail): ?>
    <!-- ─── INVOICE DETAIL VIEW ─── -->
    <div class="no-print" style="margin-bottom:14px;">
      <a href="sales_invoices.php" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Back to List</a>
      <button class="btn btn-blue" onclick="window.print()" style="margin-left:8px;"><i class="fas fa-print"></i> Print / PDF</button>
      <a href="sales_return.php?inv=<?php echo urlencode($view_inv); ?>" class="btn" style="background:var(--orange);color:#fff;margin-left:8px;"><i class="fas fa-rotate-left"></i> Sales Return</a>
      <?php if ($invoice_detail['status'] !== 'Voided'): ?>
      <button class="btn btn-red" onclick="document.getElementById('voidModal').classList.add('open')" style="margin-left:8px;"><i class="fas fa-ban"></i> Void Invoice</button>
      <?php endif; ?>
    </div>
    <div class="invoice-print-area" style="background:var(--card);border:1px solid var(--border);border-radius:14px;padding:32px;">
      <div class="inv-header">
        <h2 style="color:var(--green);">TAX INVOICE</h2>
        <p style="font-size:13px;color:var(--text-muted);">Invoice No: <strong style="color:var(--text);"><?php echo htmlspecialchars($invoice_detail['invoice_no']); ?></strong></p>
        <p style="font-size:12px;color:var(--text-muted);">Date: <?php echo date('d-M-Y', strtotime($invoice_detail['order_date'])); ?></p>
      </div>
      <div class="inv-grid">
        <div>
          <strong>Bill To:</strong><br>
          <b><?php echo htmlspecialchars($invoice_detail['customer_name']); ?></b><br>
          <?php echo htmlspecialchars($invoice_detail['mobile'] ?? ''); ?><br>
          <?php echo htmlspecialchars($invoice_detail['address'] ?? ''); ?>
          <?php if ($invoice_detail['gstin']): ?><br>GSTIN: <?php echo $invoice_detail['gstin']; ?><?php endif; ?>
        </div>
        <div style="text-align:right;">
          <strong>Payment:</strong> <?php echo $invoice_detail['bill_type']; ?><br>
          <strong>Status:</strong> <?php echo $invoice_detail['status']; ?>
        </div>
      </div>
      <table class="inv-table">
        <thead><tr><th>#</th><th>Product</th><th>Qty</th><th>Rate</th><th>Amount</th></tr></thead>
        <tbody>
        <?php $subtotal = 0; foreach ($invoice_items as $i => $it): $subtotal += $it['total_price']; ?>
          <tr>
            <td><?php echo $i+1; ?></td>
            <td><?php echo htmlspecialchars($it['fertilizer_name']); ?></td>
            <td><?php echo $it['quantity']; ?></td>
            <td>₹<?php echo number_format($it['unit_price'], 2); ?></td>
            <td>₹<?php echo number_format($it['total_price'], 2); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <?php
          $disc = floatval($invoice_detail['discount']);
          $after_disc = $subtotal - $disc;
          $gst_base = $after_disc / 1.18;
          $gst_amt = $after_disc - $gst_base;
          $due = $after_disc - floatval($invoice_detail['paid_amount']);
          ?>
          <?php if ($disc > 0): ?><tr><td colspan="4" style="text-align:right;">Discount:</td><td>- ₹<?php echo number_format($disc,2); ?></td></tr><?php endif; ?>
          <tr style="background:#f9f9f9;"><td colspan="4" style="text-align:right;">Taxable Amount:</td><td><b>₹<?php echo number_format($gst_base,2); ?></b></td></tr>
          <tr><td colspan="4" style="text-align:right;">CGST (9%):</td><td>₹<?php echo number_format($gst_amt/2,2); ?></td></tr>
          <tr><td colspan="4" style="text-align:right;">SGST (9%):</td><td>₹<?php echo number_format($gst_amt/2,2); ?></td></tr>
          <tr style="font-weight:800;background:#f0f0f0;"><td colspan="4" style="text-align:right;">Grand Total:</td><td>₹<?php echo number_format($after_disc,2); ?></td></tr>
          <tr style="color:green;"><td colspan="4" style="text-align:right;">Amount Paid:</td><td>₹<?php echo number_format($invoice_detail['paid_amount'],2); ?></td></tr>
          <?php if ($due > 0.01): ?><tr style="color:red;font-weight:700;"><td colspan="4" style="text-align:right;">Balance Due:</td><td>₹<?php echo number_format($due,2); ?></td></tr><?php endif; ?>
        </tfoot>
      </table>
      <p style="font-size:11px;color:var(--text-muted);margin-top:16px;text-align:center;">Thank you for your business! — Generated by AgriBiz ERP</p>

      <?php if ($due > 0.01 && $invoice_detail['status'] !== 'Voided'): ?>
      <div class="no-print" style="margin-top:20px;background:var(--card2);border:1px solid var(--border);border-radius:12px;padding:16px;">
        <h4 style="font-size:13px;font-weight:700;margin-bottom:12px;"><i class="fas fa-money-bill" style="color:var(--green);"></i> Record Payment</h4>
        <form method="POST" style="display:flex;gap:10px;align-items:center;">
          <input type="hidden" name="invoice_no" value="<?php echo htmlspecialchars($view_inv); ?>">
          <input type="number" name="pay_amount" placeholder="Amount ₹" max="<?php echo $due; ?>" step="0.01" style="background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:8px 12px;color:var(--text);font-size:13px;outline:none;width:160px;">
          <button type="submit" name="mark_paid" class="btn btn-green"><i class="fas fa-check"></i> Mark Paid</button>
        </form>
      </div>
      <?php endif; ?>
    </div>

    <!-- Void Modal -->
    <div class="overlay" id="voidModal">
      <div class="modal" style="max-width:420px;">
        <div class="modal-head"><h3 style="font-size:15px;font-weight:700;"><i class="fas fa-ban" style="color:var(--red);"></i> Void Invoice</h3><button onclick="document.getElementById('voidModal').classList.remove('open')" style="background:none;border:none;color:var(--text-muted);font-size:18px;cursor:pointer;">✕</button></div>
        <div class="modal-body">
          <p style="font-size:13px;color:var(--text-muted);">This will void invoice <strong><?php echo htmlspecialchars($view_inv); ?></strong> and restore all stock. This action cannot be undone.</p>
        </div>
        <div class="modal-foot">
          <button onclick="document.getElementById('voidModal').classList.remove('open')" class="btn btn-ghost">Cancel</button>
          <form method="POST"><input type="hidden" name="invoice_no" value="<?php echo htmlspecialchars($view_inv); ?>"><button type="submit" name="void_invoice" class="btn btn-red"><i class="fas fa-ban"></i> Confirm Void</button></form>
        </div>
      </div>
    </div>

    <?php else: ?>
    <!-- ─── INVOICE LIST ─── -->
    <div class="stats-grid">
      <div class="stat-card" style="border-left:4px solid var(--green);">
        <div class="lbl">Total Invoices</div>
        <div class="val" style="color:var(--green);"><?php echo $stats['total_inv']; ?></div>
      </div>
      <div class="stat-card" style="border-left:4px solid var(--blue);">
        <div class="lbl">Total Billed</div>
        <div class="val" style="color:var(--blue);">₹<?php echo number_format($stats['total_amount'], 0); ?></div>
      </div>
      <div class="stat-card" style="border-left:4px solid var(--green);">
        <div class="lbl">Amount Collected</div>
        <div class="val" style="color:var(--green);">₹<?php echo number_format($stats['total_paid'], 0); ?></div>
      </div>
      <div class="stat-card" style="border-left:4px solid var(--red);">
        <div class="lbl">Outstanding Due</div>
        <div class="val" style="color:var(--red);">₹<?php echo number_format($stats['total_amount'] - $stats['total_paid'], 0); ?></div>
      </div>
    </div>

    <form method="GET" class="filter-bar no-print">
      <input type="date" name="from" value="<?php echo $filter_from; ?>">
      <input type="date" name="to" value="<?php echo $filter_to; ?>">
      <select name="status">
        <option value="">All Status</option>
        <option value="Delivered" <?php if($filter_status==='Delivered') echo 'selected'; ?>>Delivered</option>
        <option value="Pending" <?php if($filter_status==='Pending') echo 'selected'; ?>>Pending</option>
        <option value="Voided" <?php if($filter_status==='Voided') echo 'selected'; ?>>Voided</option>
      </select>
      <input type="text" name="q" value="<?php echo htmlspecialchars($filter_search); ?>" placeholder="Search invoice / customer...">
      <button type="submit" class="btn btn-green"><i class="fas fa-filter"></i> Filter</button>
      <a href="sales_invoices.php" class="btn btn-ghost"><i class="fas fa-times"></i> Clear</a>
    </form>

    <div class="card">
      <div class="card-header">
        <h3><i class="fas fa-list" style="color:var(--green);"></i> Invoice List</h3>
        <span style="font-size:12px;color:var(--text-muted);"><?php echo date('d M Y', strtotime($filter_from)); ?> — <?php echo date('d M Y', strtotime($filter_to)); ?></span>
      </div>
      <table class="table">
        <thead>
          <tr>
            <th>Invoice No</th>
            <th>Customer</th>
            <th>Date</th>
            <th>Items</th>
            <th>Grand Total</th>
            <th>Paid</th>
            <th>Due</th>
            <th>Method</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php
        $count = 0;
        if ($invoices_res && mysqli_num_rows($invoices_res) > 0):
            while ($inv = mysqli_fetch_assoc($invoices_res)):
                $count++;
                $due = $inv['grand_total'] - $inv['paid_amount'];
                $status_class = match($inv['status']) {
                    'Delivered','Accepted' => 'badge-green',
                    'Pending' => 'badge-orange',
                    'Voided'  => 'badge-gray',
                    default   => 'badge-blue'
                };
        ?>
        <tr>
          <td><strong style="color:var(--blue);"><?php echo htmlspecialchars($inv['invoice_no']); ?></strong></td>
          <td><?php echo htmlspecialchars($inv['customer_name']); ?></td>
          <td style="color:var(--text-muted);font-size:12px;"><?php echo date('d-M-Y', strtotime($inv['order_date'])); ?></td>
          <td><span class="badge badge-blue"><?php echo $inv['item_count']; ?> items</span></td>
          <td><strong>₹<?php echo number_format($inv['grand_total'], 2); ?></strong></td>
          <td style="color:var(--green);">₹<?php echo number_format($inv['paid_amount'], 2); ?></td>
          <td style="color:<?php echo $due > 0.01 ? 'var(--red)' : 'var(--text-muted)'; ?>;font-weight:700;">
            <?php echo $due > 0.01 ? '₹'.number_format($due,2) : '—'; ?>
          </td>
          <td><span class="badge <?php echo $inv['bill_type']==='Cash' ? 'badge-orange' : 'badge-blue'; ?>"><?php echo $inv['bill_type']; ?></span></td>
          <td><span class="badge <?php echo $status_class; ?>"><?php echo $inv['status']; ?></span></td>
          <td>
            <a href="?inv=<?php echo urlencode($inv['invoice_no']); ?>" class="btn btn-ghost" style="padding:4px 10px;font-size:11px;"><i class="fas fa-eye"></i></a>
            <a href="sales_return.php?inv=<?php echo urlencode($inv['invoice_no']); ?>" class="btn" style="padding:4px 10px;font-size:11px;background:var(--card2);border:1px solid var(--border);color:var(--orange);" title="Return"><i class="fas fa-rotate-left"></i></a>
          </td>
        </tr>
        <?php endwhile; else: ?>
        <tr><td colspan="10" style="text-align:center;padding:36px;color:var(--text-muted);"><i class="fas fa-folder-open" style="font-size:28px;display:block;margin-bottom:8px;opacity:.4;"></i>No invoices found for the selected filters.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
function toggleTheme(){
  const t = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', t);
  localStorage.setItem('admin-theme', t);
  document.getElementById('themeIcon').className = t === 'light' ? 'fas fa-moon' : 'fas fa-sun';
}
(function(){const t=localStorage.getItem('admin-theme')||'dark'; document.getElementById('themeIcon').className=t==='light'?'fas fa-moon':'fas fa-sun';})();
</script>
</body></html>
