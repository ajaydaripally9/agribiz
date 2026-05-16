<?php
session_start();
if(!isset($_SESSION['admin'])){
    header('Location: index.php');
    exit();
}
include 'db.php';

$search = isset($_GET['search']) ? $_GET['search'] : '';
$query = "SELECT * FROM fertilizers WHERE fertilizer_name LIKE '%$search%' OR company_name LIKE '%$search%'";
$result = mysqli_query($conn, $query);
?>

<!DOCTYPE html>
<html>
<head>
<title>View Fertilizers</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<h2>Fertilizer List</h2>
<form method="GET">
<input type="text" name="search" placeholder="Search by name or company" value="<?php echo $search; ?>">
<button type="submit">Search</button>
</form>

<table border="1">
<tr>
<th>ID</th>
<th>Category</th>
<th>Name</th>
<th>Company</th>
<th>Quantity</th>
<th>Price</th>
<th>Actions</th>
</tr>

<?php while($row = mysqli_fetch_assoc($result)) { ?>
<tr>
<td><?php echo $row['id']; ?></td>
<td><span style="background-color: #E8F5E9; color: #2E7D32; padding: 3px 8px; border-radius: 12px; font-size: 12px; font-weight: bold;"><?php echo htmlspecialchars($row['category']); ?></span></td>
<td><?php echo htmlspecialchars($row['fertilizer_name']); ?></td>
<td><?php echo htmlspecialchars($row['company_name']); ?></td>
<td><?php echo htmlspecialchars($row['quantity']); ?></td>
<td>₹<?php echo htmlspecialchars($row['price']); ?></td>
<td>
<a href="update_fertilizer.php?id=<?php echo $row['id']; ?>">Edit</a> |
<a href="delete_fertilizer.php?id=<?php echo $row['id']; ?>" onclick="return confirm('Are you sure?')">Delete</a>
</td>
</tr>
<?php } ?>

</table>
<a href="dashboard.php">Back to Dashboard</a>
</body>
</html>