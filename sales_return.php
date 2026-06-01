<?php
session_start();
if (!isset($_SESSION['admin'])) { header('Location: index.php'); exit(); }
include 'db.php';
checkRole(['Admin', 'Manager', 'Billing Staff']);

$msg = ''; $msg_type = 'success';
$inv_no = trim($_GET['inv'] ?? '');
$invoice_detail = null; $invoice_items = [];

if ($inv_no) {
    $inv_safe = mysqli_real_escape_string($conn, $inv_no);
    $invoice_detail = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT o.invoice_no, MAX(o.customer_name) as customer_name, MAX(o.order_date) as order_date, MAX(o.status) as status, MAX(o.bill_type) as bill_type,
               SUM(o.total_price) as grand_total, MAX(o.paid_amount) as paid_amount, MAX(o.discount) as discount
        FROM orders o WHERE o.invoice_no='$inv_safe' GROUP BY o.invoice_no"));

    $items_r = mysqli_query($conn, "SELECT fertilizer_id, fertilizer_name, quantity, total_price FROM orders WHERE invoice_no='$inv_safe'");
    while ($r = mysqli_fetch_assoc($items_r)) $invoice_items[] = $r;
}

// ─── Process Return ────────────────────────────────────────────────────────
if (isset($_POST['confirm_return'])) {
    $orig_inv = mysqli_real_escape_string($conn, $_POST['orig_invoice']);
    $return_items = $_POST['return_items'] ?? [];
    $reason = mysqli_real_escape_string($conn, $_POST['reason'] ?? 'Customer Return');

    if (empty($return_items)) {
        $msg = 'No items selected for return.'; $msg_type = 'error';
    } else {
        $seq = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(MAX(id),0)+1 AS n FROM sales"))['n'];
        $return_no = 'RET-'.date('Ymd').'-'.str_pad($seq, 4, '0', STR_PAD_LEFT);
        mysqli_begin_transaction($conn);
        try {
            foreach ($return_items as $fid => $qty) {
                $qty = intval($qty);
                if ($qty <= 0) continue;
                $fid = intval($fid);
                $fert = mysqli_fetch_assoc(mysqli_query($conn, "SELECT fertilizer_name, price FROM fertilizers WHERE id=$fid"));
                $total = $fert ? $fert['price'] * $qty : 0;

                // Restore stock
                mysqli_query($conn, "UPDATE fertilizers SET quantity = quantity + $qty WHERE id = $fid");

                // Log return in sales
                $ins = mysqli_prepare($conn, "INSERT INTO sales (customer_name, fertilizer_name, quantity, total_price, sale_date, invoice_no, is_return, return_ref, bill_type) SELECT customer_name, fertilizer_name, ?, ?, CURDATE(), ?, 1, ?, bill_type FROM orders WHERE invoice_no=? AND fertilizer_id=? LIMIT 1");
                mysqli_stmt_bind_param($ins, "idsssi", $qty, $total, $return_no, $orig_inv, $orig_inv, $fid);
                mysqli_stmt_execute($ins);
            }
            mysqli_commit($conn);
            $msg = "Sales Return #{$return_no} processed successfully. Stock restored.";
            $inv_no = ''; $invoice_detail = null; $invoice_items = [];
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $msg = "Return failed: " . $e->getMessage(); $msg_type = 'error';
        }
    }
}

