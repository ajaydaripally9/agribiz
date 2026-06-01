<?php
session_start();
if (!isset($_SESSION['admin'])) { header('Location: index.php'); exit(); }
include 'db.php';
checkRole(['Admin', 'Manager']);

// AI uses simple moving average + linear regression on 60-day sales data
$days = 60;
$sales_by_day = [];
for ($i = $days - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total_price),0) as t FROM sales WHERE sale_date='$d' AND is_return IS NULL OR is_return=0"));
    $sales_by_day[] = ['date' => $d, 'amount' => floatval($r['t'])];
}

// Linear regression for forecast
$n = count($sales_by_day);
$sum_x = $sum_y = $sum_xy = $sum_x2 = 0;
foreach ($sales_by_day as $i => $d) {
    $sum_x  += $i;
    $sum_y  += $d['amount'];
    $sum_xy += $i * $d['amount'];
    $sum_x2 += $i * $i;
}
$denom = ($n * $sum_x2 - $sum_x * $sum_x);
$slope = $denom != 0 ? ($n * $sum_xy - $sum_x * $sum_y) / $denom : 0;
$intercept = ($sum_y - $slope * $sum_x) / $n;

// 7-day moving average
$ma7 = 0;
if ($n >= 7) {
    $ma7 = array_sum(array_column(array_slice($sales_by_day, -7), 'amount')) / 7;
}
$ma30 = 0;
if ($n >= 30) {
    $ma30 = array_sum(array_column(array_slice($sales_by_day, -30), 'amount')) / 30;
}

// Forecast next 14 days
$forecast = [];
for ($i = 1; $i <= 14; $i++) {
    $pred = max(0, $intercept + $slope * ($n + $i));
    $forecast[] = ['date' => date('M d', strtotime("+$i days")), 'amount' => round($pred, 2)];
}
$next_week_forecast  = array_sum(array_column(array_slice($forecast, 0, 7), 'amount'));
$next_month_forecast = $ma30 * 30; // Simple projection

// Product demand (top sellers)
$top_products = mysqli_query($conn, "
    SELECT fertilizer_name, SUM(quantity) as total_qty, SUM(total_price) as total_val
    FROM sales WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND (is_return IS NULL OR is_return=0)
    GROUP BY fertilizer_name ORDER BY total_qty DESC LIMIT 8");

// Slow movers
$slow_products = mysqli_query($conn, "
    SELECT f.fertilizer_name, f.quantity, COALESCE(s.sold_qty,0) as sold_30d
    FROM fertilizers f
    LEFT JOIN (SELECT fertilizer_name, SUM(quantity) as sold_qty FROM sales WHERE sale_date >= DATE_SUB(CURDATE(),INTERVAL 30 DAY) AND (is_return IS NULL OR is_return=0) GROUP BY fertilizer_name) s ON s.fertilizer_name=f.fertilizer_name
    ORDER BY sold_30d ASC LIMIT 5");

// Today vs yesterday
$today_sales    = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total_price),0) as t FROM sales WHERE sale_date=CURDATE() AND (is_return IS NULL OR is_return=0)"))['t'];
$yesterday_sales= mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total_price),0) as t FROM sales WHERE sale_date=DATE_SUB(CURDATE(),INTERVAL 1 DAY) AND (is_return IS NULL OR is_return=0)"))['t'];
$day_change     = $yesterday_sales > 0 ? round((($today_sales - $yesterday_sales) / $yesterday_sales) * 100, 1) : 0;

$chart_labels  = json_encode(array_column($sales_by_day, 'date'));
$chart_actual  = json_encode(array_column($sales_by_day, 'amount'));
$forecast_labels = json_encode(array_column($forecast, 'date'));
$forecast_amounts= json_encode(array_column($forecast, 'amount'));

