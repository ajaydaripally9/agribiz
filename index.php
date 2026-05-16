<?php
session_start();
if (isset($_SESSION['customer'])) { header('Location: customer_shop.php'); exit(); }
if (isset($_SESSION['admin'])) { header('Location: dashboard.php'); exit(); }
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <link rel="icon" type="image/svg+xml" href="./favicon.svg" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>AgriBiz Pro — Advanced Farmer Intelligence</title>
    <script type="module" crossorigin src="./assets/index-DYH2vWSq.js"></script>
    <link rel="stylesheet" crossorigin href="./assets/index-Sy-9Tcww.css">
  </head>
  <body>
    <div id="root"></div>
  </body>
</html>
