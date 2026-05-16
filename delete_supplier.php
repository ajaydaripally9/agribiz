<?php
session_start();
if(!isset($_SESSION['admin'])){
    header('Location: index.php');
    exit();
}
include 'db.php';

$id = $_GET['id'];
mysqli_query($conn, "DELETE FROM suppliers WHERE id = $id");
header('Location: suppliers.php');
exit();
?>