<?php
function loadDotenv(string $path): void {
    if (!file_exists($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (!str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($value === 'null') {
            $value = '';
        }

        if (getenv($key) === false) {
            putenv("$key=$value");
        }
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

loadDotenv(__DIR__ . '/.env');

$host = $_SERVER['DB_HOST'] ?? $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1';
$port = $_SERVER['DB_PORT'] ?? $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '3307';
$user = $_SERVER['DB_USER'] ?? $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'root';
$pass = isset($_SERVER['DB_PASSWORD']) ? $_SERVER['DB_PASSWORD'] : (isset($_ENV['DB_PASSWORD']) ? $_ENV['DB_PASSWORD'] : (getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : ''));
$dbname = $_SERVER['DB_NAME'] ?? $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'fertilizer_shop';

// Connect to MySQL
$conn = mysqli_connect($host, $user, $pass, $dbname, $port);
if (!$conn) {
    die("Database Connection failed: " . mysqli_connect_error());
}

function add_column_if_not_exists($conn, $table, $column, $definition) {
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    if ($result && mysqli_num_rows($result) === 0) {
        return mysqli_query($conn, "ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
    return true;
}

function logAudit($conn, $action) {
    $user = $_SESSION['admin_username'] ?? $_SESSION['admin'] ?? 'unknown';
    $role = $_SESSION['admin_role'] ?? 'Admin';
    $ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $action_safe = mysqli_real_escape_string($conn, $action);
    $user_safe   = mysqli_real_escape_string($conn, $user);
    $role_safe   = mysqli_real_escape_string($conn, $role);
    @mysqli_query($conn, "INSERT INTO audit_log (user_name, role, action, ip) VALUES ('$user_safe', '$role_safe', '$action_safe', '$ip')");
}

function checkRole($allowed_roles) {
    if (!isset($_SESSION['admin'])) {
        header('Location: index.php');
        exit();
    }
    $role = $_SESSION['admin_role'] ?? 'Admin';
    if (!in_array($role, $allowed_roles)) {
        echo "<!DOCTYPE html>
        <html>
        <head>
            <title>Access Denied</title>
            <link href='https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap' rel='stylesheet'>
            <style>
                body { background: #0d1117; color: #e6edf3; font-family: 'Inter', sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
                .card { background: #161b22; border: 1px solid #30363d; padding: 40px; border-radius: 18px; text-align: center; max-width: 450px; }
                h1 { color: #ef4444; font-size: 24px; margin-bottom: 12px; }
                p { color: #8b949e; font-size: 14px; margin-bottom: 24px; line-height: 1.5; }
                .btn { background: #22c55e; color: #fff; padding: 10px 20px; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 13px; }
            </style>
        </head>
        <body>
            <div class='card'>
                <h1>🔒 Access Denied</h1>
                <p>Your current role <strong>\"" . htmlspecialchars($role) . "\"</strong> does not have permission to access this screen.</p>
                <a href='dashboard.php' class='btn'>Back to Dashboard</a>
            </div>
        </body>
        </html>";
        exit();
    }
}
?>
