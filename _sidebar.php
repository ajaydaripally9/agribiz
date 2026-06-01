<?php
/**
 * _sidebar.php — Shared ERP sidebar navigation
 * Usage: include '_sidebar.php'; (after session_start + include 'db.php')
 */
$_role     = $_SESSION['admin_role'] ?? 'Admin';
$_page     = basename($_SERVER['PHP_SELF']);
$_shop     = '';
$_shop_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT shop_name FROM admin LIMIT 1"));
if ($_shop_row) $_shop = htmlspecialchars($_shop_row['shop_name'] ?? 'AgriBiz Pro');
if (!$_shop) $_shop = 'AgriBiz Pro';

// Count pending orders for badge
$_pending = 0;
$_pq = mysqli_query($conn, "SELECT COUNT(*) as c FROM orders WHERE status='Pending'");
if ($_pq) $_pending = mysqli_fetch_assoc($_pq)['c'];

// Count low stock
$_low_stock = 0;
$_lq = mysqli_query($conn, "SELECT COUNT(*) as c FROM fertilizers WHERE quantity <= COALESCE(reorder_level, 10)");
if ($_lq) $_low_stock = mysqli_fetch_assoc($_lq)['c'];

// Count expiring within 30 days
$_expiring = 0;
$_eq = mysqli_query($conn, "SELECT COUNT(*) as c FROM fertilizers WHERE expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND expiry_date >= CURDATE()");
if ($_eq) $_expiring = mysqli_fetch_assoc($_eq)['c'];

function _nav($href, $icon, $label, $badge = 0, $color = 'green') {
    global $_page;
    $active = (basename($href) === $_page || strpos($_page, basename($href, '.php')) !== false) ? 'active' : '';
    $badge_html = $badge > 0 ? "<span style='background:var(--red);color:#fff;border-radius:10px;padding:1px 7px;font-size:10px;margin-left:auto;'>{$badge}</span>" : '';
    echo "<a href='{$href}' class='nav-item {$active}'><i class='fas {$icon}'></i> {$label}{$badge_html}</a>";
}
?>
<aside class="sidebar">
  <audio id="notifSound" src="https://assets.mixkit.co/active_storage/sfx/2869/2869-preview.mp3" preload="auto"></audio>
  <div class="sidebar-logo">
    <div style="display:flex;align-items:center;gap:8px;">
      <i class="fas fa-seedling" style="color:var(--green);font-size:20px;"></i>
      <div>
        <h2 style="font-size:15px;font-weight:800;color:var(--text);"><?php echo $_shop; ?></h2>
        <p style="font-size:10px;color:var(--text-muted);">ERP System</p>
      </div>
    </div>
  </div>
  <nav class="sidebar-nav" style="overflow-y:auto;flex:1;">
    <?php _nav('dashboard.php', 'fa-home', 'Dashboard', $_pending); ?>

    <div class="nav-section-label">Masters</div>
    <?php _nav('view_fertilizer.php', 'fa-flask', 'Products', $_low_stock > 0 ? $_low_stock : 0); ?>
    <?php _nav('add_fertilizer.php', 'fa-plus-circle', 'Add Product'); ?>
    <?php _nav('customers.php', 'fa-users', 'Customers'); ?>
    <?php _nav('suppliers.php', 'fa-truck', 'Suppliers'); ?>

    <div class="nav-section-label">Transactions</div>
    <?php _nav('sales_invoices.php', 'fa-file-invoice', 'Sales Invoice'); ?>
    <?php _nav('purchases.php', 'fa-shopping-cart', 'Purchase Invoice'); ?>
    <?php _nav('sales_return.php', 'fa-rotate-left', 'Sales Return'); ?>
    <?php _nav('purchase_return.php', 'fa-rotate-right', 'Purchase Return'); ?>

    <div class="nav-section-label">Inventory</div>
    <?php _nav('inventory.php', 'fa-boxes-stacking', 'Stock & Inventory', $_expiring > 0 ? $_expiring : 0); ?>

    <div class="nav-section-label">Accounts</div>
    <?php _nav('receipts_payments.php', 'fa-money-bill-transfer', 'Vouchers Entry'); ?>
    <?php _nav('accounting_books.php', 'fa-book', 'Books & Ledgers'); ?>
    <?php _nav('master_ledger.php', 'fa-file-invoice-dollar', 'Master Ledger'); ?>
    <?php _nav('gst_intel.php', 'fa-search-dollar', 'GST Intelligence'); ?>
    <?php _nav('gst_reports.php', 'fa-file-excel', 'GST Reports'); ?>

    <div class="nav-section-label">Reports</div>
    <?php _nav('collection_dashboard.php', 'fa-money-bill-trend-up', 'Outstanding'); ?>
    <?php _nav('reports.php', 'fa-chart-line', 'Reports'); ?>

    <div class="nav-section-label">AI Analytics</div>
    <?php _nav('ai_analytics.php', 'fa-robot', 'AI Forecast'); ?>

    <?php if ($_role === 'Admin' || $_role === 'Manager'): ?>
    <div class="nav-section-label">Administration</div>
    <?php _nav('users.php', 'fa-user-shield', 'User Management'); ?>
    <?php _nav('audit_log.php', 'fa-shield-halved', 'Audit Logs'); ?>
    <?php _nav('backup.php', 'fa-database', 'Backup & Restore'); ?>
    <?php _nav('erp_migrate.php', 'fa-screwdriver-wrench', 'Run Migrations'); ?>
    <?php endif; ?>
  </nav>

  <div style="margin:10px;background:linear-gradient(135deg,#064e3b,#065f46);border-radius:12px;padding:14px;border:1px solid #059669;">
    <h4 style="font-size:12px;font-weight:700;color:#fff;margin-bottom:2px;">AgriBiz Pro ERP</h4>
    <p style="font-size:10px;color:#6ee7b7;">Grow More, Manage Smart</p>
  </div>

  <div style="padding:14px;border-top:1px solid var(--border);">
    <a href="logout.php" style="display:flex;align-items:center;gap:8px;color:var(--red);text-decoration:none;font-size:13px;font-weight:500;">
      <i class="fas fa-sign-out-alt"></i> Logout
    </a>
  </div>
</aside>
