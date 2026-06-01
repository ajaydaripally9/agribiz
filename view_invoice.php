<?php
session_start();
if (!isset($_SESSION['customer']) && !isset($_SESSION['admin'])) {
    header('Location: index.php');
    exit();
}
include 'db.php';

if (!isset($_GET['invoice_no'])) {
    die("Invoice number not provided.");
}

$invoice_no = $_GET['invoice_no'];

// Fetch order details
$stmt = mysqli_prepare($conn, "SELECT * FROM orders WHERE invoice_no = ?");
mysqli_stmt_bind_param($stmt, "s", $invoice_no);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$items = [];
$total_sum = 0;
while($row = mysqli_fetch_assoc($result)) {
    $items[] = $row;
    $total_sum += $row['total_price'];
}

if (count($items) === 0) {
    die("Invoice not found.");
}

$customer_id = $items[0]['customer_id'];
if (isset($_SESSION['customer']) && !isset($_SESSION['admin'])) {
    if ($customer_id != $_SESSION['customer_id']) {
        die("Access denied.");
    }
}

// Fetch customer details
$cust_stmt = mysqli_prepare($conn, "SELECT * FROM customers WHERE id = ?");
mysqli_stmt_bind_param($cust_stmt, "i", $customer_id);
mysqli_stmt_execute($cust_stmt);
$customer = mysqli_fetch_assoc(mysqli_stmt_get_result($cust_stmt));

$invoice_date = date('d-m-Y', strtotime($items[0]['order_date']));
$bill_type = $items[0]['bill_type'];

$cgst_rate = 9;
$sgst_rate = 9;
// Total price already includes GST in this app's logic (calculated at billing)
// We need to extract base price and GST components for display
$grand_total = $total_sum;
$base_total = $grand_total / 1.18;
$cgst_total_amt = ($base_total * 0.09);
$sgst_total_amt = ($base_total * 0.09);

$paid_amount = $items[0]['paid_amount'];
$balance = $grand_total - $paid_amount;

// Previous Balance logic
$prev_balance = 0;
$prev_bal_query = "SELECT SUM(total_price) - SUM(paid_amount) as outstanding FROM orders WHERE customer_id = $customer_id AND invoice_no != '$invoice_no'";
$prev_bal_res = mysqli_query($conn, $prev_bal_query);
$prev_bal_row = mysqli_fetch_assoc($prev_bal_res);
$prev_balance = $prev_bal_row['outstanding'] ?? 0;

$total_due_amt = $prev_balance + $balance;

