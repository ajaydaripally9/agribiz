<?php
session_start();
if (!isset($_SESSION['customer'])) {
    header('Location: index.php');
    exit();
}
include 'db.php';

$customer_id = intval($_SESSION['customer_id']);
$customer_query = "SELECT * FROM customers WHERE id = $customer_id";
$customer_result = mysqli_query($conn, $customer_query);
$customer = mysqli_fetch_assoc($customer_result);

if (!$customer) {
    session_unset();
    session_destroy();
    header('Location: index.php');
    exit();
}

// Fetch all products
$fert_query = "SELECT * FROM fertilizers WHERE quantity > 0";
$fert_result = mysqli_query($conn, $fert_query);
$ferts_arr = [];
while ($f = mysqli_fetch_assoc($fert_result)) {
    // Read category from database
    $cat = $f['category'] ? $f['category'] : 'Fertilizers';
    
    // Assign icon based on category
    $icon = '🧪';
    if ($cat == 'Seeds') $icon = '🌱';
    elseif ($cat == 'Pesticides') $icon = '🚿';
    elseif ($cat == 'Organic') $icon = '🍃';
    elseif ($cat == 'Tools') $icon = '⚙️';
    
    $f['category'] = $cat;
    $f['icon'] = $icon;
    $f['rating'] = number_format(rand(40, 50) / 10, 1); // Mock rating 4.0 - 5.0
    $f['reviews'] = rand(10, 500);
    $ferts_arr[] = $f;
}

$message = '';
$msg_type = 'success';

// Handle Order Processing
if (isset($_POST['buy'])) {
    $fertilizers = $_POST['fertilizer'];
    $quantities = $_POST['quantity'];
    
    $all_in_stock = true;
    $items_to_order = [];

    for ($i = 0; $i < count($fertilizers); $i++) {
        $fertilizer_id = intval($fertilizers[$i]);
        $quantity = intval($quantities[$i]);

        if ($fertilizer_id > 0 && $quantity > 0) {
            $fert_stmt = mysqli_prepare($conn, "SELECT * FROM fertilizers WHERE id = ?");
            mysqli_stmt_bind_param($fert_stmt, "i", $fertilizer_id);
            mysqli_stmt_execute($fert_stmt);
            $fert_details = mysqli_fetch_assoc(mysqli_stmt_get_result($fert_stmt));
            mysqli_stmt_close($fert_stmt);

            if (!$fert_details) {
                $message = "One of the selected products was not found.";
                $msg_type = "error";
                $all_in_stock = false;
                break;
            } elseif ($quantity > $fert_details['quantity']) {
                $message = "Insufficient stock for " . htmlspecialchars($fert_details['fertilizer_name']) . ". Available: " . $fert_details['quantity'];
                $msg_type = "error";
                $all_in_stock = false;
                break;
            } else {
                $items_to_order[] = [
                    'fertilizer_id' => $fertilizer_id,
                    'fertilizer_name' => $fert_details['fertilizer_name'],
                    'price' => $fert_details['price'],
                    'quantity' => $quantity,
                    'total' => $quantity * $fert_details['price']
                ];
            }
        }
    }

    if ($all_in_stock && count($items_to_order) > 0) {
        $customer_name = $customer['customer_name'];

        // Sequential invoice: use MAX(id)+1 from orders table
        $seq_result = mysqli_query($conn, "SELECT COALESCE(MAX(id), 0) + 1 AS next_seq FROM orders");
        $seq_row = mysqli_fetch_assoc($seq_result);
        $seq = str_pad($seq_row['next_seq'], 4, '0', STR_PAD_LEFT);
        $invoice_no = 'ORD-' . date('Y') . '-' . date('m') . '-' . $seq;

        $insert_stmt = mysqli_prepare($conn, "INSERT INTO orders (customer_id, customer_name, fertilizer_id, fertilizer_name, quantity, total_price, order_date, status, invoice_no) VALUES (?, ?, ?, ?, ?, ?, CURDATE(), 'Pending', ?)");

        $success = true;
        $total_order_value = 0;
        foreach ($items_to_order as $item) {
            mysqli_stmt_bind_param($insert_stmt, "isisids", $customer_id, $customer_name, $item['fertilizer_id'], $item['fertilizer_name'], $item['quantity'], $item['total'], $invoice_no);
            if (!mysqli_stmt_execute($insert_stmt)) {
                $success = false;
                header('Location: customer_shop.php?status=error&msg=' . urlencode('Error placing order: ' . mysqli_error($conn)));
                exit();
            }
            // Deduct stock
            mysqli_query($conn, "UPDATE fertilizers SET quantity = quantity - {$item['quantity']} WHERE id = {$item['fertilizer_id']}");
            $total_order_value += $item['total'];
        }
        mysqli_stmt_close($insert_stmt);

        if ($success) {
            // Award loyalty points (1 point per 100 Rs)
            $earned_points = floor($total_order_value / 100);
            mysqli_query($conn, "UPDATE customers SET points = points + $earned_points WHERE id = $customer_id");
            mysqli_query($conn, "UPDATE orders SET points_earned = $earned_points WHERE invoice_no = '$invoice_no'");
            
            // PRG: Redirect after POST to prevent duplicate submission
            header("Location: customer_shop.php?status=success&invoice=" . urlencode($invoice_no) . "&earned=" . $earned_points);
            exit();
        }
    }
}

// Read message from redirect query params (PRG pattern)
if (isset($_GET['status'])) {
    if ($_GET['status'] === 'success' && isset($_GET['invoice'])) {
        $earned = isset($_GET['earned']) ? " (Earned ".$_GET['earned']." Coins! 💎)" : "";
        $message = 'Order placed successfully! Invoice No: ' . htmlspecialchars($_GET['invoice']) . $earned;
        $msg_type = 'success';
    } elseif ($_GET['status'] === 'error' && isset($_GET['msg'])) {
        $message = htmlspecialchars($_GET['msg']);
        $msg_type = 'error';
    }
}

// Fetch order history
$history_stmt = mysqli_prepare($conn, "SELECT invoice_no, order_date, status, COUNT(id) as total_items, SUM(total_price) as grand_total, GROUP_CONCAT(fertilizer_name SEPARATOR ', ') as product_names FROM orders WHERE customer_id = ? GROUP BY invoice_no ORDER BY id DESC");
mysqli_stmt_bind_param($history_stmt, "i", $customer_id);
mysqli_stmt_execute($history_stmt);
$order_history = mysqli_stmt_get_result($history_stmt);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>AgriBiz - Premium Farmer Marketplace</title>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#10B981">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="GreenGrow">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* Base Variables & Reset */
* { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; -webkit-tap-highlight-color: transparent; }
:root {
  --bg: #F5F7FA; --white: #ffffff;
  --primary: #10B981; --primary-dark: #059669; --primary-light: #D1FAE5;
  --accent: #F59E0B;
  --text-dark: #1F2937; --text-gray: #6B7280; --text-light: #9CA3AF;
  --border: #E5E7EB;
  --red: #ef4444; --yellow: #f59e0b;
  --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
  --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
  --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
  --radius: 12px;
}
/* Dark Mode */
[data-theme="dark"] {
  --bg: #0d1117; --white: #161b22;
  --primary: #22c55e; --primary-dark: #16a34a; --primary-light: rgba(34,197,94,0.1);
  --text-dark: #e6edf3; --text-gray: #8b949e; --text-light: #6e7681;
  --border: #30363d;
  --shadow-sm: 0 1px 2px 0 rgba(0,0,0,0.4);
  --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.5);
}
body { background: var(--bg); color: var(--text-dark); padding-bottom: 80px; }

/* Utilities */
.container { max-width: 1200px; margin: 0 auto; padding: 0 16px; }
.section-title { font-size: 18px; font-weight: 700; color: var(--text-dark); margin: 24px 0 16px; display: flex; align-items: center; justify-content: space-between; }
.section-title span { font-size: 14px; font-weight: 500; color: var(--primary); cursor: pointer; }

