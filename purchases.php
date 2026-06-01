<?php
session_start();
if (!isset($_SESSION['admin'])) { header('Location: index.php'); exit(); }
include 'db.php';
checkRole(['Admin', 'Manager', 'Billing Staff']);

// Auto-migrate
add_column_if_not_exists($conn, 'purchases', 'invoice_no', "VARCHAR(50) DEFAULT ''");
add_column_if_not_exists($conn, 'purchases', 'supplier_id', "INT DEFAULT 0");
add_column_if_not_exists($conn, 'purchases', 'gst_rate', "DECIMAL(5,2) DEFAULT 18.00");
add_column_if_not_exists($conn, 'purchases', 'paid_amount', "DECIMAL(10,2) DEFAULT 0");
add_column_if_not_exists($conn, 'purchases', 'bill_type', "VARCHAR(20) DEFAULT 'Cash'");
add_column_if_not_exists($conn, 'purchases', 'notes', "TEXT");
add_column_if_not_exists($conn, 'purchases', 'is_return', "TINYINT DEFAULT 0");
add_column_if_not_exists($conn, 'fertilizers', 'purchase_price', "DECIMAL(10,2) DEFAULT 0");

$msg = ''; $msg_type = 'success';

// ─── Handle New Purchase Entry ───────────────────────────────────────────────
if (isset($_POST['save_purchase'])) {
    $supplier_id = intval($_POST['supplier_id']);
    $pur_date    = mysqli_real_escape_string($conn, $_POST['purchase_date']);
    $bill_type   = mysqli_real_escape_string($conn, $_POST['bill_type'] ?? 'Cash');
    $notes       = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');
    $gst_rate    = floatval($_POST['gst_rate'] ?? 18);
    $paid_amount = floatval($_POST['paid_amount'] ?? 0);
    $items       = $_POST['items'] ?? [];

    if (!$supplier_id || empty($items)) {
        $msg = 'Please select a supplier and add at least one item.';
        $msg_type = 'error';
    } else {
        $sup = mysqli_fetch_assoc(mysqli_prepare_run($conn, "SELECT * FROM suppliers WHERE id=?", "i", $supplier_id));
        $seq = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(MAX(id),0)+1 AS n FROM purchases"))['n'];
        $invoice_no = 'PUR-'.date('Ymd').'-'.str_pad($seq, 4, '0', STR_PAD_LEFT);

        mysqli_begin_transaction($conn);
        $success = true;
        foreach ($items as $item) {
            $fid  = intval($item['fertilizer_id']);
            $qty  = intval($item['qty']);
            $cost = floatval($item['cost']);
            if (!$fid || $qty <= 0 || $cost <= 0) continue;

            $fert = mysqli_fetch_assoc(mysqli_prepare_run($conn, "SELECT * FROM fertilizers WHERE id=?", "i", $fid));
            if (!$fert) { $success = false; break; }

            // Insert purchase record
            $ins = mysqli_prepare($conn, "INSERT INTO purchases (supplier_name, supplier_id, fertilizer_name, fertilizer_id, quantity, cost, purchase_date, invoice_no, paid_amount, bill_type, gst_rate, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $sname = $sup['supplier_name'] ?? '';
            $fname = $fert['fertilizer_name'];
            mysqli_stmt_bind_param($ins, "sissidsdssds", $sname, $supplier_id, $fname, $fid, $qty, $cost, $pur_date, $invoice_no, $paid_amount, $bill_type, $gst_rate, $notes);
            mysqli_stmt_execute($ins);

            // Update stock
            mysqli_query($conn, "UPDATE fertilizers SET quantity = quantity + $qty, purchase_price = $cost WHERE id = $fid");
        }
        if ($success) {
            mysqli_commit($conn);
            $msg = "Purchase Invoice #{$invoice_no} saved successfully!";
        } else {
            mysqli_rollback($conn);
            $msg = "Error saving purchase. Please try again.";
            $msg_type = 'error';
        }
    }
}

// Helper
function mysqli_prepare_run($conn, $sql, $types, ...$params) {
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    return mysqli_stmt_get_result($stmt);
}

// ─── Filters ─────────────────────────────────────────────────────────────────
$filter_from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$filter_to   = $_GET['to']   ?? date('Y-m-d');
$filter_sup  = intval($_GET['supplier_id'] ?? 0);

$where = "p.purchase_date BETWEEN '$filter_from' AND '$filter_to' AND p.invoice_no != ''";
if ($filter_sup) $where .= " AND p.supplier_id = $filter_sup";

$purchases_res = mysqli_query($conn, "
    SELECT p.invoice_no, MAX(p.supplier_name) as supplier_name, MAX(p.purchase_date) as purchase_date, MAX(p.bill_type) as bill_type,
           SUM(p.cost * p.quantity) as total_amount,
           MAX(p.paid_amount) as paid_amount,
           SUM(p.quantity) as total_qty,
           COUNT(p.id) as item_count
    FROM purchases p
    WHERE $where
    GROUP BY p.invoice_no
    ORDER BY MAX(p.purchase_date) DESC
");


$stats = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(DISTINCT invoice_no) as c, COALESCE(SUM(cost*quantity),0) as total FROM purchases WHERE purchase_date BETWEEN '$filter_from' AND '$filter_to' AND invoice_no != ''"));

// Supplier list
$suppliers_list = mysqli_query($conn, "SELECT * FROM suppliers ORDER BY supplier_name");
// Product list
$products_list  = mysqli_query($conn, "SELECT id, fertilizer_name, purchase_price FROM fertilizers ORDER BY fertilizer_name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Purchase Invoice — AgriBiz ERP</title>
<script>document.documentElement.setAttribute('data-theme',localStorage.getItem('admin-theme')||'dark');</script>
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
.stats-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px;}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px;}
.stat-card .lbl{font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
.stat-card .val{font-size:22px;font-weight:800;}
.card{background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:20px;}
.card-header{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;}
.card-header h3{font-size:14px;font-weight:700;}
.card-body{padding:20px;}
.table{width:100%;border-collapse:collapse;}
.table th{padding:10px 14px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;color:var(--text-muted);background:rgba(255,255,255,.02);}
.table td{padding:11px 14px;font-size:13px;border-top:1px solid var(--border);}
.table tr:hover td{background:rgba(255,255,255,.02);}
.badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-green{background:rgba(34,197,94,.15);color:var(--green);}
.badge-orange{background:rgba(245,158,11,.15);color:var(--orange);}
.badge-blue{background:rgba(59,130,246,.15);color:var(--blue);}
.btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;border:none;text-decoration:none;transition:.2s;}
.btn-green{background:var(--green);color:#fff;}.btn-green:hover{background:var(--green-dark);}
.btn-ghost{background:var(--card2);color:var(--text);border:1px solid var(--border);}
.btn-ghost:hover{border-color:var(--green);color:var(--green);}
.form-group{margin-bottom:14px;}
.form-group label{display:block;font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px;}
.form-control{width:100%;background:var(--card2);border:1px solid var(--border);border-radius:9px;padding:9px 13px;color:var(--text);font-size:13px;outline:none;transition:.2s;}
.form-control:focus{border-color:var(--green);box-shadow:0 0 0 3px rgba(34,197,94,.1);}
.form-row{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;}
.item-row{display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:10px;align-items:end;margin-bottom:10px;background:var(--card2);padding:12px;border-radius:10px;border:1px solid var(--border);}
.msg{padding:11px 16px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.msg-success{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.25);color:var(--green);}
.msg-error{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);color:var(--red);}
.filter-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px;}
.filter-bar input,.filter-bar select{background:var(--card2);border:1px solid var(--border);border-radius:8px;padding:7px 12px;color:var(--text);font-size:12px;outline:none;}
</style>
</head>
<body>
<?php include '_sidebar.php'; ?>
<div class="main">
  <div class="topbar">
    <div>
      <p style="font-size:11px;color:var(--text-muted);">Transactions</p>
      <h1 style="font-size:18px;font-weight:800;"><i class="fas fa-shopping-cart" style="color:var(--purple);margin-right:6px;"></i>Purchase Invoice</h1>
    </div>
    <div style="display:flex;gap:10px;">
      <button class="btn btn-ghost" onclick="toggleTheme()"><i class="fas fa-sun" id="themeIcon"></i></button>
    </div>
  </div>
  <div class="content">
    <?php if ($msg): ?><div class="msg msg-<?php echo $msg_type; ?>"><i class="fas fa-<?php echo $msg_type==='success'?'check-circle':'exclamation-circle'; ?>"></i> <?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

    <!-- ─── NEW PURCHASE ENTRY ─── -->
    <div class="card">
      <div class="card-header"><h3><i class="fas fa-plus" style="color:var(--purple);"></i> New Purchase Entry</h3></div>
      <div class="card-body">
        <form method="POST" id="purForm">
          <div class="form-row" style="margin-bottom:14px;">
            <div class="form-group">
              <label>Supplier *</label>
              <select name="supplier_id" class="form-control" required>
                <option value="">— Select Supplier —</option>
                <?php mysqli_data_seek($suppliers_list,0); while($s=mysqli_fetch_assoc($suppliers_list)): ?>
                <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['supplier_name']); ?></option>
                <?php endwhile; ?>
              </select>
            </div>
            <div class="form-group">
              <label>Purchase Date *</label>
              <input type="date" name="purchase_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <div class="form-group">
              <label>Payment Mode</label>
              <select name="bill_type" class="form-control">
                <option value="Cash">Cash</option>
                <option value="Bank">Bank / UPI</option>
                <option value="Credit">Credit</option>
              </select>
            </div>
          </div>
          <div class="form-row" style="margin-bottom:16px;">
            <div class="form-group">
              <label>GST Rate (%)</label>
              <input type="number" name="gst_rate" class="form-control" value="18" min="0" max="28" step="0.5">
            </div>
            <div class="form-group">
              <label>Amount Paid (₹)</label>
              <input type="number" name="paid_amount" class="form-control" value="0" min="0" step="0.01">
            </div>
            <div class="form-group">
              <label>Notes / Narration</label>
              <input type="text" name="notes" class="form-control" placeholder="Optional notes...">
            </div>
          </div>

          <h4 style="font-size:13px;font-weight:700;margin-bottom:12px;color:var(--text-muted);">ITEMS</h4>
          <div id="itemsContainer">
            <div class="item-row">
              <div class="form-group" style="margin:0;">
                <label>Product</label>
                <select name="items[0][fertilizer_id]" class="form-control" required>
                  <option value="">— Select Product —</option>
                  <?php mysqli_data_seek($products_list,0); while($p=mysqli_fetch_assoc($products_list)): ?>
                  <option value="<?php echo $p['id']; ?>" data-price="<?php echo $p['purchase_price']; ?>"><?php echo htmlspecialchars($p['fertilizer_name']); ?></option>
                  <?php endwhile; ?>
                </select>
              </div>
              <div class="form-group" style="margin:0;">
                <label>Qty</label>
                <input type="number" name="items[0][qty]" class="form-control" min="1" required placeholder="0">
              </div>
              <div class="form-group" style="margin:0;">
                <label>Cost/Unit (₹)</label>
                <input type="number" name="items[0][cost]" class="form-control" min="0.01" step="0.01" required placeholder="0.00">
              </div>
              <button type="button" onclick="this.closest('.item-row').remove()" style="background:rgba(239,68,68,.15);border:none;color:var(--red);border-radius:8px;padding:8px 12px;cursor:pointer;margin-bottom:14px;"><i class="fas fa-trash"></i></button>
            </div>
          </div>
          <button type="button" onclick="addItem()" class="btn btn-ghost" style="margin-bottom:16px;"><i class="fas fa-plus"></i> Add Item</button>
          <br>
          <button type="submit" name="save_purchase" class="btn btn-green"><i class="fas fa-save"></i> Save Purchase Invoice</button>
        </form>
      </div>
    </div>

    <!-- ─── PURCHASE HISTORY ─── -->
    <div class="stats-grid">
      <div class="stat-card" style="border-left:4px solid var(--purple);"><div class="lbl">Total Purchases (Period)</div><div class="val" style="color:var(--purple);"><?php echo $stats['c']; ?></div></div>
      <div class="stat-card" style="border-left:4px solid var(--blue);"><div class="lbl">Total Purchase Value</div><div class="val" style="color:var(--blue);">₹<?php echo number_format($stats['total'],0); ?></div></div>
      <div class="stat-card" style="border-left:4px solid var(--green);"><div class="lbl">Period</div><div class="val" style="font-size:14px;color:var(--green);"><?php echo date('d M',strtotime($filter_from)).' — '.date('d M Y',strtotime($filter_to)); ?></div></div>
    </div>

    <form method="GET" class="filter-bar">
      <input type="date" name="from" value="<?php echo $filter_from; ?>">
      <input type="date" name="to" value="<?php echo $filter_to; ?>">
      <select name="supplier_id">
        <option value="">All Suppliers</option>
        <?php mysqli_data_seek($suppliers_list,0); while($s=mysqli_fetch_assoc($suppliers_list)): ?>
        <option value="<?php echo $s['id']; ?>" <?php if($filter_sup==$s['id']) echo 'selected'; ?>><?php echo htmlspecialchars($s['supplier_name']); ?></option>
        <?php endwhile; ?>
      </select>
      <button type="submit" class="btn btn-green"><i class="fas fa-filter"></i> Filter</button>
    </form>

    <div class="card">
      <div class="card-header"><h3><i class="fas fa-history" style="color:var(--purple);"></i> Purchase History</h3></div>
      <table class="table">
        <thead><tr><th>Invoice No</th><th>Supplier</th><th>Date</th><th>Items</th><th>Total Amount</th><th>Paid</th><th>Due</th><th>Method</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if ($purchases_res && mysqli_num_rows($purchases_res) > 0):
            while ($p = mysqli_fetch_assoc($purchases_res)):
                $pdue = $p['total_amount'] - $p['paid_amount'];
        ?>
        <tr>
          <td><strong style="color:var(--purple);"><?php echo htmlspecialchars($p['invoice_no']); ?></strong></td>
          <td><?php echo htmlspecialchars($p['supplier_name']); ?></td>
          <td style="color:var(--text-muted);font-size:12px;"><?php echo date('d-M-Y',strtotime($p['purchase_date'])); ?></td>
          <td><span class="badge badge-blue"><?php echo $p['item_count']; ?> items</span></td>
          <td><strong>₹<?php echo number_format($p['total_amount'],2); ?></strong></td>
          <td style="color:var(--green);">₹<?php echo number_format($p['paid_amount'],2); ?></td>
          <td style="color:<?php echo $pdue>0.01?'var(--red)':'var(--text-muted)';?>;font-weight:700;"><?php echo $pdue>0.01?'₹'.number_format($pdue,2):'—'; ?></td>
          <td><span class="badge badge-<?php echo $p['bill_type']==='Cash'?'orange':'blue'; ?>"><?php echo $p['bill_type']; ?></span></td>
          <td><a href="purchase_return.php?inv=<?php echo urlencode($p['invoice_no']); ?>" class="btn btn-ghost" style="padding:4px 10px;font-size:11px;"><i class="fas fa-rotate-right"></i> Return</a></td>
        </tr>
        <?php endwhile; else: ?>
        <tr><td colspan="9" style="text-align:center;padding:32px;color:var(--text-muted);"><i class="fas fa-inbox" style="font-size:28px;display:block;margin-bottom:8px;opacity:.4;"></i>No purchase records found.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