// Function for Amount in Words
function getAmountInWords($number) {
    $decimal = round($number - ($no = floor($number)), 2) * 100;
    $hundred = null;
    $digits_length = strlen($no);
    $i = 0;
    $str = array();
    $words = array(0 => '', 1 => 'ONE', 2 => 'TWO',
        3 => 'THREE', 4 => 'FOUR', 5 => 'FIVE', 6 => 'SIX',
        7 => 'SEVEN', 8 => 'EIGHT', 9 => 'NINE',
        10 => 'TEN', 11 => 'ELEVEN', 12 => 'TWELVE',
        13 => 'THIRTEEN', 14 => 'FOURTEEN', 15 => 'FIFTEEN',
        16 => 'SIXTEEN', 17 => 'SEVENTEEN', 18 => 'EIGHTEEN',
        19 => 'NINETEEN', 20 => 'TWENTY', 30 => 'THIRTY',
        40 => 'FORTY', 50 => 'FIFTY', 60 => 'SIXTY',
        70 => 'SEVENTY', 80 => 'EIGHTY', 90 => 'NINETY');
    $digits = array('', 'HUNDRED','THOUSAND','LAKH', 'CRORE');
    while( $i < $digits_length ) {
        $divider = ($i == 2) ? 10 : 100;
        $number = floor($no % $divider);
        $no = floor($no / $divider);
        $i += ($divider == 10) ? 1 : 2;
        if ($number) {
            $plural = (($counter = count($str)) && $number > 9) ? 'S' : null;
            $hundred = ($counter == 1 && $str[0]) ? ' AND ' : null;
            $str [] = ($number < 21) ? $words[$number].' '. $digits[$counter]. $plural.' '.$hundred:$words[floor($number / 10) * 10].' '.$words[$number % 10]. ' '.$digits[$counter].$plural.' '.$hundred;
        } else $str[] = null;
    }
    $Rupees = implode('', array_reverse($str));
    $paise = ($decimal > 0) ? "." . ($words[$decimal / 10] . " " . $words[$decimal % 10]) . ' PAISE' : '';
    return ($Rupees ? $Rupees . 'RUPEES ' : '') . $paise . ' ONLY';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Bill No: <?php echo $invoice_no; ?></title>
<style>
    @page { size: auto; margin: 5mm; }
    body { font-family: 'Courier New', Courier, monospace; font-size: 13px; color: #000; margin: 0; padding: 20px; background: #fff; }
    .bill-box { border: 1px solid #000; padding: 15px; position: relative; max-width: 900px; margin: 0 auto; }
    
    .header-info { display: flex; justify-content: space-between; font-weight: bold; margin-bottom: 5px; }
    .gstin { font-size: 12px; }
    .bill-type { border: 1px solid #000; padding: 2px 10px; text-transform: uppercase; }

    .shop-name { text-align: center; margin: 10px 0; }
    .shop-name h1 { margin: 0; font-size: 24px; font-weight: 800; text-decoration: underline; }
    .shop-addr { font-size: 12px; font-weight: bold; }

    .cust-info { display: grid; grid-template-columns: 1fr 1fr; margin-top: 15px; border-top: 1px solid #000; padding-top: 10px; }
    .row { display: flex; margin-bottom: 5px; }
    .label { width: 80px; font-weight: bold; }

    .main-table { width: 100%; border-collapse: collapse; margin-top: 10px; border-top: 1px solid #000; border-bottom: 1px solid #000; }
    .main-table th, .main-table td { border: 1px solid #000; padding: 5px; text-align: center; }
    .main-table th { font-size: 11px; text-transform: uppercase; }

    .footer { margin-top: 15px; display: grid; grid-template-columns: 1.5fr 1fr; gap: 20px; }
    .terms { font-size: 10px; line-height: 1.4; }
    .summary-table { width: 100%; border-collapse: collapse; }
    .summary-table td { padding: 3px 5px; font-weight: bold; }
    .text-right { text-align: right; }

    .amt-words { border: 1px solid #000; padding: 5px; margin-top: 10px; font-weight: bold; font-size: 11px; }

    .signatures { margin-top: 40px; display: flex; justify-content: space-between; }
    .sig-box { border-top: 1px dashed #000; width: 150px; text-align: center; padding-top: 5px; font-size: 12px; font-weight: bold; }

    @media print {
        .no-print { display: none !important; }
        body { padding: 0; }
        .bill-box { border: none; }
    }
</style>
</head>
<body <?php if(isset($_GET['print'])) echo 'onload="window.print()"'; ?>>

<div class="bill-box">
    <div class="header-info">
        <div class="gstin">
            GSTIN : 36FSBPS3478C1Z8<br>
            Cell : 9573863949, 9492008842
        </div>
        <div>
            <span class="bill-type"><?php echo $bill_type; ?> Bill</span>
        </div>
    </div>

    <div class="shop-name">
        <h1>TIRUMALA FERTILIZERS, SEEDS & PESTICIDES</h1>
        <div class="shop-addr">
            #12-21, Near Ambedkar Circle, Chinnakodur (M), Siddipet Dist (T.S)<br>
            Cell : 9948481889
        </div>
    </div>

    <div class="header-info" style="margin-top:10px;">
        <div>Bill No : <strong><?php echo $invoice_no; ?></strong></div>
        <div>Date : <strong><?php echo $invoice_date; ?></strong></div>
    </div>

    <div class="cust-info">
        <div>
            <div class="row"><span class="label">Name :</span> <strong><?php echo strtoupper($customer['customer_name']); ?></strong></div>
            <div class="row"><span class="label">Mobile :</span> <?php echo $customer['mobile']; ?></div>
        </div>
        <div style="text-align: right;">
            <div class="row" style="justify-content: flex-end;"><span class="label">Place :</span> <?php echo strtoupper($customer['address']); ?></div>
        </div>
    </div>

    <table class="main-table">
        <thead>
            <tr>
                <th width="40">Sl No</th>
                <th>Particulars</th>
                <th>Batch No</th>
                <th>Mfg Date</th>
                <th>Expiry Date</th>
                <th>Qty</th>
                <th>Rate</th>
                <th>CGST %</th>
                <th>SGST %</th>
                <th width="100">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $sl = 1;
            foreach ($items as $item): 
                $item_base = $item['total_price'] / 1.18;
            ?>
            <tr style="height: 30px;">
                <td><?php echo $sl++; ?></td>
                <td style="text-align: left;"><strong><?php echo strtoupper($item['fertilizer_name']); ?></strong></td>
                <td><?php echo $item['batch_no'] ?: '-'; ?></td>
                <td><?php echo $item['mfg_date'] ? date('d-m-Y', strtotime($item['mfg_date'])) : '-'; ?></td>
                <td><?php echo $item['expiry_date'] ? date('d-m-Y', strtotime($item['expiry_date'])) : '-'; ?></td>
                <td><?php echo $item['quantity']; ?></td>
                <td><?php echo number_format($item_base / $item['quantity'], 2); ?></td>
                <td>9%</td>
                <td>9%</td>
                <td><?php echo number_format($item['total_price'], 2); ?></td>
            </tr>
            <?php endforeach; 
            // Add empty rows to keep the bill height consistent
            for($k=count($items); $k<5; $k++) {
                echo '<tr style="height: 30px;"><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>';
            }
            ?>
        </tbody>
    </table>

    <div class="footer">
        <div class="terms">
            <strong>TERMS & CONDITIONS:</strong><br>
            1. Seal / Tackle: Batch No, Mfg Date, Expiry Date, checked & Purchased in good condition.<br>
            2. Goods once sold will not taken back or exchanged.<br>
            3. Above goods are purchased for Agricultural use only.<br>
            4. All subject to SIDDIPET Jurisdiction only.<br>
            5. Certified & Truthfully labeled seeds exempted from sales tax under APGSTG.O.M.S.NO.604.<br>
            REVENUE (CT-II) DT 09-04-97 BY CBST G.O.M.S.NO.128 Revenue CT(II) Dt 14-02-1985.<br><br>
            <div style="display:flex; align-items:center; gap:10px; border:1px solid #000; padding:10px; width:fit-content; border-radius:8px;">
                <?php 
                $vpa = "9573863949@paytm"; // Placeholder UPI ID
                $pay_name = "TIRUMALA FERTILIZERS";
                $pay_amt = $total_due_amt;
                $upi_url = "upi://pay?pa=$vpa&pn=".urlencode($pay_name)."&am=$pay_amt&cu=INR";
                $qr_api = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=" . urlencode($upi_url);
                ?>
                <img src="<?php echo $qr_api; ?>" alt="UPI QR" style="width:80px; height:80px;">
                <div style="font-size:11px;">
                    <strong>PAY VIA UPI</strong><br>
                    Scan to pay total due:<br>
                    <span style="font-size:14px;">₹<?php echo number_format($total_due_amt, 2); ?></span>
                </div>
            </div>
        </div>
        <div>
            <table class="summary-table">
                <tr><td class="text-right">Previous Bal :</td><td class="text-right">₹<?php echo number_format($prev_balance, 2); ?></td></tr>
                <tr><td class="text-right">Invoice Amount :</td><td class="text-right">₹<?php echo number_format($grand_total, 2); ?></td></tr>
                <tr style="border-top: 1px solid #000;"><td class="text-right">Total Due Amt :</td><td class="text-right" style="font-size:15px;">₹<?php echo number_format($total_due_amt, 2); ?></td></tr>
                <tr><td colspan="2"><hr style="border:none; border-top:1px dashed #000;"></td></tr>
                <tr><td class="text-right">Inclusive of CGST :</td><td class="text-right">₹<?php echo number_format($cgst_total_amt, 2); ?></td></tr>
                <tr><td class="text-right">Inclusive of SGST :</td><td class="text-right">₹<?php echo number_format($sgst_total_amt, 2); ?></td></tr>
                <tr style="border-top: 1px solid #000;"><td class="text-right">NET :</td><td class="text-right" style="font-size:16px;">₹<?php echo number_format($grand_total, 2); ?></td></tr>
            </table>
        </div>
    </div>

    <div class="amt-words">
        Net Amount in Words : <?php echo getAmountInWords($grand_total); ?>
    </div>

    <div class="signatures">
        <div class="sig-box">Farmer Signature</div>
        <div class="sig-box">Signature</div>
    </div>
</div>

<div class="no-print" style="text-align: center; margin-top: 20px; display:flex; justify-content:center; gap:15px; align-items:center;">
    <?php
    $wa_msg = "Hello ".strtoupper($customer['customer_name']).", your bill from TIRUMALA FERTILIZERS is ready. \nInvoice No: $invoice_no \nDate: $invoice_date \nTotal Due: ₹".number_format($total_due_amt, 2)."\nPlease pay via the UPI QR code on the bill. \nThank you!";
    $wa_url = "https://wa.me/91".$customer['mobile']."?text=".urlencode($wa_msg);
    ?>
    <a href="<?php echo $wa_url; ?>" target="_blank" style="background:#25D366; color:#fff; padding: 10px 20px; border:none; border-radius:5px; cursor:pointer; text-decoration:none; font-weight:bold; font-size:13px; display:flex; align-items:center; gap:8px;">
        <i class="fab fa-whatsapp" style="font-size:18px;"></i> SEND ON WHATSAPP
    </a>
    <button onclick="window.print()" style="background:#000; color:#fff; padding: 10px 20px; border:none; border-radius:5px; cursor:pointer; font-weight:bold; font-size:13px;">PRINT INVOICE</button>
    <a href="dashboard.php" style="color:#666; font-size:13px; text-decoration:none; font-weight:600;">Back to Dashboard</a>
</div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</body>
</html>