/* Header & Search */
.header { background: var(--white); padding: 12px 16px; position: sticky; top: 0; z-index: 100; box-shadow: var(--shadow-sm); }
.header-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
.logo { display: flex; align-items: center; gap: 8px; font-size: 20px; font-weight: 800; color: var(--primary-dark); text-decoration: none; }
.header-icons { display: flex; gap: 16px; font-size: 20px; color: var(--text-gray); }
.search-container { display: flex; gap: 8px; }
.search-box { flex: 1; display: flex; align-items: center; background: #F3F4F6; border-radius: 8px; padding: 8px 12px; border: 1px solid transparent; transition: 0.2s; }
.search-box:focus-within { border-color: var(--primary); background: var(--white); box-shadow: 0 0 0 3px var(--primary-light); }
.search-box i { color: var(--text-light); font-size: 16px; }
.search-box input { border: none; background: transparent; padding: 0 10px; width: 100%; outline: none; font-size: 14px; color: var(--text-dark); }
.voice-search { background: var(--primary-light); color: var(--primary-dark); border: none; width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 18px; cursor: pointer; transition: 0.2s; position: relative; }
.voice-search:hover { background: var(--primary); color: white; }
.voice-search.recording { background: #FEE2E2; color: #DC2626; animation: pulse 1s infinite; box-shadow: 0 0 0 4px rgba(220,38,38,0.2); }
@keyframes pulse { 0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(220,38,38,0.4); } 70% { transform: scale(1.08); box-shadow: 0 0 0 8px rgba(220,38,38,0); } 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(220,38,38,0); } }

/* Voice Toast */
#voiceToast { position: fixed; bottom: 90px; left: 50%; transform: translateX(-50%) translateY(20px); background: #1F2937; color: white; padding: 10px 20px; border-radius: 24px; font-size: 13px; font-weight: 600; opacity: 0; pointer-events: none; transition: all 0.3s ease; z-index: 9999; white-space: nowrap; display: flex; align-items: center; gap: 8px; }
#voiceToast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