let itemIdx = 1;
function addItem() {
  const c = document.getElementById('itemsContainer');
  const d = document.createElement('div');
  d.className = 'item-row';
  d.innerHTML = `
    <div class="form-group" style="margin:0;">
      <label>Product</label>
      <select name="items[${itemIdx}][fertilizer_id]" class="form-control" required>
        <option value="">— Select Product —</option>
        <?php mysqli_data_seek($products_list,0); while($p=mysqli_fetch_assoc($products_list)): ?><option value="<?php echo $p['id']; ?>" data-price="<?php echo $p['purchase_price']; ?>"><?php echo addslashes(htmlspecialchars($p['fertilizer_name'])); ?></option><?php endwhile; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0;"><label>Qty</label><input type="number" name="items[${itemIdx}][qty]" class="form-control" min="1" required placeholder="0"></div>
    <div class="form-group" style="margin:0;"><label>Cost/Unit (₹)</label><input type="number" name="items[${itemIdx}][cost]" class="form-control" min="0.01" step="0.01" required placeholder="0.00"></div>
    <button type="button" onclick="this.closest('.item-row').remove()" style="background:rgba(239,68,68,.15);border:none;color:var(--red);border-radius:8px;padding:8px 12px;cursor:pointer;margin-bottom:14px;"><i class="fas fa-trash"></i></button>
  `;
  c.appendChild(d);
  itemIdx++;
}
function toggleTheme(){const t=document.documentElement.getAttribute('data-theme')==='dark'?'light':'dark';document.documentElement.setAttribute('data-theme',t);localStorage.setItem('admin-theme',t);document.getElementById('themeIcon').className=t==='light'?'fas fa-moon':'fas fa-sun';}
(function(){const t=localStorage.getItem('admin-theme')||'dark';document.getElementById('themeIcon').className=t==='light'?'fas fa-moon':'fas fa-sun';})();
</script>
</body></html>
