<?php
mysqli_report(MYSQLI_REPORT_OFF);

$envHost = getenv('DB_HOST');
$envPort = getenv('DB_PORT');
$envUser = getenv('DB_USER');
$envPassword = getenv('DB_PASSWORD');
$envDatabase = getenv('DB_NAME');

$database = $envDatabase ?: 'fertilizer_shop';
$attempts = [];

if ($envHost || $envPort || $envUser || $envPassword) {
    $attempts[] = [
        $envHost ?: '127.0.0.1',
        $envPort ? intval($envPort) : 3306,
        $envUser ?: 'root',
        $envPassword !== false ? $envPassword : '',
        $database,
    ];
}

$attempts[] = ['127.0.0.1', 3306, 'root', '', $database];
$attempts[] = ['127.0.0.1', 3307, 'root', '', $database];
$attempts[] = ['localhost', 3306, 'root', '', $database];

$errors = [];
$conn = null;

foreach ($attempts as $attempt) {
    list($host, $port, $user, $password, $db) = $attempt;
    $conn = @mysqli_connect($host, $user, $password, $db, $port);
    if ($conn) {
        break;
    }
    $errors[] = sprintf("%s:%s %s", $host, $port, mysqli_connect_error());
}

if (!$conn) {
    http_response_code(500);
    echo "<h1>Database connection failed</h1>";
    echo "<p>Please set the correct database credentials in <strong>db.php</strong> or via environment variables.</p>";
    echo "<p>Try one of these connections:</p>";
    echo "<ul>";
    foreach ($attempts as $attempt) {
        list($host, $port, $user) = $attempt;
        echo "<li>Host: " . htmlspecialchars($host) . ", Port: " . htmlspecialchars($port) . ", User: " . htmlspecialchars($user) . "</li>";
    }
    echo "</ul>";
    echo "<p>Errors:</p><pre>" . htmlspecialchars(implode("\n", $errors)) . "</pre>";
    exit();
}

// Auto-migrate: ensure loyalty points columns exist
@mysqli_query($conn, "ALTER TABLE customers ADD COLUMN IF NOT EXISTS points INT DEFAULT 0");
@mysqli_query($conn, "ALTER TABLE orders ADD COLUMN IF NOT EXISTS points_earned INT DEFAULT 0");
@mysqli_query($conn, "ALTER TABLE fertilizers ADD COLUMN IF NOT EXISTS barcode VARCHAR(100) AFTER id");
?>