<?php
session_start();
if(!isset($_SESSION['admin'])){
    header('Location: index.php');
    exit();
}
include 'db.php';

$query = "SELECT * FROM purchases ORDER BY purchase_date DESC, id DESC";
$result = mysqli_query($conn, $query);
?>

<!DOCTYPE html>
<html>
<head>
<title>Purchase History</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<h2>Purchase History</h2>
<table border="1">
<tr>
<th>ID</th>
<th>Supplier</th>
<th>Fertilizer</th>
<th>Quantity</th>
<th>Cost</th>
<th>Total Cost</th>
<th>Date</th>
</tr>
<?php while($row = mysqli_fetch_assoc($result)) { ?>
<tr>
<td><?php echo $row['id']; ?></td>
<td><?php echo $row['supplier_name']; ?></td>
<td><?php echo $row['fertilizer_name']; ?></td>
<td><?php echo $row['quantity']; ?></td>
<td><?php echo $row['cost']; ?></td>
<td><?php echo $row['cost'] * $row['quantity']; ?></td>
<td><?php echo $row['purchase_date']; ?></td>
</tr>
<?php } ?>
</table>
<a href="dashboard.php">Back to Dashboard</a>
</body>
</html>
