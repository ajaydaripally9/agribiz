<?php
session_start();
if (!isset($_SESSION['admin'])) { header('Location: index.php'); exit(); }
include 'db.php';
checkRole(['Admin', 'Manager', 'Billing Staff']);

// Auto-migrate
add_column_if_not_exists($conn, 'fertilizers', 'hsn_code', "VARCHAR(20) DEFAULT ''");
add_column_if_not_exists($conn, 'fertilizers', 'category', "VARCHAR(50) DEFAULT ''");
add_column_if_not_exists($conn, 'fertilizers', 'reorder_level', "INT DEFAULT 10");
add_column_if_not_exists($conn, 'fertilizers', 'purchase_price', "DECIMAL(10,2) DEFAULT 0");
add_column_if_not_exists($conn, 'fertilizers', 'gst_percent', "DECIMAL(5,2) DEFAULT 18.00");
add_column_if_not_exists($conn, 'fertilizers', 'batch_no', "VARCHAR(50) DEFAULT ''");
add_column_if_not_exists($conn, 'fertilizers', 'mfg_date', "DATE NULL");
add_column_if_not_exists($conn, 'fertilizers', 'expiry_date', "DATE NULL");
add_column_if_not_exists($conn, 'fertilizers', 'barcode', "VARCHAR(100) DEFAULT ''");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS stock_adjustments (id INT AUTO_INCREMENT PRIMARY KEY, fertilizer_id INT, fertilizer_name VARCHAR(100), adjustment_type ENUM('Add','Remove','Correction') DEFAULT 'Add', qty_before INT, qty_change INT, qty_after INT, reason TEXT, adjusted_by VARCHAR(100), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

$tab = $_GET['tab'] ?? 'stock';
$msg = ''; $msg_type = 'success';

// ─── Handle Stock Adjustment ─────────────────────────────────────────────────
if (isset($_POST['do_adjustment'])) {
    $fid   = intval($_POST['fertilizer_id']);
    $type  = in_array($_POST['adj_type'], ['Add','Remove','Correction']) ? $_POST['adj_type'] : 'Add';
    $qty   = abs(intval($_POST['adj_qty']));
    $reason= mysqli_real_escape_string($conn, $_POST['adj_reason']);
    $user  = $_SESSION['admin_username'] ?? 'admin';

    $fert = mysqli_fetch_assoc(mysqli_query($conn, "SELECT quantity, fertilizer_name FROM fertilizers WHERE id=$fid"));
    if ($fert && $qty > 0) {
        $before = $fert['quantity'];
        $after = match($type) {
            'Add'        => $before + $qty,
            'Remove'     => max(0, $before - $qty),
            'Correction' => $qty,
        };
        mysqli_query($conn, "UPDATE fertilizers SET quantity=$after WHERE id=$fid");
        $fname = mysqli_real_escape_string($conn, $fert['fertilizer_name']);
        mysqli_query($conn, "INSERT INTO stock_adjustments (fertilizer_id,fertilizer_name,adjustment_type,qty_before,qty_change,qty_after,reason,adjusted_by) VALUES ($fid,'$fname','$type',$before,$qty,$after,'$reason','$user')");
        $msg = "Stock adjusted: {$fert['fertilizer_name']} → {$before} → {$after} units.";
    } else {
        $msg = 'Invalid adjustment parameters.'; $msg_type = 'error';
    }
}

// Data for tabs
$all_products    = mysqli_query($conn, "SELECT * FROM fertilizers ORDER BY fertilizer_name");
$low_stock       = mysqli_query($conn, "SELECT * FROM fertilizers WHERE quantity <= COALESCE(reorder_level, 10) ORDER BY quantity ASC");
$expiring_30     = mysqli_query($conn, "SELECT * FROM fertilizers WHERE expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND expiry_date >= CURDATE() ORDER BY expiry_date ASC");
$expired         = mysqli_query($conn, "SELECT * FROM fertilizers WHERE expiry_date IS NOT NULL AND expiry_date < CURDATE() ORDER BY expiry_date ASC");
$adjustments     = mysqli_query($conn, "SELECT * FROM stock_adjustments ORDER BY created_at DESC LIMIT 50");
$batch_products  = mysqli_query($conn, "SELECT * FROM fertilizers WHERE batch_no != '' AND batch_no IS NOT NULL ORDER BY expiry_date ASC");

$stats = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total_products, SUM(quantity) as total_units, SUM(quantity*price) as inventory_value, SUM(quantity*purchase_price) as cost_value FROM fertilizers"));
$low_ct  = mysqli_num_rows(mysqli_query($conn, "SELECT id FROM fertilizers WHERE quantity <= COALESCE(reorder_level,10)"));
$exp_ct  = mysqli_num_rows(mysqli_query($conn, "SELECT id FROM fertilizers WHERE expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)"));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Inventory — AgriBiz ERP</title>
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
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px;}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px;}
.stat-card .lbl{font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
.stat-card .val{font-size:20px;font-weight:800;}
.tab-nav{display:flex;gap:6px;background:var(--card);border:1px solid var(--border);padding:5px;border-radius:12px;width:fit-content;margin-bottom:20px;}
.tab-btn{padding:8px 18px;border-radius:8px;font-size:12px;font-weight:700;color:var(--text-muted);text-decoration:none;transition:.2s;display:flex;align-items:center;gap:6px;}
.tab-btn:hover{color:var(--text);}
.tab-btn.active{background:var(--green);color:#fff;}
.card{background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:20px;}
.card-header{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;}
.card-header h3{font-size:14px;font-weight:700;}
.card-body{padding:20px;}
.table{width:100%;border-collapse:collapse;}
.table th{padding:10px 14px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;color:var(--text-muted);background:rgba(255,255,255,.02);}
.table td{padding:10px 14px;font-size:13px;border-top:1px solid var(--border);}
.table tr:hover td{background:rgba(255,255,255,.02);}
.badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-green{background:rgba(34,197,94,.15);color:var(--green);}
.badge-red{background:rgba(239,68,68,.15);color:var(--red);}
.badge-orange{background:rgba(245,158,11,.15);color:var(--orange);}
.badge-blue{background:rgba(59,130,246,.15);color:var(--blue);}
.badge-teal{background:rgba(20,184,166,.15);color:var(--teal);}
.btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;border:none;text-decoration:none;transition:.2s;}
.btn-green{background:var(--green);color:#fff;}.btn-green:hover{background:var(--green-dark);}
.btn-ghost{background:var(--card2);color:var(--text);border:1px solid var(--border);}
.form-control{width:100%;background:var(--card2);border:1px solid var(--border);border-radius:9px;padding:9px 13px;color:var(--text);font-size:13px;outline:none;transition:.2s;}
.form-control:focus{border-color:var(--green);}
.form-row{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:12px;align-items:end;}
.msg{padding:11px 16px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.msg-success{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.25);color:var(--green);}
.msg-error{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);color:var(--red);}
.stock-bar{height:6px;border-radius:3px;background:var(--card2);margin-top:4px;overflow:hidden;}
.stock-bar-fill{height:100%;border-radius:3px;transition:width .3s;}
.alert-card{padding:14px 18px;border-radius:12px;margin-bottom:12px;display:flex;align-items:center;gap:12px;}
.alert-card.red{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);}
.alert-card.orange{background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);}
</style>
</head>
<body>
<?php include '_sidebar.php'; ?>
<div class="main">
  <div class="topbar">
    <div>
      <p style="font-size:11px;color:var(--text-muted);">Inventory Management</p>
      <h1 style="font-size:18px;font-weight:800;"><i class="fas fa-boxes-stacking" style="color:var(--teal);margin-right:6px;"></i>Stock & Inventory</h1>
    </div>
    <div style="display:flex;gap:10px;">
      <button class="btn btn-ghost" onclick="toggleTheme()"><i class="fas fa-sun" id="themeIcon"></i></button>
      <a href="add_fertilizer.php" class="btn btn-green"><i class="fas fa-plus"></i> Add Product</a>
    </div>
  </div>
  <div class="content">
    <?php if ($msg): ?><div class="msg msg-<?php echo $msg_type; ?>"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

    <div class="stats-grid">
      <div class="stat-card" style="border-left:4px solid var(--teal);"><div class="lbl">Total Products</div><div class="val" style="color:var(--teal);"><?php echo $stats['total_products']; ?></div></div>
      <div class="stat-card" style="border-left:4px solid var(--green);"><div class="lbl">Total Units In Stock</div><div class="val" style="color:var(--green);"><?php echo number_format($stats['total_units']); ?></div></div>
      <div class="stat-card" style="border-left:4px solid var(--blue);"><div class="lbl">Inventory Value (MRP)</div><div class="val" style="color:var(--blue);">₹<?php echo number_format($stats['inventory_value'], 0); ?></div></div>
      <div class="stat-card" style="border-left:4px solid var(--orange);"><div class="lbl">Low Stock / Expiring</div><div class="val" style="color:var(--orange);"><?php echo $low_ct; ?> / <?php echo $exp_ct; ?></div></div>
    </div>

    <!-- Alerts -->
    <?php if ($low_ct > 0): ?>
    <div class="alert-card red"><i class="fas fa-triangle-exclamation" style="color:var(--red);font-size:18px;"></i><div><strong style="color:var(--red);"><?php echo $low_ct; ?> products below reorder level</strong><p style="font-size:12px;color:var(--text-muted);margin-top:2px;">Check the Low Stock tab for details and reorder soon.</p></div></div>
    <?php endif; ?>
    <?php if ($exp_ct > 0): ?>
    <div class="alert-card orange"><i class="fas fa-clock" style="color:var(--orange);font-size:18px;"></i><div><strong style="color:var(--orange);"><?php echo $exp_ct; ?> products expiring within 30 days</strong><p style="font-size:12px;color:var(--text-muted);margin-top:2px;">Check Expiry Tracking tab to take action.</p></div></div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="tab-nav">
      <a href="?tab=stock" class="tab-btn <?php echo $tab==='stock'?'active':''; ?>"><i class="fas fa-list"></i> Stock Report</a>
      <a href="?tab=batch" class="tab-btn <?php echo $tab==='batch'?'active':''; ?>"><i class="fas fa-tag"></i> Batch Tracking</a>
      <a href="?tab=expiry" class="tab-btn <?php echo $tab==='expiry'?'active':''; ?>"><i class="fas fa-clock"></i> Expiry Tracking</a>
      <a href="?tab=adjust" class="tab-btn <?php echo $tab==='adjust'?'active':''; ?>"><i class="fas fa-sliders"></i> Stock Adjustment</a>
    </div>

    <?php if ($tab === 'stock'): ?>
    <!-- ═══ STOCK REPORT ═══ -->
    <div class="card">
      <div class="card-header"><h3><i class="fas fa-warehouse" style="color:var(--teal);"></i> Complete Stock Report</h3></div>
      <table class="table">
        <thead><tr><th>Product</th><th>HSN</th><th>Category</th><th>Batch</th><th>Stock</th><th>Reorder At</th><th>Stock Level</th><th>Selling Price</th><th>Purchase Price</th><th>GST%</th><th>Actions</th></tr></thead>
        <tbody>
        <?php mysqli_data_seek($all_products,0); while($p=mysqli_fetch_assoc($all_products)):
          $pct = $p['reorder_level'] > 0 ? min(100, ($p['quantity']/$p['reorder_level'])*50) : min(100,($p['quantity']/100)*100);
          $color = $p['quantity'] <= ($p['reorder_level']??10) ? 'var(--red)' : ($p['quantity'] <= ($p['reorder_level']??10)*2 ? 'var(--orange)' : 'var(--green)');
        ?>
        <tr>
          <td><strong><?php echo htmlspecialchars($p['fertilizer_name']); ?></strong><br><small style="color:var(--text-muted);"><?php echo htmlspecialchars($p['company_name']??''); ?></small></td>
          <td style="color:var(--text-muted);"><?php echo htmlspecialchars($p['hsn_code']??'—'); ?></td>
          <td><?php echo htmlspecialchars($p['category']??'—'); ?></td>
          <td><?php echo htmlspecialchars($p['batch_no']??'—'); ?></td>
          <td><strong style="color:<?php echo $color; ?>;"><?php echo $p['quantity']; ?></strong></td>
          <td style="color:var(--text-muted);"><?php echo $p['reorder_level']??10; ?></td>
          <td style="width:100px;"><div class="stock-bar"><div class="stock-bar-fill" style="width:<?php echo min(100,$pct); ?>%;background:<?php echo $color; ?>;"></div></div></td>
          <td>₹<?php echo number_format($p['price'],2); ?></td>
          <td>₹<?php echo number_format($p['purchase_price']??0,2); ?></td>
          <td><span class="badge badge-blue"><?php echo $p['gst_percent']??18; ?>%</span></td>
          <td>
            <a href="update_fertilizer.php?id=<?php echo $p['id']; ?>" class="btn btn-ghost" style="padding:4px 10px;font-size:11px;"><i class="fas fa-edit"></i></a>
          </td>
        </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>

    <?php elseif ($tab === 'batch'): ?>
    <!-- ═══ BATCH TRACKING ═══ -->
    <div class="card">
      <div class="card-header"><h3><i class="fas fa-tag" style="color:var(--blue);"></i> Batch Tracking</h3></div>
      <table class="table">
        <thead><tr><th>Product</th><th>Batch No</th><th>MFG Date</th><th>Expiry Date</th><th>Stock</th><th>Days to Expiry</th><th>Status</th></tr></thead>
        <tbody>
        <?php mysqli_data_seek($batch_products,0); while($p=mysqli_fetch_assoc($batch_products)):
          $days_to_exp = $p['expiry_date'] ? (int)((strtotime($p['expiry_date']) - time()) / 86400) : 9999;
          $exp_status = $days_to_exp < 0 ? 'Expired' : ($days_to_exp <= 30 ? 'Expiring Soon' : 'Good');
          $exp_class = $days_to_exp < 0 ? 'badge-red' : ($days_to_exp <= 30 ? 'badge-orange' : 'badge-green');
        ?>
        <tr>
          <td><strong><?php echo htmlspecialchars($p['fertilizer_name']); ?></strong></td>
          <td><span class="badge badge-blue"><?php echo htmlspecialchars($p['batch_no']); ?></span></td>
          <td style="color:var(--text-muted);"><?php echo $p['mfg_date'] ? date('d-M-Y',strtotime($p['mfg_date'])) : '—'; ?></td>
          <td style="color:var(--text-muted);"><?php echo $p['expiry_date'] ? date('d-M-Y',strtotime($p['expiry_date'])) : '—'; ?></td>
          <td><strong><?php echo $p['quantity']; ?> units</strong></td>
          <td style="color:<?php echo $days_to_exp<30?'var(--orange)':'var(--text-muted)'; ?>;"><?php echo $p['expiry_date'] ? $days_to_exp.' days' : '—'; ?></td>
          <td><span class="badge <?php echo $exp_class; ?>"><?php echo $exp_status; ?></span></td>
        </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>

    <?php elseif ($tab === 'expiry'): ?>
    <!-- ═══ EXPIRY TRACKING ═══ -->
    <div class="card">
      <div class="card-header"><h3><i class="fas fa-triangle-exclamation" style="color:var(--red);"></i> Expired Products</h3></div>
      <table class="table">
        <thead><tr><th>Product</th><th>Batch</th><th>Expiry Date</th><th>Days Expired</th><th>Stock</th><th>Value at Risk</th></tr></thead>
        <tbody>
        <?php if (mysqli_num_rows($expired) > 0): mysqli_data_seek($expired,0); while($p=mysqli_fetch_assoc($expired)):
          $days_exp = abs((int)((strtotime($p['expiry_date'])-time())/86400));
        ?>
        <tr>
          <td><strong style="color:var(--red);"><?php echo htmlspecialchars($p['fertilizer_name']); ?></strong></td>
          <td><?php echo htmlspecialchars($p['batch_no']??'—'); ?></td>
          <td style="color:var(--red);"><?php echo date('d-M-Y',strtotime($p['expiry_date'])); ?></td>
          <td><span class="badge badge-red"><?php echo $days_exp; ?> days ago</span></td>
          <td><?php echo $p['quantity']; ?> units</td>
          <td style="color:var(--red);">₹<?php echo number_format($p['quantity']*$p['price'],2); ?></td>
        </tr>
        <?php endwhile; else: ?>
        <tr><td colspan="6" style="text-align:center;padding:24px;color:var(--text-muted);">✅ No expired products.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="card">
      <div class="card-header"><h3><i class="fas fa-clock" style="color:var(--orange);"></i> Expiring Within 30 Days</h3></div>
      <table class="table">
        <thead><tr><th>Product</th><th>Batch</th><th>Expiry Date</th><th>Days Left</th><th>Stock</th><th>Value</th></tr></thead>
        <tbody>
        <?php if (mysqli_num_rows($expiring_30) > 0): mysqli_data_seek($expiring_30,0); while($p=mysqli_fetch_assoc($expiring_30)):
          $days_left = (int)((strtotime($p['expiry_date'])-time())/86400);
        ?>
        <tr>
          <td><strong><?php echo htmlspecialchars($p['fertilizer_name']); ?></strong></td>
          <td><?php echo htmlspecialchars($p['batch_no']??'—'); ?></td>
          <td><?php echo date('d-M-Y',strtotime($p['expiry_date'])); ?></td>
          <td><span class="badge badge-orange"><?php echo $days_left; ?> days</span></td>
          <td><?php echo $p['quantity']; ?> units</td>
          <td>₹<?php echo number_format($p['quantity']*$p['price'],2); ?></td>
        </tr>
        <?php endwhile; else: ?>
        <tr><td colspan="6" style="text-align:center;padding:24px;color:var(--text-muted);">✅ No products expiring within 30 days.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php elseif ($tab === 'adjust'): ?>
    <!-- ═══ STOCK ADJUSTMENT ═══ -->
    <div class="card">
      <div class="card-header"><h3><i class="fas fa-sliders" style="color:var(--purple);"></i> Create Stock Adjustment</h3></div>
      <div class="card-body">
        <form method="POST">
          <div class="form-row" style="margin-bottom:14px;">
            <div>
              <label style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;display:block;margin-bottom:5px;">Product *</label>
              <select name="fertilizer_id" class="form-control" required>
                <option value="">— Select Product —</option>
                <?php mysqli_data_seek($all_products,0); while($p=mysqli_fetch_assoc($all_products)): ?>
                <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['fertilizer_name']); ?> (Current: <?php echo $p['quantity']; ?>)</option>
                <?php endwhile; ?>
              </select>
            </div>
            <div>
              <label style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;display:block;margin-bottom:5px;">Type *</label>
              <select name="adj_type" class="form-control" required>
                <option value="Add">➕ Add Stock</option>
                <option value="Remove">➖ Remove Stock</option>
                <option value="Correction">🔄 Set Exact Qty (Correction)</option>
              </select>
            </div>
            <div>
              <label style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;display:block;margin-bottom:5px;">Quantity *</label>
              <input type="number" name="adj_qty" class="form-control" min="1" required placeholder="Enter qty">
            </div>
            <div>
              <label style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;display:block;margin-bottom:5px;">Reason</label>
              <input type="text" name="adj_reason" class="form-control" placeholder="e.g. Damage, Theft, Count Correction">
            </div>
          </div>
          <button type="submit" name="do_adjustment" class="btn btn-green"><i class="fas fa-check"></i> Apply Adjustment</button>
        </form>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><h3><i class="fas fa-history" style="color:var(--text-muted);"></i> Adjustment Log</h3></div>
      <table class="table">
        <thead><tr><th>Date</th><th>Product</th><th>Type</th><th>Before</th><th>Change</th><th>After</th><th>Reason</th><th>By</th></tr></thead>
        <tbody>
        <?php if (mysqli_num_rows($adjustments) > 0): while($a=mysqli_fetch_assoc($adjustments)): ?>
        <tr>
          <td style="color:var(--text-muted);font-size:12px;"><?php echo date('d-M-Y H:i',strtotime($a['created_at'])); ?></td>
          <td><strong><?php echo htmlspecialchars($a['fertilizer_name']); ?></strong></td>
          <td><span class="badge <?php echo $a['adjustment_type']==='Add'?'badge-green':($a['adjustment_type']==='Remove'?'badge-red':'badge-blue'); ?>"><?php echo $a['adjustment_type']; ?></span></td>
          <td><?php echo $a['qty_before']; ?></td>
          <td style="color:var(--orange);font-weight:700;"><?php echo $a['adjustment_type']==='Remove'?'-':'+'; ?><?php echo $a['qty_change']; ?></td>
          <td><strong><?php echo $a['qty_after']; ?></strong></td>
          <td style="color:var(--text-muted);"><?php echo htmlspecialchars($a['reason']??'—'); ?></td>
          <td style="color:var(--text-muted);"><?php echo htmlspecialchars($a['adjusted_by']??'—'); ?></td>
        </tr>
        <?php endwhile; else: ?>
        <tr><td colspan="8" style="text-align:center;padding:24px;color:var(--text-muted);">No adjustments recorded.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<script>
function toggleTheme(){const t=document.documentElement.getAttribute('data-theme')==='dark'?'light':'dark';document.documentElement.setAttribute('data-theme',t);localStorage.setItem('admin-theme',t);document.getElementById('themeIcon').className=t==='light'?'fas fa-moon':'fas fa-sun';}
(function(){const t=localStorage.getItem('admin-theme')||'dark';document.getElementById('themeIcon').className=t==='light'?'fas fa-moon':'fas fa-sun';})();
</script>
</body></html>