// Recent returns
$returns_res = mysqli_query($conn, "SELECT * FROM sales WHERE is_return=1 ORDER BY sale_date DESC LIMIT 20");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sales Return — AgriBiz ERP</title>
<script>document.documentElement.setAttribute('data-theme',localStorage.getItem('admin-theme')||'dark');</script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',sans-serif;}
:root{--bg:#0d1117;--sidebar:#0d1117;--card:#161b22;--card2:#1c2333;--green:#22c55e;--green-dark:#16a34a;--orange:#f59e0b;--red:#ef4444;--text:#e6edf3;--text-muted:#8b949e;--border:#30363d;}
[data-theme="light"]{--bg:#f8fafc;--sidebar:#fff;--card:#fff;--card2:#f1f5f9;--green:#16a34a;--green-dark:#15803d;--orange:#ea580c;--red:#dc2626;--text:#0f172a;--text-muted:#64748b;--border:#e2e8f0;}
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
.card{background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:20px;}
.card-header{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;}
.card-header h3{font-size:14px;font-weight:700;}
.card-body{padding:20px;}
.table{width:100%;border-collapse:collapse;}
.table th{padding:10px 14px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;color:var(--text-muted);background:rgba(255,255,255,.02);}
.table td{padding:11px 14px;font-size:13px;border-top:1px solid var(--border);}
.badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-orange{background:rgba(245,158,11,.15);color:var(--orange);}
.badge-red{background:rgba(239,68,68,.15);color:var(--red);}
.btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;border:none;text-decoration:none;transition:.2s;}
.btn-green{background:var(--green);color:#fff;}.btn-green:hover{background:var(--green-dark);}
.btn-ghost{background:var(--card2);color:var(--text);border:1px solid var(--border);}
.btn-orange{background:var(--orange);color:#fff;}
.form-control{width:100%;background:var(--card2);border:1px solid var(--border);border-radius:9px;padding:9px 13px;color:var(--text);font-size:13px;outline:none;transition:.2s;}
.form-control:focus{border-color:var(--orange);}
.msg{padding:11px 16px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.msg-success{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.25);color:var(--green);}
.msg-error{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);color:var(--red);}
.qty-input{width:80px;background:var(--card2);border:1px solid var(--border);border-radius:8px;padding:6px 10px;color:var(--text);font-size:13px;outline:none;text-align:center;}
</style>
</head>
<body>
<?php include '_sidebar.php'; ?>
<div class="main">
  <div class="topbar">
    <div>
      <p style="font-size:11px;color:var(--text-muted);">Transactions</p>
      <h1 style="font-size:18px;font-weight:800;"><i class="fas fa-rotate-left" style="color:var(--orange);margin-right:6px;"></i>Sales Return</h1>
    </div>
    <button class="btn btn-ghost" onclick="toggleTheme()"><i class="fas fa-sun" id="themeIcon"></i></button>
  </div>
  <div class="content">
    <?php if ($msg): ?><div class="msg msg-<?php echo $msg_type; ?>"><i class="fas fa-<?php echo $msg_type==='success'?'check-circle':'exclamation-circle'; ?>"></i> <?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

    <!-- Search Invoice -->
    <div class="card">
      <div class="card-header"><h3><i class="fas fa-search" style="color:var(--orange);"></i> Find Original Invoice</h3></div>
      <div class="card-body">
        <form method="GET" style="display:flex;gap:10px;">
          <input type="text" name="inv" value="<?php echo htmlspecialchars($inv_no); ?>" class="form-control" placeholder="Enter Invoice No (e.g. BILL-20240601-0001)" style="max-width:380px;">
          <button type="submit" class="btn btn-orange"><i class="fas fa-search"></i> Find Invoice</button>
        </form>
      </div>
    </div>

    <?php if ($invoice_detail): ?>
    <!-- Return Form -->
    <div class="card">
      <div class="card-header">
        <h3><i class="fas fa-file-invoice" style="color:var(--orange);"></i> Invoice: <?php echo htmlspecialchars($invoice_detail['invoice_no']); ?></h3>
        <span style="font-size:12px;color:var(--text-muted);">Customer: <?php echo htmlspecialchars($invoice_detail['customer_name']); ?> | <?php echo date('d-M-Y',strtotime($invoice_detail['order_date'])); ?></span>
      </div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="orig_invoice" value="<?php echo htmlspecialchars($invoice_detail['invoice_no']); ?>">
          <table class="table" style="margin-bottom:16px;">
            <thead><tr><th>Return?</th><th>Product</th><th>Original Qty</th><th>Return Qty</th><th>Unit Price</th><th>Return Value</th></tr></thead>
            <tbody>
            <?php foreach ($invoice_items as $it):
              $unit_price = $it['quantity'] > 0 ? ($it['total_price'] / $it['quantity']) : 0;
            ?>
            <tr>
              <td><input type="checkbox" onchange="toggleReturnRow(this, <?php echo $it['fertilizer_id']; ?>)" style="width:16px;height:16px;accent-color:var(--orange);"></td>
              <td><?php echo htmlspecialchars($it['fertilizer_name']); ?></td>
              <td><?php echo $it['quantity']; ?> units</td>
              <td><input type="number" name="return_items[<?php echo $it['fertilizer_id']; ?>]" id="ret_<?php echo $it['fertilizer_id']; ?>" class="qty-input" min="1" max="<?php echo $it['quantity']; ?>" value="<?php echo $it['quantity']; ?>" disabled></td>
              <td>₹<?php echo number_format($unit_price,2); ?></td>
              <td id="val_<?php echo $it['fertilizer_id']; ?>" style="color:var(--orange);font-weight:700;">₹<?php echo number_format($it['total_price'],2); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <div style="margin-bottom:14px;">
            <label style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;display:block;margin-bottom:5px;">Return Reason</label>
            <input type="text" name="reason" class="form-control" value="Customer Return" style="max-width:400px;">
          </div>
          <button type="submit" name="confirm_return" class="btn btn-orange"><i class="fas fa-rotate-left"></i> Confirm Sales Return</button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <!-- Recent Returns -->
    <div class="card">
      <div class="card-header"><h3><i class="fas fa-history" style="color:var(--text-muted);"></i> Recent Returns</h3></div>
      <table class="table">
        <thead><tr><th>Return No</th><th>Customer</th><th>Product</th><th>Qty</th><th>Value</th><th>Original Inv</th><th>Date</th></tr></thead>
        <tbody>
        <?php if ($returns_res && mysqli_num_rows($returns_res) > 0):
            while ($r = mysqli_fetch_assoc($returns_res)): ?>
        <tr>
          <td><strong style="color:var(--orange);"><?php echo htmlspecialchars($r['invoice_no']); ?></strong></td>
          <td><?php echo htmlspecialchars($r['customer_name']); ?></td>
          <td><?php echo htmlspecialchars($r['fertilizer_name']); ?></td>
          <td><?php echo $r['quantity']; ?></td>
          <td style="color:var(--orange);">₹<?php echo number_format($r['total_price'],2); ?></td>
          <td style="color:var(--text-muted);"><?php echo htmlspecialchars($r['return_ref'] ?? '—'); ?></td>
          <td style="color:var(--text-muted);font-size:12px;"><?php echo date('d-M-Y',strtotime($r['sale_date'])); ?></td>
        </tr>
        <?php endwhile; else: ?>
        <tr><td colspan="7" style="text-align:center;padding:28px;color:var(--text-muted);">No returns recorded yet.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<script>
function toggleReturnRow(cb, fid) {
  const inp = document.getElementById('ret_' + fid);
  inp.disabled = !cb.checked;
}
function toggleTheme(){const t=document.documentElement.getAttribute('data-theme')==='dark'?'light':'dark';document.documentElement.setAttribute('data-theme',t);localStorage.setItem('admin-theme',t);document.getElementById('themeIcon').className=t==='light'?'fas fa-moon':'fas fa-sun';}
(function(){const t=localStorage.getItem('admin-theme')||'dark';document.getElementById('themeIcon').className=t==='light'?'fas fa-moon':'fas fa-sun';})();
</script>
</body></html>