/* Hero Banners */
.hero-slider { display: flex; overflow-x: auto; scroll-snap-type: x mandatory; gap: 16px; padding: 16px 0; -webkit-overflow-scrolling: touch; scrollbar-width: none; }
.hero-slider::-webkit-scrollbar { display: none; }
.hero-card { min-width: 85vw; scroll-snap-align: center; border-radius: 16px; padding: 24px; color: var(--white); position: relative; overflow: hidden; display: flex; flex-direction: column; justify-content: center; }
@media(min-width:768px){ .hero-card { min-width: 400px; } }
.hero-1 { background: linear-gradient(135deg, #059669, #10B981); }
.hero-2 { background: linear-gradient(135deg, #D97706, #F59E0B); }
.hero-3 { background: linear-gradient(135deg, #4F46E5, #6366F1); }
.hero-card h3 { font-size: 22px; font-weight: 800; margin-bottom: 8px; z-index: 2; }
.hero-card p { font-size: 14px; opacity: 0.9; margin-bottom: 16px; z-index: 2; }
.hero-btn { background: var(--white); color: var(--text-dark); border: none; padding: 8px 16px; border-radius: 20px; font-size: 12px; font-weight: 700; width: fit-content; z-index: 2; cursor: pointer; }
.hero-icon { position: absolute; right: -10px; bottom: -20px; font-size: 100px; opacity: 0.2; transform: rotate(-15deg); }

/* Categories */
.category-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(80px, 1fr)); gap: 12px; margin-top: 8px; }
.category-item { display: flex; flex-direction: column; align-items: center; gap: 8px; cursor: pointer; }
.category-icon { width: 60px; height: 60px; background: var(--white); border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 28px; box-shadow: var(--shadow-sm); border: 1px solid var(--border); transition: 0.2s; }
.category-item:hover .category-icon { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light); transform: translateY(-2px); }
.category-item.active .category-icon { background: var(--primary); color: white; border-color: var(--primary); }
.category-name { font-size: 12px; font-weight: 600; text-align: center; color: var(--text-dark); }

/* AI Assistant Card */
.ai-card { background: linear-gradient(135deg, #ECFDF5, #ffffff); border: 1px solid #A7F3D0; border-radius: 16px; padding: 16px; margin: 24px 0; display: flex; gap: 16px; align-items: center; box-shadow: var(--shadow-sm); cursor: pointer; }
.ai-icon { width: 48px; height: 48px; background: var(--primary-light); color: var(--primary-dark); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 24px; flex-shrink: 0; }
.ai-content h4 { font-size: 15px; font-weight: 700; color: var(--primary-dark); margin-bottom: 4px; }
.ai-content p { font-size: 12px; color: var(--text-gray); line-height: 1.4; }

/* Product Grid */
.product-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
@media(min-width:768px){ .product-grid { grid-template-columns: repeat(4, 1fr); gap: 20px; } }
.product-card { background: var(--white); border-radius: var(--radius); border: 1px solid var(--border); overflow: hidden; display: flex; flex-direction: column; position: relative; transition: 0.2s; box-shadow: var(--shadow-sm); }
.product-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); border-color: #A7F3D0; }
.product-image { height: 120px; background: #F9FAFB; display: flex; align-items: center; justify-content: center; font-size: 60px; border-bottom: 1px solid var(--border); position: relative; }
.product-tag { position: absolute; top: 8px; left: 8px; background: var(--accent); color: white; font-size: 10px; font-weight: 700; padding: 3px 8px; border-radius: 4px; }
.product-info { padding: 12px; flex: 1; display: flex; flex-direction: column; }
.product-title { font-size: 13px; font-weight: 600; line-height: 1.3; margin-bottom: 4px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.product-rating { display: flex; align-items: center; gap: 4px; font-size: 11px; color: var(--text-gray); margin-bottom: 8px; }
.product-rating i { color: var(--accent); font-size: 10px; }
.product-price-row { display: flex; justify-content: space-between; align-items: flex-end; margin-top: auto; }
.product-price { font-size: 16px; font-weight: 800; color: var(--text-dark); }
.product-price span { font-size: 11px; font-weight: 500; color: var(--text-gray); text-decoration: line-through; margin-left: 4px; }
.add-btn { background: var(--white); border: 1px solid var(--primary); color: var(--primary); width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 16px; cursor: pointer; transition: 0.2s; }
.add-btn:hover, .add-btn.added { background: var(--primary); color: white; }

/* Bottom Nav */
.bottom-nav { position: fixed; bottom: 0; left: 0; right: 0; background: var(--white); border-top: 1px solid var(--border); display: flex; justify-content: space-around; padding: 10px 0; padding-bottom: calc(10px + env(safe-area-inset-bottom)); box-shadow: 0 -4px 6px -1px rgba(0,0,0,0.05); z-index: 100; }
.nav-item { display: flex; flex-direction: column; align-items: center; gap: 4px; color: var(--text-gray); text-decoration: none; position: relative; width: 20%; }
.nav-item.active { color: var(--primary); }
.nav-item i { font-size: 20px; }
.nav-item span { font-size: 10px; font-weight: 600; }
.cart-badge { position: absolute; top: -4px; right: 20%; background: var(--accent); color: white; font-size: 9px; font-weight: 700; width: 16px; height: 16px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid var(--white); }

/* Cart Drawer */
.cart-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; opacity: 0; pointer-events: none; transition: 0.3s; }
.cart-drawer { position: fixed; bottom: 0; left: 0; right: 0; background: var(--white); border-radius: 20px 20px 0 0; z-index: 201; transform: translateY(100%); transition: 0.3s cubic-bezier(0.4, 0, 0.2, 1); max-height: 90vh; display: flex; flex-direction: column; }
.cart-overlay.active { opacity: 1; pointer-events: all; }
.cart-drawer.active { transform: translateY(0); }
.cart-header { padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
.cart-header h3 { font-size: 16px; font-weight: 700; }
.close-cart { background: none; border: none; font-size: 20px; color: var(--text-gray); cursor: pointer; }
.cart-items { flex: 1; overflow-y: auto; padding: 16px 20px; }
.cart-item { display: flex; gap: 12px; margin-bottom: 16px; border-bottom: 1px solid var(--border); padding-bottom: 16px; }
.cart-item-img { width: 60px; height: 60px; background: #F3F4F6; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 30px; }
.cart-item-info { flex: 1; }
.cart-item-name { font-size: 13px; font-weight: 600; margin-bottom: 4px; }
.cart-item-price { font-size: 14px; font-weight: 700; color: var(--primary); }
.cart-qty-ctrl { display: flex; align-items: center; gap: 12px; margin-top: 8px; }
.qty-btn { background: #F3F4F6; border: none; width: 28px; height: 28px; border-radius: 6px; font-size: 16px; font-weight: bold; cursor: pointer; }
.cart-footer { padding: 20px; border-top: 1px solid var(--border); background: var(--white); }
.cart-total-row { display: flex; justify-content: space-between; font-size: 16px; font-weight: 700; margin-bottom: 16px; }
.checkout-btn { width: 100%; background: var(--primary); color: white; border: none; padding: 14px; border-radius: 12px; font-size: 15px; font-weight: 700; display: flex; justify-content: space-between; cursor: pointer; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); }

/* AI Modal */
.ai-modal { display: none; position: fixed; inset: 0; background: var(--white); z-index: 300; flex-direction: column; }
.ai-modal.active { display: flex; }
.ai-header { padding: 16px; background: linear-gradient(135deg, #ECFDF5, #D1FAE5); display: flex; align-items: center; gap: 12px; }
.ai-header i { font-size: 24px; color: var(--primary-dark); }
.ai-body { padding: 20px; flex: 1; overflow-y: auto; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: var(--text-gray); }
.form-group select, .form-group textarea { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 8px; font-size: 14px; outline: none; background: #F9FAFB; }
.form-group select:focus { border-color: var(--primary); }
.ai-btn { background: var(--primary); color: white; width: 100%; padding: 14px; border-radius: 8px; border: none; font-size: 15px; font-weight: 700; cursor: pointer; margin-top: 10px; }
#aiResult { margin-top: 20px; padding: 16px; background: #F0FDF4; border: 1px dashed #34D399; border-radius: 8px; display: none; }

/* Alert */
.alert { padding: 12px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
.alert.success { background: #ECFDF5; color: #059669; border: 1px solid #A7F3D0; }
.alert.error { background: #FEF2F2; color: #DC2626; border: 1px solid #FECACA; }

/* Order Timeline */
.order-timeline { display: flex; align-items: center; margin-top: 12px; padding-top: 12px; border-top: 1px dashed #E5E7EB; }
.tl-step { display: flex; flex-direction: column; align-items: center; flex: 1; position: relative; }
.tl-step:not(:last-child)::after { content: ''; position: absolute; top: 14px; left: 60%; width: 80%; height: 2px; background: #E5E7EB; z-index: 0; }
.tl-step.done:not(:last-child)::after { background: #10B981; }
.tl-step.fail:not(:last-child)::after { background: #EF4444; }
.tl-dot { width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11px; z-index: 1; border: 2px solid #E5E7EB; background: #fff; color: #9CA3AF; transition: all 0.3s; }
.tl-step.done .tl-dot { background: #10B981; border-color: #10B981; color: #fff; }
.tl-step.fail .tl-dot { background: #EF4444; border-color: #EF4444; color: #fff; }
.tl-step.active .tl-dot { background: #F59E0B; border-color: #F59E0B; color: #fff; animation: tlpulse 1.5s infinite; }
.tl-step.ship .tl-dot { background: #3B82F6; border-color: #3B82F6; color: #fff; animation: tlpulse-blue 1.5s infinite; }
@keyframes tlpulse { 0%,100% { box-shadow: 0 0 0 0 rgba(245,158,11,0.4); } 50% { box-shadow: 0 0 0 6px rgba(245,158,11,0); } }
@keyframes tlpulse-blue { 0%,100% { box-shadow: 0 0 0 0 rgba(59,130,246,0.4); } 50% { box-shadow: 0 0 0 6px rgba(59,130,246,0); } }
.tl-label { font-size: 9px; font-weight: 600; color: #9CA3AF; margin-top: 4px; text-align: center; }
.tl-step.done .tl-label, .tl-step.active .tl-label, .tl-step.ship .tl-label { color: #374151; }

/* Dark Mode Toggle */
.dark-toggle { background: var(--white); border: 1px solid var(--border); border-radius: 8px; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 16px; transition: 0.2s; }
.dark-toggle:hover { background: var(--primary-light); }
</style>
</head>
<body>

<div class="header">
  <div class="header-top">
    <a href="#" class="logo">
      <i class="fas fa-leaf" style="color:var(--primary);"></i> AgriBiz
    </a>
    <div class="header-icons">
      <select id="langToggle" onchange="changeLang(this.value)" style="background:var(--primary-light); color:var(--primary-dark); border:none; border-radius:12px; padding:4px 8px; font-size:11px; font-weight:800; outline:none; cursor:pointer; height:36px;">
        <option value="en">🇺🇸 EN</option>
        <option value="hi">🇮🇳 HI</option>
        <option value="te">🇮🇳 TE</option>
      </select>
      <div style="background:var(--primary-light); color:var(--primary-dark); font-size:11px; font-weight:800; padding:4px 10px; border-radius:20px; display:flex; align-items:center; gap:4px; height:36px;"><i class="fas fa-gem" style="color:#3b82f6;"></i> <?php echo number_format($customer['points']); ?></div>
      <button class="dark-toggle" id="darkToggle" onclick="toggleDark()" title="Toggle Dark Mode">🌙</button>
      <i class="far fa-bell"></i>
      <i class="far fa-user-circle" onclick="window.location='customer_profile.php'" style="cursor:pointer;"></i>
    </div>
  </div>

  <!-- THINK BIG: MANDI INTEL & WEATHER HUB -->
  <div style="padding:0 20px; margin-bottom:25px;">
    <div style="background: linear-gradient(135deg, #1e293b, #0f172a); border-radius:24px; padding:20px; color:white; box-shadow:0 15px 35px rgba(0,0,0,0.2); position:relative; overflow:hidden;">
      <div style="position:absolute; top:-20px; right:-20px; font-size:120px; opacity:0.1; color:#22c55e;"><i class="fas fa-chart-line"></i></div>
      
      <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
        <!-- Mandi Intel -->
        <div>
          <div style="display:flex; align-items:center; gap:8px; margin-bottom:15px;">
            <div style="width:35px; height:35px; background:rgba(34,197,94,0.2); border-radius:10px; display:flex; align-items:center; justify-content:center; color:#22c55e;"><i class="fas fa-landmark"></i></div>
            <h3 style="font-size:16px; font-weight:800;" id="mandiTitle">Live Mandi Prices</h3>
          </div>
          <div style="display:flex; flex-direction:column; gap:10px;" id="mandiList">
            <div style="font-size:12px; opacity:0.5;">Loading Siddipet Mandi data...</div>
          </div>
        </div>

        <!-- Weather advisory -->
        <div style="border-left:1px solid rgba(255,255,255,0.1); padding-left:20px;">
          <div style="display:flex; align-items:center; gap:8px; margin-bottom:15px;">
            <div style="width:35px; height:35px; background:rgba(59,130,246,0.2); border-radius:10px; display:flex; align-items:center; justify-content:center; color:#3b82f6;"><i class="fas fa-cloud-sun-rain"></i></div>
            <h3 style="font-size:16px; font-weight:800;" id="weatherTitle">Agri-Weather AI</h3>
          </div>
          <div style="background:rgba(59,130,246,0.1); border:1px solid rgba(59,130,246,0.2); padding:12px; border-radius:18px;">
          <div style="background:rgba(255,255,255,0.05); padding:15px; border-radius:18px; border:1px solid rgba(255,255,255,0.1);">
            <div style="font-size:12px; font-weight:700; color:#60a5fa; margin-bottom:4px;" id="sprayTitle"><i class="fas fa-info-circle"></i> SPRAYING ADVISORY</div>
            <div style="font-size:13px; line-height:1.4; font-weight:500;" id="sprayDesc">Checking weather conditions for Siddipet...</div>
          </div>
          </div>
        </div>
      </div>
      
      <div style="margin-top:15px; text-align:center; border-top:1px solid rgba(255,255,255,0.05); padding-top:10px;">
        <button onclick="openMandiModal()" style="background:none; border:none; color:#22c55e; font-size:12px; font-weight:700; cursor:pointer;" id="viewAllBtn">VIEW ALL MARKETS <i class="fas fa-arrow-right"></i></button>
      </div>
    </div>
  </div>

  </div>

  <!-- THINK BIG: REFERRAL ENGINE -->
  <div style="padding:0 20px; margin-bottom:25px;">
    <div style="background: linear-gradient(135deg, #10b981, #059669); border-radius:24px; padding:20px; color:white; display:flex; align-items:center; justify-content:space-between; box-shadow:0 10px 25px rgba(16,185,129,0.2);">
      <div style="flex:1;">
        <h3 style="font-size:16px; font-weight:800;">Invite a Farmer Friend 🤝</h3>
        <p style="font-size:12px; opacity:0.9; margin-top:4px;">Refer a friend and you both get **50 Agri-Coins**!</p>
        <button onclick="alert('Referral Code: AGRI-'+Math.floor(Math.random()*9999))" style="margin-top:12px; background:white; color:#059669; border:none; padding:8px 16px; border-radius:12px; font-size:12px; font-weight:800; cursor:pointer;">SHARE CODE <i class="fas fa-share-alt"></i></button>
      </div>
      <div style="font-size:50px; opacity:0.3;"><i class="fas fa-users"></i></div>
    </div>
  </div>

  <!-- THINK BIG: COMMUNITY GREEN WALL -->
  <div class="section-header" style="padding:0 20px; display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
    <h2 class="section-title">Community Green Wall 🌿</h2>
    <button style="background:none; border:none; color:var(--primary); font-size:12px; font-weight:700;">POST SUCCESS <i class="fas fa-plus"></i></button>
  </div>
  
  <div style="display:flex; gap:15px; overflow-x:auto; padding:0 20px 20px; scroll-behavior:smooth;">
    <!-- Post 1 -->
    <div style="min-width:280px; background:white; border-radius:24px; padding:15px; border:1px solid #e2e8f0; box-shadow:0 4px 12px rgba(0,0,0,0.05);">
      <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
        <div style="width:35px; height:35px; background:#f1f5f9; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:800; color:#059669;">SK</div>
        <div>
          <div style="font-size:13px; font-weight:700;">Suresh Kumar</div>
          <div style="font-size:10px; color:#64748b;">Hisar, Haryana</div>
        </div>
      </div>
      <div style="width:100%; height:140px; background:url('https://images.unsplash.com/photo-1500382017468-9049fed747ef?auto=format&fit=crop&w=400&q=80'); background-size:cover; border-radius:15px; margin-bottom:12px;"></div>
      <p style="font-size:12px; line-height:1.4; color:#334155; font-weight:500;">"Used the <b>DAP Fertilizer</b> suggested by Agri-Doctor. My wheat crop looks incredibly healthy this year!"</p>
      <div style="margin-top:12px; display:flex; gap:15px; color:#64748b; font-size:12px; font-weight:700;">
        <span><i class="far fa-heart"></i> 42</span>
        <span><i class="far fa-comment"></i> 5</span>
      </div>
    </div>

    <!-- Post 2 -->
    <div style="min-width:280px; background:white; border-radius:24px; padding:15px; border:1px solid #e2e8f0; box-shadow:0 4px 12px rgba(0,0,0,0.05);">
      <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
        <div style="width:35px; height:35px; background:#f1f5f9; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:800; color:#059669;">RP</div>
        <div>
          <div style="font-size:13px; font-weight:700;">Ram Prasad</div>
          <div style="font-size:10px; color:#64748b;">Meerut, UP</div>
        </div>
      </div>
      <div style="width:100%; height:140px; background:url('https://images.unsplash.com/photo-1523348837708-15d4a09cfac2?auto=format&fit=crop&w=400&q=80'); background-size:cover; border-radius:15px; margin-bottom:12px;"></div>
      <p style="font-size:12px; line-height:1.4; color:#334155; font-weight:500;">"The voice search is so easy! Just ordered pesticide while working in the field. Great app!"</p>
      <div style="margin-top:12px; display:flex; gap:15px; color:#64748b; font-size:12px; font-weight:700;">
        <span><i class="far fa-heart"></i> 28</span>
        <span><i class="far fa-comment"></i> 3</span>
      </div>
    </div>
  </div>

  <!-- THINK BIG: SERVICES HUB -->
  <div class="section-header" style="padding:0 20px; display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; margin-top:20px;">
    <h2 class="section-title">Professional Services 🛸</h2>
  </div>
  
  <div style="display:flex; gap:15px; overflow-x:auto; padding:0 20px 20px; scroll-behavior:smooth;">
    <!-- Drone Spraying -->
    <div style="min-width:240px; background:linear-gradient(135deg, #eff6ff, #dbeafe); border-radius:24px; padding:20px; border:1px solid #bfdbfe; position:relative;">
      <div style="position:absolute; top:10px; right:10px; background:#3b82f6; color:white; font-size:10px; font-weight:800; padding:4px 10px; border-radius:20px;">NEW</div>
      <div style="width:50px; height:50px; background:white; border-radius:15px; display:flex; align-items:center; justify-content:center; color:#3b82f6; font-size:24px; margin-bottom:15px; box-shadow:0 4px 10px rgba(59,130,246,0.1);"><i class="fas fa-helicopter"></i></div>
      <h3 style="font-size:15px; font-weight:800; color:#1e40af;">Drone Spraying</h3>
      <p style="font-size:11px; color:#1e40af; margin-top:4px; font-weight:600;">Precision spraying for pests & nutrients.</p>
      <div style="margin-top:15px; font-size:18px; font-weight:900; color:#1e40af;">₹499 <small style="font-size:10px; font-weight:600;">/ Acre</small></div>
      <button onclick="alert('Booking Drone Service... Our team will contact you!')" style="width:100%; margin-top:12px; background:#3b82f6; color:white; border:none; padding:10px; border-radius:12px; font-size:12px; font-weight:800; cursor:pointer;">BOOK NOW</button>
    </div>

    <!-- Soil Testing -->
    <div style="min-width:240px; background:linear-gradient(135deg, #ecfdf5, #d1fae5); border-radius:24px; padding:20px; border:1px solid #a7f3d0;">
      <div style="width:50px; height:50px; background:white; border-radius:15px; display:flex; align-items:center; justify-content:center; color:#059669; font-size:24px; margin-bottom:15px; box-shadow:0 4px 10px rgba(5,150,105,0.1);"><i class="fas fa-flask"></i></div>
      <h3 style="font-size:15px; font-weight:800; color:#065f46;">Soil Testing</h3>
      <p style="font-size:11px; color:#065f46; margin-top:4px; font-weight:600;">Full NPK & pH analysis report.</p>
      <div style="margin-top:15px; font-size:18px; font-weight:900; color:#065f46;">₹199 <small style="font-size:10px; font-weight:600;">/ Sample</small></div>
      <button onclick="alert('Sample collection scheduled! 🧪')" style="width:100%; margin-top:12px; background:#059669; color:white; border:none; padding:10px; border-radius:12px; font-size:12px; font-weight:800; cursor:pointer;">REQUEST TEST</button>
    </div>

    <!-- Expert Call -->
    <div style="min-width:240px; background:linear-gradient(135deg, #fff7ed, #ffedd5); border-radius:24px; padding:20px; border:1px solid #fed7aa;">
      <div style="width:50px; height:50px; background:white; border-radius:15px; display:flex; align-items:center; justify-content:center; color:#ea580c; font-size:24px; margin-bottom:15px; box-shadow:0 4px 10px rgba(234,88,12,0.1);"><i class="fas fa-video"></i></div>
      <h3 style="font-size:15px; font-weight:800; color:#9a3412;">Expert Video Call</h3>
      <p style="font-size:11px; color:#9a3412; margin-top:4px; font-weight:600;">10 min live call with Ph.D. Expert.</p>
      <div style="margin-top:15px; font-size:18px; font-weight:900; color:#9a3412;">₹99 <small style="font-size:10px; font-weight:600;">/ Call</small></div>
      <button onclick="alert('Connecting to Expert... Please wait.')" style="width:100%; margin-top:12px; background:#ea580c; color:white; border:none; padding:10px; border-radius:12px; font-size:12px; font-weight:800; cursor:pointer;">BOOK CALL</button>
    </div>
  </div>

  <div class="search-container">
    <div class="search-box">
      <i class="fas fa-search"></i>
      <input type="text" id="searchInput" placeholder="Search fertilizers, seeds..." oninput="filterProducts()">
    </div>
    <button class="voice-search" id="voiceBtn" onclick="startVoiceSearch()" title="Voice Search">
      <i class="fas fa-microphone" id="voiceIcon"></i>
    </button>
  </div>
</div>

<!-- Voice Toast -->
<div id="voiceToast"><i class="fas fa-microphone-alt"></i> <span id="voiceToastText">Listening...</span></div>

<div class="container">
  <?php if($message): ?>
    <div class="alert <?php echo $msg_type; ?>"><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($message); ?></div>
  <?php endif; ?>

  <!-- Hero Slider -->
  <div class="hero-slider">
    <div class="hero-card hero-1">
      <h3>Monsoon Mega Sale!</h3>
      <p>Up to 20% off on all Paddy Seeds & Fertilizers.</p>
      <button class="hero-btn">Shop Now</button>
      <i class="fas fa-cloud-showers-heavy hero-icon"></i>
    </div>
    <div class="hero-card hero-2">
      <h3>Organic Farming</h3>
      <p>100% natural compost & bio-pesticides.</p>
      <button class="hero-btn">Explore</button>
      <i class="fas fa-seedling hero-icon"></i>
    </div>
    <div class="hero-card hero-3">
      <h3>New Tools Arrived</h3>
      <p>Upgrade your equipment for better yield.</p>
      <button class="hero-btn">View All</button>
      <i class="fas fa-tractor hero-icon"></i>
    </div>
  </div>

  <!-- Categories -->
  <div class="section-title">Shop by Category</div>
  <div class="category-grid">
    <div class="category-item active" onclick="filterCategory('All')">
      <div class="category-icon" style="background:var(--primary-light); color:var(--primary-dark);">🛒</div>
      <div class="category-name">All</div>
    </div>
    <div class="category-item" onclick="filterCategory('Seeds')">
      <div class="category-icon">🌱</div>
      <div class="category-name">Seeds</div>
    </div>
    <div class="category-item" onclick="filterCategory('Fertilizers')">
      <div class="category-icon">🧪</div>
      <div class="category-name">Fertilizers</div>
    </div>
    <div class="category-item" onclick="filterCategory('Pesticides')">
      <div class="category-icon">🚿</div>
      <div class="category-name">Pesticides</div>
    </div>
    <div class="category-item" onclick="filterCategory('Organic')">
      <div class="category-icon">🍃</div>
      <div class="category-name">Organic</div>
    </div>
    <div class="category-item" onclick="filterCategory('Tools')">
      <div class="category-icon">⚙️</div>
      <div class="category-name">Tools</div>
    </div>
  </div>

  <!-- AI Assistant -->
  <div class="ai-card" onclick="openAiModal()">
    <div class="ai-icon"><i class="fas fa-robot"></i></div>
    <div class="ai-content">
      <h4>AI Crop Assistant</h4>
      <p>Tell us your crop and soil problem, and we'll recommend the perfect product for you.</p>
    </div>
    <i class="fas fa-chevron-right" style="color:var(--text-light); margin-left:auto;"></i>
  </div>

  <!-- Products -->
  <div class="section-title" id="productSectionTitle">Recommended for You <span>See All</span></div>
  <div class="product-grid" id="productsGrid">
    <?php foreach($ferts_arr as $f): 
      $tag = $f['quantity'] < 10 ? 'Selling Fast' : 'Best Seller';
      $tag_bg = $f['quantity'] < 10 ? '#EF4444' : '#F59E0B';
    ?>
    <div class="product-card" data-name="<?php echo strtolower($f['fertilizer_name']); ?>" data-cat="<?php echo $f['category']; ?>">
      <div class="product-image">
        <?php echo $f['icon']; ?>
        <div class="product-tag" style="background:<?php echo $tag_bg; ?>;"><?php echo $tag; ?></div>
      </div>
      <div class="product-info">
        <div class="product-title"><?php echo htmlspecialchars($f['fertilizer_name']); ?></div>
        <div class="product-rating">
          <i class="fas fa-star"></i> <?php echo $f['rating']; ?> (<?php echo $f['reviews']; ?>)
          <span style="margin-left:8px; font-size:10px; font-weight:700; color:<?php echo $f['quantity'] < 10 ? 'var(--red)' : 'var(--primary)'; ?>;">
            <i class="fas fa-cubes"></i> <?php echo $f['quantity']; ?> In Stock
          </span>
        </div>
        <div class="product-price-row">
          <div class="product-price">₹<?php echo number_format($f['price'], 0); ?><span>₹<?php echo number_format($f['price']*1.2, 0); ?></span></div>
          <button class="add-btn" onclick="addToCart(<?php echo $f['id']; ?>, '<?php echo addslashes($f['fertilizer_name']); ?>', <?php echo $f['price']; ?>, '<?php echo $f['icon']; ?>', <?php echo $f['quantity']; ?>)">
            <i class="fas fa-plus"></i>
          </button>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

</div>

<!-- Bottom Navigation -->
<div class="bottom-nav">
  <a href="#" class="nav-item active">
    <i class="fas fa-home"></i>
    <span>Home</span>
  </a>
  <a href="#" class="nav-item" onclick="document.getElementById('searchInput').focus();">
    <i class="fas fa-search"></i>
    <span>Search</span>
  </a>
  <a href="#" class="nav-item" onclick="toggleCart()">
    <i class="fas fa-shopping-cart"></i>
    <span>Cart</span>
    <div class="cart-badge" id="navCartCount">0</div>
  </a>
  <a href="#" class="nav-item" onclick="toggleOrders(); return false;">
    <i class="fas fa-box-open"></i>
    <span>Orders</span>
  </a>
  <a href="customer_profile.php" class="nav-item">
    <i class="fas fa-user"></i>
    <span>Profile</span>
  </a>
</div>

<!-- Orders Drawer -->
<div class="cart-overlay" id="ordersOverlay" onclick="toggleOrders()"></div>
<div class="cart-drawer" id="ordersDrawer">
  <div class="cart-header">
    <h3><i class="fas fa-box-open" style="color:var(--primary);margin-right:8px;"></i>My Orders</h3>
    <button class="close-cart" onclick="toggleOrders()"><i class="fas fa-times"></i></button>
  </div>
  <div class="cart-items">
    <?php if(mysqli_num_rows($order_history) > 0): ?>
    <?php while($order = mysqli_fetch_assoc($order_history)):
      $status = $order['status'];
      $badge_map = ['Pending'=>['#FEF3C7','#F59E0B'],'Accepted'=>['#D1FAE5','#10B981'],'Rejected'=>['#FEE2E2','#EF4444'],'Out for Delivery'=>['#DBEAFE','#3B82F6'],'Delivered'=>['#D1FAE5','#059669']];
      [$badge_bg, $badge_color] = $badge_map[$status] ?? ['#FEF3C7','#F59E0B'];
      // 5-step timeline: Placed → Reviewing → Accepted → Shipped → Delivered
      $s1 = 'done'; // Always placed
      $s2 = in_array($status, ['Accepted','Rejected','Out for Delivery','Delivered']) ? 'done' : ($status==='Pending' ? 'active' : '');
      $s3 = in_array($status, ['Out for Delivery','Delivered']) ? 'done' : ($status==='Accepted' ? 'done' : ($status==='Rejected' ? 'fail' : ''));
      $s4 = $status==='Delivered' ? 'done' : ($status==='Out for Delivery' ? 'ship' : '');
      $s5 = $status==='Delivered' ? 'done' : '';
    ?>
    <div style="background:#F9FAFB;border-radius:12px;padding:14px;margin-bottom:14px;border:1px solid #E5E7EB;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
        <span style="font-size:12px;font-weight:700;color:#374151;"><?php echo htmlspecialchars($order['invoice_no']); ?></span>
        <span style="background:<?php echo $badge_bg; ?>;color:<?php echo $badge_color; ?>;font-size:10px;font-weight:700;padding:3px 8px;border-radius:12px;"><?php echo htmlspecialchars($status); ?></span>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <div>
          <div style="font-size:11px;color:#6B7280;"><i class="fas fa-calendar-alt"></i> <?php echo htmlspecialchars($order['order_date']); ?></div>
          <div style="font-size:11px;color:#6B7280;margin-top:2px;"><i class="fas fa-box"></i> <?php echo htmlspecialchars($order['product_names']); ?> (<?php echo $order['total_items']; ?>)</div>
        </div>
        <div style="text-align:right;">
          <div style="font-size:16px;font-weight:800;color:#10B981;">₹<?php echo number_format($order['grand_total'],2); ?></div>
          <?php if($status === 'Accepted'): ?>
          <a href="view_invoice.php?invoice_no=<?php echo urlencode($order['invoice_no']); ?>" target="_blank" style="font-size:11px;color:#3B82F6;text-decoration:none;font-weight:600;"><i class="fas fa-eye"></i> View Bill</a>
          <a href="reorder.php?invoice_no=<?php echo urlencode($order['invoice_no']); ?>" style="font-size:11px;color:#10B981;text-decoration:none;font-weight:600;display:block;margin-top:4px;" onclick="return confirm('Reorder the same items?')"><i class="fas fa-redo"></i> Reorder</a>
          <?php endif; ?>
        </div>
      </div>

      <!-- 5-Step Order Tracking Timeline -->
      <div class="order-timeline">
        <div class="tl-step done">
          <div class="tl-dot"><i class="fas fa-check"></i></div>
          <div class="tl-label">Placed</div>
        </div>
        <div class="tl-step <?php echo $s2; ?>">
          <div class="tl-dot"><i class="fas fa-<?php echo $s2==='active'?'clock':'check'; ?>"></i></div>
          <div class="tl-label">Review</div>
        </div>
        <div class="tl-step <?php echo $s3; ?>">
          <div class="tl-dot">
            <?php if($status==='Rejected'): ?><i class="fas fa-times"></i>
            <?php else: ?><i class="fas fa-check"></i><?php endif; ?>
          </div>
          <div class="tl-label"><?php echo $status==='Rejected'?'Rejected':'Accepted'; ?></div>
        </div>
        <div class="tl-step <?php echo $s4; ?>">
          <div class="tl-dot"><i class="fas fa-truck"></i></div>
          <div class="tl-label">Shipped</div>
        </div>
        <div class="tl-step <?php echo $s5; ?>">
          <div class="tl-dot"><i class="fas fa-leaf"></i></div>
          <div class="tl-label">Delivered</div>
        </div>
      </div>
    </div>
    <?php endwhile; ?>
    <?php else: ?>
    <div style="text-align:center;padding:50px 20px;color:#9CA3AF;">
      <i class="fas fa-shopping-bag" style="font-size:48px;margin-bottom:12px;opacity:0.5;display:block;"></i>
      <p style="font-size:14px;font-weight:600;">No orders yet!</p>
      <p style="font-size:12px;margin-top:4px;">Add products to cart and place your first order.</p>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Cart Drawer -->
<div class="cart-overlay" id="cartOverlay" onclick="toggleCart()"></div>
<div class="cart-drawer" id="cartDrawer">
  <div class="cart-header">
    <h3>Your Cart</h3>
    <button class="close-cart" onclick="toggleCart()"><i class="fas fa-times"></i></button>
  </div>
  <div class="cart-items" id="cartItems">
    <div style="text-align:center; padding: 40px 0; color: var(--text-light);">
      <i class="fas fa-shopping-basket" style="font-size: 48px; margin-bottom: 12px; opacity: 0.5;"></i>
      <p>Your cart is empty.</p>
    </div>
  </div>
  <div class="cart-footer" id="cartFooter" style="display:none;">
    <div class="cart-total-row">
      <span>Total Amount</span>
      <span id="cartTotalVal">₹0</span>
    </div>
    <form method="POST" id="checkoutForm">
      <input type="hidden" name="buy" value="1">
      <div id="checkoutInputs"></div>
      <button type="button" class="checkout-btn" onclick="submitCheckout()">
        <span>Proceed to Checkout</span>
        <i class="fas fa-arrow-right"></i>
      </button>
    </form>
  </div>
</div>

<!-- AI Modal (Crop Doctor) -->
<div class="ai-modal" id="aiModal">
  <div class="ai-header" style="justify-content: space-between;">
    <div style="display:flex; align-items:center; gap:12px;">
      <i class="fas fa-user-md" style="font-size:24px; color:var(--primary-dark);"></i>
      <h3 style="font-size:18px; font-weight:700;">Crop Doctor AI</h3>
    </div>
    <button style="background:none;border:none;font-size:24px;cursor:pointer;color:var(--text-gray);" onclick="closeAiModal()">&times;</button>
  </div>
  <div class="ai-body" style="background:#F9FAFB;">
    <div style="background:white; border-radius:16px; border:1px solid var(--border); padding:20px; box-shadow:var(--shadow-sm);">
      <p style="font-size:12px; color:var(--text-gray); margin-bottom:15px; text-align:center;">Upload a photo of your crop or describe the problem for an instant diagnosis.</p>
      
      <div id="aiResult" style="display:none; margin-bottom:20px; padding:16px; background:#F0FDF4; border:1px dashed #34D399; border-radius:12px;">
        <h4 style="color:#059669; font-size:14px; margin-bottom:8px;"><i class="fas fa-check-circle"></i> AI Diagnosis Complete!</h4>
        <p style="font-size:13px; color:var(--text-dark); line-height:1.5;" id="aiText"></p>
        <div id="aiLoader" style="display:none; text-align:center; padding:10px;">
           <i class="fas fa-dna fa-spin" style="color:var(--primary); font-size:24px;"></i>
           <p style="font-size:11px; margin-top:5px; font-weight:600;">Sequencing visual data...</p>
        </div>
      </div>

      <div class="form-group">
        <label>Select Crop</label>
        <select id="aiCrop"><option>Paddy</option><option>Cotton</option><option>Vegetables</option><option>Wheat</option></select>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:20px;">
        <label for="aiImage" style="background:#f3f4f6; border:2px dashed #d1d5db; border-radius:12px; padding:15px; text-align:center; cursor:pointer; display:flex; flex-direction:column; align-items:center; gap:5px;">
           <i class="fas fa-camera" style="font-size:20px; color:var(--text-gray);"></i>
           <span style="font-size:11px; font-weight:600;">Upload Photo</span>
           <input type="file" id="aiImage" style="display:none;" accept="image/*" onchange="handleAiImage(this)">
        </label>
        <button onclick="getAiRecommendation()" style="background:var(--primary); color:white; border:none; border-radius:12px; padding:15px; font-size:13px; font-weight:700; cursor:pointer; display:flex; flex-direction:column; align-items:center; gap:5px;">
           <i class="fas fa-magic"></i>
           <span>Analyze Now</span>
        </button>
      </div>

      <div id="imgPreview" style="display:none; margin-bottom:20px; border-radius:12px; overflow:hidden; border:1px solid var(--border);">
         <img src="" id="cropImg" style="width:100%; display:block;">
      </div>

      <div class="form-group">
        <label>Describe the Symptoms</label>
        <textarea id="aiIssue" rows="3" placeholder="e.g. Small brown spots on leaves..."></textarea>
      </div>
    </div>
  </div>
</div>

<script>
// Logic
let cart = {};

function addToCart(id, name, price, icon, maxStock) {
  if (cart[id]) {
    if (cart[id].qty >= maxStock) { alert('Maximum stock reached!'); return; }
    cart[id].qty++;
  } else {
    cart[id] = { id, name, price, icon, maxStock, qty: 1 };
  }
  updateCartUI();
  
  // Animation
  const btn = event.currentTarget;
  btn.classList.add('added');
  btn.innerHTML = '<i class="fas fa-check"></i>';
  setTimeout(() => { btn.classList.remove('added'); btn.innerHTML = '<i class="fas fa-plus"></i>'; }, 1000);
}

function updateCartUI() {
  const keys = Object.keys(cart);
  let count = 0, total = 0, html = '';
  
  keys.forEach(k => {
    const it = cart[k];
    count += it.qty;
    total += it.price * it.qty;
    html += `
      <div class="cart-item">
        <div class="cart-item-img">${it.icon}</div>
        <div class="cart-item-info">
          <div class="cart-item-name">${it.name}</div>
          <div class="cart-item-price">₹${it.price}</div>
          <div class="cart-qty-ctrl">
            <button class="qty-btn" onclick="changeQty(${it.id}, -1)">-</button>
            <span style="font-size:14px;font-weight:600;width:20px;text-align:center;">${it.qty}</span>
            <button class="qty-btn" onclick="changeQty(${it.id}, 1)">+</button>
            <i class="fas fa-trash" style="color:#EF4444;margin-left:auto;cursor:pointer;" onclick="changeQty(${it.id}, -99)"></i>
          </div>
        </div>
      </div>
    `;
  });
  
  document.getElementById('navCartCount').textContent = count;
  
  if (count > 0) {
    document.getElementById('cartItems').innerHTML = html;
    document.getElementById('cartTotalVal').textContent = '₹' + total.toLocaleString();
    document.getElementById('cartFooter').style.display = 'block';
  } else {
    document.getElementById('cartItems').innerHTML = '<div style="text-align:center; padding: 40px 0; color: var(--text-light);"><i class="fas fa-shopping-basket" style="font-size: 48px; margin-bottom: 12px; opacity: 0.5;"></i><p>Your cart is empty.</p></div>';
    document.getElementById('cartFooter').style.display = 'none';
  }
}

function changeQty(id, delta) {
  if(!cart[id]) return;
  cart[id].qty += delta;
  if(cart[id].qty > cart[id].maxStock) { cart[id].qty = cart[id].maxStock; alert('Max stock reached!'); }
  if(cart[id].qty <= 0) delete cart[id];
  updateCartUI();
}

function toggleCart() {
  document.getElementById('cartOverlay').classList.toggle('active');
  document.getElementById('cartDrawer').classList.toggle('active');
}
function toggleOrders() {
  document.getElementById('ordersOverlay').classList.toggle('active');
  document.getElementById('ordersDrawer').classList.toggle('active');
}

function submitCheckout() {
  const keys = Object.keys(cart);
  if(!keys.length) return;
  let html = '';
  keys.forEach(k => {
    html += `<input type="hidden" name="fertilizer[]" value="${cart[k].id}"><input type="hidden" name="quantity[]" value="${cart[k].qty}">`;
  });
  document.getElementById('checkoutInputs').innerHTML = html;
  document.getElementById('checkoutForm').submit();
}

// Filtering
function filterCategory(cat) {
  document.querySelectorAll('.category-item').forEach(el => el.classList.remove('active'));
  event.currentTarget.classList.add('active');
  document.getElementById('productSectionTitle').innerHTML = `${cat} Products <span>See All</span>`;
  
  document.querySelectorAll('.product-card').forEach(card => {
    if (cat === 'All' || card.dataset.cat === cat) card.style.display = 'flex';
    else card.style.display = 'none';
  });
}

function filterProducts() {
  const q = document.getElementById('searchInput').value.toLowerCase();
  document.querySelectorAll('.product-card').forEach(card => {
    if (card.dataset.name.includes(q) || card.dataset.cat.toLowerCase().includes(q)) card.style.display = 'flex';
    else card.style.display = 'none';
  });
}

// Voice Search
let voiceRecognition = null;
function showVoiceToast(msg, duration = 2500) {
  const toast = document.getElementById('voiceToast');
  document.getElementById('voiceToastText').textContent = msg;
  toast.classList.add('show');
  setTimeout(() => toast.classList.remove('show'), duration);
}

function startVoiceSearch() {
  const btn = document.getElementById('voiceBtn');
  const icon = document.getElementById('voiceIcon');
  const input = document.getElementById('searchInput');

  // If already listening, stop
  if (voiceRecognition) {
    voiceRecognition.stop();
    voiceRecognition = null;
    return;
  }

  const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (!SpeechRecognition) {
    showVoiceToast('❌ Voice search not supported in this browser.');
    return;
  }

  voiceRecognition = new SpeechRecognition();
  voiceRecognition.lang = 'en-IN';
  voiceRecognition.continuous = false;
  voiceRecognition.interimResults = true;

  voiceRecognition.onstart = function() {
    btn.classList.add('recording');
    icon.className = 'fas fa-stop';
    input.placeholder = '🎤 Listening...';
    showVoiceToast('🎤 Listening... Speak now', 5000);
  };

  voiceRecognition.onresult = function(event) {
    let interim = '';
    let final = '';
    for (let i = event.resultIndex; i < event.results.length; i++) {
      const t = event.results[i][0].transcript;
      if (event.results[i].isFinal) final += t;
      else interim += t;
    }
    input.value = final || interim;
    if (final) {
      filterProducts();
      showVoiceToast('✅ Heard: "' + final + '"');
    }
  };

  voiceRecognition.onerror = function(event) {
    const msgs = { 'no-speech': '🔇 No speech detected. Try again.', 'not-allowed': '🚫 Microphone access denied.', 'network': '📶 Network error. Try again.' };
    showVoiceToast(msgs[event.error] || '⚠️ Voice error: ' + event.error);
  };

  voiceRecognition.onend = function() {
    btn.classList.remove('recording');
    icon.className = 'fas fa-microphone';
    input.placeholder = 'Search fertilizers, seeds...';
    voiceRecognition = null;
  };

  voiceRecognition.start();
}

// AI Assistant
function openAiModal() { document.getElementById('aiModal').classList.add('active'); }
function closeAiModal() { document.getElementById('aiModal').classList.remove('active'); document.getElementById('aiResult').style.display='none'; }
function handleAiImage(input) {
  if (input.files && input.files[0]) {
    const reader = new FileReader();
    reader.onload = function(e) {
      document.getElementById('cropImg').src = e.target.result;
      document.getElementById('imgPreview').style.display = 'block';
      getAiRecommendation(true);
    };
    reader.readAsDataURL(input.files[0]);
  }
}

function getAiRecommendation(isVisual = false) {
  const crop = document.getElementById('aiCrop').value;
  const issue = document.getElementById('aiIssue').value;
  const res = document.getElementById('aiResult');
  const txt = document.getElementById('aiText');
  const loader = document.getElementById('aiLoader');
  
  res.style.display = 'block';
  loader.style.display = 'block';
  txt.innerHTML = "";
  
  setTimeout(() => {
    loader.style.display = 'none';
    let rec = "";
    if (isVisual) {
      rec = "✅ **AI Diagnosis**: Based on visual analysis, your " + crop + " shows signs of **Bacterial Blight**. <br><br>**Recommendation**: Apply **'Copper Oxychloride'** or **'Streptocycline'**. Check our Pesticides section!";
    } else {
      if(issue.toLowerCase().includes("yield")) rec = "We recommend an NPK base fertilizer or Organic Compost to boost nutrient intake for your " + crop + ".";
      else if(issue.toLowerCase().includes("pest")) rec = "We recommend a broad-spectrum Pesticide spray to protect your " + crop + " leaves immediately.";
      else rec = "We recommend our premium Plant Growth Promoters to resolve your " + crop + " issues.";
    }
    
    txt.innerHTML = rec;
    if(rec.includes("Pesticide") || rec.includes("Blight")) document.getElementById('searchInput').value = "pesticide";
    else document.getElementById('searchInput').value = "fertilizer";
    filterProducts();
  }, 2000);
}

// THINK BIG: AI VOICE COMMANDS
function startVoiceSearch() {
  if (!('webkitSpeechRecognition' in window)) {
    alert("Voice search not supported in this browser.");
    return;
  }
  const recognition = new webkitSpeechRecognition();
  recognition.lang = 'hi-IN'; // Default to Hindi
  recognition.onstart = () => {
    document.getElementById('voiceBtn').style.color = '#ef4444';
    document.getElementById('voiceBtn').style.animation = 'tlpulse 1s infinite';
  };
  recognition.onresult = (event) => {
    const transcript = event.results[0][0].transcript;
    document.getElementById('searchInput').value = transcript;
    filterProducts();
    // Auto-reply simulation
    const speech = new SpeechSynthesisUtterance();
    speech.text = "Searching for " + transcript;
    window.speechSynthesis.speak(speech);
  };
  recognition.onend = () => {
    document.getElementById('voiceBtn').style.color = 'var(--primary)';
    document.getElementById('voiceBtn').style.animation = 'none';
  };
  recognition.start();
}
</script>

<script>
const translations = {
  en: { 
    welcome: "Shop by Category", rec: "Recommended for You", search: "Search fertilizers, seeds...", 
    home: "Home", searchBtn: "Search", cart: "Cart", orders: "Orders", profile: "Profile",
    mandi: "Live Mandi Prices", weather: "Agri-Weather AI", spray: "SPRAYING ADVISORY", viewAll: "VIEW ALL MARKETS", marketReport: "Full Market Report", commodity: "Commodity", price: "Price"
  },
  hi: { 
    welcome: "श्रेणी के अनुसार खरीदारी करें", rec: "आपके लिए अनुशंसित", search: "उर्वरक, बीज खोजें...", 
    home: "मुख्य", searchBtn: "खोज", cart: "कार्ट", orders: "ऑर्डर", profile: "प्रोफ़ाइल",
    mandi: "मंडी के भाव", weather: "कृषि मौसम एआई", spray: "छिड़काव सलाह", viewAll: "सभी मंडी देखें", marketReport: "पूर्ण बाजार रिपोर्ट", commodity: "वस्तु", price: "मूल्य"
  },
  te: { 
    welcome: "వర్గం వారీగా షాపింగ్ చేయండి", rec: "మీ కోసం సిఫార్సు చేయబడింది", search: "ఎరువులు, విత్తనాలు వెతకండి...", 
    home: "హోమ్", searchBtn: "శోధన", cart: "కార్ట్", orders: "ఆర్డర్లు", profile: "ప్రొఫైల్",
    mandi: "లైవ్ మండి ధరలు", weather: "అగ్రి-వెదర్ AI", spray: "స్ప్రేయింగ్ సలహా", viewAll: "అన్ని మార్కెట్లు చూడండి", marketReport: "పూర్తి మార్కెట్ నివేదిక", commodity: "వస్తువు", price: "ధర"
  }
};

let currentLang = localStorage.getItem('gg-lang') || 'en';

function changeLang(lang) {
  currentLang = lang;
  const t = translations[lang];
  document.querySelectorAll('.section-title')[0].firstChild.textContent = t.welcome;
  document.getElementById('productSectionTitle').firstChild.textContent = t.rec;
  document.getElementById('searchInput').placeholder = t.search;
  document.getElementById('mandiTitle').textContent = t.mandi;
  document.getElementById('weatherTitle').textContent = t.weather;
  document.getElementById('sprayTitle').innerHTML = '<i class="fas fa-info-circle"></i> ' + t.spray;
  document.getElementById('viewAllBtn').innerHTML = t.viewAll + ' <i class="fas fa-arrow-right"></i>';
  document.getElementById('mModalTitle').textContent = t.marketReport;
  document.getElementById('mThComm').textContent = t.commodity;
  document.getElementById('mThPrice').textContent = t.price;
  
  const navs = document.querySelectorAll('.nav-item span');
  if(navs.length >= 5) {
    navs[0].textContent = t.home; navs[1].textContent = t.searchBtn; navs[2].textContent = t.cart; navs[3].textContent = t.orders || 'Orders'; navs[4].textContent = t.profile || 'Profile';
  }
  localStorage.setItem('gg-lang', lang);
  fetchMandiPrices(); 
  fetchWeatherAdvisory();
}

async function fetchWeatherAdvisory() {
  try {
    const res = await fetch('api_weather_advisory.php');
    const data = await res.json();
    if(data.status === 'success') {
      const title = document.getElementById('sprayTitle');
      const desc = document.getElementById('sprayDesc');
      
      if(currentLang === 'te') {
        title.innerHTML = '<i class="fas fa-info-circle"></i> ' + data.telugu.title;
        desc.textContent = data.telugu.text;
      } else {
        title.innerHTML = '<i class="fas fa-info-circle"></i> ' + data.advisory.title;
        desc.textContent = data.advisory.text;
      }
      title.style.color = data.advisory.color;
    }
  } catch(e) { console.error("Weather advisory error", e); }
}

async function fetchMandiPrices() {
  try {
    const res = await fetch('api_mandi_prices.php');
    const data = await res.json();
    if(data.status === 'success') {
      const list = document.getElementById('mandiList');
      list.innerHTML = '';
      data.data.slice(0, 3).forEach(item => {
        const name = currentLang === 'te' ? item.commodity_te : item.commodity;
        const trendIcon = item.trend === 'up' ? 'fa-caret-up' : (item.trend === 'down' ? 'fa-caret-down' : 'fa-minus');
        const trendColor = item.trend === 'up' ? '#22c55e' : (item.trend === 'down' ? '#ef4444' : '#94a3b8');
        list.innerHTML += `
          <div style="background:rgba(255,255,255,0.05); padding:10px 15px; border-radius:15px; display:flex; justify-content:space-between; align-items:center;">
            <span style="font-size:13px; font-weight:600; color:#94a3b8;">${name}</span>
            <span style="font-size:14px; font-weight:800; color:${trendColor};">₹${item.price}/${item.unit} <i class="fas ${trendIcon}"></i></span>
          </div>
        `;
      });
    }
  } catch(e) { console.error("Mandi fetch error", e); }
}

async function openMandiModal() {
  document.getElementById('mandiModal').style.display = 'flex';
  const table = document.getElementById('mandiFullTable');
  table.innerHTML = '<tr><td colspan="2" style="text-align:center; padding:20px;">Loading...</td></tr>';
  try {
    const res = await fetch('api_mandi_prices.php');
    const data = await res.json();
    if(data.status === 'success') {
      table.innerHTML = '';
      data.data.forEach(item => {
        const name = currentLang === 'te' ? item.commodity_te : item.commodity;
        const trendIcon = item.trend === 'up' ? 'fa-caret-up' : (item.trend === 'down' ? 'fa-caret-down' : 'fa-minus');
        const trendColor = item.trend === 'up' ? '#22c55e' : (item.trend === 'down' ? '#ef4444' : '#94a3b8');
        table.innerHTML += `
          <tr style="border-bottom:1px solid #f1f5f9;">
            <td style="padding:12px; font-weight:600;">${name}</td>
            <td style="padding:12px; text-align:right; font-weight:800; color:${trendColor};">₹${item.price}/${item.unit} <i class="fas ${trendIcon}"></i></td>
          </tr>
        `;
      });
    }
  } catch(e) { table.innerHTML = '<tr><td colspan="2" style="text-align:center; color:red;">Failed to load prices.</td></tr>'; }
}

function closeMandiModal() {
  document.getElementById('mandiModal').style.display = 'none';
}

window.addEventListener('load', () => {
  const savedLang = localStorage.getItem('gg-lang');
  if(savedLang) {
    document.getElementById('langToggle').value = savedLang;
    changeLang(savedLang);
  } else {
    fetchMandiPrices();
    fetchWeatherAdvisory();
  }
});

// Dark Mode logic
function toggleDark() {
  const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
  const newTheme = isDark ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', newTheme);
  localStorage.setItem('gg-theme', newTheme);
  document.getElementById('darkToggle').textContent = newTheme === 'dark' ? '☀️' : '🌙';
}
(function() {
  const saved = localStorage.getItem('gg-theme') || 'light';
  document.documentElement.setAttribute('data-theme', saved);
  const btn = document.getElementById('darkToggle');
  if (btn) btn.textContent = saved === 'dark' ? '☀️' : '🌙';
})();
</script>

<!-- MANDI MODAL -->
<div id="mandiModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:9999; align-items:center; justify-content:center; padding:20px;">
  <div style="background:white; border-radius:30px; width:100%; max-width:400px; max-height:80vh; overflow-y:auto; padding:25px; box-shadow:0 25px 50px rgba(0,0,0,0.3);">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; position:sticky; top:0; background:white; padding-bottom:10px; border-bottom:1px solid #f1f5f9;">
      <h2 style="font-size:18px; font-weight:800; color:var(--primary-dark);" id="mModalTitle">Full Market Report</h2>
      <button onclick="closeMandiModal()" style="background:#f1f5f9; border:none; width:30px; height:30px; border-radius:50%; cursor:pointer;"><i class="fas fa-times"></i></button>
    </div>
    <div style="font-size:11px; color:#64748b; margin-bottom:15px; display:flex; align-items:center; gap:5px;"><i class="fas fa-map-marker-alt"></i> Siddipet Mandi, Telangana</div>
    <table style="width:100%; border-collapse:collapse; font-size:14px;">
      <thead style="background:#f8fafc;">
        <tr>
          <th style="padding:10px; text-align:left; border-radius:10px 0 0 10px;" id="mThComm">Commodity</th>
          <th style="padding:10px; text-align:right; border-radius:0 10px 10px 0;" id="mThPrice">Price</th>
        </tr>
      </thead>
      <tbody id="mandiFullTable"></tbody>
    </table>
    <div style="margin-top:20px; padding:15px; background:#f0fdf4; border-radius:15px; border:1px solid #bbf7d0; font-size:12px; color:#166534; display:flex; gap:10px;">
      <i class="fas fa-info-circle" style="margin-top:3px;"></i>
      <span>Prices are updated hourly based on Agmarknet Siddipet reports.</span>
    </div>
  </div>
</div>

</body>
</html>


