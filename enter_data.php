<?php
// enter_data.php – Advanced Indicator Data Entry & Aggregation for Nexus Ethiopia

require_once __DIR__ . '/_preflight.php';
require_once __DIR__ . '/../helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_login();
$pdo = getPDO();

$action  = $_GET['action'] ?? '';
$message = '';
$error   = '';
$edit_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : null;

// ---------------------------------------------------------------------
// 0. Small helper: check if a table exists (to avoid 42S02 errors)
// ---------------------------------------------------------------------
if (!function_exists('table_exists')) {
    function table_exists(PDO $pdo, string $tableName): bool {
        if (function_exists('hrs_table_exists')) {
            return hrs_table_exists($pdo, $tableName);
        }
        try {
            $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$tableName]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

// Flags to know which indicator tables are really available
$hasImpactIndicators  = table_exists($pdo, 'impact_indicators');
$hasOutcomeIndicators = table_exists($pdo, 'outcome_indicators');
$hasOutputIndicators  = table_exists($pdo, 'output_indicators');
// =======================================================
// INDICATORS & LOCATION LOOKUPS (from planning.php tables)
// Drop-in block for enter_data.php
// =======================================================

// 1) Which project is selected?
// Note: This early selection is used for indicator loading below
// The main $selected_project_id is set later in section 5
$early_project_id = 0;
if (isset($_POST['project_id'])) {
    $early_project_id = (int)$_POST['project_id'];
} elseif (isset($_GET['project_id'])) {
    $early_project_id = (int)$_GET['project_id'];
} elseif (isset($_SESSION['selected_project_id'])) {
    // Auto-communicate with planning.php (project selected there is stored in session)
    $early_project_id = (int)$_SESSION['selected_project_id'];
}

// 2) Load indicators per level for this project
$impactIndicators  = [];
$outcomeIndicators = [];
$outputIndicators  = [];

if ($early_project_id > 0) {
    if ($hasImpactIndicators) {
        $stmt = $pdo->prepare("
            SELECT id, indicator_code AS code, indicator_name AS description, unit_type AS unit, input_mode, 
                   CASE WHEN unit_type LIKE '%person%' OR unit_type = 'persons' THEN 1 ELSE 0 END AS is_person_unit
            FROM impact_indicators
            WHERE project_id = ?
            ORDER BY id
        ");
        $stmt->execute([$early_project_id]);
        $impactIndicators = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($hasOutcomeIndicators) {
        $stmt = $pdo->prepare("
            SELECT id, indicator_code AS code, indicator_name AS description, unit_type AS unit, input_mode,
                   CASE WHEN unit_type LIKE '%person%' OR unit_type = 'persons' THEN 1 ELSE 0 END AS is_person_unit
            FROM outcome_indicators
            WHERE project_id = ?
            ORDER BY id
        ");
        $stmt->execute([$early_project_id]);
        $outcomeIndicators = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($hasOutputIndicators) {
        $stmt = $pdo->prepare("
            SELECT id, indicator_code AS code, indicator_name AS description, unit_type AS unit, input_mode,
                   CASE WHEN unit_type LIKE '%person%' OR unit_type = 'persons' THEN 1 ELSE 0 END AS is_person_unit
            FROM output_indicators
            WHERE project_id = ?
            ORDER BY id
        ");
        $stmt->execute([$early_project_id]);
        $outputIndicators = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// 3) Build a flat list that your <select name="indicator_key"> expects
//    (value pattern "level:id", label like "[Output] OP-01 – description")
$indicatorOptions = [];

$fmt = function($prefix, $row, $level) {
    $code = trim((string)($row['code'] ?? ''));
    if ($code === '') {
        $code = strtoupper(substr($level,0,1)) . '-' . (int)$row['id'];
    }
    $desc = trim((string)($row['description'] ?? ''));
    $unit = trim((string)($row['unit'] ?? ''));
    $label = '[' . ucfirst($level) . '] ' . $code;
    if ($desc !== '') $label .= ' – ' . $desc;
    if ($unit !== '') $label .= ' (' . $unit . ')';
    return [
        'value' => $level . ':' . (int)$row['id'],
        'label' => $label
    ];
};

foreach ($impactIndicators as $r)  { $indicatorOptions[] = $fmt('I',  $r, 'impact');  }
foreach ($outcomeIndicators as $r) { $indicatorOptions[] = $fmt('OC', $r, 'outcome'); }
foreach ($outputIndicators as $r)  { $indicatorOptions[] = $fmt('OP', $r, 'output');  }

// 4) Regions / Zones / Woredas lookups (simple server-side lists)
$regions = $zones = $woredas = [];

if (table_exists($pdo, 'regions')) {
    $regions = $pdo->query("SELECT id, name FROM regions ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}
if (table_exists($pdo, 'zones')) {
    $zones = $pdo->query("SELECT id, region_id, name FROM zones ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}
if (table_exists($pdo, 'woredas')) {
    $woredas = $pdo->query("SELECT id, zone_id, name FROM woredas ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}

// Optional: tiny helper for safe output if you don't already have h()
if (!function_exists('h')) {
    function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// ---------------------------------------------------------------------
// NEW: Load geographic lists for dropdowns
// ---------------------------------------------------------------------

$regions = [];
$zones   = [];
$woredas = [];

// Regions
if (table_exists($pdo, 'regions')) {
    try {
        $stmt = $pdo->query("SELECT id, name FROM regions ORDER BY name");
        $regions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $regions = [];
    }
}

// Zones
if (table_exists($pdo, 'zones')) {
    try {
        $stmt = $pdo->query("SELECT id, region_id, name FROM zones ORDER BY name");
        $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $zones = [];
    }
}

// Woredas
if (table_exists($pdo, 'woredas')) {
    try {
        $stmt = $pdo->query("SELECT id, zone_id, name FROM woredas ORDER BY name");
        $woredas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $woredas = [];
    }
}


// ---------------------------------------------------------------------
// 1. Ensure indicator_reports table & columns exist (for exports/queries)
// ---------------------------------------------------------------------
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS indicator_reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            indicator_level ENUM('impact','outcome','output') NOT NULL,
            indicator_id INT NOT NULL,
            period_type ENUM('weekly','monthly','quarterly','annual') NOT NULL,
            year INT NOT NULL,
            month TINYINT NULL,
            week TINYINT NULL,
            start_date DATE NULL,
            end_date DATE NULL,
            region_id INT NULL,
            zone_id INT NULL,
            woreda_id INT NULL,
            region_other VARCHAR(255) NULL,
            zone_other VARCHAR(255) NULL,
            woreda_other VARCHAR(255) NULL,
            boys_u5 INT DEFAULT 0,
            girls_u5 INT DEFAULT 0,
            boys_5_17 INT DEFAULT 0,
            girls_5_17 INT DEFAULT 0,
            men_18_59 INT DEFAULT 0,
            women_18_59 INT DEFAULT 0,
            men_60p INT DEFAULT 0,
            women_60p INT DEFAULT 0,
            pwd_boys_u5 INT DEFAULT 0,
            pwd_girls_u5 INT DEFAULT 0,
            pwd_boys_5_17 INT DEFAULT 0,
            pwd_girls_5_17 INT DEFAULT 0,
            pwd_men_18_59 INT DEFAULT 0,
            pwd_women_18_59 INT DEFAULT 0,
            pwd_men_60p INT DEFAULT 0,
            pwd_women_60p INT DEFAULT 0,
            pwd_count INT DEFAULT 0,
            non_beneficiary INT DEFAULT 0,
            non_person_value DECIMAL(18,2) DEFAULT 0,
            reported_by VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Alter existing table to ensure all needed columns exist (for older DBs)
    $alterStmts = [
        "ADD COLUMN indicator_level ENUM('impact','outcome','output') NOT NULL DEFAULT 'output'",
        "ADD COLUMN indicator_id INT NOT NULL DEFAULT 0",
        "ADD COLUMN period_type ENUM('weekly','monthly','quarterly','annual') NOT NULL DEFAULT 'monthly'",
        "ADD COLUMN year INT NOT NULL DEFAULT 2000",
        "ADD COLUMN month TINYINT NULL",
        "ADD COLUMN week TINYINT NULL",
        "ADD COLUMN start_date DATE NULL",
        "ADD COLUMN end_date DATE NULL",
        "ADD COLUMN region_id INT NULL",
        "ADD COLUMN zone_id INT NULL",
        "ADD COLUMN woreda_id INT NULL",
        "ADD COLUMN region_other VARCHAR(255) NULL",
        "ADD COLUMN zone_other VARCHAR(255) NULL",
        "ADD COLUMN woreda_other VARCHAR(255) NULL",
        "ADD COLUMN boys_u5 INT DEFAULT 0",
        "ADD COLUMN girls_u5 INT DEFAULT 0",
        "ADD COLUMN boys_5_17 INT DEFAULT 0",
        "ADD COLUMN girls_5_17 INT DEFAULT 0",
        "ADD COLUMN men_18_59 INT DEFAULT 0",
        "ADD COLUMN women_18_59 INT DEFAULT 0",
        "ADD COLUMN men_60p INT DEFAULT 0",
        "ADD COLUMN women_60p INT DEFAULT 0",
        "ADD COLUMN pwd_boys_u5 INT DEFAULT 0",
        "ADD COLUMN pwd_girls_u5 INT DEFAULT 0",
        "ADD COLUMN pwd_boys_5_17 INT DEFAULT 0",
        "ADD COLUMN pwd_girls_5_17 INT DEFAULT 0",
        "ADD COLUMN pwd_men_18_59 INT DEFAULT 0",
        "ADD COLUMN pwd_women_18_59 INT DEFAULT 0",
        "ADD COLUMN pwd_men_60p INT DEFAULT 0",
        "ADD COLUMN pwd_women_60p INT DEFAULT 0",
        "ADD COLUMN pwd_count INT DEFAULT 0",
        "ADD COLUMN non_beneficiary INT DEFAULT 0",
        "ADD COLUMN non_person_value DECIMAL(18,2) DEFAULT 0",
        "ADD COLUMN reported_by VARCHAR(255) NULL",
        "ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
    ];
    foreach ($alterStmts as $stmt) {
        try {
            $pdo->exec("ALTER TABLE indicator_reports {$stmt}");
        } catch (Exception $e) {
            // ignore duplicate column errors
        }
    }
} catch (Exception $e) {
    $error = 'Database error: ' . $e->getMessage();
}

// Get current user for reported_by field
$current_user = $_SESSION['user_name'] ?? $_SESSION['username'] ?? 'Unknown User';

// ---------------------------------------------------------------------
// 2. Handle POST (save / update / import CSV) BEFORE any export headers
//    CSV import is triggered ONLY when a CSV file is actually uploaded.
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // SAVE / UPDATE
    if (isset($_POST['save_report']) || isset($_POST['update_report'])) {
        try {
            // Get project_id from select dropdown or hidden field
            $project_id     = (int)($_POST['project_id'] ?? $_POST['project_id_hidden'] ?? 0);
            $indicator_key  = $_POST['indicator_key'] ?? '';
            $period_type    = $_POST['period_type'] ?? 'monthly';
            $year           = (int)($_POST['year'] ?? date('Y'));
            $month          = ($_POST['month'] ?? 'na') === 'na' ? null : (int)($_POST['month'] ?? 0);
            $week           = ($_POST['week'] ?? '') === '' ? null : (int)($_POST['week'] ?? 0);
            $start_date     = $_POST['start_date'] ?: null;
            $end_date       = $_POST['end_date'] ?: null;

            // Location & "other" fields
            $region_id      = ($_POST['region_id'] ?? '') !== '' && $_POST['region_id'] !== 'other'
                ? (int)$_POST['region_id'] : null;
            $zone_id        = ($_POST['zone_id'] ?? '') !== '' && $_POST['zone_id'] !== 'other'
                ? (int)$_POST['zone_id']   : null;
            $woreda_id      = ($_POST['woreda_id'] ?? '') !== '' && $_POST['woreda_id'] !== 'other'
                ? (int)$_POST['woreda_id'] : null;

            $region_other   = trim($_POST['region_other'] ?? '');
            $zone_other     = trim($_POST['zone_other'] ?? '');
            $woreda_other   = trim($_POST['woreda_other'] ?? '');

            $reported_by    = $current_user; // Auto-filled from session

            // SADD normal
            $boys_u5        = (int)($_POST['boys_u5'] ?? 0);
            $girls_u5       = (int)($_POST['girls_u5'] ?? 0);
            $boys_5_17      = (int)($_POST['boys_5_17'] ?? 0);
            $girls_5_17     = (int)($_POST['girls_5_17'] ?? 0);
            $men_18_59      = (int)($_POST['men_18_59'] ?? 0);
            $women_18_59    = (int)($_POST['women_18_59'] ?? 0);
            $men_60p        = (int)($_POST['men_60p'] ?? 0);
            $women_60p      = (int)($_POST['women_60p'] ?? 0);

            // SADD PWD
            $pwd_boys_u5     = (int)($_POST['pwd_boys_u5'] ?? 0);
            $pwd_girls_u5    = (int)($_POST['pwd_girls_u5'] ?? 0);
            $pwd_boys_5_17   = (int)($_POST['pwd_boys_5_17'] ?? 0);
            $pwd_girls_5_17  = (int)($_POST['pwd_girls_5_17'] ?? 0);
            $pwd_men_18_59   = (int)($_POST['pwd_men_18_59'] ?? 0);
            $pwd_women_18_59 = (int)($_POST['pwd_women_18_59'] ?? 0);
            $pwd_men_60p     = (int)($_POST['pwd_men_60p'] ?? 0);
            $pwd_women_60p   = (int)($_POST['pwd_women_60p'] ?? 0);

            $pwd_total       = $pwd_boys_u5 + $pwd_girls_u5 + $pwd_boys_5_17 + $pwd_girls_5_17 +
                               $pwd_men_18_59 + $pwd_women_18_59 + $pwd_men_60p + $pwd_women_60p;

            $non_beneficiary = (int)($_POST['non_beneficiary'] ?? 0);
            $non_person_value = isset($_POST['non_person_value']) && $_POST['non_person_value'] !== ''
                ? (float)$_POST['non_person_value']
                : 0.0;

            if (!$project_id || !$indicator_key) {
                throw new Exception('Project and indicator are required.');
            }

            if (strpos($indicator_key, ':') === false) {
                throw new Exception('Invalid indicator selection.');
            }

            [$indicator_level, $indicator_id] = explode(':', $indicator_key);
            $indicator_level = trim($indicator_level);
            $indicator_id    = (int)$indicator_id;

            // --------------------------
            // UPDATE existing report
            // --------------------------
            if (isset($_POST['update_report']) && !empty($_POST['report_id'])) {
                $reportId = (int)$_POST['report_id'];

                $sql = "
                    UPDATE indicator_reports
                    SET project_id      = ?, 
                        indicator_level = ?, 
                        indicator_id    = ?,
                        period_type     = ?, 
                        year            = ?, 
                        month           = ?, 
                        week            = ?,
                        start_date      = ?, 
                        end_date        = ?, 
                        region_id       = ?, 
                        zone_id         = ?, 
                        woreda_id       = ?,
                        region_other    = ?, 
                        zone_other      = ?, 
                        woreda_other    = ?, 
                        reported_by     = ?,
                        boys_u5         = ?, 
                        girls_u5        = ?, 
                        boys_5_17       = ?, 
                        girls_5_17      = ?,
                        men_18_59       = ?, 
                        women_18_59     = ?, 
                        men_60p         = ?, 
                        women_60p       = ?,
                        pwd_boys_u5     = ?, 
                        pwd_girls_u5    = ?, 
                        pwd_boys_5_17   = ?, 
                        pwd_girls_5_17  = ?,
                        pwd_men_18_59   = ?, 
                        pwd_women_18_59 = ?, 
                        pwd_men_60p     = ?, 
                        pwd_women_60p   = ?,
                        pwd_count       = ?, 
                        non_beneficiary = ?, 
                        non_person_value= ?
                    WHERE id = ?
                ";

                $params = [
                    $project_id, $indicator_level, $indicator_id,
                    $period_type, $year, $month, $week,
                    $start_date, $end_date, $region_id, $zone_id, $woreda_id,
                    $region_other ?: null, $zone_other ?: null, $woreda_other ?: null, $reported_by,
                    $boys_u5, $girls_u5, $boys_5_17, $girls_5_17,
                    $men_18_59, $women_18_59, $men_60p, $women_60p,
                    $pwd_boys_u5, $pwd_girls_u5, $pwd_boys_5_17, $pwd_girls_5_17,
                    $pwd_men_18_59, $pwd_women_18_59, $pwd_men_60p, $pwd_women_60p,
                    $pwd_total, $non_beneficiary, $non_person_value,
                    $reportId
                ];

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                $message = '✅ Report updated successfully!';
                $edit_id = $reportId;

            // --------------------------
            // INSERT new report
            // --------------------------
            } else {
                $sql = "
                    INSERT INTO indicator_reports
                    (
                        project_id, indicator_level, indicator_id,
                        period_type, year, month, week,
                        start_date, end_date, region_id, zone_id, woreda_id,
                        region_other, zone_other, woreda_other, reported_by,
                        boys_u5, girls_u5, boys_5_17, girls_5_17,
                        men_18_59, women_18_59, men_60p, women_60p,
                        pwd_boys_u5, pwd_girls_u5, pwd_boys_5_17, pwd_girls_5_17,
                        pwd_men_18_59, pwd_women_18_59, pwd_men_60p, pwd_women_60p,
                        pwd_count, non_beneficiary, non_person_value
                    )
                    VALUES (
                        ?,?,?,?,?,?,?,?,
                        ?,?,?,?,?,?,?,?,
                        ?,?,?,?,
                        ?,?,?,?,
                        ?,?,?,?,
                        ?,?,?,?,
                        ?,?,?
                    )
                ";

                $params = [
                    $project_id, $indicator_level, $indicator_id,
                    $period_type, $year, $month, $week,
                    $start_date, $end_date, $region_id, $zone_id, $woreda_id,
                    $region_other ?: null, $zone_other ?: null, $woreda_other ?: null, $reported_by,
                    $boys_u5, $girls_u5, $boys_5_17, $girls_5_17,
                    $men_18_59, $women_18_59, $men_60p, $women_60p,
                    $pwd_boys_u5, $pwd_girls_u5, $pwd_boys_5_17, $pwd_girls_5_17,
                    $pwd_men_18_59, $pwd_women_18_59, $pwd_men_60p, $pwd_women_60p,
                    $pwd_total, $non_beneficiary, $non_person_value
                ];

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                $message = '✅ Report saved successfully!';
            }

        } catch (Exception $e) {
            $error = '❌ Error saving/updating report: ' . $e->getMessage();
        }
    }

    // NOTE: other POST handlers (e.g. CSV import) can follow here...
}
     // CSV IMPORT – expects internal column names; template below matches this exactly.
    // Runs ONLY when a CSV file is actually uploaded (fixes the old "Please select CSV" bug).
    if (
        isset($_FILES['csv_file']) &&
        !empty($_FILES['csv_file']['tmp_name']) &&
        is_uploaded_file($_FILES['csv_file']['tmp_name'])
    ) {
        $tmpName = $_FILES['csv_file']['tmp_name'];
        if (($handle = fopen($tmpName, 'r')) !== false) {
            $header = fgetcsv($handle);
            if ($header !== false) {
                $header = array_map('strtolower', $header);
                $insertCount = 0;

                while (($row = fgetcsv($handle)) !== false) {
                    // Skip rows that don't match header column count
                    if (count($row) !== count($header)) {
                        continue;
                    }

                    $data = array_combine($header, $row);
                    if ($data === false) {
                        continue;
                    }

                    $project_id    = (int)($data['project_id'] ?? 0);
                    $indicator_lvl = $data['indicator_level'] ?? 'output';
                    $indicator_id  = (int)($data['indicator_id'] ?? 0);
                    $period_type   = $data['period_type'] ?? 'monthly';
                    $year          = (int)($data['year'] ?? date('Y'));
                    $month         = isset($data['month']) && $data['month'] !== '' ? (int)$data['month'] : null;
                    $week          = isset($data['week']) && $data['week'] !== '' ? (int)$data['week'] : null;

                    $start_date    = $data['start_date'] ?? null;
                    $end_date      = $data['end_date'] ?? null;

                    $region_id  = isset($data['region_id']) && $data['region_id'] !== '' ? (int)$data['region_id'] : null;
                    $zone_id    = isset($data['zone_id'])   && $data['zone_id']   !== '' ? (int)$data['zone_id']   : null;
                    $woreda_id  = isset($data['woreda_id']) && $data['woreda_id'] !== '' ? (int)$data['woreda_id'] : null;

                    // These are optional free-text "other" fields; only used if present
                    $region_other = $data['region_other'] ?? null;
                    $zone_other   = $data['zone_other']   ?? null;
                    $woreda_other = $data['woreda_other'] ?? null;

                    // If reported_by is empty in the CSV, fall back to the current user
                    $reported_by = trim($data['reported_by'] ?? '');
                    if ($reported_by === '') {
                        $reported_by = $current_user;
                    }

                    $boys_u5        = (int)($data['boys_u5'] ?? 0);
                    $girls_u5       = (int)($data['girls_u5'] ?? 0);
                    $boys_5_17      = (int)($data['boys_5_17'] ?? 0);
                    $girls_5_17     = (int)($data['girls_5_17'] ?? 0);
                    $men_18_59      = (int)($data['men_18_59'] ?? 0);
                    $women_18_59    = (int)($data['women_18_59'] ?? 0);
                    $men_60p        = (int)($data['men_60p'] ?? 0);
                    $women_60p      = (int)($data['women_60p'] ?? 0);

                    $pwd_boys_u5    = (int)($data['pwd_boys_u5'] ?? 0);
                    $pwd_girls_u5   = (int)($data['pwd_girls_u5'] ?? 0);
                    $pwd_boys_5_17  = (int)($data['pwd_boys_5_17'] ?? 0);
                    $pwd_girls_5_17 = (int)($data['pwd_girls_5_17'] ?? 0);
                    $pwd_men_18_59  = (int)($data['pwd_men_18_59'] ?? 0);
                    $pwd_women_18_59= (int)($data['pwd_women_18_59'] ?? 0);
                    $pwd_men_60p    = (int)($data['pwd_men_60p'] ?? 0);
                    $pwd_women_60p  = (int)($data['pwd_women_60p'] ?? 0);

                    $pwd_total = $pwd_boys_u5 + $pwd_girls_u5 + $pwd_boys_5_17 + $pwd_girls_5_17 +
                                 $pwd_men_18_59 + $pwd_women_18_59 + $pwd_men_60p + $pwd_women_60p;
                    if ($pwd_total === 0) {
                        $pwd_total = (int)($data['pwd_count'] ?? 0);
                    }

                    $non_beneficiary = (int)($data['non_beneficiary'] ?? 0);
                    $non_person_value = isset($data['non_person_value']) && $data['non_person_value'] !== ''
                        ? (float)$data['non_person_value']
                        : 0;

                    // Skip invalid rows
                    if (!$project_id || !$indicator_id) {
                        continue;
                    }

                    $stmt = $pdo->prepare("
                        INSERT INTO indicator_reports
                        (project_id, indicator_level, indicator_id,
                         period_type, year, month, week,
                         start_date, end_date, region_id, zone_id, woreda_id,
                         zone_other, woreda_other, reported_by,
                         boys_u5, girls_u5, boys_5_17, girls_5_17,
                         men_18_59, women_18_59, men_60p, women_60p,
                         pwd_boys_u5, pwd_girls_u5, pwd_boys_5_17, pwd_girls_5_17,
                         pwd_men_18_59, pwd_women_18_59, pwd_men_60p, pwd_women_60p,
                         pwd_count, non_beneficiary, non_person_value)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ");

                    $stmt->execute([
                        $project_id, $indicator_lvl, $indicator_id,
                        $period_type, $year, $month, $week,
                        $start_date, $end_date, $region_id, $zone_id, $woreda_id,
                        $zone_other, $woreda_other, $reported_by,
                        $boys_u5, $girls_u5, $boys_5_17, $girls_5_17,
                        $men_18_59, $women_18_59, $men_60p, $women_60p,
                        $pwd_boys_u5, $pwd_girls_u5, $pwd_boys_5_17, $pwd_girls_5_17,
                        $pwd_men_18_59, $pwd_women_18_59, $pwd_men_60p, $pwd_women_60p,
                        $pwd_total, $non_beneficiary, $non_person_value
                    ]);

                    $insertCount++;
                }

                fclose($handle);
                $message = "✅ CSV imported successfully ({$insertCount} rows).";
            } else {
                $error = '❌ CSV file is empty or invalid.';
            }
        } else {
            $error = '❌ Unable to open CSV file.';
        }
    }

// ---------------------------------------------------------------------
// 3. Aggregation EXPORT: Word / CSV in standard Nexus table format
// ---------------------------------------------------------------------
if ($action === 'export_aggregation_word' || $action === 'export_aggregation_csv') {
    $export_project_id = isset($_GET['project_id']) && $_GET['project_id'] !== ''
        ? (int)$_GET['project_id']
        : null;

    try {
        // Build SELECT parts that only reference tables that exist
        $indicatorCaseWhens = [];
        $unitCaseWhens      = [];
        $benefCaseWhens     = [];
        $joins              = [];

        if ($hasImpactIndicators) {
            $indicatorCaseWhens[] = "WHEN ir.indicator_level = 'impact'  THEN ii.indicator_name";
            $unitCaseWhens[]      = "WHEN ir.indicator_level = 'impact'  THEN ii.unit_type";
            $benefCaseWhens[]     = "WHEN ir.indicator_level = 'impact'  THEN ii.beneficiary_type";
            $joins[] = "LEFT JOIN impact_indicators  ii ON ir.indicator_level = 'impact'  AND ir.indicator_id = ii.id";
        }
        if ($hasOutcomeIndicators) {
            $indicatorCaseWhens[] = "WHEN ir.indicator_level = 'outcome' THEN oc.indicator_name";
            $unitCaseWhens[]      = "WHEN ir.indicator_level = 'outcome' THEN oc.unit_type";
            $benefCaseWhens[]     = "WHEN ir.indicator_level = 'outcome' THEN oc.beneficiary_type";
            $joins[] = "LEFT JOIN outcome_indicators oc ON ir.indicator_level = 'outcome' AND ir.indicator_id = oc.id";
        }
        if ($hasOutputIndicators) {
            $indicatorCaseWhens[] = "WHEN ir.indicator_level = 'output'  THEN oo.indicator_name";
            $unitCaseWhens[]      = "WHEN ir.indicator_level = 'output'  THEN oo.unit_type";
            $benefCaseWhens[]     = "WHEN ir.indicator_level = 'output'  THEN oo.beneficiary_type";
            $joins[] = "LEFT JOIN output_indicators  oo ON ir.indicator_level = 'output'  AND ir.indicator_id = oo.id";
        }

        if ($indicatorCaseWhens) {
            $indicatorExpr = "CASE " . implode(' ', $indicatorCaseWhens) . " ELSE CONCAT('Indicator #', ir.indicator_id) END AS indicator_name";
        } else {
            $indicatorExpr = "CONCAT('Indicator #', ir.indicator_id) AS indicator_name";
        }

        if ($unitCaseWhens) {
            $unitExpr = "CASE " . implode(' ', $unitCaseWhens) . " ELSE '' END AS unit_type";
        } else {
            $unitExpr = "'' AS unit_type";
        }

        if ($benefCaseWhens) {
            $benefExpr = "CASE " . implode(' ', $benefCaseWhens) . " ELSE '' END AS beneficiary_type";
        } else {
            $benefExpr = "'' AS beneficiary_type";
        }

        $sql = "
            SELECT
                ir.*,
                p.title AS project_title,
                p.description AS project_description,
                p.main_sector AS project_main_sector,
                p.specific_sector AS project_specific_sector,
                r.name  AS region_name,
                z.name  AS zone_name,
                w.name  AS woreda_name,
                {$indicatorExpr},
                {$unitExpr},
                {$benefExpr}
            FROM indicator_reports ir
            LEFT JOIN projects p ON ir.project_id = p.id
            LEFT JOIN regions  r ON ir.region_id = r.id
            LEFT JOIN zones    z ON ir.zone_id   = z.id
            LEFT JOIN woredas  w ON ir.woreda_id = w.id
            " . implode("\n", $joins) . "
        ";

        $params = [];
        if ($export_project_id) {
            $sql .= " WHERE ir.project_id = :pid";
            $params['pid'] = $export_project_id;
        }
        $sql .= " ORDER BY ir.project_id, ir.indicator_level, ir.indicator_id, ir.created_at";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $projectTitle = 'All projects';
        $projectDescription = '';
        $mainSector = '';
        $specificSector = '';
        $regionTitle  = 'N/A';
        $zoneTitle    = 'N/A';
        $woredaTitle  = 'N/A';
        $reportType   = 'N/A';
        $reportMonth  = 'N/A';
        $periodLabel  = '';

        if (!empty($rows)) {
            $projectTitle = $rows[0]['project_title'] ?? $projectTitle;
            $projectDescription = $rows[0]['project_description'] ?? '';
            $mainSector = $rows[0]['project_main_sector'] ?? '';
            $specificSector = $rows[0]['project_specific_sector'] ?? '';
            $regionTitle  = $rows[0]['region_name'] ?? $regionTitle;
            $zoneTitle    = $rows[0]['zone_name']   ?? $zoneTitle;
            $woredaTitle  = $rows[0]['woreda_name'] ?? $woredaTitle;

            $pType = $rows[0]['period_type'] ?? '';
            $year  = (int)($rows[0]['year'] ?? 0);
            $month = (int)($rows[0]['month'] ?? 0);
            $week  = (int)($rows[0]['week'] ?? 0);

            if ($pType) {
                $reportType = strtoupper($pType);
            }
            if ($pType === 'monthly' && $month >= 1 && $month <= 12) {
                $reportMonth = date('F', mktime(0,0,0,$month,1)) . " {$year}";
            } elseif ($pType === 'weekly' && $week > 0) {
                $reportMonth = "Week {$week}, {$year}";
            } elseif ($year > 0) {
                $reportMonth = (string)$year;
            }

            $start = $rows[0]['start_date'] ?? '';
            $end   = $rows[0]['end_date']   ?? '';
            if ($start && $end) {
                $periodLabel = "{$start} to {$end}";
            }
        }

        if ($action === 'export_aggregation_csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="Nexus_Project_Performance_Report.csv"');
            $out = fopen('php://output', 'w');

            // Enhanced Meta header with project sectors
            fputcsv($out, ["Nexus Ethiopia Project Performance Report"]);
            fputcsv($out, ["Project Title", $projectTitle]);
            fputcsv($out, ["Project Description", $projectDescription]);
            fputcsv($out, ["Main Sector", $mainSector]);
            fputcsv($out, ["Specific Sector", $specificSector]);
            fputcsv($out, ["Region", $regionTitle, "Zone", $zoneTitle, "Woreda", $woredaTitle]);
            fputcsv($out, ["Report type", $reportType, "Reporting period", $reportMonth, "Dates", $periodLabel]);
            fputcsv($out, ["Reported by", $current_user]);
            fputcsv($out, []); // blank line

            // Data header row
            fputcsv($out, [
                'SN',
                'Indicator type',
                'Indicator',
                'Beneficiary type',
                'Unit of measurement',
                'Boys <5',
                'Girls <5',
                'Boys 5–17',
                'Girls 5–17',
                'Male Adult 18–59',
                'Female Adult 18–59',
                'Male Elderly 60+',
                'Female Elderly 60+',
                'People With Disability',
                'Total Reached by age categories',
                'Non person (%, number, etc.)',
                'Women reached',
                'Girls reached',
                'Men reached',
                'Boys reached',
                'Total beneficiaries reached by sex',
                'Reported by'
            ]);

            $sn = 1;
            foreach ($rows as $r) {
                $boys_u5      = (int)$r['boys_u5'];
                $girls_u5     = (int)$r['girls_u5'];
                $boys_5_17    = (int)$r['boys_5_17'];
                $girls_5_17   = (int)$r['girls_5_17'];
                $men_18_59    = (int)$r['men_18_59'];
                $women_18_59  = (int)$r['women_18_59'];
                $men_60p      = (int)$r['men_60p'];
                $women_60p    = (int)$r['women_60p'];
                $pwd          = (int)$r['pwd_count'];
                $den          = (int)$r['non_beneficiary'];
                $non_person   = (float)$r['non_person_value'];

                $totalAge = $boys_u5 + $girls_u5 + $boys_5_17 + $girls_5_17 + $men_18_59 + $women_18_59 + $men_60p + $women_60p;
                $women    = $women_18_59 + $women_60p;
                $girls    = $girls_u5 + $girls_5_17;
                $men      = $men_18_59 + $men_60p;
                $boys     = $boys_u5 + $boys_5_17;
                $totalSex = $women + $girls + $men + $boys;

                $indicatorType = ucfirst((string)$r['indicator_level']); // impact/outcome/output

                // Non-person text (%, number etc.)
                $nonPersonText = '';
                if ($non_person > 0 && $den > 0) {
                    $pct = ($non_person / $den) * 100;
                    $nonPersonText = round($pct, 1) . '% (' . $non_person . '/' . $den . ')';
                } elseif ($non_person > 0) {
                    $nonPersonText = (string)$non_person;
                }

                fputcsv($out, [
                    $sn++,
                    $indicatorType,
                    $r['indicator_name'],
                    $r['beneficiary_type'],
                    $r['unit_type'],
                    $boys_u5,
                    $girls_u5,
                    $boys_5_17,
                    $girls_5_17,
                    $men_18_59,
                    $women_18_59,
                    $men_60p,
                    $women_60p,
                    $pwd,
                    $totalAge,
                    $nonPersonText,
                    $women,
                    $girls,
                    $men,
                    $boys,
                    $totalSex,
                    $r['reported_by'] ?? $current_user
                ]);
            }

            fclose($out);
            exit;
        } else { // export_aggregation_word
            header('Content-Type: application/vnd.ms-word');
            header('Content-Disposition: attachment; filename="Nexus_Project_Performance_Report.doc"');

            echo '<html><head><meta charset="UTF-8"><title>Nexus Ethiopia Project Performance Report</title></head><body>';
            echo '<h2 style="text-align:center;">Nexus Ethiopia Project Performance Report</h2>';

            echo '<p>Project Title : <strong>' . htmlspecialchars($projectTitle) . '</strong></p>';
            if ($projectDescription) {
                echo '<p>Project Description: <strong>' . htmlspecialchars($projectDescription) . '</strong></p>';
            }
            if ($mainSector) {
                echo '<p>Main Sector: <strong>' . htmlspecialchars($mainSector) . '</strong></p>';
            }
            if ($specificSector) {
                echo '<p>Specific Sector: <strong>' . htmlspecialchars($specificSector) . '</strong></p>';
            }
            echo '<p>Region: <strong>' . htmlspecialchars($regionTitle) . '</strong>  ';
            echo 'Zone: <strong>' . htmlspecialchars($zoneTitle) . '</strong>  ';
            echo 'Name of Woreda: <strong>' . htmlspecialchars($woredaTitle) . '</strong></p>';
            echo '<p>Report type: <strong>' . htmlspecialchars($reportType) . '</strong>  ';
            echo 'Reporting Month/Period: <strong>' . htmlspecialchars($reportMonth) . '</strong></p>';
            echo '<p>Date: From <strong>' . htmlspecialchars($periodLabel ?: '___________') . '</strong></p>';
            echo '<p>Reported by: <strong>' . htmlspecialchars($current_user) . '</strong></p>';

            echo '<table border="1" cellpadding="4" cellspacing="0" width="100%">';
            echo '<tr style="font-weight:bold; text-align:center;">';
            echo '<td rowspan="2">SN</td>';
            echo '<td rowspan="2">Indicator type</td>';
            echo '<td rowspan="2">Indicator</td>';
            echo '<td rowspan="2">Beneficiary type</td>';
            echo '<td rowspan="2">Unit of measurement</td>';
            echo '<td colspan="2">Boys / Girls &lt; 5</td>';
            echo '<td colspan="2">Boys / Girls 5–17</td>';
            echo '<td colspan="2">Male / Female Adult 18–59</td>';
            echo '<td colspan="2">Male / Female Elderly 60+</td>';
            echo '<td rowspan="2">People With Disability</td>';
            echo '<td rowspan="2">Total Reached by age categories</td>';
            echo '<td rowspan="2">Non person (%, number … etc)</td>';
            echo '<td rowspan="2">Women reached</td>';
            echo '<td rowspan="2">Girls reached</td>';
            echo '<td rowspan="2">Men reached</td>';
            echo '<td rowspan="2">Boys reached</td>';
            echo '<td rowspan="2">Total beneficiaries reached by sex</td>';
            echo '<td rowspan="2">Reported by</td>';
            echo '</tr>';

            echo '<tr style="font-weight:bold; text-align:center;">';
            echo '<td>Boys &lt; 5</td>';
            echo '<td>Girls &lt; 5</td>';
            echo '<td>Boys 5–17</td>';
            echo '<td>Girls 5–17</td>';
            echo '<td>Male Adult 18–59</td>';
            echo '<td>Female Adult 18–59</td>';
            echo '<td>Male Elderly 60+</td>';
            echo '<td>Female Elderly 60+</td>';
            echo '</tr>';

            $sn = 1;
            foreach ($rows as $r) {
                $boys_u5      = (int)$r['boys_u5'];
                $girls_u5     = (int)$r['girls_u5'];
                $boys_5_17    = (int)$r['boys_5_17'];
                $girls_5_17   = (int)$r['girls_5_17'];
                $men_18_59    = (int)$r['men_18_59'];
                $women_18_59  = (int)$r['women_18_59'];
                $men_60p      = (int)$r['men_60p'];
                $women_60p    = (int)$r['women_60p'];
                $pwd          = (int)$r['pwd_count'];
                $den          = (int)$r['non_beneficiary'];
                $non_person   = (float)$r['non_person_value'];

                $totalAge = $boys_u5 + $girls_u5 + $boys_5_17 + $girls_5_17 + $men_18_59 + $women_18_59 + $men_60p + $women_60p;
                $women    = $women_18_59 + $women_60p;
                $girls    = $girls_u5 + $girls_5_17;
                $men      = $men_18_59 + $men_60p;
                $boys     = $boys_u5 + $boys_5_17;
                $totalSex = $women + $girls + $men + $boys;

                $nonPersonText = '';
                if ($non_person > 0 && $den > 0) {
                    $pct = ($non_person / $den) * 100;
                    $nonPersonText = round($pct, 1) . '% (' . $non_person . '/' . $den . ')';
                } elseif ($non_person > 0) {
                    $nonPersonText = (string)$non_person;
                }

                $indicatorType = ucfirst((string)$r['indicator_level']);

                echo '<tr>';
                echo '<td>' . $sn++ . '</td>';
                echo '<td>' . htmlspecialchars($indicatorType) . '</td>';
                echo '<td>' . htmlspecialchars($r['indicator_name']) . '</td>';
                echo '<td>' . htmlspecialchars($r['beneficiary_type']) . '</td>';
                echo '<td>' . htmlspecialchars($r['unit_type']) . '</td>';
                echo '<td>' . $boys_u5 . '</td>';
                echo '<td>' . $girls_u5 . '</td>';
                echo '<td>' . $boys_5_17 . '</td>';
                echo '<td>' . $girls_5_17 . '</td>';
                echo '<td>' . $men_18_59 . '</td>';
                echo '<td>' . $women_18_59 . '</td>';
                echo '<td>' . $men_60p . '</td>';
                echo '<td>' . $women_60p . '</td>';
                echo '<td>' . $pwd . '</td>';
                echo '<td>' . $totalAge . '</td>';
                echo '<td>' . htmlspecialchars($nonPersonText) . '</td>';
                echo '<td>' . $women . '</td>';
                echo '<td>' . $girls . '</td>';
                echo '<td>' . $men . '</td>';
                echo '<td>' . $boys . '</td>';
                echo '<td>' . $totalSex . '</td>';
                echo '<td>' . htmlspecialchars($r['reported_by'] ?? $current_user) . '</td>';
                echo '</tr>';
            }
            echo '</table>';

            echo '<br><br>';
            echo '<table border="0" width="100%">';
            echo '<tr>';
            echo '<td width="50%"><strong>Report Verified by</strong></td>';
            echo '<td width="50%"><strong>Report Approved by</strong></td>';
            echo '</tr>';
            echo '<tr>';
            echo '<td>Name: ____________________</td>';
            echo '<td>Name: ____________________</td>';
            echo '</tr>';
            echo '<tr>';
            echo '<td>Position: ____________________</td>';
            echo '<td>Position: ____________________</td>';
            echo '</tr>';
            echo '<tr>';
            echo '<td>Signature: ____________________</td>';
            echo '<td>Signature: ____________________</td>';
            echo '</tr>';
            echo '<tr>';
            echo '<td>Date: ____________________</td>';
            echo '<td>Date: ____________________</td>';
            echo '</tr>';
            echo '</table>';

            echo <<<HTML

<script>
// HRS_INDICATOR_FILTER_UI: filter indicators by level + search
document.addEventListener('DOMContentLoaded', function() {
    const sel = document.getElementById('indicator_key');
    if (!sel) return;

    // Build UI controls
    const container = document.createElement('div');
    container.style.marginBottom = '8px';
    container.innerHTML = `
        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
            <strong style="margin-right:6px;">Filter:</strong>
            <button type="button" class="btn btn-sm" data-level="all">All</button>
            <button type="button" class="btn btn-sm" data-level="impact">Impact</button>
            <button type="button" class="btn btn-sm" data-level="outcome">Outcome</button>
            <button type="button" class="btn btn-sm" data-level="output">Output</button>
            <input type="text" id="hrsIndSearch" placeholder="Search indicator..." style="flex:1; min-width:220px; padding:6px 10px; border:1px solid #ddd; border-radius:8px;">
        </div>
    `;

    // Insert controls right before the select
    sel.parentElement.insertBefore(container, sel);

    const buttons = container.querySelectorAll('button[data-level]');
    const search  = container.querySelector('#hrsIndSearch');

    // Keep a copy of all original options
    const original = Array.from(sel.options).map(o => ({
        value: o.value,
        text: o.text,
        disabled: o.disabled,
        selected: o.selected
    }));

    function applyFilter(level, q) {
        q = (q || '').toLowerCase().trim();
        sel.innerHTML = '';
        original.forEach(o => {
            if (!o.value) { // keep placeholder
                const opt = new Option(o.text, o.value);
                opt.disabled = o.disabled;
                sel.add(opt);
                return;
            }
            const val = String(o.value);
            const txt = String(o.text || '');
            const lv  = val.split(':')[0].toLowerCase();

            if (level && level !== 'all' && lv !== level) return;
            if (q && !txt.toLowerCase().includes(q)) return;

            const opt = new Option(txt, val);
            sel.add(opt);
        });
    }

    buttons.forEach(btn => {
        btn.addEventListener('click', function() {
            buttons.forEach(b => b.classList.remove('btn-primary'));
            btn.classList.add('btn-primary');
            applyFilter(btn.getAttribute('data-level') || 'all', search.value);
        });
    });

    if (search) {
        search.addEventListener('input', function() {
            const active = container.querySelector('button.btn-primary[data-level]');
            const level = active ? active.getAttribute('data-level') : 'all';
            applyFilter(level || 'all', search.value);
        });
    }

    // default: All
    const firstBtn = container.querySelector('button[data-level="all"]');
    if (firstBtn) firstBtn.click();
});
</script>

</body></html>
HTML;

            exit;
        }

    } catch (Exception $e) {
        $error = 'Export error: ' . $e->getMessage();
    }
}

// ---------------------------------------------------------------------
// 4. NEW: Project-specific template downloads (CSV / Word templates with project header)
// ---------------------------------------------------------------------
if ($action === 'download_project_csv_template') {
    $template_project_id = isset($_GET['project_id']) && $_GET['project_id'] !== ''
        ? (int)$_GET['project_id']
        : null;

    if (!$template_project_id) {
        die('Project ID is required for template download.');
    }

    // Get project details for header
    try {
        $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
        $stmt->execute([$template_project_id]);
        $project = $stmt->fetch();
        
        if (!$project) {
            die('Project not found.');
        }
    } catch (Exception $e) {
        die('Error fetching project details: ' . $e->getMessage());
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . htmlspecialchars($project['title']) . '_indicator_template.csv"');

    $out = fopen('php://output', 'w');

    // Enhanced header with project information
    fputcsv($out, ["Nexus Ethiopia - Indicator Reporting Template"]);
    fputcsv($out, ["Project:", $project['title']]);
    fputcsv($out, ["Project Description:", $project['description'] ?? '']);
    fputcsv($out, ["Main Sector:", $project['main_sector'] ?? '']);
    fputcsv($out, ["Specific Sector:", $project['specific_sector'] ?? '']);
    fputcsv($out, ["Generated on:", date('Y-m-d')]);
    fputcsv($out, ["Instructions:", "Fill in the data below. Leave blank if not applicable."]);
    fputcsv($out, []); // Empty line

    // Header row exactly as the import code expects
    fputcsv($out, [
        'project_id',
        'indicator_level',
        'indicator_id',
        'period_type',
        'year',
        'month',
        'week',
        'start_date',
        'end_date',
        'region_id',
        'zone_id',
        'woreda_id',
        'zone_other',
        'woreda_other',
        'boys_u5',
        'girls_u5',
        'boys_5_17',
        'girls_5_17',
        'men_18_59',
        'women_18_59',
        'men_60p',
        'women_60p',
        'pwd_boys_u5',
        'pwd_girls_u5',
        'pwd_boys_5_17',
        'pwd_girls_5_17',
        'pwd_men_18_59',
        'pwd_women_18_59',
        'pwd_men_60p',
        'pwd_women_60p',
        'pwd_count',
        'non_beneficiary',
        'non_person_value'
    ]);

    // One example row with project_id pre-filled
    $example_row = array_fill(0, 34, '');
    $example_row[0] = $template_project_id; // Pre-fill project_id
    $example_row[3] = 'monthly'; // Example period_type
    $example_row[4] = date('Y'); // Current year
    $example_row[5] = date('n'); // Current month
    
    fputcsv($out, $example_row);

    fclose($out);
    exit;
}

if ($action === 'download_project_word_template') {
    $template_project_id = isset($_GET['project_id']) && $_GET['project_id'] !== ''
        ? (int)$_GET['project_id']
        : null;

    if (!$template_project_id) {
        die('Project ID is required for template download.');
    }

    // Get project details for header
    try {
        $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
        $stmt->execute([$template_project_id]);
        $project = $stmt->fetch();
        
        if (!$project) {
            die('Project not found.');
        }
    } catch (Exception $e) {
        die('Error fetching project details: ' . $e->getMessage());
    }

    header('Content-Type: application/vnd.ms-word');
    header('Content-Disposition: attachment; filename="' . htmlspecialchars($project['title']) . '_report_template.doc"');
    
    echo '<html><head><meta charset="UTF-8"><title>Nexus Ethiopia Project Performance Report Template - ' . htmlspecialchars($project['title']) . '</title></head><body>';

    // Project header information
    echo '<h2 style="text-align:center;">Nexus Ethiopia Project Performance Report</h2>';
    echo '<h3 style="text-align:center;">Project: ' . htmlspecialchars($project['title']) . '</h3>';
    
    if (!empty($project['description'])) {
        echo '<p><strong>Project Description:</strong> ' . htmlspecialchars($project['description']) . '</p>';
    }
    if (!empty($project['main_sector'])) {
        echo '<p><strong>Main Sector:</strong> ' . htmlspecialchars($project['main_sector']) . '</p>';
    }
    if (!empty($project['specific_sector'])) {
        echo '<p><strong>Specific Sector:</strong> ' . htmlspecialchars($project['specific_sector']) . '</p>';
    }
    
    echo '<p><strong>Template Generated on:</strong> ' . date('Y-m-d') . '</p>';
    echo '<p><strong>Reported by:</strong> ' . htmlspecialchars($current_user) . '</p>';
    echo '<hr>';

    for ($section = 1; $section <= 2; $section++) {
        echo '<h3 style="text-align:center;">Nexus Ethiopia Project Performance Report</h3>';
        echo '<p>Project Title : ' . htmlspecialchars($project['title']) . '</p>';
        echo '<p>Region: ___________  Zone: ___________  Name of Woreda: ___________</p>';
        echo '<p>Report type: ___________  Reporting Month: ______  Date: From ___________ to ___________</p>';

        echo '<table border="1" cellpadding="4" cellspacing="0" width="100%">';
        echo '<tr style="font-weight:bold; text-align:center;">';
        echo '<td rowspan="2">SN</td>';
        echo '<td rowspan="2">Indicator type</td>';
        echo '<td rowspan="2">Indicator</td>';
        echo '<td rowspan="2">Beneficiary type</td>';
        echo '<td rowspan="2">Unit of measurement</td>';
        echo '<td colspan="2">Boys / Girls &lt; 5</td>';
        echo '<td colspan="2">Boys / Girls 5–17</td>';
        echo '<td colspan="2">Male / Female Adult 18–59</td>';
        echo '<td colspan="2">Male / Female Elderly 60+</td>';
        echo '<td rowspan="2">People With Disability</td>';
        echo '<td rowspan="2">Total Reached by age categories</td>';
        echo '<td rowspan="2">Non person (%, number …etc)</td>';
        echo '<td rowspan="2">Women reached</td>';
        echo '<td rowspan="2">Girls reached</td>';
        echo '<td rowspan="2">Men reached</td>';
        echo '<td rowspan="2">Boys reached</td>';
        echo '<td rowspan="2">Total Beneficiaries reached by sex</td>';
        echo '</tr>';
        echo '<tr style="font-weight:bold; text-align:center;">';
        echo '<td>Boys &lt; 5</td>';
        echo '<td>Girls &lt; 5</td>';
        echo '<td>Boys 5 to 17</td>';
        echo '<td>Girls 5 to 17</td>';
        echo '<td>Male Adult 18 to 59</td>';
        echo '<td>Female Adult 18 to 59</td>';
        echo '<td>Male Elderly 60+</td>';
        echo '<td>Female Elderly 60+</td>';
        echo '</tr>';

        // Template rows for Host, IDP, Returnee, Refugee, PWD, Total
        $beneficiaries = ['Host community', 'IDP', 'Returnee', 'Refugee', 'People with disability', 'Total'];
        $snRow = 1;
        foreach ($beneficiaries as $b) {
            echo '<tr>';
            echo '<td>' . ($snRow === 1 ? $snRow : '') . '</td>';
            echo '<td>Indicator</td>';
            echo '<td>Indicator</td>';
            echo '<td>' . htmlspecialchars($b) . '</td>';
            echo '<td>Number</td>';
            for ($i = 0; $i < 8; $i++) {
                echo '<td>&nbsp;</td>';
            }
            echo '<td>&nbsp;</td>'; // PWD
            echo '<td>&nbsp;</td>'; // Total by age
            echo '<td>&nbsp;</td>'; // Non person
            echo '<td>&nbsp;</td>'; // Women
            echo '<td>&nbsp;</td>'; // Girls
            echo '<td>&nbsp;</td>'; // Men
            echo '<td>&nbsp;</td>'; // Boys
            echo '<td>&nbsp;</td>'; // Total by sex
            echo '</tr>';
            $snRow++;
        }

        echo '</table>';

        echo '<br><table border="0" width="100%">';
        echo '<tr>';
        echo '<th colspan="2">Report Verified by</th>';
        echo '<th colspan="2">Report Approved by</th>';
        echo '</tr>';
        echo '<tr>';
        echo '<td>Name:</td><td>&nbsp;</td>';
        echo '<td>Name:</td><td>&nbsp;</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<td>Position:</td><td>&nbsp;</td>';
        echo '<td>Position:</td><td>&nbsp;</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<td>Signature:</td><td>&nbsp;</td>';
        echo '<td>Signature:</td><td>&nbsp;</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<td>Date:</td><td>&nbsp;</td>';
        echo '<td>Date:</td><td>&nbsp;</td>';
        echo '</tr>';
        echo '</table>';

        if ($section < 2) {
            echo '<div style="page-break-after: always;"></div>';
        }
    }

    echo '</body></html>';
    exit;
}

// Keep existing template downloads for backward compatibility
if ($action === 'download_csv_template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="indicator_reports_import_template.csv"');

    $out = fopen('php://output', 'w');

    // Header row exactly as the import code expects
    fputcsv($out, [
        'project_id',
        'indicator_level',
        'indicator_id',
        'period_type',
        'year',
        'month',
        'week',
        'start_date',
        'end_date',
        'region_id',
        'zone_id',
        'woreda_id',
        'zone_other',
        'woreda_other',
        'boys_u5',
        'girls_u5',
        'boys_5_17',
        'girls_5_17',
        'men_18_59',
        'women_18_59',
        'men_60p',
        'women_60p',
        'pwd_boys_u5',
        'pwd_girls_u5',
        'pwd_boys_5_17',
        'pwd_girls_5_17',
        'pwd_men_18_59',
        'pwd_women_18_59',
        'pwd_men_60p',
        'pwd_women_60p',
        'pwd_count',
        'non_beneficiary',
        'non_person_value'
    ]);

    // One blank row
    fputcsv($out, array_fill(0, 34, ''));

    fclose($out);
    exit;
}

// ---------------------------------------------------------------------
// 5. Projects & selected project
// ---------------------------------------------------------------------
$projects = get_projects();

// Use the project_id from early in the file if it was set, otherwise check POST/GET/SESSION
$selected_project_id = null;
if ($early_project_id > 0) {
    // Use the one from early in file if it was set
    $selected_project_id = $early_project_id;
} elseif (!empty($_POST['project_id'])) {
    $selected_project_id = (int)$_POST['project_id'];
} elseif (!empty($_GET['project_id'])) {
    $selected_project_id = (int)$_GET['project_id'];
} elseif (!empty($_SESSION['selected_project_id'])) {
    $selected_project_id = (int)$_SESSION['selected_project_id'];
} elseif (!empty($projects)) {
    $selected_project_id = (int)$projects[0]['id'];
}
if ($selected_project_id) {
    $_SESSION['selected_project_id'] = $selected_project_id;
}

// Fetch full project details for auto header / description
$selected_project = null;
if ($selected_project_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
        $stmt->execute([$selected_project_id]);
        $selected_project = $stmt->fetch();
    } catch (Exception $e) {
        $selected_project = null;
    }
}

// ---------------------------------------------------------------------
// 6. Regions / zones / woredas
// ---------------------------------------------------------------------
$regions = [];
try {
    if (function_exists('get_regions')) {
        $regions = get_regions();
    } else {
        $regions = $pdo->query("SELECT id, name FROM regions ORDER BY name")->fetchAll();
    }
} catch (Throwable $e) {
    $regions = [];
}

$zones = [];
$woredas = [];
try {
    $zones = $pdo->query("SELECT id, name, region_id FROM zones ORDER BY name")->fetchAll();
    $woredas = $pdo->query("SELECT id, name, zone_id FROM woredas ORDER BY name")->fetchAll();
} catch (Throwable $e) {
    $zones = [];
    $woredas = [];
}

$zones_js = [];
foreach ($zones as $z) {
    $zones_js[] = [
        'id'        => (int)$z['id'],
        'name'      => $z['name'],
        'region_id' => (int)$z['region_id']
    ];
}
$woredas_js = [];
foreach ($woredas as $w) {
    $woredas_js[] = [
        'id'      => (int)$w['id'],
        'name'    => $w['name'],
        'zone_id' => (int)$w['zone_id']
    ];
}

// ---------------------------------------------------------------------
// 7. Edit mode
// ---------------------------------------------------------------------
$editingReport = null;
if ($edit_id) {
    $stmt = $pdo->prepare("SELECT * FROM indicator_reports WHERE id = ?");
    $stmt->execute([$edit_id]);
    $editingReport = $stmt->fetch();
    if ($editingReport) {
        $selected_project_id = (int)$editingReport['project_id'];
        $_SESSION['selected_project_id'] = $selected_project_id;
    } else {
        $edit_id = null;
    }
}

$period_type_val = $editingReport['period_type'] ?? 'monthly';
$year_val        = $editingReport['year'] ?? (int)date('Y');
$month_val       = isset($editingReport['month']) && $editingReport['month'] ? (int)$editingReport['month'] : (int)date('n');
$week_val        = $editingReport['week'] ?? '';
$start_date_val  = $editingReport['start_date'] ?? date('Y-m-01');
$end_date_val    = $editingReport['end_date'] ?? date('Y-m-t');

$edit_region_id  = $editingReport['region_id'] ?? null;
$edit_zone_id    = $editingReport['zone_id'] ?? null;
$edit_woreda_id  = $editingReport['woreda_id'] ?? null;

// ---------------------------------------------------------------------
// 8. Indicators for selected project (with robust fallback so list is
//    NEVER empty if indicators exist in planning.php)
// ---------------------------------------------------------------------
$indicators = [];

try {
    // 8.1 Project-specific indicators first (normal case)
    if ($selected_project_id) {
        if ($hasImpactIndicators) {
            $stmt = $pdo->prepare("
                SELECT
                    'impact' AS level,
                    ii.id,
                    ii.project_id,
                    ii.indicator_code,
                    ii.indicator_name,
                    rc.code AS result_code,
                    rc.name AS result_name,
                    ii.unit_type,
                    ii.beneficiary_type,
                    p.title AS project_title
                FROM impact_indicators ii
                LEFT JOIN results_chain rc ON rc.id = ii.result_id
                LEFT JOIN projects      p  ON p.id = ii.project_id
                WHERE ii.project_id = ?
                ORDER BY rc.id, ii.id
            ");
            $stmt->execute([$selected_project_id]);
            $indicators = array_merge($indicators, $stmt->fetchAll());
        }

        if ($hasOutcomeIndicators) {
            $stmt = $pdo->prepare("
                SELECT
                    'outcome' AS level,
                    oi.id,
                    oi.project_id,
                    oi.indicator_code,
                    oi.indicator_name,
                    rc.code AS result_code,
                    rc.name AS result_name,
                    oi.unit_type,
                    oi.beneficiary_type,
                    p.title AS project_title
                FROM outcome_indicators oi
                LEFT JOIN results_chain rc ON rc.id = oi.result_id
                LEFT JOIN projects      p  ON p.id = oi.project_id
                WHERE oi.project_id = ?
                ORDER BY rc.id, oi.id
            ");
            $stmt->execute([$selected_project_id]);
            $indicators = array_merge($indicators, $stmt->fetchAll());
        }

        if ($hasOutputIndicators) {
            $stmt = $pdo->prepare("
                SELECT
                    'output' AS level,
                    oo.id,
                    oo.project_id,
                    oo.indicator_code,
                    oo.indicator_name,
                    rc.code AS result_code,
                    rc.name AS result_name,
                    oo.unit_type,
                    oo.beneficiary_type,
                    p.title AS project_title
                FROM output_indicators oo
                LEFT JOIN results_chain rc ON rc.id = oo.result_id
                LEFT JOIN projects      p  ON p.id = oo.project_id
                WHERE oo.project_id = ?
                ORDER BY rc.id, oo.id
            ");
            $stmt->execute([$selected_project_id]);
            $indicators = array_merge($indicators, $stmt->fetchAll());
        }
    }

    // 8.2 Fallback: if nothing came back (project_id mismatch, old data, etc),
    //     load ALL indicators so the dropdown is never empty.
    if (empty($indicators)) {
        if ($hasImpactIndicators) {
            $stmt = $pdo->query("
                SELECT
                    'impact' AS level,
                    ii.id,
                    ii.project_id,
                    ii.indicator_code,
                    ii.indicator_name,
                    rc.code AS result_code,
                    rc.name AS result_name,
                    ii.unit_type,
                    ii.beneficiary_type,
                    p.title AS project_title
                FROM impact_indicators ii
                LEFT JOIN results_chain rc ON rc.id = ii.result_id
                LEFT JOIN projects      p  ON p.id = ii.project_id
                ORDER BY p.title, rc.id, ii.id
            ");
            $indicators = array_merge($indicators, $stmt->fetchAll());
        }

        if ($hasOutcomeIndicators) {
            $stmt = $pdo->query("
                SELECT
                    'outcome' AS level,
                    oi.id,
                    oi.project_id,
                    oi.indicator_code,
                    oi.indicator_name,
                    rc.code AS result_code,
                    rc.name AS result_name,
                    oi.unit_type,
                    oi.beneficiary_type,
                    p.title AS project_title
                FROM outcome_indicators oi
                LEFT JOIN results_chain rc ON rc.id = oi.result_id
                LEFT JOIN projects      p  ON p.id = oi.project_id
                ORDER BY p.title, rc.id, oi.id
            ");
            $indicators = array_merge($indicators, $stmt->fetchAll());
        }

        if ($hasOutputIndicators) {
            $stmt = $pdo->query("
                SELECT
                    'output' AS level,
                    oo.id,
                    oo.project_id,
                    oo.indicator_code,
                    oo.indicator_name,
                    rc.code AS result_code,
                    rc.name AS result_name,
                    oo.unit_type,
                    oo.beneficiary_type,
                    p.title AS project_title
                FROM output_indicators oo
                LEFT JOIN results_chain rc ON rc.id = oo.result_id
                LEFT JOIN projects      p  ON p.id = oo.project_id
                ORDER BY p.title, rc.id, oo.id
            ");
            $indicators = array_merge($indicators, $stmt->fetchAll());
        }
    }

} catch (Exception $e) {
    // If indicator tables are missing we just leave the list empty
    $indicators = [];
}

// ---------------------------------------------------------------------
// 9. Recent saved reports (with indicator info for aggregation)
// ---------------------------------------------------------------------
$recentReports = [];
$agg_person_total = 0;
$agg_pwd_total = 0;
$agg_non_person_total = 0;

try {
    $indicatorCaseWhens = [];
    $unitCaseWhens      = [];
    $benefCaseWhens     = [];
    $joins              = [];

    if ($hasImpactIndicators) {
        $indicatorCaseWhens[] = "WHEN ir.indicator_level = 'impact'  THEN ii.indicator_name";
        $unitCaseWhens[]      = "WHEN ir.indicator_level = 'impact'  THEN ii.unit_type";
        $benefCaseWhens[]     = "WHEN ir.indicator_level = 'impact'  THEN ii.beneficiary_type";
        $joins[] = "LEFT JOIN impact_indicators  ii ON ir.indicator_level = 'impact'  AND ir.indicator_id = ii.id";
    }
    if ($hasOutcomeIndicators) {
        $indicatorCaseWhens[] = "WHEN ir.indicator_level = 'outcome' THEN oc.indicator_name";
        $unitCaseWhens[]      = "WHEN ir.indicator_level = 'outcome' THEN oc.unit_type";
        $benefCaseWhens[]     = "WHEN ir.indicator_level = 'outcome' THEN oc.beneficiary_type";
        $joins[] = "LEFT JOIN outcome_indicators oc ON ir.indicator_level = 'outcome' AND ir.indicator_id = oc.id";
    }
    if ($hasOutputIndicators) {
        $indicatorCaseWhens[] = "WHEN ir.indicator_level = 'output'  THEN oo.indicator_name";
        $unitCaseWhens[]      = "WHEN ir.indicator_level = 'output'  THEN oo.unit_type";
        $benefCaseWhens[]     = "WHEN ir.indicator_level = 'output'  THEN oo.beneficiary_type";
        $joins[] = "LEFT JOIN output_indicators  oo ON ir.indicator_level = 'output'  AND ir.indicator_id = oo.id";
    }

    if ($indicatorCaseWhens) {
        $indicatorExpr = "CASE " . implode(' ', $indicatorCaseWhens) . " ELSE CONCAT('Indicator #', ir.indicator_id) END AS indicator_name";
    } else {
        $indicatorExpr = "CONCAT('Indicator #', ir.indicator_id) AS indicator_name";
    }

    if ($unitCaseWhens) {
        $unitExpr = "CASE " . implode(' ', $unitCaseWhens) . " ELSE '' END AS unit_type";
    } else {
        $unitExpr = "'' AS unit_type";
    }

    if ($benefCaseWhens) {
        $benefExpr = "CASE " . implode(' ', $benefCaseWhens) . " ELSE '' END AS beneficiary_type";
    } else {
        $benefExpr = "'' AS beneficiary_type";
    }

    $sql = "
        SELECT
            ir.*,
            p.title AS project_title,
            p.main_sector AS project_main_sector,
            p.specific_sector AS project_specific_sector,
            r.name  AS region_name,
            z.name  AS zone_name,
            w.name  AS woreda_name,
            {$indicatorExpr},
            {$unitExpr},
            {$benefExpr}
        FROM indicator_reports ir
        LEFT JOIN projects p ON ir.project_id = p.id
        LEFT JOIN regions  r ON ir.region_id = r.id
        LEFT JOIN zones    z ON ir.zone_id   = z.id
        LEFT JOIN woredas  w ON ir.woreda_id = w.id
        " . implode("\n", $joins) . "
    ";
    $params = [];
    if ($selected_project_id) {
        $sql .= " WHERE ir.project_id = :pid";
        $params['pid'] = $selected_project_id;
    }
    $sql .= " ORDER BY ir.created_at DESC LIMIT 50";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $recentReports = $stmt->fetchAll();

    foreach ($recentReports as $r) {
        $rowPersons = ($r['boys_u5'] + $r['girls_u5'] +
                       $r['boys_5_17'] + $r['girls_5_17'] +
                       $r['men_18_59'] + $r['women_18_59'] +
                       $r['men_60p'] + $r['women_60p']);
        if ($r['non_person_value'] > 0) {
            $agg_non_person_total += (float)$r['non_person_value'];
        } else {
            $agg_person_total += $rowPersons;
        }
        $agg_pwd_total += (int)$r['pwd_count'];
    }
} catch (Exception $e) {
    // ignore
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Nexus Ethiopia Project Performance Report</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
/* (CSS remains the same as original, just adding the new features) */
:root {
    --primary: #4361ee;
    --secondary: #3f37c9;
    --accent: #f72585;
    --success: #4cc9f0;
    --light: #f8f9fa;
    --dark: #212529;
    --radius: 12px;
    --shadow: 0 8px 24px rgba(0,0,0,0.1);
    --transition: all .3s ease;
    --gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}
body {
    margin: 0;
    padding: 0;
    font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    background: #f0f2f5;
}
.page-container {
    max-width: 1200px;
    margin: 20px auto 60px;
    padding: 0 10px;
}
.card {
    background: #fff;
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    margin-bottom: 20px;
    overflow: hidden;
}
.card-header {
    padding: 16px 20px;
    background: var(--gradient);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.card-header h2 {
    margin: 0;
    font-size: 1.1rem;
    display: flex;
    align-items: center;
    gap: 8px;
}
.card-body {
    padding: 20px;
}
.hero-title {
    font-size: 1.6rem;
    font-weight: 800;
    display: flex;
    align-items: center;
    gap: 10px;
    text-decoration: underline wavy #ffd166;
    text-underline-offset: 6px;
    text-shadow: 0 2px 4px rgba(0,0,0,0.35);
}
.hero-title span.icons {
    font-size: 1.8rem;
}
.hero-subtitle {
    font-size: 0.9rem;
    color: #f1f1f1;
    margin-top: 4px;
}
.hero-right {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 6px;
}
.hero-badge {
    padding: 6px 10px;
    border-radius: 999px;
    background: rgba(0,0,0,0.15);
    font-size: 0.8rem;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit,minmax(220px,1fr));
    gap: 16px;
    margin-bottom: 10px;
}
.form-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.form-group label {
    font-size: 0.9rem;
    font-weight: 600;
    color: #333;
}
.form-group input, .form-group select {
    padding: 8px 10px;
    border-radius: 8px;
    border: 1px solid #ced4da;
    font-size: 0.9rem;
    outline: none;
    transition: var(--transition);
}
.form-group input:focus, .form-group select:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(67,97,238,0.15);
}
.btn-row {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 10px;
}
.btn {
    border: none;
    border-radius: 999px;
    padding: 8px 16px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: var(--transition);
    text-decoration: none;
}
.btn-sm {
    padding: 4px 8px;
    font-size: 0.75rem;
}
.btn-primary {
    background: var(--gradient);
    color: #fff;
}
.btn-primary:hover { transform: translateY(-1px); }
.btn-secondary {
    background: #6c757d;
    color: #fff;
}
.btn-secondary:hover { opacity: 0.9; }
.btn-success {
    background: #28a745;
    color: #fff;
}
.btn-success:hover { opacity: 0.9; }
.btn-warning {
    background: #ffc107;
    color: #000;
}
.btn-warning:hover { opacity: 0.9; }
.btn-outline {
    background: transparent;
    border: 1px solid #ced4da;
    color: #333;
}
.btn-outline:hover {
    border-color: var(--primary);
    color: var(--primary);
}
.tab-nav {
    display: flex;
    flex-wrap: wrap;
    border-bottom: 1px solid #e9ecef;
}
.tab-nav button {
    flex: 1;
    border: none;
    background: #f8f9fa;
    padding: 10px;
    cursor: pointer;
    font-size: 0.9rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: var(--transition);
}
.tab-nav button.active {
    background: #fff;
    border-bottom: 3px solid var(--primary);
    color: var(--primary);
}
.tab-content {
    display: none;
    padding: 16px 20px 20px;
}
.tab-content.active {
    display: block;
}
.sadd-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
    font-size: 0.85rem;
}
.sadd-table th, .sadd-table td {
    border: 1px solid #dee2e6;
    padding: 6px 5px;
    text-align: center;
}
.sadd-table th {
    background: #f1f3f5;
}
.alert {
    padding: 8px 12px;
    border-radius: 999px;
    margin-bottom: 10px;
    font-size: 0.9rem;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.alert-success { background: #d4edda; color: #155724; }
.alert-error   { background: #f8d7da; color: #721c24; }
.alert-warning { background: #fff3cd; color: #856404; }
.small-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8rem;
}
.small-table th, .small-table td {
    border: 1px solid #e9ecef;
    padding: 4px 5px;
}
.small-table th {
    background: #f1f3f5;
}
.badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 8px;
    border-radius: 999px;
    font-size: 0.75rem;
    background: rgba(255,255,255,0.2);
}
.ai-helper {
    margin-top: 10px;
    padding: 10px 12px;
    border-radius: var(--radius);
    background: #e9f5ff;
    border: 1px dashed var(--primary);
    font-size: 0.85rem;
}
.ai-helper strong { color: var(--primary); }
.dash-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit,minmax(220px,1fr));
    gap: 16px;
    margin-top: 16px;
}
.dash-card {
    background: #f8f9ff;
    border-radius: var(--radius);
    padding: 12px 14px;
    border: 1px solid #e1e5ff;
}
.dash-label {
    font-size: 0.85rem;
    color: #444;
}
.dash-value {
    margin-top: 4px;
    font-weight: 700;
    font-size: 0.9rem;
}
.progress-bar {
    width: 100%;
    height: 8px;
    border-radius: 999px;
    background: #e9ecef;
    overflow: hidden;
    margin-top: 6px;
}
.progress-fill {
    height: 100%;
    width: 0%;
    background: var(--accent);
    transition: width .4s ease;
}
.small-note {
    font-size: 0.8rem;
    margin-top: 8px;
    color: #555;
}
.print-header {
    display: none;
    margin-bottom: 10px;
    padding-bottom: 10px;
    border-bottom: 1px solid #000;
}
.print-header h1 {
    font-size: 1.4rem;
    margin: 0 0 4px 0;
}
.print-header p {
    margin: 0;
    font-size: 0.9rem;
}

/* Horizontal scroll container for wide tables (fix hidden columns) */
.table-responsive {
    width: 100%;
    overflow-x: auto;
}
.table-responsive > table {
    min-width: 1100px;
}

@media (max-width: 768px) {
    .card-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
    .hero-right {
        align-items: flex-start;
    }
}

/* PRINTING RULES */
@media print {
    body {
        background: #fff;
    }
    header,
    body > header,
    #site-header,
    .site-header,
    .app-header,
    .main-header,
    .top-nav,
    .navbar,
    nav,
    #header,
    .header {
        display: none !important;
    }
    .card {
        box-shadow: none;
        border-radius: 0;
    }
    .page-container {
        margin: 0;
        max-width: 100%;
    }
    .print-header {
        display: block !important;
    }

    /* Special mode: when printing aggregation, show only header + tab3 */
    body.print-aggregation * {
        visibility: hidden;
    }
    body.print-aggregation .print-header,
    body.print-aggregation .print-header * {
        visibility: visible !important;
    }
    body.print-aggregation #tab3,
    body.print-aggregation #tab3 * {
        visibility: visible !important;
    }
}

/* New styles for template download section */
.template-section {
    background: #f8f9fa;
    border-radius: var(--radius);
    padding: 15px;
    margin: 15px 0;
    border-left: 4px solid var(--primary);
}

.template-buttons {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 10px;
    margin-top: 10px;
}

.template-info {
    font-size: 0.9rem;
    color: #666;
    margin-top: 8px;
}
</style>
</head>
<body>

<?php require_once __DIR__ . '/../header.php'; ?>

<div class="page-container">

    <!-- PRINT HEADER -->
    <div class="print-header">
        <h1>📊 Nexus Ethiopia Project Performance Report</h1>
        <p><strong>Project:</strong> <span id="ph_project"><?php echo h($selected_project['title'] ?? 'N/A'); ?></span></p>
        <?php if (!empty($selected_project['main_sector'])): ?>
            <p><strong>Main Sector:</strong> <?php echo h($selected_project['main_sector']); ?></p>
        <?php endif; ?>
        <?php if (!empty($selected_project['specific_sector'])): ?>
            <p><strong>Specific Sector:</strong> <?php echo h($selected_project['specific_sector']); ?></p>
        <?php endif; ?>
        <p><strong>Location:</strong> <span id="ph_location">N/A</span></p>
        <p><strong>Report period:</strong> <span id="ph_period">N/A</span></p>
        <p><strong>Reported by:</strong> <?php echo h($current_user); ?></p>
        <p><strong>Print date:</strong> <?php echo date('Y-m-d'); ?></p>
    </div>

    <!-- HERO -->
    <div class="card">
        <div class="card-header">
            <div>
                <div class="hero-title">
                    <span class="icons">📊📈🧮</span>
                    Nexus Ethiopia Project Performance Report
                </div>
                <div class="hero-subtitle">
                    <?php if ($selected_project): ?>
                        Project: <strong><?php echo h($selected_project['title'] ?? ''); ?></strong>
                        <?php if (!empty($selected_project['main_sector'])): ?>
                            • Main Sector: <strong><?php echo h($selected_project['main_sector']); ?></strong>
                        <?php endif; ?>
                        <?php if (!empty($selected_project['specific_sector'])): ?>
                            • Specific Sector: <strong><?php echo h($selected_project['specific_sector']); ?></strong>
                        <?php endif; ?>
                        <?php if (!empty($selected_project['project_code'] ?? $selected_project['code'] ?? '')): ?>
                            • Code: <strong><?php echo h($selected_project['project_code'] ?? $selected_project['code']); ?></strong>
                        <?php endif; ?>
                        <?php if (!empty($selected_project['donor'] ?? '')): ?>
                            • Donor: <strong><?php echo h($selected_project['donor']); ?></strong>
                        <?php endif; ?>
                    <?php else: ?>
                        Smart MEAL reporting • Age/sex/PWD disaggregation • Pivot-ready data for all Nexus locations
                    <?php endif; ?>
                </div>
            </div>
            <div class="hero-right">
                <div class="hero-badge">🧠 AI-assisted guidance</div>
                <div class="hero-badge">📌 Edit &amp; update saved reports</div>
                <div class="hero-badge">👤 Reported by: <?php echo h($current_user); ?></div>
            </div>
        </div>
        <div class="card-body">
            <?php if ($selected_project && !empty($selected_project['description'] ?? '')): ?>
                <div style="margin-bottom:10px; font-size:0.9rem; color:#333; background:#f8f9ff; padding:10px 12px; border-radius:8px;">
                    <strong>Project description:</strong>
                    <?php echo nl2br(h($selected_project['description'])); ?>
                </div>
            <?php endif; ?>

            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo h($message); ?></div><br>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo h($error); ?></div><br>
            <?php endif; ?>

            <?php if ($edit_id && $editingReport): ?>
                <div class="alert alert-warning">
                    ✏️ You are editing existing report ID #<?php echo (int)$editingReport['id']; ?>
                    (created <?php echo h(substr($editingReport['created_at'], 0, 10)); ?>).
                    Adjust values and click <strong>Update report</strong>.
                </div>
            <?php endif; ?>

            <div id="ai_helper" class="ai-helper">
                🤖 <strong>Smart hint:</strong> Select a project and indicator. The system will auto-activate
                the right mode for <strong>person</strong> vs <strong>non-person</strong> indicators, handle
                SADD/PWD auto-calculation, and compute coverage percentages.
            </div>
        </div>
    </div>

    <!-- NEW TEMPLATE DOWNLOAD SECTION -->
    <?php if ($selected_project_id): ?>
    <div class="card">
        <div class="card-header">
            <h2>📥 Download Templates for Selected Project</h2>
            <div class="badge">📋 Project-specific • 📊 Pre-formatted • 🔄 Easy import</div>
        </div>
        <div class="card-body">
            <div class="template-section">
                <h4>Download project-specific templates:</h4>
                <p class="template-info">
                    Download pre-formatted templates with project header information already included. 
                    Fill out the template and re-import using the "Import filled CSV" button below.
                </p>
                
                <div class="template-buttons">
                    <a href="?action=download_project_csv_template&project_id=<?php echo $selected_project_id; ?>" 
                       class="btn btn-primary">
                        📥 Download CSV Template
                    </a>
                    <a href="?action=download_project_word_template&project_id=<?php echo $selected_project_id; ?>" 
                       class="btn btn-outline">
                        📥 Download Word Template
                    </a>
                </div>
                
                <div class="template-info">
                    <strong>Project:</strong> <?php echo h($selected_project['title'] ?? ''); ?><br>
                    <?php if (!empty($selected_project['main_sector'])): ?>
                        <strong>Main Sector:</strong> <?php echo h($selected_project['main_sector']); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($selected_project['specific_sector'])): ?>
                        <strong>Specific Sector:</strong> <?php echo h($selected_project['specific_sector']); ?><br>
                    <?php endif; ?>
                    <strong>Reported by:</strong> <?php echo h($current_user); ?> (auto-filled)
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- MAIN FORM -->
    <form method="post" enctype="multipart/form-data" id="reportForm">
        <?php if ($edit_id && $editingReport): ?>
            <input type="hidden" name="report_id" value="<?php echo (int)$editingReport['id']; ?>">
        <?php endif; ?>
        <?php if ($selected_project_id): ?>
            <input type="hidden" name="project_id_hidden" value="<?php echo (int)$selected_project_id; ?>" id="project_id_hidden">
        <?php endif; ?>
        <!-- flag for non-person indicators (used in JS) -->
        <input type="hidden" id="indicator_is_non_person" value="0">

        <!-- HEADER / PIVOT -->
        <div class="card">
            <div class="card-header">
                <h2>🧭 Report Header &amp; Pivot Filters</h2>
                <div class="badge">🧮 Pivot-ready • 📁 Project based • 🌍 Location-aware</div>
            </div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>📁 Project</label>
                        <select name="project_id" id="project_id" onchange="onProjectChange(this)" required>
                            <option value="">-- Select project --</option>
                            <?php foreach ($projects as $p): ?>
                                <option value="<?php echo (int)$p['id']; ?>"
                                    <?php echo ($selected_project_id == $p['id']) ? 'selected' : ''; ?>>
                                    <?php echo h($p['title']); ?>
                                    <?php if (!empty($p['main_sector'])): ?> (<?php echo h($p['main_sector']); ?>)<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($selected_project_id && !empty($selected_project)): ?>
                            <?php if (!empty($selected_project['description'])): ?>
                                <div style="margin-top: 8px; padding: 10px; background: #e7f3ff; border-left: 4px solid #2196F3; border-radius: 4px;">
                                    <strong>📝 Project Description:</strong><br>
                                    <span style="color: #333;"><?php echo nl2br(h($selected_project['description'])); ?></span>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($selected_project_id): ?>
                            <?php if (empty($indicators)): ?>
                                <div style="margin-top: 5px; padding: 8px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; color: #856404;">
                                    ⚠️ <strong>No indicators found for this project (ID: <?php echo $selected_project_id; ?>).</strong><br>
                                    Please add indicators in the <a href="planning.php?project_id=<?php echo $selected_project_id; ?>" target="_blank">Planning</a> section first.<br>
                                    
                                </div>
                            <?php else: ?>
                                <div style="margin-top: 5px; padding: 8px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; color: #155724;">
                                    ✅ <strong><?php echo count($indicators); ?> indicator(s)</strong> loaded from project planning.
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div style="margin-top: 5px; padding: 8px; background: #d1ecf1; border: 1px solid #bee5eb; border-radius: 4px; color: #0c5460;">
                                💡 <strong>Please select a project first</strong> to see indicators from project planning.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label>⏱️ Period type</label>
                        <select name="period_type" id="period_type" onchange="updatePeriodFields();updatePrintHeader();">
                            <option value="weekly"   <?php echo $period_type_val === 'weekly'   ? 'selected' : ''; ?>>Weekly</option>
                            <option value="monthly"  <?php echo $period_type_val === 'monthly'  ? 'selected' : ''; ?>>Monthly</option>
                            <option value="quarterly"<?php echo $period_type_val === 'quarterly'? 'selected' : ''; ?>>Quarterly</option>
                            <option value="annual"   <?php echo $period_type_val === 'annual'   ? 'selected' : ''; ?>>Annual</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>📅 Year</label>
                        <select name="year" id="year" onchange="updatePrintHeader();">
                            <?php
                            $currentYear = (int)date('Y');
                            // allow going 25 years back and 25 years forward for flexibility
                            for ($i = $currentYear - 25; $i <= $currentYear + 25; $i++):
                            ?>
                                <option value="<?php echo $i; ?>"
                                    <?php echo ($i === (int)$year_val) ? 'selected' : ''; ?>>
                                    <?php echo $i; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="form-group" id="month_group">
                        <label>🗓️ Month</label>
                        <select name="month" id="month" onchange="updatePrintHeader();">
                            <option value="na">-- N/A --</option>
                            <?php
                            for ($m = 1; $m <= 12; $m++):
                                $name = date('F', mktime(0,0,0,$m,1));
                            ?>
                                <option value="<?php echo $m; ?>"
                                    <?php echo ($m == (int)$month_val) ? 'selected' : ''; ?>>
                                    <?php echo $name; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="form-group" id="week_group">
                        <label>📆 Week (for weekly)</label>
                        <select name="week" id="week" onchange="updatePrintHeader();">
                            <option value="">-- N/A --</option>
                            <?php for ($w = 1; $w <= 53; $w++): ?>
                                <option value="<?php echo $w; ?>"
                                    <?php echo ((string)$w === (string)$week_val) ? 'selected' : ''; ?>>
                                    Week <?php echo $w; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>📌 Start date</label>
                        <input type="date" name="start_date" id="start_date"
                               value="<?php echo h($start_date_val); ?>"
                               onchange="updatePrintHeader();">
                    </div>

                    <div class="form-group">
                        <label>🏁 End date</label>
                        <input type="date" name="end_date" id="end_date"
                               value="<?php echo h($end_date_val); ?>"
                               onchange="updatePrintHeader();">
                    </div>

                    <div class="form-group">
    <label>📍 Region</label>
    <select name="region_id" id="region_id" class="geo-region region-select" onchange="onRegionChange();updatePrintHeader();">
        <option value="">-- Select region --</option>
        <?php foreach ($regions as $r): ?>
            <option value="<?php echo (int)$r['id']; ?>"
                <?php echo ($edit_region_id && $edit_region_id == $r['id']) ? 'selected' : ''; ?>>
                <?php echo h($r['name']); ?>
            </option>
        <?php endforeach; ?>
        <option value="other"<?php echo !empty($editingReport['region_other'] ?? '') ? ' selected' : ''; ?>>
            Other (specify)
        </option>
    </select>
    <input type="text"
           name="region_other"
           id="region_other"
           placeholder="+ other (enter text)"
           value="<?php echo h($editingReport['region_other'] ?? ''); ?>"
           style="margin-top:4px; display:<?php echo !empty($editingReport['region_other'] ?? '') ? 'block' : 'none'; ?>;">
</div>


                    <div class="form-group">
                        <label>🧭 Zone</label>
                        <select name="zone_id" id="zone_id" class="geo-zone zone-select" onchange="updateWoredasFromZone();updatePrintHeader();">
                            <option value="">-- Select zone --</option>
                        </select>
                        <input type="text" name="zone_other" id="zone_other" placeholder="+ other (enter text)"
                               value="<?php echo h($editingReport['zone_other'] ?? ''); ?>"
                               style="margin-top:4px; display:<?php echo !empty($editingReport['zone_other'] ?? '') ? 'block' : 'none'; ?>;">
                    </div>

                    <div class="form-group">
    <label>🏘️ Woreda</label>
    <select name="woreda_id" id="woreda_id" class="geo-woreda woreda-select" onchange="onWoredaChange();updatePrintHeader();">
        <option value="">-- Select woreda --</option>
        <option value="other"<?php echo !empty($editingReport['woreda_other'] ?? '') ? ' selected' : ''; ?>>Other (specify)</option>
    </select>
    <input type="text" name="woreda_other"
           id="woreda_other"
           placeholder="+ other (enter text)"
           value="<?php echo h($editingReport['woreda_other'] ?? '') ?? ''; ?>"
           style="margin-top:4px; display:<?php echo !empty($editingReport['woreda_other'] ?? '') ? 'block' : 'none'; ?>;">
</div>

                    <!-- Reported By Field (Auto-filled) -->

                <div class="btn-row">
                    <?php if ($edit_id && $editingReport): ?>
                        <button type="submit" name="update_report" class="btn btn-primary">
                            💾 Update report
                        </button>
                        <button type="button" class="btn btn-success"
                                onclick="window.location.href='enter_data.php?project_id=<?php echo (int)$selected_project_id; ?>';">
                            ➕ New report
                        </button>
                    <?php else: ?>
                        <button type="submit" name="save_report" class="btn btn-primary">
                            💾 Save report
                        </button>
                        <button type="button" class="btn btn-success"
                                onclick="window.location.href='enter_data.php';">
                            🆕 Create new form
                        </button>
                    <?php endif; ?>

                    <button type="button" class="btn btn-secondary"
                            onclick="document.getElementById('reportForm').reset();updatePeriodFields();updatePrintHeader();updateDerived();">
                        🧹 Clear form
                    </button>
                </div>
            </div>
        </div>

        <!-- TABS -->
        <div class="card">
            <div class="tab-nav">
                <button type="button" class="active" data-tab="tab1" onclick="switchTab('tab1', this)">📌 Report entry</button>
                <button type="button" data-tab="tab2" onclick="switchTab('tab2', this)">👥 Beneficiary breakdown &amp; analytics</button>
                <button type="button" data-tab="tab3" onclick="switchTab('tab3', this)">📈 Saved reports &amp; aggregation</button>
            </div>

            <!-- TAB 1 -->
            <div class="tab-content active" id="tab1">
                <h3 style="margin-top:0;">🎯 Indicator &amp; data entry</h3>

                <div class="form-grid">
                    <div class="form-group">
                        <label>🎯 Indicator (from project planning)</label>
                        <select name="indicator_key" id="indicator_key" onchange="onIndicatorChange();">
                            <option value="">-- Select indicator --</option>
                            <?php foreach ($indicators as $ind): ?>
    <?php
        $key          = $ind['level'] . ':' . $ind['id'];
        $projectTitle = $ind['project_title'] ?? '';
        $indProjectId = isset($ind['project_id']) ? (int)$ind['project_id'] : 0;

        $label = strtoupper($ind['level']) . ' | ' .
                 (!empty($ind['result_code'])    ? $ind['result_code'] . ' - '    : '') .
                 (!empty($ind['indicator_code']) ? $ind['indicator_code'] . ' - ' : '') .
                 ($ind['indicator_name'] ?? '');

        // If we’re in fallback mode (showing indicators from other projects),
        // append the project title so you can see which one it belongs to.
        if ($projectTitle && $selected_project_id && $indProjectId !== (int)$selected_project_id) {
            $label .= ' [' . $projectTitle . ']';
        }

        $selected_indicator = '';
        if ($editingReport &&
            $editingReport['indicator_level'] === $ind['level'] &&
            (int)$editingReport['indicator_id'] === (int)$ind['id']) {
            $selected_indicator = 'selected';
        }
    ?>
    <option
        value="<?php echo h($key); ?>"
        data-unit="<?php echo h($ind['unit_type'] ?? ''); ?>"
        data-benef="<?php echo h($ind['beneficiary_type'] ?? ''); ?>"
                                        data-target="<?php echo h($ind['total_target'] ?? ''); ?>"
                                        data-disagg="<?php echo h($ind['disaggregation'] ?? ''); ?>"
        data-indname="<?php echo h($ind['indicator_name'] ?? ''); ?>"
        data-level="<?php echo h($ind['level']); ?>"
        data-indcode="<?php echo h($ind['indicator_code'] ?? ''); ?>"
        data-resultcode="<?php echo h($ind['result_code'] ?? ''); ?>"
        <?php echo $selected_indicator; ?>
    >
        <?php echo h($label); ?>
    </option>
<?php endforeach; ?>
                        </select>
                        <?php if (empty($indicators) && $selected_project_id): ?>
                            <div style="margin-top: 5px; padding: 8px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; color: #856404;">
                                ⚠️ <strong>No indicators found for this project.</strong> Please add indicators in the <a href="planning.php?project_id=<?php echo $selected_project_id; ?>" target="_blank">Planning</a> section first.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label>📏 Unit of measurement (auto)</label>
                        <input type="text" id="unit_display" readonly
                               placeholder="Auto from indicator">
                    </div>

                    <div class="form-group">
                        <label>👥 Beneficiary type (auto)</label>
                        <input type="text" id="benef_display" readonly
                               placeholder="Auto from indicator / project">
                    </div>

                    <div class="form-group">
                        <label>🎯 Total target (auto)</label>
                        <input type="text" id="target_display" readonly
                               placeholder="Auto from planning">
                    </div>

                    <div class="form-group">
                        <label>🧩 Disaggregation (auto)</label>
                        <input type="text" id="disagg_display" readonly
                               placeholder="Auto from planning">
                    </div>
                </div>

                <!-- MAIN SADD TABLE -->
                <h4 style="margin-top:20px;">📊 Age / sex / disability disaggregation (entry)</h4>

                <div class="table-responsive">
                    <table class="sadd-table">
                        <thead>
                            <tr>
                                <th rowspan="2">Indicator Type</th>
                                <th rowspan="2">Indicator name</th>
                                <th rowspan="2">Beneficiary type</th>
                                <th rowspan="2">Unit of measurement</th>
                                <th colspan="2">Under 5</th>
                                <th colspan="2">5–17</th>
                                <th colspan="2">18–59</th>
                                <th colspan="2">60+</th>
                                <th rowspan="2">Row total (excl. PWD)</th>
                            </tr>
                            <tr>
                                <th>Boys</th>
                                <th>Girls</th>
                                <th>Boys</th>
                                <th>Girls</th>
                                <th>Adult male</th>
                                <th>Adult female</th>
                                <th>Elder men</th>
                                <th>Elder women</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td id="sadd_indicator_type"></td>
                                <td id="sadd_indicator_name"></td>
                                <td id="sadd_benef_type"></td>
                                <td id="sadd_unit"></td>
                                <td><input class="person-input" type="number" name="boys_u5" id="boys_u5" min="0"
                                           value="<?php echo (int)($editingReport['boys_u5'] ?? 0); ?>"
                                           oninput="updateDerived();"></td>
                                <td><input class="person-input" type="number" name="girls_u5" id="girls_u5" min="0"
                                           value="<?php echo (int)($editingReport['girls_u5'] ?? 0); ?>"
                                           oninput="updateDerived();"></td>
                                <td><input class="person-input" type="number" name="boys_5_17" id="boys_5_17" min="0"
                                           value="<?php echo (int)($editingReport['boys_5_17'] ?? 0); ?>"
                                           oninput="updateDerived();"></td>
                                <td><input class="person-input" type="number" name="girls_5_17" id="girls_5_17" min="0"
                                           value="<?php echo (int)($editingReport['girls_5_17'] ?? 0); ?>"
                                           oninput="updateDerived();"></td>
                                <td><input class="person-input" type="number" name="men_18_59" id="men_18_59" min="0"
                                           value="<?php echo (int)($editingReport['men_18_59'] ?? 0); ?>"
                                           oninput="updateDerived();"></td>
                                <td><input class="person-input" type="number" name="women_18_59" id="women_18_59" min="0"
                                           value="<?php echo (int)($editingReport['women_18_59'] ?? 0); ?>"
                                           oninput="updateDerived();"></td>
                                <td><input class="person-input" type="number" name="men_60p" id="men_60p" min="0"
                                           value="<?php echo (int)($editingReport['men_60p'] ?? 0); ?>"
                                           oninput="updateDerived();"></td>
                                <td><input class="person-input" type="number" name="women_60p" id="women_60p" min="0"
                                           value="<?php echo (int)($editingReport['women_60p'] ?? 0); ?>"
                                           oninput="updateDerived();"></td>
                                <td id="row_total_all">0</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- PWD TABLE -->
                <h4 style="margin-top:20px;">♿ Persons with disabilities (by age &amp; sex)</h4>

                <div class="table-responsive">
                    <table class="sadd-table">
                        <thead>
                            <tr>
                                <th rowspan="2">Indicator Type</th>
                                <th rowspan="2">Indicator name</th>
                                <th rowspan="2">Beneficiary type</th>
                                <th rowspan="2">Unit of measurement</th>
                                <th colspan="2">Under 5</th>
                                <th colspan="2">5–17</th>
                                <th colspan="2">18–59</th>
                                <th colspan="2">60+</th>
                                <th rowspan="2">Row total PWD</th>
                            </tr>
                            <tr>
                                <th>Boys</th>
                                <th>Girls</th>
                                <th>Boys</th>
                                <th>Girls</th>
                                <th>Adult male</th>
                                <th>Adult female</th>
                                <th>Elder men</th>
                                <th>Elder women</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td id="pwd_indicator_type"></td>
                                <td id="pwd_indicator_name"></td>
                                <td id="pwd_benef_type"></td>
                                <td id="pwd_unit"></td>
                                <td><input class="person-input" type="number" name="pwd_boys_u5" id="pwd_boys_u5" min="0"
                                           value="<?php echo (int)($editingReport['pwd_boys_u5'] ?? 0); ?>"
                                           oninput="updateDerived();"></td>
                                <td><input class="person-input" type="number" name="pwd_girls_u5" id="pwd_girls_u5" min="0"
                                           value="<?php echo (int)($editingReport['pwd_girls_u5'] ?? 0); ?>"
                                           oninput="updateDerived();"></td>
                                <td><input class="person-input" type="number" name="pwd_boys_5_17" id="pwd_boys_5_17" min="0"
                                           value="<?php echo (int)($editingReport['pwd_boys_5_17'] ?? 0); ?>"
                                           oninput="updateDerived();"></td>
                                <td><input class="person-input" type="number" name="pwd_girls_5_17" id="pwd_girls_5_17" min="0"
                                           value="<?php echo (int)($editingReport['pwd_girls_5_17'] ?? 0); ?>"
                                           oninput="updateDerived();"></td>
                                <td><input class="person-input" type="number" name="pwd_men_18_59" id="pwd_men_18_59" min="0"
                                           value="<?php echo (int)($editingReport['pwd_men_18_59'] ?? 0); ?>"
                                           oninput="updateDerived();"></td>
                                <td><input class="person-input" type="number" name="pwd_women_18_59" id="pwd_women_18_59" min="0"
                                           value="<?php echo (int)($editingReport['pwd_women_18_59'] ?? 0); ?>"
                                           oninput="updateDerived();"></td>
                                <td><input class="person-input" type="number" name="pwd_men_60p" id="pwd_men_60p" min="0"
                                           value="<?php echo (int)($editingReport['pwd_men_60p'] ?? 0); ?>"
                                           oninput="updateDerived();"></td>
                                <td><input class="person-input" type="number" name="pwd_women_60p" id="pwd_women_60p" min="0"
                                           value="<?php echo (int)($editingReport['pwd_women_60p'] ?? 0); ?>"
                                           oninput="updateDerived();"></td>
                                <td id="pwd_total_cell">0</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- NON-BENEFICIARY / NON-PERSON BLOCK -->
                <h4 style="margin-top:20px;">📉 Non beneficiary indicators report</h4>

                <div class="table-responsive">
                    <table class="sadd-table">
                        <thead>
                            <tr>
                                <th>Indicator Type</th>
                                <th>Indicator name</th>
                                <th>Unit of measurement (auto)</th>
                                <th>Denominator (if needed)</th>
                                <th>% (with denominator &amp; numerator)</th>
                                <th>Number (for those reported by number only)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td id="nb_indicator_type"></td>
                                <td id="nb_indicator_name"></td>
                                <td id="nb_unit"></td>
                                <td>
                                    <input type="number" name="non_beneficiary" id="non_beneficiary" min="0"
                                           value="<?php echo (int)($editingReport['non_beneficiary'] ?? 0); ?>"
                                           oninput="updateDerived();">
                                </td>
                                <td id="nb_percent">0%</td>
                                <td id="nb_number">0</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="form-grid" style="margin-top:10px;">
                    <div class="form-group">
                        <label>📦 Non-person numeric value (for facilities, items, sessions, etc.)</label>
                        <input type="number" step="0.01" name="non_person_value" id="non_person_value"
                               value="<?php echo h($editingReport['non_person_value'] ?? ''); ?>"
                               placeholder="Enabled only for non-person indicators">
                    </div>
                </div>

                <p class="small-note">
                    🔎 <strong>Note:</strong> For <strong>person-based</strong> indicators, the system uses the
                    SADD totals as the numerator in the non-beneficiary block. For <strong>non-person</strong>
                    indicators, the numerator is the non-person value. Coverage (%) is numerator / denominator.
                    SADD and PWD tables are automatically disabled for non-person indicators.
                </p>
            </div>

            <!-- TAB 2: BREAKDOWN & ANALYTICS -->
            <div class="tab-content" id="tab2">
                <h3 style="margin-top:0;">👥 Derived beneficiary breakdown &amp; analytics</h3>
                <p style="font-size:0.9rem;">
                    This page auto-calculates age/sex/PWD breakdowns and key percentages from the entry tab.
                </p>

                <div class="table-responsive">
                    <table class="sadd-table">
                        <thead>
                            <tr>
                                <th rowspan="2">Indicator Type</th>
                                <th rowspan="2">Indicator name</th>
                                <th rowspan="2">Beneficiary type</th>
                                <th rowspan="2">Unit of measurement</th>
                                <th colspan="2">Under 5</th>
                                <th colspan="2">5–17</th>
                                <th colspan="2">18–59</th>
                                <th colspan="2">60+</th>
                                <th rowspan="2">Row total (excl. PWD)</th>
                            </tr>
                            <tr>
                                <th>Boys</th>
                                <th>Girls</th>
                                <th>Boys</th>
                                <th>Girls</th>
                                <th>Adult male</th>
                                <th>Adult female</th>
                                <th>Elder men</th>
                                <th>Elder women</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td id="d_indicator_type"></td>
                                <td id="d_indicator_name"></td>
                                <td id="d_benef_type"></td>
                                <td id="d_unit"></td>
                                <td id="d_male_u5">0</td>
                                <td id="d_female_u5">0</td>
                                <td id="d_male_5_17">0</td>
                                <td id="d_female_5_17">0</td>
                                <td id="d_male_18_59">0</td>
                                <td id="d_female_18_59">0</td>
                                <td id="d_male_60p">0</td>
                                <td id="d_female_60p">0</td>
                                <td id="d_row_all">0</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="table-responsive">
                    <table class="sadd-table" style="margin-top:16px;">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Male</th>
                                <th>Female</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Children &lt;18 (U5 + 5–17)</strong></td>
                                <td id="d_children_male">0</td>
                                <td id="d_children_female">0</td>
                                <td id="d_children_total">0</td>
                            </tr>
                            <tr>
                                <td><strong>Adults 18–59</strong></td>
                                <td id="d_adults_male">0</td>
                                <td id="d_adults_female">0</td>
                                <td id="d_adults_total">0</td>
                            </tr>
                            <tr>
                                <td><strong>Elders 60+</strong></td>
                                <td id="d_elder_male">0</td>
                                <td id="d_elder_female">0</td>
                                <td id="d_elder_total">0</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="table-responsive">
                    <table class="sadd-table" style="margin-top:16px;">
                        <thead>
                            <tr>
                                <th>Group</th>
                                <th>Under 5</th>
                                <th>5–17</th>
                                <th>18–59</th>
                                <th>60+</th>
                                <th>Total PWD</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Male PWD</strong></td>
                                <td id="d_pwd_male_u5">0</td>
                                <td id="d_pwd_male_5_17">0</td>
                                <td id="d_pwd_male_18_59">0</td>
                                <td id="d_pwd_male_60p">0</td>
                                <td id="d_pwd_row_male">0</td>
                            </tr>
                            <tr>
                                <td><strong>Female PWD</strong></td>
                                <td id="d_pwd_female_u5">0</td>
                                <td id="d_pwd_female_5_17">0</td>
                                <td id="d_pwd_female_18_59">0</td>
                                <td id="d_pwd_female_60p">0</td>
                                <td id="d_pwd_row_female">0</td>
                            </tr>
                            <tr>
                                <td colspan="4" style="text-align:right;"><strong>Total PWD (all age/sex)</strong></td>
                                <td colspan="2" id="d_pwd_total">0</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div id="analytics_wrapper" class="dash-grid">
                    <div class="dash-card">
                        <div class="dash-label">🧒 % share of children (&lt;18) reached</div>
                        <div class="progress-bar">
                            <div class="progress-fill" id="bar_children_share"></div>
                        </div>
                        <div class="dash-value" id="metric_children_share">0%</div>
                    </div>
                    <div class="dash-card">
                        <div class="dash-label">👩 % share of women &amp; girls reached</div>
                        <div class="progress-bar">
                            <div class="progress-fill" id="bar_women_share"></div>
                        </div>
                        <div class="dash-value" id="metric_women_share">0%</div>
                    </div>
                    <div class="dash-card">
                        <div class="dash-label">♿ % share of PWD (of total persons incl. PWD)</div>
                        <div class="progress-bar">
                            <div class="progress-fill" id="bar_pwd_share"></div>
                        </div>
                        <div class="dash-value" id="metric_pwd_share">0%</div>
                    </div>
                </div>
                <p id="analytics_note" class="small-note"></p>

                <div class="btn-row" style="margin-top:12px;">
                    <button type="button" class="btn btn-outline" onclick="window.print();">
                        🖨️ Print beneficiary breakdown
                    </button>
                </div>
            </div>

            <!-- TAB 3: SAVED & AGGREGATION -->
            <div class="tab-content" id="tab3">
                <h3 style="margin-top:0;">📈 Saved reports &amp; aggregation (standard Nexus format)</h3>
                <p style="font-size:0.9rem;">
                    This view follows the standard <strong>Nexus Ethiopia Project Performance Report</strong> table
                    you shared. Use it to print, download, and export aggregated results.
                </p>

                <div class="btn-row">
                    <a href="?action=export_aggregation_word<?php echo $selected_project_id ? '&project_id=' . (int)$selected_project_id : ''; ?>"
                       class="btn btn-primary">
                        📄 Download aggregation (Word)
                    </a>
                    <a href="?action=export_aggregation_csv<?php echo $selected_project_id ? '&project_id=' . (int)$selected_project_id : ''; ?>"
                       class="btn btn-outline">
                        📊 Download aggregation (CSV)
                    </a>
                    
                    <!-- NEW: Project-specific template downloads -->
                    <?php if ($selected_project_id): ?>
                    <a href="?action=download_project_csv_template&project_id=<?php echo $selected_project_id; ?>"
                       class="btn btn-success">
                        📥 Download CSV Template
                    </a>
                    <a href="?action=download_project_word_template&project_id=<?php echo $selected_project_id; ?>"
                       class="btn btn-outline">
                        📥 Download Word Template
                    </a>
                    <?php endif; ?>

                    <!-- CSV IMPORT BUTTON – now correctly triggers only when file is chosen -->
                    <label class="btn btn-warning">
                        📤 Import filled CSV
                        <input type="file" name="csv_file" accept=".csv"
                               style="display:none;"
                               onchange="if(this.files.length){ document.getElementById('reportForm').submit(); }" />
                    </label>

                    <button type="button" class="btn btn-secondary" onclick="printAggregation();">
                        🖨️ Print aggregation table
                    </button>
                </div>

                <div class="form-grid" style="margin:14px 0;">
                    <div class="form-group">
                        <label>👤 Total person-reach (excl. PWD, recent 50)</label>
                        <input type="text" readonly
                               value="<?php echo (int)$agg_person_total; ?>">
                    </div>
                    <div class="form-group">
                        <label>♿ Total PWD (recent 50)</label>
                        <input type="text" readonly
                               value="<?php echo (int)$agg_pwd_total; ?>">
                    </div>
                    <div class="form-group">
                        <label>📦 Total non-person value (recent 50)</label>
                        <input type="text" readonly
                               value="<?php echo number_format($agg_non_person_total, 2); ?>">
                    </div>
                </div>

                <?php if ($recentReports): ?>
                    <div style="overflow-x:auto;">
                        <table class="small-table">
                            <thead>
                                <tr>
                                    <th rowspan="2">SN</th>
                                    <th rowspan="2">Indicator type</th>
                                    <th rowspan="2">Indicator</th>
                                    <th rowspan="2">Beneficiary type</th>
                                    <th rowspan="2">Unit of measurement</th>
                                    <th colspan="2">Boys / Girls &lt; 5</th>
                                    <th colspan="2">Boys / Girls 5–17</th>
                                    <th colspan="2">Male / Female Adult 18–59</th>
                                    <th colspan="2">Male / Female Elderly 60+</th>
                                    <th rowspan="2">People With Disability</th>
                                    <th rowspan="2">Total Reached by age categories</th>
                                    <th rowspan="2">Non person (%, number … etc)</th>
                                    <th rowspan="2">Women reached</th>
                                    <th rowspan="2">Girls reached</th>
                                    <th rowspan="2">Men reached</th>
                                    <th rowspan="2">Boys reached</th>
                                    <th rowspan="2">Total beneficiaries reached by sex</th>
                                    <th rowspan="2">Reported by</th>
                                    <th rowspan="2">Actions</th>
                                </tr>
                                <tr>
                                    <th>Boys &lt; 5</th>
                                    <th>Girls &lt; 5</th>
                                    <th>Boys 5–17</th>
                                    <th>Girls 5–17</th>
                                    <th>Male Adult 18–59</th>
                                    <th>Female Adult 18–59</th>
                                    <th>Male Elderly 60+</th>
                                    <th>Female Elderly 60+</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $sn = 1;
                                foreach ($recentReports as $r):
                                    $boys_u5      = (int)$r['boys_u5'];
                                    $girls_u5     = (int)$r['girls_u5'];
                                    $boys_5_17    = (int)$r['boys_5_17'];
                                    $girls_5_17   = (int)$r['girls_5_17'];
                                    $men_18_59    = (int)$r['men_18_59'];
                                    $women_18_59  = (int)$r['women_18_59'];
                                    $men_60p      = (int)$r['men_60p'];
                                    $women_60p    = (int)$r['women_60p'];
                                    $pwd          = (int)$r['pwd_count'];
                                    $den          = (int)$r['non_beneficiary'];
                                    $non_person   = (float)$r['non_person_value'];

                                    $totalAge = $boys_u5 + $girls_u5 + $boys_5_17 + $girls_5_17 + $men_18_59 + $women_18_59 + $men_60p + $women_60p;
                                    $women    = $women_18_59 + $women_60p;
                                    $girls    = $girls_u5 + $girls_5_17;
                                    $men      = $men_18_59 + $men_60p;
                                    $boys     = $boys_u5 + $boys_5_17;
                                    $totalSex = $women + $girls + $men + $boys;

                                    $nonPersonText = '-';
                                    if ($non_person > 0 && $den > 0) {
                                        $pct = ($non_person / $den) * 100;
                                        $nonPersonText = round($pct, 1) . '% (' . $non_person . '/' . $den . ')';
                                    } elseif ($non_person > 0) {
                                        $nonPersonText = number_format($non_person, 2);
                                    }
                                    $indicatorType = ucfirst((string)$r['indicator_level']);
                                ?>
                                    <tr>
                                        <td><?php echo $sn++; ?></td>
                                        <td><?php echo h($indicatorType); ?></td>
                                        <td><?php echo h($r['indicator_name']); ?></td>
                                        <td><?php echo h($r['beneficiary_type']); ?></td>
                                        <td><?php echo h($r['unit_type']); ?></td>
                                        <td><?php echo $boys_u5; ?></td>
                                        <td><?php echo $girls_u5; ?></td>
                                        <td><?php echo $boys_5_17; ?></td>
                                        <td><?php echo $girls_5_17; ?></td>
                                        <td><?php echo $men_18_59; ?></td>
                                        <td><?php echo $women_18_59; ?></td>
                                        <td><?php echo $men_60p; ?></td>
                                        <td><?php echo $women_60p; ?></td>
                                        <td><?php echo $pwd; ?></td>
                                        <td><?php echo $totalAge; ?></td>
                                        <td><?php echo h($nonPersonText); ?></td>
                                        <td><?php echo $women; ?></td>
                                        <td><?php echo $girls; ?></td>
                                        <td><?php echo $men; ?></td>
                                        <td><?php echo $boys; ?></td>
                                        <td><?php echo $totalSex; ?></td>
                                        <td><?php echo h($r['reported_by'] ?? $current_user); ?></td>
                                        <td>
                                            <a href="enter_data.php?edit_id=<?php echo (int)$r['id']; ?>&project_id=<?php echo (int)$r['project_id']; ?>"
                                               class="btn btn-outline btn-sm">
                                                ✏️ Edit
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <p class="small-note">
                        🖨️ When printing aggregation, only the app header (project, location, period, date)
                        and this Nexus standard table will appear – the main system header is hidden.
                    </p>

                    <br>
                    <table class="small-table" style="margin-top:8px;">
                        <tr>
                            <th colspan="2">Report Verified by</th>
                            <th colspan="2">Report Approved by</th>
                        </tr>
                        <tr>
                            <td>Name:</td><td>&nbsp;</td>
                            <td>Name:</td><td>&nbsp;</td>
                        </tr>
                        <tr>
                            <td>Position:</td><td>&nbsp;</td>
                            <td>Position:</td><td>&nbsp;</td>
                        </tr>
                        <tr>
                            <td>Signature:</td><td>&nbsp;</td>
                            <td>Signature:</td><td>&nbsp;</td>
                        </tr>
                        <tr>
                            <td>Date:</td><td>&nbsp;</td>
                            <td>Date:</td><td>&nbsp;</td>
                        </tr>
                    </table>

                <?php else: ?>
                    <p>No reports saved yet for this project.</p>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<script>
const zones   = <?php echo json_encode($zones_js, JSON_UNESCAPED_UNICODE); ?>;
const woredas = <?php echo json_encode($woredas_js, JSON_UNESCAPED_UNICODE); ?>;

const editRegionId = <?php echo $edit_region_id !== null ? (int)$edit_region_id : 'null'; ?>;
const editZoneId   = <?php echo $edit_zone_id   !== null ? (int)$edit_zone_id   : 'null'; ?>;
const editWoredaId = <?php echo $edit_woreda_id !== null ? (int)$edit_woreda_id : 'null'; ?>;

function onProjectChange(sel) {
    const id = sel.value;
    if (id) {
        window.location = 'enter_data.php?project_id=' + encodeURIComponent(id);
    }
}

function switchTab(tabId, btn) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.getElementById(tabId).classList.add('active');
    document.querySelectorAll('.tab-nav button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
}

function updatePeriodFields() {
    const periodType = document.getElementById('period_type').value;
    const monthGroup = document.getElementById('month_group');
    const weekGroup  = document.getElementById('week_group');

    if (periodType === 'weekly') {
        weekGroup.style.display  = 'block';
        monthGroup.style.display = 'block';
    } else if (periodType === 'monthly') {
        weekGroup.style.display  = 'none';
        monthGroup.style.display = 'block';
    } else {
        weekGroup.style.display  = 'none';
        monthGroup.style.display = 'none';
    }
}

function updateZonesFromRegion() {
    const regionSel   = document.getElementById('region_id');
    const zoneSel     = document.getElementById('zone_id');
    const woredaSel   = document.getElementById('woreda_id');
    const regionValue = regionSel ? regionSel.value : '';

    if (!zoneSel || !woredaSel) return;

    // reset zone & woreda
    zoneSel.innerHTML =
        '<option value="">-- Select zone --</option>' +
        '<option value="other">Other (specify)</option>';
    woredaSel.innerHTML =
        '<option value="">-- Select woreda --</option>' +
        '<option value="other">Other (specify)</option>';

    if (!regionValue || regionValue === 'other') {
        return; // nothing else to do for "other" / empty
    }

    const regionId = parseInt(regionValue, 10);
    if (!regionId) return;

    zones.forEach(z => {
        if (z.region_id === regionId) {
            const opt = document.createElement('option');
            opt.value = z.id;
            opt.textContent = z.name;
            zoneSel.insertBefore(opt, zoneSel.querySelector('option[value="other"]'));
        }
    });
}

function updateWoredasFromZone() {
    const zoneSel     = document.getElementById('zone_id');
    const woredaSel   = document.getElementById('woreda_id');
    const zoneValue   = zoneSel ? zoneSel.value : '';
    const zoneOther   = document.getElementById('zone_other');
    const woredaOther = document.getElementById('woreda_other');

    if (!woredaSel) return;

    // reset woreda options
    woredaSel.innerHTML =
        '<option value="">-- Select woreda --</option>' +
        '<option value="other">Other (specify)</option>';

    if (zoneValue === 'other') {
        if (zoneOther)  zoneOther.style.display   = 'block';
        if (woredaOther) {
            woredaOther.style.display = 'none';
            // keep value in case user already typed something
        }
        return;
    } else {
        if (zoneOther)  zoneOther.style.display   = 'none';
    }

    const zoneId = parseInt(zoneValue || '0', 10);
    if (!zoneId) {
        if (woredaOther) {
            woredaOther.style.display = 'none';
        }
        return;
    }

    woredas.forEach(w => {
        if (w.zone_id === zoneId) {
            const opt = document.createElement('option');
            opt.value = w.id;
            opt.textContent = w.name;
            woredaSel.insertBefore(opt, woredaSel.querySelector('option[value="other"]'));
        }
    });

    if (woredaOther) {
        woredaOther.style.display = 'none';
    }
}

function updateWoredasFromZone() {
    const zoneId = parseInt(document.getElementById('zone_id').value || 0, 10);
    const woredaSel = document.getElementById('woreda_id');
    woredaSel.innerHTML = '<option value="">-- Select woreda --</option>';
    if (!zoneId) return;

    woredas.forEach(w => {
        if (w.zone_id === zoneId) {
            const opt = document.createElement('option');
            opt.value = w.id;
            opt.textContent = w.name;
            woredaSel.appendChild(opt);
        }
    });
}

// Handle project change - reload page with new project_id to load indicators
function onProjectChange(selectElement) {
    const projectId = selectElement.value;
    if (!projectId) {
        // If no project selected, reload without project_id
        window.location.href = 'enter_data.php';
        return;
    }
    // Update hidden field if it exists
    const hiddenField = document.getElementById('project_id_hidden');
    if (hiddenField) {
        hiddenField.value = projectId;
    }
    // Reload page with project_id to fetch indicators and project description
    window.location.href = 'enter_data.php?project_id=' + projectId;
}

function onIndicatorChange() {
    const sel = document.getElementById('indicator_key');
    if (!sel) return;
    
    const opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) {
        // Clear all fields if no indicator selected
        document.getElementById('unit_display').value = '';
        document.getElementById('benef_display').value = '';
        if (document.getElementById('target_display')) document.getElementById('target_display').value = '';
        if (document.getElementById('disagg_display')) document.getElementById('disagg_display').value = '';
        if (document.getElementById('sadd_indicator_type')) document.getElementById('sadd_indicator_type').textContent = '';
        if (document.getElementById('sadd_indicator_name')) document.getElementById('sadd_indicator_name').textContent = '';
        if (document.getElementById('sadd_benef_type')) document.getElementById('sadd_benef_type').textContent = '';
        if (document.getElementById('sadd_unit')) document.getElementById('sadd_unit').textContent = '';
        return;
    }

    const unit  = opt.getAttribute('data-unit') || '';
    const benef = opt.getAttribute('data-benef') || '';
    const indName = opt.getAttribute('data-indname') || '';
    const level = opt.getAttribute('data-level') || '';
    const indCode = opt.getAttribute('data-indcode') || '';

    // Auto-populate unit and beneficiary fields
    const unitDisplay = document.getElementById('unit_display');
    const benefDisplay = document.getElementById('benef_display');
    if (unitDisplay) unitDisplay.value = unit;
    if (benefDisplay) benefDisplay.value = benef;

        const targetDisplay = document.getElementById('target_display');
        const disaggDisplay = document.getElementById('disagg_display');
        const target = opt.getAttribute('data-target') || '';
        const disagg = opt.getAttribute('data-disagg') || '';
        if (targetDisplay) targetDisplay.value = target;
        if (disaggDisplay) disaggDisplay.value = disagg;


    // Populate SADD table header cells
    const typeText = level ? level.toUpperCase() : '';
    if (document.getElementById('sadd_indicator_type')) document.getElementById('sadd_indicator_type').textContent = typeText;
    if (document.getElementById('sadd_indicator_name')) document.getElementById('sadd_indicator_name').textContent = indName || indCode;
    if (document.getElementById('sadd_benef_type'))   document.getElementById('sadd_benef_type').textContent = benef;
    if (document.getElementById('sadd_unit'))         document.getElementById('sadd_unit').textContent = unit;

    if (document.getElementById('pwd_indicator_type')) document.getElementById('pwd_indicator_type').textContent = typeText;
    if (document.getElementById('pwd_indicator_name')) document.getElementById('pwd_indicator_name').textContent = indName;
    if (document.getElementById('pwd_benef_type'))     document.getElementById('pwd_benef_type').textContent = benef;
    if (document.getElementById('pwd_unit'))           document.getElementById('pwd_unit').textContent = unit;

    if (document.getElementById('nb_indicator_type'))  document.getElementById('nb_indicator_type').textContent = typeText;
    if (document.getElementById('nb_indicator_name'))  document.getElementById('nb_indicator_name').textContent = indName;
    if (document.getElementById('nb_unit'))            document.getElementById('nb_unit').textContent = unit;

    if (document.getElementById('d_indicator_type'))   document.getElementById('d_indicator_type').textContent = typeText;
    if (document.getElementById('d_indicator_name'))   document.getElementById('d_indicator_name').textContent = indName;
    if (document.getElementById('d_benef_type'))       document.getElementById('d_benef_type').textContent = benef;
    if (document.getElementById('d_unit'))             document.getElementById('d_unit').textContent = unit;

    const benefLower = (benef || '').toLowerCase();
    const unitLower  = (unit || '').toLowerCase();

    const isNonPersonByBenef =
        benefLower.includes('non-person') ||
        benefLower.includes('non person') ||
        benefLower.includes('facility') ||
        benefLower.includes('infrastructure') ||
        benefLower.includes('system');

    const isNumberUnit = unitLower.includes('number') || unitLower === 'no.' || unitLower === 'no';

    const isNonPerson = isNonPersonByBenef || (!benef && isNumberUnit && !unitLower.includes('%'));

    const personInputs = document.querySelectorAll('.person-input');
    const nonPersonInput = document.getElementById('non_person_value');
    const aiHelper = document.getElementById('ai_helper');
    const flag = document.getElementById('indicator_is_non_person');
    if (flag) flag.value = isNonPerson ? '1' : '0';

    if (isNonPerson) {
        personInputs.forEach(inp => { inp.disabled = true; inp.value = inp.value || 0; });
        if (nonPersonInput) nonPersonInput.disabled = false;
        if (aiHelper) {
            aiHelper.innerHTML =
                '🤖 <strong>Smart hint:</strong> This looks like a <strong>non-person</strong> indicator. ' +
                'Age/sex/PWD fields are turned off. Please enter the <strong>non-person number</strong> ' +
                '(e.g. facilities, sessions, kits) and optional denominator for coverage.';
        }
    } else {
        personInputs.forEach(inp => { inp.disabled = false; });
        if (nonPersonInput) nonPersonInput.disabled = false;
        if (aiHelper) {
            aiHelper.innerHTML =
                '🤖 <strong>Smart hint:</strong> This is a <strong>person-based</strong> indicator. ' +
                'Complete the age/sex/PWD breakdown. The system will auto-compute totals and coverage (%) ' +
                'when a denominator is provided.';
        }
    }
    updateDerived();
}

function updateDerived() {
    const getVal = id => {
        const el = document.getElementById(id);
        if (!el) return 0;
        const v = el.value !== undefined ? el.value : el.textContent;
        const n = parseFloat(v || '0');
        return isNaN(n) ? 0 : n;
    };

    const boys_u5      = getVal('boys_u5');
    const girls_u5     = getVal('girls_u5');
    const boys_5_17    = getVal('boys_5_17');
    const girls_5_17   = getVal('girls_5_17');
    const men_18_59    = getVal('men_18_59');
    const women_18_59  = getVal('women_18_59');
    const men_60p      = getVal('men_60p');
    const women_60p    = getVal('women_60p');

    const maleRowTotal   = boys_u5 + boys_5_17 + men_18_59 + men_60p;
    const femaleRowTotal = girls_u5 + girls_5_17 + women_18_59 + women_60p;
    const grandPersons   = maleRowTotal + femaleRowTotal;

    if (document.getElementById('row_total_all')) document.getElementById('row_total_all').textContent = grandPersons;

    if (document.getElementById('d_male_u5'))        document.getElementById('d_male_u5').textContent        = boys_u5;
    if (document.getElementById('d_female_u5'))      document.getElementById('d_female_u5').textContent      = girls_u5;
    if (document.getElementById('d_male_5_17'))      document.getElementById('d_male_5_17').textContent      = boys_5_17;
    if (document.getElementById('d_female_5_17'))    document.getElementById('d_female_5_17').textContent    = girls_5_17;
    if (document.getElementById('d_male_18_59'))     document.getElementById('d_male_18_59').textContent     = men_18_59;
    if (document.getElementById('d_female_18_59'))   document.getElementById('d_female_18_59').textContent   = women_18_59;
    if (document.getElementById('d_male_60p'))       document.getElementById('d_male_60p').textContent       = men_60p;
    if (document.getElementById('d_female_60p'))     document.getElementById('d_female_60p').textContent     = women_60p;
    if (document.getElementById('d_row_all'))        document.getElementById('d_row_all').textContent        = grandPersons;

    const childrenMale   = boys_u5 + boys_5_17;
    const childrenFemale = girls_u5 + girls_5_17;
    const childrenTotal  = childrenMale + childrenFemale;

    const adultsMale     = men_18_59;
    const adultsFemale   = women_18_59;
    const adultsTotal    = adultsMale + adultsFemale;

    const elderMale      = men_60p;
    const elderFemale    = women_60p;
    const elderTotal     = elderMale + elderFemale;

    if (document.getElementById('d_children_male'))   document.getElementById('d_children_male').textContent   = childrenMale;
    if (document.getElementById('d_children_female')) document.getElementById('d_children_female').textContent = childrenFemale;
    if (document.getElementById('d_children_total'))  document.getElementById('d_children_total').textContent  = childrenTotal;

    if (document.getElementById('d_adults_male'))     document.getElementById('d_adults_male').textContent     = adultsMale;
    if (document.getElementById('d_adults_female'))   document.getElementById('d_adults_female').textContent   = adultsFemale;
    if (document.getElementById('d_adults_total'))    document.getElementById('d_adults_total').textContent    = adultsTotal;

    if (document.getElementById('d_elder_male'))      document.getElementById('d_elder_male').textContent      = elderMale;
    if (document.getElementById('d_elder_female'))    document.getElementById('d_elder_female').textContent    = elderFemale;
    if (document.getElementById('d_elder_total'))     document.getElementById('d_elder_total').textContent     = elderTotal;

    const pwd_boys_u5        = getVal('pwd_boys_u5');
    const pwd_girls_u5       = getVal('pwd_girls_u5');
    const pwd_boys_5_17      = getVal('pwd_boys_5_17');
    const pwd_girls_5_17     = getVal('pwd_girls_5_17');
    const pwd_men_18_59      = getVal('pwd_men_18_59');
    const pwd_women_18_59    = getVal('pwd_women_18_59');
    const pwd_men_60p        = getVal('pwd_men_60p');
    const pwd_women_60p      = getVal('pwd_women_60p');

    const pwdMaleRow   = pwd_boys_u5 + pwd_boys_5_17 + pwd_men_18_59 + pwd_men_60p;
    const pwdFemaleRow = pwd_girls_u5 + pwd_girls_5_17 + pwd_women_18_59 + pwd_women_60p;
    const pwdTotal     = pwdMaleRow + pwdFemaleRow;

    if (document.getElementById('pwd_total_cell'))   document.getElementById('pwd_total_cell').textContent   = pwdTotal;

    if (document.getElementById('d_pwd_male_u5'))    document.getElementById('d_pwd_male_u5').textContent    = pwd_boys_u5;
    if (document.getElementById('d_pwd_male_5_17'))  document.getElementById('d_pwd_male_5_17').textContent  = pwd_boys_5_17;
    if (document.getElementById('d_pwd_male_18_59')) document.getElementById('d_pwd_male_18_59').textContent = pwd_men_18_59;
    if (document.getElementById('d_pwd_male_60p'))   document.getElementById('d_pwd_male_60p').textContent   = pwd_men_60p;

    if (document.getElementById('d_pwd_female_u5'))    document.getElementById('d_pwd_female_u5').textContent    = pwd_girls_u5;
    if (document.getElementById('d_pwd_female_5_17'))  document.getElementById('d_pwd_female_5_17').textContent  = pwd_girls_5_17;
    if (document.getElementById('d_pwd_female_18_59')) document.getElementById('d_pwd_female_18_59').textContent = pwd_women_18_59;
    if (document.getElementById('d_pwd_female_60p'))   document.getElementById('d_pwd_female_60p').textContent   = pwd_women_60p;

    if (document.getElementById('d_pwd_row_male'))   document.getElementById('d_pwd_row_male').textContent   = pwdMaleRow;
    if (document.getElementById('d_pwd_row_female')) document.getElementById('d_pwd_row_female').textContent = pwdFemaleRow;
    if (document.getElementById('d_pwd_total'))      document.getElementById('d_pwd_total').textContent      = pwdTotal;

    const flag = document.getElementById('indicator_is_non_person');
    const isNonPerson = flag && flag.value === '1';

    const nbDen = getVal('non_beneficiary');
    let nbNumerator = 0;
    if (isNonPerson) {
        const nonPersonInput = document.getElementById('non_person_value');
        const valFloat = nonPersonInput ? parseFloat(nonPersonInput.value || '0') : 0;
        nbNumerator = isNaN(valFloat) ? 0 : valFloat;
    } else {
        nbNumerator = grandPersons;
    }
    let nbPercent = 0;
    if (nbDen > 0 && nbNumerator > 0) {
        nbPercent = (nbNumerator / nbDen) * 100;
    }

    if (document.getElementById('nb_percent')) {
        document.getElementById('nb_percent').textContent = nbPercent > 0 ? nbPercent.toFixed(1) + '%' : '0%';
    }
    if (document.getElementById('nb_number')) {
        const numText = (typeof nbNumerator === 'number')
            ? nbNumerator.toFixed(2).replace(/\.00$/, '')
            : nbNumerator;
        document.getElementById('nb_number').textContent = numText;
    }

    const totalWithPwd = grandPersons + pwdTotal;
    const womenAndGirlsTotal = girls_u5 + girls_5_17 + women_18_59 + women_60p;

    let childrenShare = 0;
    let womenShare    = 0;
    let pwdShare      = 0;

    if (grandPersons > 0) {
        childrenShare = (childrenTotal / grandPersons) * 100;
        womenShare    = (womenAndGirlsTotal / grandPersons) * 100;
    }
    if (totalWithPwd > 0) {
        pwdShare = (pwdTotal / totalWithPwd) * 100;
    }

    const setPct = (idVal, idBar, val) => {
        const v = Math.round(val * 10) / 10;
        const elVal = document.getElementById(idVal);
        const elBar = document.getElementById(idBar);
        if (elVal) elVal.textContent = v.toFixed(1) + '%';
        if (elBar) elBar.style.width = (v > 100 ? 100 : v) + '%';
    };

    setPct('metric_children_share', 'bar_children_share', childrenShare);
    setPct('metric_women_share',    'bar_women_share',    womenShare);
    setPct('metric_pwd_share',      'bar_pwd_share',      pwdShare);

    const analyticsWrapper = document.getElementById('analytics_wrapper');
    const analyticsNote    = document.getElementById('analytics_note');

    if (analyticsWrapper && analyticsNote) {
        if (isNonPerson) {
            analyticsWrapper.style.opacity = 0.4;
            analyticsNote.textContent = 'ℹ️ Analytics charts are limited for non-person indicators. ' +
                'They will show zero until person-based data are entered.';
        } else {
            analyticsWrapper.style.opacity = 1;
            analyticsNote.textContent = '📌 Percentages are calculated using total persons reached (excluding PWD) ' +
                'and total PWD for share of PWD.';
        }
    }
}

function updatePrintHeader() {
    const phProject = document.getElementById('ph_project');
    const phLocation = document.getElementById('ph_location');
    const phPeriod = document.getElementById('ph_period');
    if (!phProject || !phLocation || !phPeriod) return;

    const projSel = document.getElementById('project_id');
    let projText = 'All / N/A';
    if (projSel && projSel.value) {
        projText = projSel.options[projSel.selectedIndex].text;
    }
    phProject.textContent = projText;

    const rSel = document.getElementById('region_id');
    const zSel = document.getElementById('zone_id');
    const wSel = document.getElementById('woreda_id');

    const locParts = [];
    if (rSel && rSel.value) locParts.push(rSel.options[rSel.selectedIndex].text);
    if (zSel && zSel.value) locParts.push(zSel.options[zSel.selectedIndex].text);
    if (wSel && wSel.value) locParts.push(wSel.options[wSel.selectedIndex].text);

    phLocation.textContent = locParts.length ? locParts.join(', ') : 'N/A';

    const pTypeSel = document.getElementById('period_type');
    const yearSel  = document.getElementById('year');
    const monthSel = document.getElementById('month');
    const weekSel  = document.getElementById('week');

    const year = yearSel ? yearSel.value : '';
    let label = (pTypeSel ? pTypeSel.value.toUpperCase() : 'PERIOD') + ' ' + year;

    if (pTypeSel && pTypeSel.value === 'monthly' && monthSel && monthSel.value !== 'na' && monthSel.value !== '') {
        label += ' (' + monthSel.options[monthSel.selectedIndex].text + ')';
    }
    if (pTypeSel && pTypeSel.value === 'weekly' && weekSel && weekSel.value !== '') {
        label += ' / W' + weekSel.value;
    }

    const startDate = document.getElementById('start_date')?.value || '';
    const endDate   = document.getElementById('end_date')?.value || '';
    if (startDate && endDate) {
        label += ' [' + startDate + ' to ' + endDate + ']';
    }

    phPeriod.textContent = label;
}

/* Special print for aggregation tab:
   only print header + tab3 content, hide everything else */
function printAggregation() {
    document.body.classList.add('print-aggregation');
    window.print();
    setTimeout(() => {
        document.body.classList.remove('print-aggregation');
    }, 1000);
}
function onRegionChange() {
    const regionSel   = document.getElementById('region_id');
    const regionOther = document.getElementById('region_other');

    if (!regionSel) return;
    const val = regionSel.value;

    if (val === 'other') {
        if (regionOther) regionOther.style.display = 'block';
    } else {
        if (regionOther) {
            regionOther.style.display = 'none';
            if (val !== '') regionOther.value = '';
        }
    }
    updateZonesFromRegion();
}

function onZoneChange() {
    updateWoredasFromZone();
}

function onWoredaChange() {
    const wSel       = document.getElementById('woreda_id');
    const woredaOther = document.getElementById('woreda_other');
    if (!wSel || !woredaOther) return;

    if (wSel.value === 'other') {
        woredaOther.style.display = 'block';
    } else {
        woredaOther.style.display = 'none';
        if (wSel.value !== '') {
            woredaOther.value = '';
        }
    }
}

document.addEventListener('DOMContentLoaded', function () {
    updatePeriodFields();
    updateDerived();
    
    // Auto-populate indicator fields if indicator is already selected (edit mode or page reload)
    const indicatorSelect = document.getElementById('indicator_key');
    if (indicatorSelect && indicatorSelect.value) {
        onIndicatorChange();
    }
    
    updatePrintHeader();
    // Ensure location "other" fields look correct on edit
    onRegionChange();
    onZoneChange();
    onWoredaChange();

    if (editRegionId) {
        const regionSel = document.getElementById('region_id');
        if (regionSel) {
            regionSel.value = String(editRegionId);
            updateZonesFromRegion();
            if (editZoneId) {
                const zoneSel = document.getElementById('zone_id');
                if (zoneSel) {
                    zoneSel.value = String(editZoneId);
                    updateWoredasFromZone();
                    if (editWoredaId) {
                        const woredaSel = document.getElementById('woreda_id');
                        if (woredaSel) {
                            woredaSel.value = String(editWoredaId);
                        }
                    }
                }
            }
        }
    }

    ['project_id','region_id','zone_id','woreda_id','period_type','year','month','week','start_date','end_date']
        .forEach(id => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('change', updatePrintHeader);
        });
});
</script>

</body>
</html>