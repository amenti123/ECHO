<?php
/**
 * MySQL Connection Test and Troubleshooting Script
 * Helps diagnose MySQL connection issues
 */

echo "<h2>MySQL Connection Diagnostic Tool</h2>";
echo "<pre>";

// Test 1: Check if MySQL port is accessible
echo "=== Test 1: Port Check ===\n";
$port = 3306;
$connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2);
if ($connection) {
    echo "✓ Port $port is OPEN and accessible\n";
    fclose($connection);
} else {
    echo "✗ Port $port is CLOSED or not accessible\n";
    echo "  Error: $errstr ($errno)\n";
    echo "  This means MySQL is NOT running or not listening on port $port\n";
}

// Test 2: Try to connect to MySQL
echo "\n=== Test 2: MySQL Connection Test ===\n";
try {
    $pdo = new PDO(
        'mysql:host=127.0.0.1;port=3306',
        'root',
        '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
    );
    echo "✓ Successfully connected to MySQL!\n";
    $version = $pdo->query('SELECT VERSION()')->fetchColumn();
    echo "  MySQL Version: $version\n";
    
    // Test 3: Check if database exists
    echo "\n=== Test 3: Database Check ===\n";
    $dbName = 'health_system_reporting';
    $stmt = $pdo->query("SHOW DATABASES LIKE '$dbName'");
    if ($stmt->rowCount() > 0) {
        echo "✓ Database '$dbName' EXISTS\n";
    } else {
        echo "⚠ Database '$dbName' does NOT exist\n";
        echo "  Creating database...\n";
        try {
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            echo "✓ Database created successfully!\n";
        } catch (Exception $e) {
            echo "✗ Failed to create database: " . $e->getMessage() . "\n";
        }
    }
    
} catch (PDOException $e) {
    echo "✗ Failed to connect to MySQL\n";
    echo "  Error: " . $e->getMessage() . "\n";
    echo "\n  Possible causes:\n";
    echo "  1. MySQL service is not running\n";
    echo "  2. Wrong port (check if MySQL uses 3307 instead of 3306)\n";
    echo "  3. MySQL password is set (try with password)\n";
    echo "  4. MySQL is blocking connections\n";
}

// Test 4: Check MySQL process
echo "\n=== Test 4: Process Check ===\n";
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    $output = shell_exec('tasklist /FI "IMAGENAME eq mysqld.exe" 2>nul');
    if (strpos($output, 'mysqld.exe') !== false) {
        echo "✓ MySQL process (mysqld.exe) is RUNNING\n";
    } else {
        echo "✗ MySQL process (mysqld.exe) is NOT running\n";
    }
} else {
    $output = shell_exec('ps aux | grep mysqld | grep -v grep');
    if (!empty($output)) {
        echo "✓ MySQL process is RUNNING\n";
    } else {
        echo "✗ MySQL process is NOT running\n";
    }
}

// Test 5: Check XAMPP MySQL path
echo "\n=== Test 5: XAMPP MySQL Path Check ===\n";
$xamppPath = 'C:\\xampp2\\mysql\\bin\\mysqld.exe';
if (file_exists($xamppPath)) {
    echo "✓ MySQL executable found at: $xamppPath\n";
} else {
    echo "⚠ MySQL executable not found at: $xamppPath\n";
    echo "  Please check your XAMPP installation path\n";
}

// Test 6: Check MySQL data directory
echo "\n=== Test 6: MySQL Data Directory Check ===\n";
$dataDir = 'C:\\xampp2\\mysql\\data';
if (is_dir($dataDir)) {
    echo "✓ MySQL data directory exists: $dataDir\n";
    if (is_writable($dataDir)) {
        echo "✓ Data directory is writable\n";
    } else {
        echo "⚠ Data directory is NOT writable (may cause issues)\n";
    }
} else {
    echo "✗ MySQL data directory NOT found: $dataDir\n";
}

echo "\n=== Recommendations ===\n";
echo "1. Open XAMPP Control Panel\n";
echo "2. Click 'Stop' on MySQL (if it shows as running)\n";
echo "3. Wait 5 seconds\n";
echo "4. Click 'Start' on MySQL\n";
echo "5. Wait for it to show 'Running' status\n";
echo "6. Check if port 3306 is green\n";
echo "7. If it still doesn't work, check the 'Logs' button next to MySQL\n";
echo "\nIf MySQL keeps stopping:\n";
echo "- Check Windows Event Viewer for errors\n";
echo "- Check if another MySQL instance is running\n";
echo "- Try running XAMPP as Administrator\n";
echo "- Check if port 3306 is used by another application\n";

echo "</pre>";
?>

