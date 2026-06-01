<?php
session_start();
include 'db.php';
checkRole(['Admin', 'Accountant', 'Billing Staff']);

$id = $_GET['id'];
mysqli_query($conn, "DELETE FROM customers WHERE id = $id");
header('Location: customers.php');
exit();
?>