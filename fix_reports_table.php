<?php
// One-time script to recreate the `reports` table with region/zone/woreda columns

require_once __DIR__ . '/db.php';

$pdo = getPDO();

echo "<pre>Recreating reports table...\n";

// Drop old table if it exists
$pdo->exec("DROP TABLE IF EXISTS reports");

// Create new table with full structure
$createSql = "
CREATE TABLE reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT,
    region_id INT,
    zone_id INT,
    woreda_id INT,
    period_type ENUM('weekly','monthly','quarterly','annual') NOT NULL DEFAULT 'monthly',
    year INT NOT NULL,
    month INT DEFAULT NULL,
    week INT DEFAULT NULL,
    start_date DATE,
    end_date DATE,
    status ENUM('draft','submitted') NOT NULL DEFAULT 'submitted',
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    submitted_at TIMESTAMP NULL DEFAULT NULL,
    timeliness_score DECIMAL(5,2) DEFAULT 0,
    completeness_score DECIMAL(5,2) DEFAULT 0,
    accuracy_score DECIMAL(5,2) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

$pdo->exec($createSql);

echo "Done. `reports` table recreated successfully.\n";
echo "Now DELETE this file (fix_reports_table.php) from your folder.\n";
echo "</pre>";