$top_prod_labels = []; $top_prod_qty = []; $top_prod_val = [];
if ($top_products) {
    mysqli_data_seek($top_products, 0);
    while ($r = mysqli_fetch_assoc($top_products)) {
        $top_prod_labels[] = $r['fertilizer_name'];
        $top_prod_qty[]    = $r['total_qty'];
        $top_prod_val[]    = $r['total_val'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>AI Analytics — AgriBiz ERP</title>
<script>document.documentElement.setAttribute('data-theme',localStorage.getItem('admin-theme')||'dark');</script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px;position:relative;overflow:hidden;}
.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;}
.stat-card.ai::before{background:linear-gradient(90deg,var(--purple),var(--blue));}
.stat-card .lbl{font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
.stat-card .val{font-size:22px;font-weight:800;}
.stat-card .sub{font-size:11px;color:var(--text-muted);margin-top:4px;}
.chart-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:22px;margin-bottom:20px;}
.chart-card h3{font-size:14px;font-weight:700;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
.ai-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 10px;background:linear-gradient(135deg,rgba(168,85,247,.2),rgba(59,130,246,.2));border:1px solid rgba(168,85,247,.3);border-radius:20px;font-size:10px;font-weight:700;color:#c084fc;margin-left:8px;}
.table{width:100%;border-collapse:collapse;}
.table th{padding:10px 14px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;color:var(--text-muted);}
.table td{padding:10px 14px;font-size:13px;border-top:1px solid var(--border);}
.badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-green{background:rgba(34,197,94,.15);color:var(--green);}
.badge-red{background:rgba(239,68,68,.15);color:var(--red);}
.badge-purple{background:rgba(168,85,247,.15);color:var(--purple);}
.insight{background:linear-gradient(135deg,rgba(168,85,247,.06),rgba(59,130,246,.06));border:1px solid rgba(168,85,247,.2);border-radius:12px;padding:16px;margin-bottom:12px;display:flex;align-items:center;gap:14px;}
.insight i{font-size:20px;flex-shrink:0;}
.insight h4{font-size:13px;font-weight:700;margin-bottom:3px;}
.insight p{font-size:12px;color:var(--text-muted);line-height:1.4;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;border:none;text-decoration:none;transition:.2s;}
.btn-ghost{background:var(--card2);color:var(--text);border:1px solid var(--border);}
</style>
</head>
<body>
<?php include '_sidebar.php'; ?>
<div class="main">
  <div class="topbar">
    <div>
      <p style="font-size:11px;color:var(--text-muted);">AI & Machine Learning</p>
      <h1 style="font-size:18px;font-weight:800;"><i class="fas fa-robot" style="color:var(--purple);margin-right:6px;"></i>AI Analytics <span style="font-size:12px;font-weight:500;color:var(--text-muted);">— Powered by Linear Regression + Moving Average</span></h1>
    </div>
    <button class="btn btn-ghost" onclick="toggleTheme()"><i class="fas fa-sun" id="themeIcon"></i></button>
  </div>
  <div class="content">
    <!-- KPI Row -->
    <div class="stats-grid">
      <div class="stat-card ai">
        <div class="lbl">Today's Sales</div>
        <div class="val" style="color:var(--green);">₹<?php echo number_format($today_sales, 0); ?></div>
        <div class="sub" style="color:<?php echo $day_change >= 0 ? 'var(--green)' : 'var(--red)'; ?>;">
          <?php echo $day_change >= 0 ? '↑' : '↓'; ?> <?php echo abs($day_change); ?>% vs yesterday
        </div>
      </div>
      <div class="stat-card ai">
        <div class="lbl">7-Day Avg Daily</div>
        <div class="val" style="color:var(--blue);">₹<?php echo number_format($ma7, 0); ?></div>
        <div class="sub">Moving average</div>
      </div>
      <div class="stat-card ai">
        <div class="lbl">Next Week Forecast</div>
        <div class="val" style="color:var(--purple);">₹<?php echo number_format($next_week_forecast, 0); ?></div>
        <div class="sub"><span class="ai-badge">🤖 AI</span> Linear regression</div>
      </div>
      <div class="stat-card ai">
        <div class="lbl">Next Month Forecast</div>
        <div class="val" style="color:var(--orange);">₹<?php echo number_format($next_month_forecast, 0); ?></div>
        <div class="sub"><span class="ai-badge">🤖 AI</span> Based on 30-day avg</div>
      </div>
    </div>

    <!-- AI Insights -->
    <?php
    $best_product = $top_prod_labels[0] ?? 'N/A';
    $trend_txt = $slope > 50 ? "📈 Strong upward trend — consider increasing stock for peak demand." : ($slope < -50 ? "📉 Declining sales trend — review product pricing and promotions." : "➡️ Stable sales trend — business is consistent.");
    ?>
    <div style="margin-bottom:20px;">
      <div class="insight"><i class="fas fa-brain" style="color:var(--purple);"></i><div><h4>AI Insight: Sales Trend</h4><p><?php echo $trend_txt; ?> Daily sales are changing by ₹<?php echo number_format(abs($slope),2); ?>/day on average.</p></div></div>
      <div class="insight"><i class="fas fa-star" style="color:var(--orange);"></i><div><h4>Best Selling Product (30 Days)</h4><p><strong><?php echo htmlspecialchars($best_product); ?></strong> is your top mover. Ensure adequate stock levels to meet forecasted demand.</p></div></div>
      <div class="insight"><i class="fas fa-chart-line" style="color:var(--teal);"></i><div><h4>Demand Forecast</h4><p>Based on last <?php echo $days; ?> days of data, expected daily revenue next week: <strong>₹<?php echo number_format($next_week_forecast/7, 0); ?>/day</strong>.</p></div></div>
    </div>

    <!-- Charts -->
    <div class="chart-card">
      <h3><i class="fas fa-chart-area" style="color:var(--blue);"></i> Historical Sales (60 Days) + 14-Day Forecast <span class="ai-badge">🤖 AI Forecast</span></h3>
      <canvas id="forecastChart" height="80"></canvas>
    </div>

    <div class="two-col">
      <div class="chart-card">
        <h3><i class="fas fa-trophy" style="color:var(--orange);"></i> Top Products (30 Days)</h3>
        <canvas id="topProductsChart" height="180"></canvas>
      </div>
      <div class="chart-card">
        <h3><i class="fas fa-exclamation-triangle" style="color:var(--red);"></i> Slow Moving Products</h3>
        <table class="table">
          <thead><tr><th>Product</th><th>Stock</th><th>Sold (30d)</th><th>Alert</th></tr></thead>
          <tbody>
          <?php if ($slow_products): mysqli_data_seek($slow_products,0); while($p=mysqli_fetch_assoc($slow_products)): ?>
          <tr>
            <td><?php echo htmlspecialchars($p['fertilizer_name']); ?></td>
            <td><?php echo $p['quantity']; ?></td>
            <td style="color:var(--text-muted);"><?php echo $p['sold_30d']; ?></td>
            <td>
              <?php if ($p['sold_30d'] == 0): ?>
              <span class="badge badge-red">Dead Stock</span>
              <?php elseif ($p['sold_30d'] < 5): ?>
              <span class="badge badge-purple">Slow Mover</span>
              <?php else: ?>
              <span class="badge badge-green">Normal</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endwhile; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
const isLight = () => localStorage.getItem('admin-theme') === 'light';
const gridColor = () => isLight() ? 'rgba(0,0,0,.06)' : 'rgba(255,255,255,.06)';
const tickColor = () => isLight() ? '#64748b' : '#8b949e';

// Combined Actual + Forecast chart
const actualLabels = <?php echo $chart_labels; ?>.map(d => d.slice(5)); // M-D only
const forecastLabels = <?php echo $forecast_labels; ?>;
const allLabels = [...actualLabels, ...forecastLabels];

const actualData = <?php echo $chart_actual; ?>;
const forecastData = <?php echo $forecast_amounts; ?>;

// Pad actual with nulls for forecast dates
const actualPadded = [...actualData, ...Array(forecastLabels.length).fill(null)];
const forecastPadded = [...Array(actualLabels.length - 1).fill(null), actualData[actualData.length-1], ...forecastData];

new Chart(document.getElementById('forecastChart'), {
  type: 'line',
  data: {
    labels: allLabels,
    datasets: [
      {
        label: 'Actual Sales (₹)',
        data: actualPadded,
        borderColor: '#22c55e',
        backgroundColor: 'rgba(34,197,94,0.08)',
        borderWidth: 2,
        pointRadius: 0,
        fill: true,
        tension: 0.4
      },
      {
        label: 'AI Forecast (₹)',
        data: forecastPadded,
        borderColor: '#a855f7',
        backgroundColor: 'rgba(168,85,247,0.08)',
        borderWidth: 2,
        borderDash: [6,4],
        pointRadius: 3,
        pointBackgroundColor: '#a855f7',
        fill: true,
        tension: 0.4
      }
    ]
  },
  options: {
    responsive: true,
    plugins: {
      legend: { display: true, labels: { color: tickColor(), font: { size: 12 } } },
      tooltip: { callbacks: { label: ctx => '₹' + ctx.raw?.toLocaleString() } }
    },
    scales: {
      x: { grid: { color: gridColor() }, ticks: { color: tickColor(), maxTicksLimit: 15 } },
      y: { grid: { color: gridColor() }, ticks: { color: tickColor(), callback: v => '₹' + v.toLocaleString() }, beginAtZero: true }
    }
  }
});

// Top Products Bar
new Chart(document.getElementById('topProductsChart'), {
  type: 'bar',
  data: {
    labels: <?php echo json_encode($top_prod_labels); ?>,
    datasets: [{
      label: 'Units Sold',
      data: <?php echo json_encode($top_prod_qty); ?>,
      backgroundColor: ['#22c55e','#a855f7','#3b82f6','#f59e0b','#14b8a6','#ef4444','#84cc16','#f43f5e'].slice(0, <?php echo count($top_prod_labels); ?>),
      borderRadius: 6
    }]
  },
  options: {
    indexAxis: 'y',
    responsive: true,
    plugins: { legend: { display: false } },
    scales: {
      x: { grid: { color: gridColor() }, ticks: { color: tickColor() } },
      y: { grid: { display: false }, ticks: { color: tickColor(), font: { size: 11 } } }
    }
  }
});

function toggleTheme(){const t=document.documentElement.getAttribute('data-theme')==='dark'?'light':'dark';document.documentElement.setAttribute('data-theme',t);localStorage.setItem('admin-theme',t);document.getElementById('themeIcon').className=t==='light'?'fas fa-moon':'fas fa-sun';}
(function(){const t=localStorage.getItem('admin-theme')||'dark';document.getElementById('themeIcon').className=t==='light'?'fas fa-moon':'fas fa-sun';})();
</script>
</body></html>
