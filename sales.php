<?php
session_start();
if(!isset($_SESSION['admin'])){
    header('Location: index.php');
    exit();
}
include 'db.php';

$query = "SELECT * FROM sales ORDER BY sale_date DESC, id DESC";
$result = mysqli_query($conn, $query);
?>

<!DOCTYPE html>
<html>
<head>
<title>Sales History</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<h2>Sales History</h2>
<table border="1">
<tr>
<th>ID</th>
<th>Customer</th>
<th>Fertilizer</th>
<th>Quantity</th>
<th>Price</th>
<th>Date</th>
</tr>
<?php while($row = mysqli_fetch_assoc($result)) { ?>
<tr>
<td><?php echo $row['id']; ?></td>
<td><?php echo $row['customer_name']; ?></td>
<td><?php echo $row['fertilizer_name']; ?></td>
<td><?php echo $row['quantity']; ?></td>
<td><?php echo $row['total_price']; ?></td>
<td><?php echo $row['sale_date']; ?></td>
</tr>
<?php } ?>
</table>
<a href="dashboard.php">Back to Dashboard</a>
</body>
</html>
