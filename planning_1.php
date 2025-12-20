<?php
// Enhanced Project Planning Suite with Beneficiary Calculation
// Robust helpers.php loading - try multiple possible paths
$rootPath = dirname(__DIR__); // Get parent directory of pages/

if (!function_exists('smart_require_once')) {
    /**
     * Try several candidate paths and require the first that exists.
     * If none exist, throw a clear exception.
     */
    function smart_require_once(string $label, array $candidates): void
    {
        $tried = [];
        foreach ($candidates as $path) {
            $tried[] = $path;
            if (file_exists($path)) {
                require_once $path;
                return;
            }
        }
        
        // Last attempt: try to find in common locations
        $common_paths = [
            dirname(__DIR__) . '/helpers.php',
            $_SERVER['DOCUMENT_ROOT'] . '/helpers.php',
            $_SERVER['DOCUMENT_ROOT'] . '/hrs/helpers.php',
            $_SERVER['DOCUMENT_ROOT'] . '/health_reporting_system/helpers.php',
        ];
        
        foreach ($common_paths as $path) {
            if (file_exists($path)) {
                require_once $path;
                return;
            }
        }
        
        $msg = $label . " file not found. Tried: \n" . implode("\n", array_merge($tried, $common_paths));
        die('Fatal Error: ' . $msg);
    }
}

// Load helpers.php with multiple path attempts
// Also check based on actual script path in case of symlinks or different directory names
$script_dir = dirname($_SERVER['SCRIPT_FILENAME'] ?? __FILE__);
$script_parent = dirname($script_dir);

smart_require_once('helpers.php', [
    $rootPath . '/helpers.php',                    // root/helpers.php (most likely)
    __DIR__ . '/../helpers.php',                   // pages/../helpers.php
    $script_parent . '/helpers.php',              // Based on actual script path
    dirname(__DIR__) . '/helpers.php',            // Alternative parent path
    __DIR__ . '/helpers.php',                     // pages/helpers.php
    $rootPath . '/includes/helpers.php',          // root/includes/helpers.php
    $_SERVER['DOCUMENT_ROOT'] . '/helpers.php',   // Document root
    $_SERVER['DOCUMENT_ROOT'] . '/hrs/helpers.php', // Document root/hrs
    $_SERVER['DOCUMENT_ROOT'] . '/health_reporting_system/helpers.php', // Document root/health_reporting_system
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_login();
$pdo = getPDO();

// Ensure PDO throws exceptions (fix: silent failures can make saves look like they "worked" but nothing is stored)
try {
    if ($pdo instanceof PDO) {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }
} catch (Throwable $e) {
    // ignore
}

// ------------------------------------------------------
// EARLY POST HANDLER (fix: save happens BEFORE any HTML output)
// - Also removes hh_count dependency (households) so indicators/activities save reliably.
// ------------------------------------------------------
if (!defined('PLANNING_EARLY_POST')) {
    define('PLANNING_EARLY_POST', 1);
}

if (!function_exists('planning_table_has_column')) {
    function planning_table_has_column(PDO $pdo, string $table, string $column): bool {
        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
            $stmt->execute([$column]);
            return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('planning_ensure_tables')) {
    function planning_ensure_tables(PDO $pdo): void {
        // Ensure indicator tables exist (planning module specific)
        $pdo->exec("CREATE TABLE IF NOT EXISTS impact_indicators (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            result_id INT NOT NULL,
            beneficiary_type ENUM('host','returnee','idp','refugee','pwd','mixed','other') NOT NULL DEFAULT 'host',
            indicator_code VARCHAR(50),
            indicator_name TEXT NOT NULL,
            unit_type VARCHAR(50) DEFAULT 'persons',
            input_mode ENUM('sadd','count','percent_pair','percent_direct') NOT NULL DEFAULT 'sadd',
            total_target DECIMAL(15,2) DEFAULT 0,
            target_total DECIMAL(15,2) DEFAULT 0,
            boys_u5 INT DEFAULT 0,
            girls_u5 INT DEFAULT 0,
            boys_5_17 INT DEFAULT 0,
            girls_5_17 INT DEFAULT 0,
            men_18_59 INT DEFAULT 0,
            women_18_59 INT DEFAULT 0,
            men_60p INT DEFAULT 0,
            women_60p INT DEFAULT 0,
            pwd_count INT DEFAULT 0,
            hh_count INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS outcome_indicators (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            result_id INT NOT NULL,
            beneficiary_type ENUM('host','returnee','idp','refugee','pwd','mixed','other') NOT NULL DEFAULT 'host',
            indicator_code VARCHAR(50),
            indicator_name TEXT NOT NULL,
            unit_type VARCHAR(50) DEFAULT 'persons',
            input_mode ENUM('sadd','count','percent_pair','percent_direct') NOT NULL DEFAULT 'sadd',
            total_target DECIMAL(15,2) DEFAULT 0,
            target_total DECIMAL(15,2) DEFAULT 0,
            boys_u5 INT DEFAULT 0,
            girls_u5 INT DEFAULT 0,
            boys_5_17 INT DEFAULT 0,
            girls_5_17 INT DEFAULT 0,
            men_18_59 INT DEFAULT 0,
            women_18_59 INT DEFAULT 0,
            men_60p INT DEFAULT 0,
            women_60p INT DEFAULT 0,
            pwd_count INT DEFAULT 0,
            hh_count INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS output_indicators (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            result_id INT NOT NULL,
            beneficiary_type ENUM('host','returnee','idp','refugee','pwd','mixed','other') NOT NULL DEFAULT 'host',
            indicator_code VARCHAR(50),
            indicator_name TEXT NOT NULL,
            unit_type VARCHAR(50) DEFAULT 'persons',
            input_mode ENUM('sadd','count','percent_pair','percent_direct') NOT NULL DEFAULT 'sadd',
            total_target DECIMAL(15,2) DEFAULT 0,
            target_total DECIMAL(15,2) DEFAULT 0,
            boys_u5 INT DEFAULT 0,
            girls_u5 INT DEFAULT 0,
            boys_5_17 INT DEFAULT 0,
            girls_5_17 INT DEFAULT 0,
            men_18_59 INT DEFAULT 0,
            women_18_59 INT DEFAULT 0,
            men_60p INT DEFAULT 0,
            women_60p INT DEFAULT 0,
            pwd_count INT DEFAULT 0,
            hh_count INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Beneficiary planning table (if used)
        $pdo->exec("CREATE TABLE IF NOT EXISTS project_beneficiaries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            region_id INT NULL,
            zone_id INT NULL,
            woreda_id INT NULL,
            beneficiary_type VARCHAR(50) NOT NULL,
            women INT DEFAULT 0,
            girls INT DEFAULT 0,
            men INT DEFAULT 0,
            boys INT DEFAULT 0,
            pwd_count INT DEFAULT 0,
            hh_count INT DEFAULT 0,
            total INT DEFAULT 0,
            share_percentage DECIMAL(5,2) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
        
        // ✅ Ensure 'Other' text columns exist for custom locations
        foreach (['region_other','zone_other','woreda_other'] as $col) {
            try {
                if (!planning_table_has_column($pdo, 'project_beneficiaries', $col)) {
                    $pdo->exec("ALTER TABLE `project_beneficiaries` ADD COLUMN `$col` VARCHAR(255) NULL");
                }
            } catch (Throwable $e) {
                // ignore
            }
        }

        // ✅ Ensure beneficiary table has household & share columns (HH-only rows must SAVE + share recalculation)
        foreach (['hh_count','share_percentage'] as $col) {
            try {
                if (!planning_table_has_column($pdo, 'project_beneficiaries', $col)) {
                    if ($col === 'share_percentage') {
                        $pdo->exec("ALTER TABLE `project_beneficiaries` ADD COLUMN `share_percentage` DECIMAL(5,2) DEFAULT 0");
                    } else {
                        $pdo->exec("ALTER TABLE `project_beneficiaries` ADD COLUMN `hh_count` INT DEFAULT 0");
                    }
                }
            } catch (Throwable $e) { /* ignore */ }
        }

// ✅ Ensure new column hh_count exists (for household targets) in indicator tables
        foreach (['impact_indicators','outcome_indicators','output_indicators'] as $t) {
            try {
                if (!planning_table_has_column($pdo, $t, 'hh_count')) {
                    $pdo->exec("ALTER TABLE `$t` ADD COLUMN hh_count INT DEFAULT 0");
                }
            } catch (Throwable $e) {
                // ignore (no privileges / already exists / etc.)
            }
        }
        // ✅ Ensure total_target supports decimals + keep alias column target_total (for UI + cross-module compatibility)
        foreach (['impact_indicators','outcome_indicators','output_indicators'] as $t) {
            try {
                // total_target: allow decimals (%, kg, etc.)
                if (planning_table_has_column($pdo, $t, 'total_target')) {
                    $pdo->exec("ALTER TABLE `$t` MODIFY COLUMN `total_target` DECIMAL(15,2) DEFAULT 0");
                }
            } catch (Throwable $e) {
                // ignore
            }
            try {
                // target_total: legacy alias used in some UI sections
                if (!planning_table_has_column($pdo, $t, 'target_total')) {
                    $pdo->exec("ALTER TABLE `$t` ADD COLUMN `target_total` DECIMAL(15,2) DEFAULT 0");
                } else {
                    $pdo->exec("ALTER TABLE `$t` MODIFY COLUMN `target_total` DECIMAL(15,2) DEFAULT 0");
                }
                // One-time sync for existing rows (only where total_target is non-zero and alias is zero)
                $pdo->exec("UPDATE `$t` SET `target_total` = `total_target` WHERE `total_target` <> 0 AND (`target_total` IS NULL OR `target_total` = 0)");
            } catch (Throwable $e) {
                // ignore
            }
        }

        // ✅ Ensure ALL expected indicator columns exist (fix: older DB schemas caused "saved" indicators to disappear / not store)
        $expectedIndicatorCols = [
            'project_id'      => "INT NOT NULL DEFAULT 0",
            'result_id'       => "INT NOT NULL DEFAULT 0",
            'beneficiary_type'=> "VARCHAR(50) NOT NULL DEFAULT 'host'",
            'indicator_code'  => "VARCHAR(50) NULL",
            'indicator_name'  => "TEXT NULL",
            'unit_type'       => "VARCHAR(50) NULL",
            'input_mode'      => "VARCHAR(30) NULL",
            'total_target'    => "DECIMAL(15,2) DEFAULT 0",
            'target_total'    => "DECIMAL(15,2) DEFAULT 0",
            'boys_u5'         => "INT DEFAULT 0",
            'girls_u5'        => "INT DEFAULT 0",
            'boys_5_17'       => "INT DEFAULT 0",
            'girls_5_17'      => "INT DEFAULT 0",
            'men_18_59'       => "INT DEFAULT 0",
            'women_18_59'     => "INT DEFAULT 0",
            'men_60p'         => "INT DEFAULT 0",
            'women_60p'       => "INT DEFAULT 0",
            'pwd_count'       => "INT DEFAULT 0",
            'hh_count'        => "INT DEFAULT 0",
            'created_at'      => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
        ];

        foreach (['impact_indicators','outcome_indicators','output_indicators'] as $t) {
            foreach ($expectedIndicatorCols as $col => $def) {
                try {
                    if (!planning_table_has_column($pdo, $t, $col)) {
                        $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$col` $def");
                    }
                } catch (Throwable $e) {
                    // ignore
                }
            }

            // Backfill common legacy column names if they exist
            try {
                if (planning_table_has_column($pdo, $t, 'indicator_text') && planning_table_has_column($pdo, $t, 'indicator_name')) {
                    $pdo->exec("UPDATE `$t` SET `indicator_name` = COALESCE(NULLIF(`indicator_name`, ''), `indicator_text`) WHERE `indicator_text` IS NOT NULL");
                }
            } catch (Throwable $e) { /* ignore */ }
        }


    }
}

if (!function_exists('planning_sum_sadd')) {
    function planning_sum_sadd(array $data): int {
        $keys = ['boys_u5','girls_u5','boys_5_17','girls_5_17','men_18_59','women_18_59','men_60p','women_60p'];
        $sum = 0;
        foreach ($keys as $k) { $sum += (int)($data[$k] ?? 0); }
        return $sum;
    }
}


if (!function_exists('planning_sync_target_total_alias')) {
    function planning_sync_target_total_alias(PDO $pdo, string $table, int $rowId): void {
        try {
            if (function_exists('planning_table_has_column') && planning_table_has_column($pdo, $table, 'target_total')) {
                $stmt = $pdo->prepare("UPDATE `$table` SET `target_total` = `total_target` WHERE `id` = ?");
                $stmt->execute([$rowId]);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
}

if (!function_exists('planning_fetch_project_details')) {
    /**
     * Fetch full project details from DB for reliable cross-app display (donor, duration, fund, owner, etc.).
     * Keeps any fields already present in $fallback (e.g., helper-joined location names).
     */
    function planning_fetch_project_details(PDO $pdo, int $projectId, array $fallback = []): array {
        $row = $fallback;

        // 1) Base project row
        try {
            $st = $pdo->prepare("SELECT * FROM `projects` WHERE `id` = ?");
            $st->execute([$projectId]);
            $db = $st->fetch(PDO::FETCH_ASSOC);
            if (is_array($db)) {
                // Prefer DB values, but do not erase non-empty fallback values
                foreach ($db as $k => $v) {
                    if (!array_key_exists($k, $row) || $row[$k] === null || $row[$k] === '' || $row[$k] === 'Not specified') {
                        $row[$k] = $v;
                    } else {
                        // Keep existing fallback value
                        $row[$k] = $row[$k];
                    }
                }
            }
        } catch (Throwable $e) {
            // ignore
        }

        // 2) Location best-effort (show something useful even if reference tables are missing)
        $loc = trim((string)($row['location'] ?? ''));
        if ($loc !== '') {
            // If planning UI expects region_name/zone_name/woreda_name, use location as a single-line fallback
            if (empty($row['region_name'])) { $row['region_name'] = $loc; }
        }

        // If projects.php stores "other" strings, surface them into *_name fields
        foreach ([
            ['region_other', 'region_name'],
            ['zone_other',   'zone_name'],
            ['woreda_other', 'woreda_name'],
        ] as $map) {
            [$src, $dst] = $map;
            if (empty($row[$dst]) && !empty($row[$src])) {
                $row[$dst] = $row[$src];
            }
        }

        // 3) If only IDs exist, try to resolve names from common tables (safe: all in try/catch)
        try {
            $rid = isset($row['region_id']) ? (int)$row['region_id'] : 0;
            if ($rid > 0 && empty($row['region_name'])) {
                foreach (['regions','admin_regions','tbl_regions'] as $t) {
                    try {
                        $st = $pdo->prepare("SELECT `name` FROM `$t` WHERE `id` = ? LIMIT 1");
                        $st->execute([$rid]);
                        $nm = $st->fetchColumn();
                        if ($nm) { $row['region_name'] = $nm; break; }
                    } catch (Throwable $e) { /* ignore */ }
                }
            }
        } catch (Throwable $e) { /* ignore */ }

        try {
            $zid = isset($row['zone_id']) ? (int)$row['zone_id'] : 0;
            if ($zid > 0 && empty($row['zone_name'])) {
                foreach (['zones','admin_zones','tbl_zones'] as $t) {
                    try {
                        $st = $pdo->prepare("SELECT `name` FROM `$t` WHERE `id` = ? LIMIT 1");
                        $st->execute([$zid]);
                        $nm = $st->fetchColumn();
                        if ($nm) { $row['zone_name'] = $nm; break; }
                    } catch (Throwable $e) { /* ignore */ }
                }
            }
        } catch (Throwable $e) { /* ignore */ }

        try {
            $wid = isset($row['woreda_id']) ? (int)$row['woreda_id'] : 0;
            if ($wid > 0 && empty($row['woreda_name'])) {
                foreach (['woredas','admin_woredas','tbl_woredas'] as $t) {
                    try {
                        $st = $pdo->prepare("SELECT `name` FROM `$t` WHERE `id` = ? LIMIT 1");
                        $st->execute([$wid]);
                        $nm = $st->fetchColumn();
                        if ($nm) { $row['woreda_name'] = $nm; break; }
                    } catch (Throwable $e) { /* ignore */ }
                }
            }
        } catch (Throwable $e) { /* ignore */ }

        return $row;
    }
}

// Ensure planning tables exist for BOTH GET and POST (fix: prevents 'not saved/unknown' symptoms when tables/cols are missing)
try { planning_ensure_tables($pdo); } catch (Throwable $e) { /* ignore */ }

// Handle POST early and redirect (PRG) so tables always refresh and show saved rows.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    planning_ensure_tables($pdo);

    // Fix: if the hidden project_id is present but empty (""), it previously overwrote the session selection and became 0.
    $project_id_raw = $_POST['project_id'] ?? null;
    if ($project_id_raw === null || $project_id_raw === '') {
        $project_id_raw = $_SESSION['selected_project_id'] ?? 0;
    }
    $project_id = (int)$project_id_raw;
    $anchor = 'projects';
    $msg = '';
    $err = '';

    try {
        // RESULTS (Impact/Outcome/Output)
        if (isset($_POST['add_result']) || isset($_POST['update_result']) || isset($_POST['delete_result'])) {
            $anchor = 'results';

            if (isset($_POST['add_result'])) {
                $level = $_POST['level'] ?? 'output';
                $code  = trim($_POST['code'] ?? '');
                $name  = trim($_POST['name'] ?? '');
                if (!$project_id || $name === '') { throw new RuntimeException("Missing required fields for result (project/name)."); }
                $stmt = $pdo->prepare("INSERT INTO results_chain (project_id, level, code, name) VALUES (?,?,?,?)");
                $stmt->execute([$project_id, $level, $code, $name]);
                $msg = "✅ Result added.";
            }

            if (isset($_POST['update_result'])) {
                $id    = (int)($_POST['id'] ?? 0);
                $level = $_POST['level'] ?? 'output';
                $code  = trim($_POST['code'] ?? '');
                $name  = trim($_POST['name'] ?? '');
                if ($id <= 0) { throw new RuntimeException("Invalid result id."); }
                $stmt = $pdo->prepare("UPDATE results_chain SET level=?, code=?, name=? WHERE id=? AND project_id=?");
                $stmt->execute([$level, $code, $name, $id, $project_id]);
                $msg = "✅ Result updated.";
            }

            if (isset($_POST['delete_result'])) {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { throw new RuntimeException("Invalid result id."); }
                // delete linked indicators and unlink activities then delete result
                $pdo->prepare("DELETE FROM impact_indicators WHERE result_id=? AND project_id=?")->execute([$id, $project_id]);
                $pdo->prepare("DELETE FROM outcome_indicators WHERE result_id=? AND project_id=?")->execute([$id, $project_id]);
                $pdo->prepare("DELETE FROM output_indicators WHERE result_id=? AND project_id=?")->execute([$id, $project_id]);
                $pdo->prepare("UPDATE activities SET output_id=NULL WHERE output_id=? AND project_id=?")->execute([$id, $project_id]);
                $pdo->prepare("DELETE FROM results_chain WHERE id=? AND project_id=?")->execute([$id, $project_id]);
                $msg = "🗑️ Result deleted.";
            }
        }

        // INDICATORS (Impact/Outcome/Output) - households removed (we ignore hh_count)
        if (isset($_POST['add_impact_indicator']) || isset($_POST['update_impact_indicator']) || isset($_POST['delete_impact_indicator'])
            || isset($_POST['add_outcome_indicator']) || isset($_POST['update_outcome_indicator']) || isset($_POST['delete_outcome_indicator'])
            || isset($_POST['add_output_indicator']) || isset($_POST['update_output_indicator']) || isset($_POST['delete_output_indicator'])) {

            $anchor = 'indicators';

            $is_impact  = isset($_POST['add_impact_indicator']) || isset($_POST['update_impact_indicator']) || isset($_POST['delete_impact_indicator']);
            $is_outcome = isset($_POST['add_outcome_indicator']) || isset($_POST['update_outcome_indicator']) || isset($_POST['delete_outcome_indicator']);
            $is_output  = isset($_POST['add_output_indicator']) || isset($_POST['update_output_indicator']) || isset($_POST['delete_output_indicator']);

            $table = $is_impact ? 'impact_indicators' : ($is_outcome ? 'outcome_indicators' : 'output_indicators');

            if (isset($_POST['delete_impact_indicator']) || isset($_POST['delete_outcome_indicator']) || isset($_POST['delete_output_indicator'])) {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { throw new RuntimeException("Invalid indicator id."); }
                $pdo->prepare("DELETE FROM `$table` WHERE id=? AND project_id=?")->execute([$id, $project_id]);
                $msg = "🗑️ Indicator deleted.";
            } else {
                $result_id      = (int)($_POST['result_id'] ?? 0);
                $beneficiary    = $_POST['beneficiary_type'] ?? 'host';
                $indicator_code = trim($_POST['indicator_code'] ?? '');
                $indicator_name = trim($_POST['indicator_name'] ?? '');
                $unit_type      = $_POST['unit_type'] ?? 'persons';
                $input_mode     = $_POST['input_mode'] ?? 'sadd';

                $disagg = [
                    'boys_u5'     => (int)($_POST['boys_u5'] ?? 0),
                    'girls_u5'    => (int)($_POST['girls_u5'] ?? 0),
                    'boys_5_17'   => (int)($_POST['boys_5_17'] ?? 0),
                    'girls_5_17'  => (int)($_POST['girls_5_17'] ?? 0),
                    'men_18_59'   => (int)($_POST['men_18_59'] ?? 0),
                    'women_18_59' => (int)($_POST['women_18_59'] ?? 0),
                    'men_60p'     => (int)($_POST['men_60p'] ?? 0),
                    'women_60p'   => (int)($_POST['women_60p'] ?? 0),
                    'pwd_count'   => (int)($_POST['pwd_count'] ?? 0),
                ];

                
                $hh_count = (int)($_POST['hh_count'] ?? 0);

                // ✅ Enforce unit-of-measure logic:
                // - Non-person units MUST NOT use SADD (age/sex) entry
                // - Percent allows decimals; sessions/alerts are integers; kg/grams etc allow decimals
                $unit_cat = function_exists('indicator_unit_category') ? indicator_unit_category($unit_type) : 'integer';
                $is_person_unit = function_exists('is_person_unit_type') ? is_person_unit_type($unit_type) : (stripos($unit_type, 'person') !== false);
                $u_lc = strtolower(trim((string)$unit_type));
                $is_household_unit = ($u_lc === 'households' || $u_lc === 'hh' || strpos($u_lc, 'household') !== false);

                if (!$is_person_unit) {
                    // Force input mode away from SADD for non-person units
                    $input_mode = ($unit_cat === 'percent') ? 'percent_direct' : 'count';

                    // Clear SADD breakdown + PWD (not applicable when unit isn't people)
                    foreach (array_keys($disagg) as $k) { $disagg[$k] = 0; }

                    // HH count is only applicable when unit is households/HH
                    if (!$is_household_unit) { $hh_count = 0; }
                }
$total_target_raw = $_POST['total_target'] ?? ($_POST['target_total'] ?? 0);
    $total_target     = normalize_indicator_total_target($unit_type, $input_mode, $total_target_raw);

                // Smart total target rule (FIX: do NOT overwrite user Total Target with 0 when SADD/HH fields are not filled)
                $sadd_sum = planning_sum_sadd($disagg);

                if ($unit_type === 'households') {
                    // Prefer HH count if provided; otherwise keep typed total target
                    if ($hh_count > 0) { $total_target = $hh_count; }
                } elseif ($input_mode === 'sadd') {
                    // Prefer SADD sum if provided; otherwise keep typed total target
                    if ($sadd_sum > 0) { $total_target = $sadd_sum; }
                }
if (!$project_id || $result_id <= 0 || $indicator_name === '') {
                    throw new RuntimeException("Missing required fields for indicator (project/result/name).");
                }

                if (isset($_POST['add_impact_indicator']) || isset($_POST['add_outcome_indicator']) || isset($_POST['add_output_indicator'])) {
                    $stmt = $pdo->prepare("INSERT INTO `$table`
                        (project_id, result_id, beneficiary_type, indicator_code, indicator_name, unit_type, input_mode, total_target,
                         boys_u5, girls_u5, boys_5_17, girls_5_17, men_18_59, women_18_59, men_60p, women_60p, pwd_count, hh_count)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $stmt->execute([
                        $project_id, $result_id, $beneficiary, $indicator_code, $indicator_name, $unit_type, $input_mode, $total_target,
                        $disagg['boys_u5'], $disagg['girls_u5'], $disagg['boys_5_17'], $disagg['girls_5_17'],
                        $disagg['men_18_59'], $disagg['women_18_59'], $disagg['men_60p'], $disagg['women_60p'], $disagg['pwd_count'], $hh_count,
                    ]);
                    $newId = (int)$pdo->lastInsertId();
                    if ($newId > 0) { planning_sync_target_total_alias($pdo, $table, $newId); }
                    $msg = "✅ Indicator added.";
                } else {
                    $id = (int)($_POST['id'] ?? 0);
                    if ($id <= 0) { throw new RuntimeException("Invalid indicator id."); }
                    $stmt = $pdo->prepare("UPDATE `$table` SET
                        result_id=?, beneficiary_type=?, indicator_code=?, indicator_name=?, unit_type=?, input_mode=?, total_target=?,
                        boys_u5=?, girls_u5=?, boys_5_17=?, girls_5_17=?, men_18_59=?, women_18_59=?, men_60p=?, women_60p=?, pwd_count=?, hh_count=?
                        WHERE id=? AND project_id=?");
                    $stmt->execute([
                        $result_id, $beneficiary, $indicator_code, $indicator_name, $unit_type, $input_mode, $total_target,
                        $disagg['boys_u5'], $disagg['girls_u5'], $disagg['boys_5_17'], $disagg['girls_5_17'],
                        $disagg['men_18_59'], $disagg['women_18_59'], $disagg['men_60p'], $disagg['women_60p'], $disagg['pwd_count'], $hh_count,
                        $id, $project_id
                    ]);
                    planning_sync_target_total_alias($pdo, $table, $id);
                    $msg = "✅ Indicator updated.";
                }
            }
        }

        // ACTIVITIES
        if (isset($_POST['add_activity']) || isset($_POST['update_activity']) || isset($_POST['delete_activity'])) {
            $anchor = 'activities';

            if (isset($_POST['delete_activity'])) {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { throw new RuntimeException("Invalid activity id."); }
                $pdo->prepare("DELETE FROM activities WHERE id=? AND project_id=?")->execute([$id, $project_id]);
                $msg = "🗑️ Activity deleted.";
            } else {
                $output_id = ($_POST['output_id'] ?? '') !== '' ? (int)$_POST['output_id'] : null;
                $code   = trim($_POST['code'] ?? '');
                $name   = trim($_POST['name'] ?? '');
                $unit   = trim($_POST['unit'] ?? '');
                $target = (int)($_POST['target'] ?? 0);

                if (!$project_id || $code === '' || $name === '') {
                    throw new RuntimeException("Missing required fields for activity (project/name).");
                }

                if (isset($_POST['add_activity'])) {
                    $stmt = $pdo->prepare("INSERT INTO activities (project_id, output_id, code, name, unit, target) VALUES (?,?,?,?,?,?)");
                    $stmt->execute([$project_id, $output_id, $code, $name, $unit, $target]);
                    $msg = "✅ Activity added.";
                } else {
                    $id = (int)($_POST['id'] ?? 0);
                    if ($id <= 0) { throw new RuntimeException("Invalid activity id."); }
                    $stmt = $pdo->prepare("UPDATE activities SET output_id=?, code=?, name=?, unit=?, target=? WHERE id=? AND project_id=?");
                    $stmt->execute([$output_id, $code, $name, $unit, $target, $id, $project_id]);
                    $msg = "✅ Activity updated.";
                }
            }
        }

        // BENEFICIARIES SAVE (optional) - Upgraded (upsert, delete tracking, Other fields, validation)
        if (isset($_POST['action']) && $_POST['action'] === 'save_beneficiaries') {
            $beneficiary_data = $_POST['beneficiaries'] ?? [];
            $deleted_ids = $_POST['beneficiaries_deleted_ids'] ?? [];

            if (!$project_id) { throw new RuntimeException("Project not selected for beneficiary save."); }

            // Normalize deleted ids
            $del = [];
            if (is_array($deleted_ids)) {
                foreach ($deleted_ids as $did) {
                    $did = (int)$did;
                    if ($did > 0) { $del[] = $did; }
                }
            }

            $pdo->beginTransaction();

            // Delete removed rows (only those explicitly removed)
            if (!empty($del)) {
                $placeholders = implode(',', array_fill(0, count($del), '?'));
                $params = array_merge([$project_id], $del);
                $pdo->prepare("DELETE FROM project_beneficiaries WHERE project_id=? AND id IN ($placeholders)")->execute($params);
            }

            // Upsert rows
            $upRows = [];

            foreach ($beneficiary_data as $i => $row) {
                $region_id = $row['region_id'] ?? null;
                $zone_id   = $row['zone_id'] ?? null;
                $woreda_id = $row['woreda_id'] ?? null;

                $region_other = trim((string)($row['region_other'] ?? ''));
                $zone_other   = trim((string)($row['zone_other'] ?? ''));
                $woreda_other = trim((string)($row['woreda_other'] ?? ''));

                if ($region_id === 'other') { $region_id = null; }
                if ($zone_id === 'other')   { $zone_id = null; }
                if ($woreda_id === 'other') { $woreda_id = null; }

                $women = max(0, (int)($row['women'] ?? 0));
                $girls = max(0, (int)($row['girls'] ?? 0));
                $men   = max(0, (int)($row['men'] ?? 0));
                $boys  = max(0, (int)($row['boys'] ?? 0));
                $pwd   = max(0, (int)($row['pwd_count'] ?? 0));
                $hh    = max(0, (int)($row['hh_count'] ?? 0));

                // Total rule:
                // - if people breakdown exists, use people total
                // - else if HH exists, use HH as total (so HH-only rows SAVE and are counted)
                $people_total = $women + $girls + $men + $boys + $pwd;
                $total = ($people_total > 0) ? $people_total : $hh;

                $btype = trim((string)($row['beneficiary_type'] ?? 'other'));
                if ($btype === '') { $btype = 'other'; }

                // Skip completely empty rows
                $hasLoc = ($region_id || $region_other !== '') || ($zone_id || $zone_other !== '') || ($woreda_id || $woreda_other !== '');
                if (!$hasLoc && $total === 0) { continue; }

                // Required location fields for meaningful rows
                if ((!$region_id && $region_other === '') ||
                    (!$zone_id && $zone_other === '') ||
                    (!$woreda_id && $woreda_other === '')) {
                    if ($total > 0) {
                        throw new RuntimeException("Row " . ($i+1) . ": Region/Zone/Woreda is required (select or type Other).");
                    }
                    continue;
                }

                $id = (int)($row['id'] ?? 0);

                $upRows[] = [
                    'id' => $id,
                    'region_id' => $region_id ? (int)$region_id : null,
                    'zone_id' => $zone_id ? (int)$zone_id : null,
                    'woreda_id' => $woreda_id ? (int)$woreda_id : null,
                    'region_other' => ($region_id ? null : ($region_other ?: null)),
                    'zone_other'   => ($zone_id ? null : ($zone_other ?: null)),
                    'woreda_other' => ($woreda_id ? null : ($woreda_other ?: null)),
                    'beneficiary_type' => $btype,
                    'women' => $women, 'girls' => $girls, 'men' => $men, 'boys' => $boys, 'pwd_count' => $pwd,
                    'hh_count' => $hh,
                    'total' => $total,
                ];
            }

            $insertStmt = $pdo->prepare("
                INSERT INTO project_beneficiaries
                (project_id, region_id, zone_id, woreda_id, region_other, zone_other, woreda_other, beneficiary_type, women, girls, men, boys, pwd_count, hh_count, total, share_percentage)
                VALUES
                (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");

            $updateStmt = $pdo->prepare("
                UPDATE project_beneficiaries SET
                    region_id=?, zone_id=?, woreda_id=?,
                    region_other=?, zone_other=?, woreda_other=?,
                    beneficiary_type=?,
                    women=?, girls=?, men=?, boys=?, pwd_count=?, hh_count=?,
                    total=?
                WHERE id=? AND project_id=?
            ");

            foreach ($upRows as $r) {
                if (!empty($r['id'])) {
                    $updateStmt->execute([
                        $r['region_id'], $r['zone_id'], $r['woreda_id'],
                        $r['region_other'], $r['zone_other'], $r['woreda_other'],
                        $r['beneficiary_type'],
                        $r['women'], $r['girls'], $r['men'], $r['boys'], $r['pwd_count'], $r['hh_count'],
                        $r['total'],
                        $r['id'], $project_id
                    ]);
                } else {
                    $insertStmt->execute([
                        $project_id,
                        $r['region_id'], $r['zone_id'], $r['woreda_id'],
                        $r['region_other'], $r['zone_other'], $r['woreda_other'],
                        $r['beneficiary_type'],
                        $r['women'], $r['girls'], $r['men'], $r['boys'], $r['pwd_count'], $r['hh_count'],
                        $r['total'],
                        0
                    ]);
                }
            }

            // Recalculate shares
            $sumStmt = $pdo->prepare("SELECT SUM(total) FROM project_beneficiaries WHERE project_id=?");
            $sumStmt->execute([$project_id]);
            $grandTotal = (int)$sumStmt->fetchColumn();

            if ($grandTotal > 0) {
                $pdo->prepare("UPDATE project_beneficiaries SET share_percentage = ROUND((total / ?) * 100, 2) WHERE project_id=?")->execute([$grandTotal, $project_id]);
            } else {
                $pdo->prepare("UPDATE project_beneficiaries SET share_percentage = 0 WHERE project_id=?")->execute([$project_id]);
            }

            $pdo->commit();
            $msg = "✅ Beneficiaries saved.";
        }

        // BENEFICIARIES BULK IMPORT (CSV)
        if (isset($_POST['action']) && $_POST['action'] === 'import_beneficiaries_csv') {
            if (!$project_id) { throw new RuntimeException("Project not selected for beneficiary import."); }
            if (empty($_FILES['beneficiary_csv']) || !is_uploaded_file($_FILES['beneficiary_csv']['tmp_name'])) {
                throw new RuntimeException("Please select a CSV file to import.");
            }
            $replace = ((int)($_POST['replace_existing'] ?? 0) === 1);

            $tmp = $_FILES['beneficiary_csv']['tmp_name'];
            $fh = fopen($tmp, 'r');
            if (!$fh) { throw new RuntimeException("Failed to open uploaded CSV."); }

            $header = fgetcsv($fh);
            if (!$header) { throw new RuntimeException("CSV is empty."); }

            $keys = array_map(function($h){
                $h = strtolower(trim((string)$h));
                $h = preg_replace('/\s+/', '_', $h);
                return $h;
            }, $header);

            // Lookups (name->id)
            $regionMap = [];
            foreach ($regions as $r) { $regionMap[strtolower(trim($r['name']))] = (int)$r['id']; }
            $zoneMap = [];
            foreach ($zones as $z) { $zoneMap[strtolower(trim($z['name']))] = (int)$z['id']; }
            $woredaMap = [];
            foreach ($woredas as $w) { $woredaMap[strtolower(trim($w['name']))] = (int)$w['id']; }

            $rows = [];

            while (($data = fgetcsv($fh)) !== false) {
                if (count($data) === 1 && trim((string)$data[0]) === '') { continue; }
                $row = [];
                foreach ($keys as $i => $k) { $row[$k] = $data[$i] ?? ''; }

                $regionVal = trim((string)($row['region'] ?? ''));
                $zoneVal   = trim((string)($row['zone'] ?? ''));
                $woredaVal = trim((string)($row['woreda'] ?? ''));

                $region_other = trim((string)($row['region_other'] ?? ''));
                $zone_other   = trim((string)($row['zone_other'] ?? ''));
                $woreda_other = trim((string)($row['woreda_other'] ?? ''));

                $region_id = null;
                if ($regionVal !== '' && ctype_digit($regionVal)) {
                    $region_id = (int)$regionVal;
                } elseif ($regionVal !== '') {
                    $lk = strtolower($regionVal);
                    if (isset($regionMap[$lk])) $region_id = $regionMap[$lk];
                    else $region_other = $region_other ?: $regionVal;
                } elseif ($region_other !== '') {
                    $region_id = null;
                }

                $zone_id = null;
                if ($zoneVal !== '' && ctype_digit($zoneVal)) {
                    $zone_id = (int)$zoneVal;
                } elseif ($zoneVal !== '') {
                    $lk = strtolower($zoneVal);
                    if (isset($zoneMap[$lk])) $zone_id = $zoneMap[$lk];
                    else $zone_other = $zone_other ?: $zoneVal;
                } elseif ($zone_other !== '') {
                    $zone_id = null;
                }

                $woreda_id = null;
                if ($woredaVal !== '' && ctype_digit($woredaVal)) {
                    $woreda_id = (int)$woredaVal;
                } elseif ($woredaVal !== '') {
                    $lk = strtolower($woredaVal);
                    if (isset($woredaMap[$lk])) $woreda_id = $woredaMap[$lk];
                    else $woreda_other = $woreda_other ?: $woredaVal;
                } elseif ($woreda_other !== '') {
                    $woreda_id = null;
                }

                $btype = trim((string)($row['beneficiary_type'] ?? 'other'));
                if ($btype === '') { $btype = 'other'; }

                $women = max(0, (int)($row['women'] ?? 0));
                $girls = max(0, (int)($row['girls'] ?? 0));
                $men   = max(0, (int)($row['men'] ?? 0));
                $boys  = max(0, (int)($row['boys'] ?? 0));
                $pwd   = max(0, (int)($row['pwd_count'] ?? ($row['pwd'] ?? 0)));

                $total = $women + $girls + $men + $boys + $pwd;

                $hasLoc = ($region_id || $region_other !== '') || ($zone_id || $zone_other !== '') || ($woreda_id || $woreda_other !== '');
                if (!$hasLoc && $total === 0) { continue; }

                $rows[] = [
                    'region_id' => $region_id,
                    'zone_id' => $zone_id,
                    'woreda_id' => $woreda_id,
                    'region_other' => $region_id ? null : ($region_other ?: null),
                    'zone_other'   => $zone_id ? null : ($zone_other ?: null),
                    'woreda_other' => $woreda_id ? null : ($woreda_other ?: null),
                    'beneficiary_type' => $btype,
                    'women' => $women, 'girls' => $girls, 'men' => $men, 'boys' => $boys, 'pwd_count' => $pwd,
                    'total' => $total,
                ];
            }
            fclose($fh);

            $pdo->beginTransaction();
            if ($replace) {
                $pdo->prepare("DELETE FROM project_beneficiaries WHERE project_id=?")->execute([$project_id]);
            }

            $ins = $pdo->prepare("
                INSERT INTO project_beneficiaries
                (project_id, region_id, zone_id, woreda_id, region_other, zone_other, woreda_other, beneficiary_type, women, girls, men, boys, pwd_count, hh_count, total, share_percentage)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");

            foreach ($rows as $r) {
                $ins->execute([
                    $project_id,
                    $r['region_id'], $r['zone_id'], $r['woreda_id'],
                    $r['region_other'], $r['zone_other'], $r['woreda_other'],
                    $r['beneficiary_type'],
                    $r['women'], $r['girls'], $r['men'], $r['boys'], $r['pwd_count'],
                    0,
                    $r['total'],
                    0
                ]);
            }

            // Recalculate shares
            $sumStmt = $pdo->prepare("SELECT SUM(total) FROM project_beneficiaries WHERE project_id=?");
            $sumStmt->execute([$project_id]);
            $grandTotal = (int)$sumStmt->fetchColumn();

            if ($grandTotal > 0) {
                $pdo->prepare("UPDATE project_beneficiaries SET share_percentage = ROUND((total / ?) * 100, 2) WHERE project_id=?")->execute([$grandTotal, $project_id]);
            } else {
                $pdo->prepare("UPDATE project_beneficiaries SET share_percentage = 0 WHERE project_id=?")->execute([$project_id]);
            }

            $pdo->commit();
            $msg = "✅ Beneficiaries imported (" . count($rows) . " rows).";
        }


        if ($msg === '' && $err === '') {
            $msg = "✅ Saved.";
        }
    } catch (Throwable $e) {
        $err = "❌ Save failed: " . $e->getMessage();
        error_log("Planning EARLY POST error: " . $e->getMessage());
    }

    $_SESSION['planning_flash_message'] = $msg;
    $_SESSION['planning_flash_error'] = $err;

    $redir = "planning.php" . ($project_id ? ("?project_id=" . $project_id) : "");
    if ($anchor) { $redir .= "#" . $anchor; }
    header("Location: " . $redir);
    exit;
}

// ------------------------------------------------------
// Export handling (CSV / Word / Excel) - MOVED TO TOP
// ------------------------------------------------------
$action = $_GET['action'] ?? '';

// ------------------------------------------------------
// Beneficiary tools (template, export, pagination JSON)
// ------------------------------------------------------
if ($action === 'download_beneficiary_template_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="beneficiaries_template.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

    fputcsv($out, [
        'region','zone','woreda',
        'region_other','zone_other','woreda_other',
        'beneficiary_type','women','girls','men','boys','pwd_count','hh_count'
    ]);
    fputcsv($out, ['Oromia','East Welega','Sasiga','','','','idp',10,12,8,9,1,0]);
    fclose($out);
    exit;
}

if ($action === 'export_beneficiaries_csv') {
    $pid = (int)($_GET['project_id'] ?? 0);
    if ($pid <= 0) { http_response_code(400); echo "Missing project_id"; exit; }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="beneficiaries_project_' . $pid . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

    fputcsv($out, ['Region','Zone','Woreda','Beneficiary Type','Women','Girls','Men','Boys','PWD','HH','Total','Share %']);

    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(r.name, pb.region_other, '') AS region_name,
            COALESCE(z.name, pb.zone_other, '')   AS zone_name,
            COALESCE(w.name, pb.woreda_other, '') AS woreda_name,
            pb.beneficiary_type,
            pb.women, pb.girls, pb.men, pb.boys, pb.pwd_count, pb.hh_count, pb.total, pb.share_percentage
        FROM project_beneficiaries pb
        LEFT JOIN regions r ON pb.region_id = r.id
        LEFT JOIN zones z ON pb.zone_id = z.id
        LEFT JOIN woredas w ON pb.woreda_id = w.id
        WHERE pb.project_id = ?
        ORDER BY pb.id
    ");
    $stmt->execute([$pid]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $row['region_name'],
            $row['zone_name'],
            $row['woreda_name'],
            $row['beneficiary_type'],
            (int)$row['women'], (int)$row['girls'], (int)$row['men'], (int)$row['boys'], (int)$row['pwd_count'],
            (int)$row['hh_count'],
            (int)$row['total'],
            (float)$row['share_percentage'],
        ]);
    }
    fclose($out);
    exit;
}

if ($action === 'beneficiary_rows_json') {
    header('Content-Type: application/json; charset=utf-8');
    $pid = (int)($_GET['project_id'] ?? 0);
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $limit  = (int)($_GET['limit'] ?? 25);
    if ($limit <= 0 || $limit > 200) { $limit = 25; }
    if ($pid <= 0) { echo json_encode(['ok'=>false,'error'=>'Missing project_id']); exit; }

    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM project_beneficiaries WHERE project_id=?");
    $totalStmt->execute([$pid]);
    $total = (int)$totalStmt->fetchColumn();

    $sql = "
        SELECT 
            pb.*,
            r.name AS region_name,
            z.name AS zone_name,
            w.name AS woreda_name
        FROM project_beneficiaries pb
        LEFT JOIN regions r ON pb.region_id = r.id
        LEFT JOIN zones z ON pb.zone_id = z.id
        LEFT JOIN woredas w ON pb.woreda_id = w.id
        WHERE pb.project_id = ?
        ORDER BY pb.id
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$pid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'total' => $total,
        'offset' => $offset,
        'limit' => $limit,
        'next_offset' => min($total, $offset + $limit),
        'rows' => $rows,
    ]);
    exit;
}


if ($action === 'export_logframe_csv' || $action === 'export_logframe_word' || $action === 'export_logframe_excel' || $action === 'export_logframe_pdf' || $action === 'export_template' || $action === 'export_section') {
    // Function to build export data
    function build_logframe_export_rows($results, $impactIndicators, $outcomeIndicators, $outputIndicators, $activities, $projectMap, $projectDetails = null) {
        $rows = [];
        
        // Add project header information
        if ($projectDetails) {
            $project_name = $projectDetails['title'] ?? $projectDetails['name'] ?? ('Project ' . $projectDetails['id']);
            $region = $projectDetails['region_name'] ?? '';
            $zone = $projectDetails['zone_name'] ?? '';
            $woreda = $projectDetails['woreda_name'] ?? '';
            $start_date = isset($projectDetails['start_date']) ? date('Y-m-d', strtotime($projectDetails['start_date'])) : '';
            $end_date = isset($projectDetails['end_date']) ? date('Y-m-d', strtotime($projectDetails['end_date'])) : '';
            
            $rows[] = ['PROJECT DETAILS'];
            $rows[] = ['Project Name:', $project_name];
            $rows[] = ['Region:', $region];
            $rows[] = ['Zone:', $zone];
            $rows[] = ['Woreda:', $woreda];
            $rows[] = ['Start Date:', $start_date];
            $rows[] = ['End Date:', $end_date];
            $rows[] = []; // Empty row for spacing
        }
        
        // Main data headers
        $rows[] = [
            'Section','Project','Level/Type','Result/Output/Activity code',
            'Result/Output/Activity name','Indicator code','Indicator name',
            'Beneficiary type','Unit type','Input mode','Total target',
            'Boys <5','Girls <5','Boys 5-17','Girls 5-17',
            'Men 18-59','Women 18-59','Men 60+','Women 60+','PWD',
            'Activity unit','Activity target'
        ];

        foreach ($results as $r) {
            $proj = $projectMap[$r['project_id']] ?? ('Project ' . $r['project_id']);
            $rows[] = [
                'result',
                $proj,
                $r['level'],
                $r['code'] ?? '',
                $r['name'] ?? '',
                '', '', '', '', '', '',
                '', '', '', '', '', '', '', '', '',
                '', ''
            ];
        }

        foreach ($impactIndicators as $oi) {
            $proj = $projectMap[$oi['project_id']] ?? ('Project ' . $oi['project_id']);
            $rows[] = [
                'impact_indicator',
                $proj,
                'impact',
                $oi['result_code'] ?? '',
                $oi['result_name'] ?? '',
                $oi['indicator_code'] ?? '',
                $oi['indicator_name'] ?? '',
                $oi['beneficiary_type'] ?? '',
                $oi['unit_type'] ?? '',
                $oi['input_mode'] ?? '',
                (int)($oi['total_target'] ?? 0),
                (int)($oi['boys_u5'] ?? 0),
                (int)($oi['girls_u5'] ?? 0),
                (int)($oi['boys_5_17'] ?? 0),
                (int)($oi['girls_5_17'] ?? 0),
                (int)($oi['men_18_59'] ?? 0),
                (int)($oi['women_18_59'] ?? 0),
                (int)($oi['men_60p'] ?? 0),
                (int)($oi['women_60p'] ?? 0),
                (int)($oi['pwd_count'] ?? 0),
                '', ''
            ];
        }

        foreach ($outcomeIndicators as $oi) {
            $proj = $projectMap[$oi['project_id']] ?? ('Project ' . $oi['project_id']);
            $rows[] = [
                'outcome_indicator',
                $proj,
                'outcome',
                $oi['result_code'] ?? '',
                $oi['result_name'] ?? '',
                $oi['indicator_code'] ?? '',
                $oi['indicator_name'] ?? '',
                $oi['beneficiary_type'] ?? '',
                $oi['unit_type'] ?? '',
                $oi['input_mode'] ?? '',
                (int)($oi['total_target'] ?? 0),
                (int)($oi['boys_u5'] ?? 0),
                (int)($oi['girls_u5'] ?? 0),
                (int)($oi['boys_5_17'] ?? 0),
                (int)($oi['girls_5_17'] ?? 0),
                (int)($oi['men_18_59'] ?? 0),
                (int)($oi['women_18_59'] ?? 0),
                (int)($oi['men_60p'] ?? 0),
                (int)($oi['women_60p'] ?? 0),
                (int)($oi['pwd_count'] ?? 0),
                '', ''
            ];
        }

        foreach ($outputIndicators as $oi) {
            $proj = $projectMap[$oi['project_id']] ?? ('Project ' . $oi['project_id']);
            $rows[] = [
                'output_indicator',
                $proj,
                'output',
                $oi['result_code'] ?? '',
                $oi['result_name'] ?? '',
                $oi['indicator_code'] ?? '',
                $oi['indicator_name'] ?? '',
                $oi['beneficiary_type'] ?? '',
                $oi['unit_type'] ?? '',
                $oi['input_mode'] ?? '',
                (int)($oi['target_total'] ?? 0),
                (int)($oi['boys_u5'] ?? 0),
                (int)($oi['girls_u5'] ?? 0),
                (int)($oi['boys_5_17'] ?? 0),
                (int)($oi['girls_5_17'] ?? 0),
                (int)($oi['men_18_59'] ?? 0),
                (int)($oi['women_18_59'] ?? 0),
                (int)($oi['men_60p'] ?? 0),
                (int)($oi['women_60p'] ?? 0),
                (int)($oi['pwd_count'] ?? 0),
                '', ''
            ];
        }

        foreach ($activities as $a) {
            $proj = $projectMap[$a['project_id']] ?? ('Project ' . $a['project_id']);
            $rows[] = [
                'activity',
                $proj,
                'activity',
                $a['code'] ?? '',
                $a['name'] ?? '',
                '', '', '',
                '', '', '',
                '', '', '', '', '', '', '', '', '',
                $a['unit'] ?? '',
                (int)($a['target'] ?? 0)
            ];
        }

        return $rows;
    }

    // Get selected project from session or request
    $selected_project_id = $_GET['project_id'] ?? $_SESSION['selected_project_id'] ?? null;
    // Keep project selection in session so other modules (e.g., enter_data.php) can auto-load the same project
    if ($selected_project_id !== null && $selected_project_id !== '') {
        $selected_project_id = (int)$selected_project_id;
        if ($selected_project_id > 0) {
            $_SESSION['selected_project_id'] = $selected_project_id;
        } else {
            unset($_SESSION['selected_project_id']);
        }
    }
    
    // Fetch data for export
    $projects = get_projects();
    if (!is_array($projects)) { $projects = []; }

    $projectMap = [];
    $selected_project_details = null;

    foreach ($projects as $p) {
        $label = $p['title'] ?? ($p['name'] ?? ('Project ' . $p['id']));
        $projectMap[$p['id']] = $label;
        
        if ($p['id'] == $selected_project_id) {
            $selected_project_details = $p;
        }

    // ✅ Hard-refresh selected project details directly from DB (fix: missing location/donor/duration fields in planning.php)
    if ($selected_project_id && is_numeric($selected_project_id)) {
        // If selected project was deleted, reset selection cleanly (prevents "deleted info" resurfacing)
        if (!$selected_project_details) {
            $selected_project_id = 0;
            unset($_SESSION['selected_project_id']);
        } else {
            $selected_project_details = planning_fetch_project_details($pdo, (int)$selected_project_id, $selected_project_details);
        }
    }

    }

    // Build queries with project filter
    $where_clause = $selected_project_id ? " WHERE project_id = ?" : "";
    $params = $selected_project_id ? [$selected_project_id] : [];

    $results_query = "SELECT * FROM results_chain" . $where_clause . " ORDER BY FIELD(level,'impact','outcome','output'), id";
    $stmt = $pdo->prepare($results_query);
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    $impactIndicators_query = "SELECT oi.*, rc.code AS result_code, rc.name AS result_name
         FROM impact_indicators oi
         LEFT JOIN results_chain rc ON rc.id = oi.result_id" . 
         ($selected_project_id ? " WHERE oi.project_id = ?" : "") . 
         " ORDER BY oi.project_id, oi.result_id, oi.id";
    $stmt = $pdo->prepare($impactIndicators_query);
    $stmt->execute($selected_project_id ? [$selected_project_id] : []);
    $impactIndicators = $stmt->fetchAll();

    $outcomeIndicators_query = "SELECT oi.*, rc.code AS result_code, rc.name AS result_name
         FROM outcome_indicators oi
         LEFT JOIN results_chain rc ON rc.id = oi.result_id" . 
         ($selected_project_id ? " WHERE oi.project_id = ?" : "") . 
         " ORDER BY oi.project_id, oi.result_id, oi.id";
    $stmt = $pdo->prepare($outcomeIndicators_query);
    $stmt->execute($selected_project_id ? [$selected_project_id] : []);
    $outcomeIndicators = $stmt->fetchAll();

    $outputIndicators_query = "SELECT oi.*, rc.code AS result_code, rc.name AS result_name
         FROM output_indicators oi
         LEFT JOIN results_chain rc ON rc.id = oi.result_id" . 
         ($selected_project_id ? " WHERE oi.project_id = ?" : "") . 
         " ORDER BY oi.project_id, oi.result_id, oi.id";
    $stmt = $pdo->prepare($outputIndicators_query);
    $stmt->execute($selected_project_id ? [$selected_project_id] : []);
    $outputIndicators = $stmt->fetchAll();

    $activities_query = "SELECT a.*, r.code AS output_code, r.name AS output_name, r.project_id AS output_project_id
         FROM activities a
         LEFT JOIN results_chain r ON a.output_id = r.id" . 
         ($selected_project_id ? " WHERE a.project_id = ?" : "") . 
         " ORDER BY a.project_id, a.id";
    $stmt = $pdo->prepare($activities_query);
    $stmt->execute($selected_project_id ? [$selected_project_id] : []);
    $activities = $stmt->fetchAll();

    $rows = build_logframe_export_rows($results, $impactIndicators, $outcomeIndicators, $outputIndicators, $activities, $projectMap, $selected_project_details);

    if ($action === 'export_logframe_csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="project_planning_' . ($selected_project_id ?: 'all') . '.csv"');
        $output = fopen('php://output', 'w');
        
        // Add app header comment
        fputcsv($output, ['Health Reporting System - Project Planning Suite']);
        fputcsv($output, ['Export Date: ' . date('Y-m-d H:i:s')]);
        fputcsv($output, []);
        
        // Add project details
        if ($selected_project_details) {
            fputcsv($output, ['PROJECT DETAILS']);
            fputcsv($output, ['Project Name:', $selected_project_details['title'] ?? $selected_project_details['name'] ?? '']);
            if (!empty($selected_project_details['code'])) {
                fputcsv($output, ['Project Code:', $selected_project_details['code']]);
            }
            fputcsv($output, ['Location:', 
                ($selected_project_details['region_name'] ?? '') . ' / ' . 
                ($selected_project_details['zone_name'] ?? '') . ' / ' . 
                ($selected_project_details['woreda_name'] ?? '')]);
            
            if (isset($selected_project_details['start_date']) && isset($selected_project_details['end_date']) && 
                $selected_project_details['start_date'] && $selected_project_details['end_date']) {
                $start = new DateTime($selected_project_details['start_date']);
                $end = new DateTime($selected_project_details['end_date']);
                $interval = $start->diff($end);
                $duration = $interval->m + ($interval->y * 12);
                fputcsv($output, ['Duration:', $duration . ' months (' . $start->format('M Y') . ' - ' . $end->format('M Y') . ')']);
            }
            
            if (!empty($selected_project_details['donor'])) {
                fputcsv($output, ['Donor:', $selected_project_details['donor']]);
            }
            if (!empty($selected_project_details['total_fund_usd'])) {
                fputcsv($output, ['Total Fund (USD):', '$' . number_format($selected_project_details['total_fund_usd'], 2)]);
            }
            fputcsv($output, []);
        }
        
        // Main data
        foreach ($rows as $row) {
            if (is_array($row) && !empty($row) && $row[0] !== 'PROJECT DETAILS' && $row[0] !== 'Project Name:' && !empty($row[0])) {
                fputcsv($output, $row);
            }
        }
        fclose($output);
        exit;
    } elseif ($action === 'export_logframe_excel') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="project_planning.xls"');
        
        echo "<table border='1'>";
        foreach ($rows as $row) {
            echo "<tr>";
            foreach ($row as $cell) {
                echo "<td>" . htmlspecialchars((string)$cell) . "</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
        exit;
    } elseif ($action === 'export_logframe_word') {
        header("Content-Type: application/vnd.ms-word");
        header('Content-Disposition: attachment; filename="project_planning_' . ($selected_project_id ?: 'all') . '.doc"');
        
        echo "<html>";
        echo "<head>";
        echo "<meta charset=\"UTF-8\">";
        echo "<title>Project Planning Export - Health Reporting System</title>";
        echo "<style>";
        echo "body { font-family: Arial, sans-serif; margin: 20px; }";
        echo ".header { background: #4361ee; color: white; padding: 20px; margin-bottom: 20px; border-radius: 8px; }";
        echo ".header h1 { margin: 0 0 10px 0; }";
        echo ".project-info { background: #f8f9fa; padding: 15px; margin-bottom: 20px; border-left: 4px solid #4361ee; }";
        echo "table { border-collapse: collapse; width: 100%; margin: 20px 0; }";
        echo "th, td { border: 1px solid #000; padding: 8px; text-align: left; }";
        echo "th { background-color: #4361ee; color: white; font-weight: bold; }";
        echo ".section { margin: 30px 0; page-break-inside: avoid; }";
        echo ".section h2 { color: #4361ee; border-bottom: 2px solid #4361ee; padding-bottom: 10px; }";
        echo "
/* === Beneficiary Distribution: responsive scroll container === */
.table-scroll {
    width: 100%;
    max-width: 100%;
    overflow-x: auto;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    border: 1px solid rgba(0,0,0,0.08);
    border-radius: 14px;
}
#beneficiaryTable {
    min-width: 1200px; /* ensures horizontal scroll instead of clipping */
}
#beneficiaryTable th, #beneficiaryTable td {
    white-space: nowrap;
    vertical-align: middle;
}
#beneficiaryTable thead th {
    position: sticky;
    top: 0;
    background: #f8f9fa;
    z-index: 2;
}
#beneficiaryTable tfoot td {
    position: sticky;
    bottom: 0;
    background: #ffffff;
    z-index: 1;
}
.beneficiary-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
    margin: 10px 0 12px;
}
.scroll-hint {
    font-size: 13px;
    opacity: 0.8;
    margin-bottom: 8px;
}

</style>";
        echo "</head>";
        echo "<body>";
        
        // App System Header (not global)
        echo "<div class=\"header\">";
        echo "<h1>🚀 Health Reporting System - Project Planning Suite</h1>";
        echo "<p>Export Date: " . date('Y-m-d H:i:s') . "</p>";
        echo "</div>";
        
        // Full Project Document Header
        if ($selected_project_details) {
            echo "<div class=\"project-info\">";
            echo "<h2>📋 Project Details</h2>";
            echo "<table style=\"border: none;\">";
            echo "<tr><td><strong>Project Name:</strong></td><td>" . htmlspecialchars($selected_project_details['title'] ?? $selected_project_details['name'] ?? '') . "</td></tr>";
            if (!empty($selected_project_details['code'])) {
                echo "<tr><td><strong>Project Code:</strong></td><td>" . htmlspecialchars($selected_project_details['code']) . "</td></tr>";
            }
            echo "<tr><td><strong>Location:</strong></td><td>" . 
                 htmlspecialchars($selected_project_details['region_name'] ?? '') . " / " . 
                 htmlspecialchars($selected_project_details['zone_name'] ?? '') . " / " . 
                 htmlspecialchars($selected_project_details['woreda_name'] ?? '') . "</td></tr>";
            
            if (isset($selected_project_details['start_date']) && isset($selected_project_details['end_date']) && 
                $selected_project_details['start_date'] && $selected_project_details['end_date']) {
                $start = new DateTime($selected_project_details['start_date']);
                $end = new DateTime($selected_project_details['end_date']);
                $interval = $start->diff($end);
                $duration = $interval->m + ($interval->y * 12);
                echo "<tr><td><strong>Duration:</strong></td><td>" . $duration . " months (" . 
                     $start->format('M Y') . " - " . $end->format('M Y') . ")</td></tr>";
            }
            
            if (!empty($selected_project_details['donor'])) {
                echo "<tr><td><strong>Donor:</strong></td><td>" . htmlspecialchars($selected_project_details['donor']) . "</td></tr>";
            }
            if (!empty($selected_project_details['total_fund_usd'])) {
                echo "<tr><td><strong>Total Fund (USD):</strong></td><td>$" . number_format($selected_project_details['total_fund_usd'], 2) . "</td></tr>";
            }
            if (!empty($selected_project_details['owner_name'])) {
                echo "<tr><td><strong>Project Owner:</strong></td><td>" . htmlspecialchars($selected_project_details['owner_name']) . "</td></tr>";
            }
            if (!empty($selected_project_details['ip_name'])) {
                echo "<tr><td><strong>Implementing Partner:</strong></td><td>" . htmlspecialchars($selected_project_details['ip_name']) . "</td></tr>";
            }
            echo "</table>";
            echo "</div>";
        }
        
        // All Sections Data
        echo "<div class=\"section\">";
        echo "<h2>📊 Strategic Results</h2>";
        echo "<table>";
        echo "<tr><th>Level</th><th>Code</th><th>Description</th></tr>";
        foreach ($results as $r) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars(ucfirst($r['level'])) . "</td>";
            echo "<td>" . htmlspecialchars($r['code'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($r['name'] ?? '') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        echo "</div>";
        
        echo "<div class=\"section\">";
        echo "<h2>🎯 Impact Indicators</h2>";
        echo "<table>";
        echo "<tr><th>Result</th><th>Code</th><th>Indicator</th><th>Beneficiary</th><th>Unit</th><th>Total Target</th></tr>";
        foreach ($impactIndicators as $ind) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($ind['result_name'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($ind['indicator_code'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($ind['indicator_name'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($ind['beneficiary_type'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($ind['unit_type'] ?? '') . "</td>";
            echo "<td>" . number_format($ind['total_target'] ?? 0) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        echo "</div>";
        
        echo "<div class=\"section\">";
        echo "<h2>📈 Outcome Indicators</h2>";
        echo "<table>";
        echo "<tr><th>Result</th><th>Code</th><th>Indicator</th><th>Beneficiary</th><th>Unit</th><th>Total Target</th></tr>";
        foreach ($outcomeIndicators as $ind) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($ind['result_name'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($ind['indicator_code'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($ind['indicator_name'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($ind['beneficiary_type'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($ind['unit_type'] ?? '') . "</td>";
            echo "<td>" . number_format($ind['total_target'] ?? 0) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        echo "</div>";
        
        echo "<div class=\"section\">";
        echo "<h2>📊 Output Indicators</h2>";
        echo "<table>";
        echo "<tr><th>Result</th><th>Code</th><th>Indicator</th><th>Beneficiary</th><th>Unit</th><th>Total Target</th></tr>";
        foreach ($outputIndicators as $ind) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($ind['result_name'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($ind['indicator_code'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($ind['indicator_name'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($ind['beneficiary_type'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($ind['unit_type'] ?? '') . "</td>";
            echo "<td>" . number_format($ind['target_total'] ?? 0) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        echo "</div>";
        
        echo "<div class=\"section\">";
        echo "<h2>🛠️ Activities</h2>";
        echo "<table>";
        echo "<tr><th>Output</th><th>Code</th><th>Activity</th><th>Unit</th><th>Target</th></tr>";
        foreach ($activities as $act) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($act['output_name'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($act['code'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($act['name'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($act['unit'] ?? '') . "</td>";
            echo "<td>" . number_format($act['target'] ?? 0) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        echo "</div>";
        
        // Main logframe table
        echo "<div class=\"section\">";
        echo "<h2>📋 Complete Logframe Matrix</h2>";
        echo "<table>";
        $is_header = true;
        foreach ($rows as $row) {
            if (is_array($row) && !empty($row) && $row[0] !== 'PROJECT DETAILS' && $row[0] !== 'Project Name:' && !empty($row[0])) {
                echo "<tr>";
                foreach ($row as $cell) {
                    if ($is_header) {
                        echo "<th>" . htmlspecialchars((string)$cell) . "</th>";
                    } else {
                        echo "<td>" . htmlspecialchars((string)$cell) . "</td>";
                    }
                }
                echo "</tr>";
                $is_header = false;
            }
        }
        echo "</table>";
        echo "</div>";
        
        echo "</body></html>";
        exit;
    } elseif ($action === 'export_template') {
        // Export template for import
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="planning_template.csv"');
        $output = fopen('php://output', 'w');
        
        // Template headers
        fputcsv($output, ['Section', 'Level/Type', 'Code', 'Name', 'Result Code', 'Beneficiary Type', 'Unit Type', 'Input Mode', 'Total Target', 'Boys <5', 'Girls <5', 'Boys 5-17', 'Girls 5-17', 'Men 18-59', 'Women 18-59', 'Men 60+', 'Women 60+', 'PWD', 'Activity Unit', 'Activity Target']);
        fputcsv($output, ['result', 'impact', 'IM1', 'Example Impact Result', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '']);
        fputcsv($output, ['result', 'outcome', 'OC1', 'Example Outcome Result', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '']);
        fputcsv($output, ['result', 'output', 'OP1', 'Example Output Result', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '']);
        fputcsv($output, ['impact_indicator', 'impact', '', '', 'IM1', 'host', 'persons', 'sadd', '1000', '50', '50', '100', '100', '200', '200', '50', '50', '50', '', '']);
        fputcsv($output, ['outcome_indicator', 'outcome', '', '', 'OC1', 'host', 'persons', 'sadd', '800', '40', '40', '80', '80', '160', '160', '40', '40', '40', '', '']);
        fputcsv($output, ['output_indicator', 'output', '', '', 'OP1', 'host', 'persons', 'sadd', '600', '30', '30', '60', '60', '120', '120', '30', '30', '30', '', '']);
        fputcsv($output, ['activity', 'activity', 'ACT1', 'Example Activity', 'OP1', '', '', '', '', '', '', '', '', '', '', '', '', '', 'HHs', '500']);
        
        fclose($output);
        exit;
    } elseif ($action === 'export_section') {
        // Export specific section
        $section = $_GET['section'] ?? '';
        $project_id = (int)($_GET['project_id'] ?? 0);
        
        if (!$project_id) {
            die('Project ID required');
        }
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="planning_' . $section . '_' . $project_id . '.csv"');
        $output = fopen('php://output', 'w');
        
        // Fetch section-specific data
        if ($section === 'results') {
            fputcsv($output, ['Level', 'Code', 'Description']);
            $stmt = $pdo->prepare("SELECT level, code, name FROM results_chain WHERE project_id = ? ORDER BY FIELD(level,'impact','outcome','output'), id");
            $stmt->execute([$project_id]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, [$row['level'], $row['code'], $row['name']]);
            }
        } elseif ($section === 'indicators') {
            fputcsv($output, ['Type', 'Result Code', 'Indicator Code', 'Indicator Name', 'Beneficiary', 'Unit', 'Total Target']);
            // Impact
            $stmt = $pdo->prepare("SELECT oi.*, rc.code AS result_code FROM impact_indicators oi LEFT JOIN results_chain rc ON oi.result_id = rc.id WHERE oi.project_id = ?");
            $stmt->execute([$project_id]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, ['Impact', $row['result_code'], $row['indicator_code'], $row['indicator_name'], $row['beneficiary_type'], $row['unit_type'], $row['total_target']]);
            }
            // Outcome
            $stmt = $pdo->prepare("SELECT oi.*, rc.code AS result_code FROM outcome_indicators oi LEFT JOIN results_chain rc ON oi.result_id = rc.id WHERE oi.project_id = ?");
            $stmt->execute([$project_id]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, ['Outcome', $row['result_code'], $row['indicator_code'], $row['indicator_name'], $row['beneficiary_type'], $row['unit_type'], $row['total_target']]);
            }
            // Output
            $stmt = $pdo->prepare("SELECT oi.*, rc.code AS result_code FROM output_indicators oi LEFT JOIN results_chain rc ON oi.result_id = rc.id WHERE oi.project_id = ?");
            $stmt->execute([$project_id]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, ['Output', $row['result_code'], $row['indicator_code'], $row['indicator_name'], $row['beneficiary_type'], $row['unit_type'], $row['target_total']]);
            }
        } elseif ($section === 'activities') {
            fputcsv($output, ['Output Code', 'Activity Code', 'Activity Name', 'Unit', 'Target']);
            $stmt = $pdo->prepare("SELECT a.*, r.code AS output_code FROM activities a LEFT JOIN results_chain r ON a.output_id = r.id WHERE a.project_id = ?");
            $stmt->execute([$project_id]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, [$row['output_code'], $row['code'], $row['name'], $row['unit'], $row['target']]);
            }
        } elseif ($section === 'beneficiaries') {
            fputcsv($output, ['Region', 'Zone', 'Woreda', 'Beneficiary Type', 'Women', 'Girls', 'Men', 'Boys', 'PWD', 'Total', 'Share %']);
            $stmt = $pdo->prepare("SELECT pb.*, r.name AS region_name, z.name AS zone_name, w.name AS woreda_name FROM project_beneficiaries pb LEFT JOIN regions r ON pb.region_id = r.id LEFT JOIN zones z ON pb.zone_id = z.id LEFT JOIN woredas w ON pb.woreda_id = w.id WHERE pb.project_id = ?");
            $stmt->execute([$project_id]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, [$row['region_name'], $row['zone_name'], $row['woreda_name'], $row['beneficiary_type'], $row['women'], $row['girls'], $row['men'], $row['boys'], $row['pwd_count'], $row['total'], $row['share_percentage']]);
            }
        }
        
        fclose($output);
        exit;
    }
}

// ------------------------------------------------------
// Get selected project from session / request / postback
// ------------------------------------------------------
// Many planning forms submit via POST without a querystring; keep the same project selected.
$selected_project_id = $_GET['project_id'] ?? $_POST['project_id'] ?? $_SESSION['selected_project_id'] ?? null;
if ($selected_project_id) {
    $_SESSION['selected_project_id'] = $selected_project_id;
}

// ------------------------------------------------------
// Enhanced CSS with modern design and animations
// ------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Planning Suite</title>
    <style>
:root {
    --primary-color: #4361ee;
    --secondary-color: #3f37c9;
    --success-color: #4cc9f0;
    --warning-color: #f72585;
    --info-color: #4895ef;
    --light-color: #f8f9fa;
    --dark-color: #212529;
    --gradient-primary: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    --gradient-secondary: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    --gradient-success: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    --shadow: 0 10px 30px rgba(0,0,0,0.1);
    --shadow-hover: 0 15px 40px rgba(0,0,0,0.15);
    --border-radius: 12px;
    --transition: all 0.3s ease;
}

/* Welcome Animation */
.welcome-container {
    position: relative;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 40px 20px;
    border-radius: var(--border-radius);
    margin: 20px 0;
    overflow: hidden;
    text-align: center;
}

.welcome-container::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: url('data:image/svg+xml;utf8,<svg viewBox="0 0 1200 120" xmlns="http://www.w3.org/2000/svg"><path d="M0 0v46.29c47.79 22.2 103.59 32.17 158 28 70.36-5.37 136.33-33.31 206.8-37.5 73.84-4.36 147.54 16.88 218.2 35.26 69.27 18 138.3 24.88 209.4 13.08 36.15-6 69.85-17.84 104.45-29.34C989.49 25 1113-14.29 1200 52.47V0z" fill="%23ffffff" opacity=".15"/></svg>');
    background-size: cover;
    animation: wave 15s linear infinite;
}

.welcome-title {
    font-size: 3rem;
    font-weight: 800;
    margin-bottom: 20px;
    background: linear-gradient(45deg, #FFD700, #FFA500, #FF8C00);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    animation: glow 2s ease-in-out infinite alternate;
}

.welcome-subtitle {
    font-size: 1.4rem;
    margin-bottom: 30px;
    opacity: 0.9;
}

@keyframes glow {
    from {
        text-shadow: 0 0 20px rgba(255, 215, 0, 0.6);
    }
    to {
        text-shadow: 0 0 30px rgba(255, 165, 0, 0.8), 0 0 40px rgba(255, 140, 0, 0.6);
    }
}

@keyframes wave {
    0% { transform: translateX(0); }
    100% { transform: translateX(-50%); }
}

/* Floating Elements */
.floating-element {
    animation: float 6s ease-in-out infinite;
}

@keyframes float {
    0%, 100% { transform: translateY(0px); }
    50% { transform: translateY(-20px); }
}

/* Progress Bar */
.progress-container {
    width: 100%;
    height: 8px;
    background: #e9ecef;
    border-radius: 4px;
    margin: 20px 0;
    overflow: hidden;
}

.progress-bar {
    height: 100%;
    background: var(--gradient-primary);
    border-radius: 4px;
    transition: width 0.6s ease;
    animation: progressAnimation 2s ease-in-out infinite;
    background-size: 200% 100%;
}

@keyframes progressAnimation {
    0% {
        background-position: 0% 50%;
    }
    50% {
        background-position: 100% 50%;
    }
    100% {
        background-position: 0% 50%;
    }
}

/* Card Styling */
.card {
    background: #fff;
    border-radius: var(--border-radius);
    padding: 25px;
    margin: 20px 0;
    box-shadow: var(--shadow);
    border: none;
    overflow: hidden;
    transition: var(--transition);
    animation: slideUp 0.6s ease-out;
}

.card:hover {
    box-shadow: var(--shadow-hover);
    transform: translateY(-5px);
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Table Styling */
.table {
    width: 100%;
    border-collapse: collapse;
    margin: 20px 0;
    font-weight: 600;
    background: white;
    border-radius: var(--border-radius);
    overflow: hidden;
    box-shadow: 0 5px 15px rgba(0,0,0,0.08);
}

.table th {
    background: var(--gradient-primary);
    color: white;
    padding: 15px;
    text-align: left;
    font-weight: 700;
    border: none;
    position: relative;
}

.table th::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    width: 100%;
    height: 2px;
    background: var(--success-color);
}

.table td {
    padding: 12px 15px;
    border-bottom: 1px solid #e9ecef;
    color: #2c3e50;
    font-weight: 600;
    background: #fff;
    transition: var(--transition);
}

.table tr:nth-child(even) td {
    background: #f8f9fa;
}

.table tr:hover td {
    background-color: #e3f2fd;
    transform: translateX(5px);
}

/* Form Styling */
.form-row {
    display: flex;
    gap: 20px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.form-row label {
    flex: 1;
    min-width: 250px;
    font-weight: 600;
    color: var(--dark-color);
}

.form-row input, .form-row select, .form-row textarea {
    width: 100%;
    padding: 12px 15px;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    transition: var(--transition);
    font-size: 14px;
    font-weight: 600;
}

.form-row input:focus, .form-row select:focus, .form-row textarea:focus {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
    outline: none;
}

/* Button Styling */
.btn {
    padding: 12px 25px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    transition: var(--transition);
    position: relative;
    overflow: hidden;
}

.btn::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
    transition: left 0.5s;
}

.btn:hover::before {
    left: 100%;
}

.btn-sm {
    padding: 10px 20px;
    font-size: 14px;
}

.btn-primary {
    background: var(--gradient-primary);
    color: white;
}

.btn-primary:hover {
    background: var(--secondary-color);
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(67, 97, 238, 0.3);
}

.btn-success {
    background: var(--gradient-success);
    color: white;
}

.btn-success:hover {
    background: #00c6ff;
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(76, 201, 240, 0.3);
}

.btn-warning {
    background: var(--gradient-secondary);
    color: white;
}

.btn-warning:hover {
    background: #f5365c;
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(245, 37, 133, 0.3);
}

.btn-info {
    background: linear-gradient(135deg, #17ead9 0%, #6078ea 100%);
    color: white;
}

.btn-info:hover {
    background: #6078ea;
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(96, 120, 234, 0.3);
}

.btn-secondary {
    background: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background: #545b62;
    transform: translateY(-2px);
}

/* Section Toggle */
.section-toggle {
    background: var(--gradient-primary);
    color: white;
    padding: 20px;
    border-radius: var(--border-radius) var(--border-radius) 0 0;
    cursor: pointer;
    transition: var(--transition);
    display: flex;
    align-items: center;
    gap: 15px;
    font-weight: 600;
    font-size: 18px;
}

.section-toggle:hover {
    background: var(--secondary-color);
    padding-left: 25px;
}

.section-content {
    display: block;
    padding: 30px;
    background: white;
    border-radius: 0 0 var(--border-radius) var(--border-radius);
    animation: fadeIn 0.5s ease-out;
}

@keyframes fadeIn {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

/* Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin: 20px 0;
}

.stat-card {
    background: white;
    padding: 25px;
    border-radius: var(--border-radius);
    text-align: center;
    box-shadow: var(--shadow);
    transition: var(--transition);
    border-left: 5px solid var(--primary-color);
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: var(--shadow-hover);
}

.stat-number {
    font-size: 2.5em;
    font-weight: bold;
    background: var(--gradient-primary);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    margin-bottom: 10px;
}

.stat-label {
    color: #6c757d;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.9em;
    letter-spacing: 1px;
}

/* Beneficiary Grid */
.beneficiary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin: 20px 0;
}

.beneficiary-card {
    background: white;
    padding: 20px;
    border-radius: 10px;
    text-align: center;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    border-left: 4px solid #3498db;
    transition: var(--transition);
}

.beneficiary-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.2);
}

.beneficiary-card h4 {
    margin: 0 0 10px 0;
    color: #2c3e50;
    font-weight: 700;
}

.beneficiary-card .number {
    font-size: 2rem;
    font-weight: 700;
    color: #e74c3c;
}

/* Auto-calculation highlight */
.auto-calc {
    background: #fff3cd !important;
    font-weight: 700 !important;
    color: #856404 !important;
}

.total-row {
    background: #d4edda !important;
    font-weight: 700 !important;
    color: #155724 !important;
}

/* Project Selector */
.project-selector {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    padding: 20px;
    border-radius: 10px;
    margin: 20px 0;
}

.project-selector select {
    width: 100%;
    padding: 12px;
    border: none;
    border-radius: 5px;
    font-weight: 600;
    font-size: 1.1rem;
    background: white;
    color: #2c3e50;
}

/* Export Options */
.export-options {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
    margin: 20px 0;
}

/* Print Styles with App Header */
@media print {
    @page {
        margin: 1cm;
    }
    
    /* Hide navigation and non-essential elements */
    header, nav, .no-print, .section-toggle, .export-options, .social-share,
    button, .btn, .project-selector, .guidance-message {
        display: none !important;
    }
    
    /* Show all sections in print */
    .section-content {
        display: block !important;
    }
    
    /* App System Header for Print */
    body::before {
        content: "🚀 Health Reporting System - Project Planning Suite";
        display: block;
        font-size: 18px;
        font-weight: bold;
        color: #4361ee;
        padding: 15px;
        border-bottom: 3px solid #4361ee;
        margin-bottom: 20px;
        page-break-after: avoid;
    }
    
    /* Project Details Header */
    .card:first-of-type::before {
        content: "📋 Project Planning Document";
        display: block;
        font-size: 16px;
        font-weight: bold;
        color: #2c3e50;
        padding: 10px;
        background: #f8f9fa;
        margin-bottom: 15px;
        page-break-after: avoid;
    }
    
    /* Ensure tables print well */
    .table {
        page-break-inside: auto;
    }
    
    .table tr {
        page-break-inside: avoid;
        page-break-after: auto;
    }
    
    .table thead {
        display: table-header-group;
    }
    
    .table tfoot {
        display: table-footer-group;
    }
    
    /* Section breaks */
    .card {
        page-break-inside: avoid;
        margin-bottom: 20px;
    }
    
    /* Full content visibility */
    .section-content {
        max-height: none !important;
        overflow: visible !important;
    }
}

/* Social Share */
.social-share {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
    margin: 20px 0;
}

.share-btn {
    padding: 12px 25px;
    border-radius: 8px;
    color: white;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    transition: var(--transition);
}

.share-email { background: #ea4335; }
.share-whatsapp { background: #25d366; }
.share-telegram { background: #0088cc; }
.share-link { background: var(--primary-color); }

.share-btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 25px rgba(0,0,0,0.2);
    color: white;
}

/* Floating Action */
.floating-action {
    position: fixed;
    bottom: 30px;
    right: 30px;
    z-index: 1000;
}

.floating-btn {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: var(--gradient-primary);
    color: white;
    border: none;
    font-size: 24px;
    cursor: pointer;
    box-shadow: 0 10px 30px rgba(67, 97, 238, 0.3);
    transition: var(--transition);
    display: flex;
    align-items: center;
    justify-content: center;
}

.floating-btn:hover {
    transform: scale(1.1) rotate(15deg);
    box-shadow: 0 15px 40px rgba(67, 97, 238, 0.4);
}

/* Loading Animation */
.loading {
    display: inline-block;
    width: 20px;
    height: 20px;
    border: 3px solid #f3f3f3;
    border-top: 3px solid var(--primary-color);
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Badge Styling */
.badge {
    padding: 10px 20px;
    border-radius: 50px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    animation: pulse 2s infinite;
}

.badge-success {
    background: var(--gradient-success);
    color: white;
}

.badge-danger {
    background: var(--gradient-secondary);
    color: white;
}

@keyframes pulse {
    0% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.05);
    }
    100% {
        transform: scale(1);
    }
}

/* Guidance Messages */
.guidance-message {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 20px;
    border-radius: var(--border-radius);
    margin: 15px 0;
    border-left: 5px solid #FFD700;
}

.guidance-message h4 {
    margin: 0 0 10px 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.guidance-message p {
    margin: 0;
    opacity: 0.9;
}

/* Success Animation */
.success-animation {
    animation: successPulse 2s ease-in-out;
}

@keyframes successPulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.05); }
    100% { transform: scale(1); }
}

/* Bold Black Text for All Content */
body, h1, h2, h3, h4, h5, h6, p, span, div, td, th, label, input, select, button, textarea {
    color: #000000 !important;
    font-weight: 700 !important;
}

.card h1, .card h2, .card h3, .card h4, .card h5, .card h6 {
    color: #000000 !important;
    font-weight: 800 !important;
}

/* Responsive Design */
@media (max-width: 768px) {
    .form-row {
        flex-direction: column;
    }
    
    .form-row label {
        min-width: 100%;
    }
    
    .stats-grid, .beneficiary-grid {
        grid-template-columns: 1fr;
    }
    
    .export-options, .social-share {
        flex-direction: column;
    }
    
    .table {
        font-size: 0.9rem;
    }
    
    .section-toggle {
        padding: 15px;
        font-size: 16px;
    }
    
    .welcome-title {
        font-size: 2rem;
    }
    
    .welcome-subtitle {
        font-size: 1.1rem;
    }
}
    
/* === Beneficiary Distribution: Wide + Scrollable + Stable layout === */
.table-scroll {
    width: 100%;
    max-width: 100%;
    overflow-x: scroll; /* force scrollbar */
    overflow-y: auto;
    scrollbar-gutter: stable both-edges;
    -webkit-overflow-scrolling: touch;
    border: 1px solid rgba(0,0,0,0.08);
    border-radius: 14px;
    padding-bottom: 8px;
}
#beneficiaryTable {
    width: max-content;
    min-width: 1800px;
}
#beneficiaryTable th, #beneficiaryTable td {
    white-space: nowrap;
    vertical-align: middle;
}
#beneficiaryTable select, #beneficiaryTable input[type="text"], #beneficiaryTable input[type="number"] {
    min-width: 140px;
}
#beneficiaryTable input[type="number"] { min-width: 95px; }
#beneficiaryTable thead th {
    position: sticky;
    top: 0;
    background: #ffffff;
    z-index: 2;
}
#beneficiaryTable tfoot td {
    position: sticky;
    bottom: 0;
    background: #ffffff;
    z-index: 1;
}
.beneficiary-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
    margin: 10px 0 12px;
}
.beneficiary-pagination {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
    justify-content: space-between;
    margin: 10px 0 0;
    opacity: 0.95;
}
.beneficiary-pagination .left, .beneficiary-pagination .right {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
}
.scroll-hint { font-size: 13px; opacity: 0.8; margin-bottom: 8px; }
.invalid-field { outline: 2px solid rgba(247,37,133,0.55); border-radius: 6px; }

</style>
</head>
<body>
<?php
// ------------------------------------------------------
// Database Schema Evolution
// ------------------------------------------------------
if (!function_exists('table_has_column')) {
    function table_has_column(PDO $pdo, string $table, string $column): bool {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch();
    }
}

if (!function_exists('ensure_column')) {
    function ensure_column(PDO $pdo, string $table, string $column, string $definition): void {
        if (!table_has_column($pdo, $table, $column)) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    }
}

// Ensure all necessary tables exist
$tables = [
    "CREATE TABLE IF NOT EXISTS results_chain (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        level ENUM('impact','outcome','output') NOT NULL,
        code VARCHAR(50),
        name TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS impact_indicators (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        result_id INT NOT NULL,
        beneficiary_type ENUM('host','returnee','idp','refugee','pwd','mixed','other') NOT NULL DEFAULT 'host',
        indicator_code VARCHAR(50),
        indicator_name TEXT NOT NULL,
        unit_type VARCHAR(50) DEFAULT 'persons',
        input_mode ENUM('sadd','count','percent_pair','percent_direct') NOT NULL DEFAULT 'sadd',
        total_target DECIMAL(15,2) DEFAULT 0,
            target_total DECIMAL(15,2) DEFAULT 0,
        boys_u5 INT DEFAULT 0,
        girls_u5 INT DEFAULT 0,
        boys_5_17 INT DEFAULT 0,
        girls_5_17 INT DEFAULT 0,
        men_18_59 INT DEFAULT 0,
        women_18_59 INT DEFAULT 0,
        men_60p INT DEFAULT 0,
        women_60p INT DEFAULT 0,
        pwd_count INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS outcome_indicators (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        result_id INT NOT NULL,
        beneficiary_type ENUM('host','returnee','idp','refugee','pwd','mixed','other') NOT NULL DEFAULT 'host',
        indicator_code VARCHAR(50),
        indicator_name TEXT NOT NULL,
        unit_type VARCHAR(50) DEFAULT 'persons',
        input_mode ENUM('sadd','count','percent_pair','percent_direct') NOT NULL DEFAULT 'sadd',
        total_target DECIMAL(15,2) DEFAULT 0,
            target_total DECIMAL(15,2) DEFAULT 0,
        boys_u5 INT DEFAULT 0,
        girls_u5 INT DEFAULT 0,
        boys_5_17 INT DEFAULT 0,
        girls_5_17 INT DEFAULT 0,
        men_18_59 INT DEFAULT 0,
        women_18_59 INT DEFAULT 0,
        men_60p INT DEFAULT 0,
        women_60p INT DEFAULT 0,
        pwd_count INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS output_indicators (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        result_id INT NOT NULL,
        beneficiary_type ENUM('host','returnee','idp','refugee','pwd','mixed','other') NOT NULL DEFAULT 'host',
        indicator_code VARCHAR(50),
        indicator_name TEXT NOT NULL,
        unit_type VARCHAR(50) DEFAULT 'persons',
        input_mode ENUM('sadd','count','percent_pair','percent_direct') NOT NULL DEFAULT 'sadd',
        target_total INT DEFAULT 0,
        boys_u5 INT DEFAULT 0,
        girls_u5 INT DEFAULT 0,
        boys_5_17 INT DEFAULT 0,
        girls_5_17 INT DEFAULT 0,
        men_18_59 INT DEFAULT 0,
        women_18_59 INT DEFAULT 0,
        men_60p INT DEFAULT 0,
        women_60p INT DEFAULT 0,
        pwd_count INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS activities (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        output_id INT NULL,
        code VARCHAR(50),
        name TEXT NOT NULL,
        unit VARCHAR(50),
        target INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS project_beneficiaries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        region_id INT NULL,
        zone_id INT NULL,
        woreda_id INT NULL,
        beneficiary_type ENUM('host_community', 'refugee', 'returnee', 'idp', 'pwd', 'other') NOT NULL,
        women INT DEFAULT 0,
        girls INT DEFAULT 0,
        men INT DEFAULT 0,
        boys INT DEFAULT 0,
        pwd_count INT DEFAULT 0,
        total INT DEFAULT 0,
        share_percentage DECIMAL(5,2) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
];

foreach ($tables as $tableSql) {
    try {
        $pdo->exec($tableSql);
    } catch (Exception $e) {
        // Table might already exist with different structure
        error_log("Table creation warning: " . $e->getMessage());
    }
}

// Ensure columns for all indicator tables
$indicatorTables = ['impact_indicators', 'outcome_indicators', 'output_indicators'];
foreach ($indicatorTables as $table) {
    $columns = [
        'beneficiary_type' => "ENUM('host','returnee','idp','refugee','pwd','mixed','other') NOT NULL DEFAULT 'host'",
        'unit_type' => "VARCHAR(50) DEFAULT 'persons'",
        'input_mode' => "ENUM('sadd','count','percent_pair','percent_direct') NOT NULL DEFAULT 'sadd'",
        'total_target' => "INT DEFAULT 0",
        'boys_u5' => "INT DEFAULT 0",
        'girls_u5' => "INT DEFAULT 0",
        'boys_5_17' => "INT DEFAULT 0",
        'girls_5_17' => "INT DEFAULT 0",
        'men_18_59' => "INT DEFAULT 0",
        'women_18_59' => "INT DEFAULT 0",
        'men_60p' => "INT DEFAULT 0",
        'women_60p' => "INT DEFAULT 0",
        'pwd_count' => "INT DEFAULT 0"
    ];
    
    foreach ($columns as $column => $definition) {
        ensure_column($pdo, $table, $column, $definition);
    }
}

// ------------------------------------------------------
// Lookups and Data Fetching
// ------------------------------------------------------
$projects = get_projects();
    if (!is_array($projects)) { $projects = []; }

$projectMap = [];
$selected_project_details = null;

foreach ($projects as $p) {
    $label = $p['title'] ?? ($p['name'] ?? ('Project ' . $p['id']));
    $projectMap[$p['id']] = $label;
    
    if ($p['id'] == $selected_project_id) {
        $selected_project_details = $p;
    }

    // ✅ Hard-refresh selected project details directly from DB (fix: missing location/donor/duration fields in planning.php)
    if ($selected_project_id && is_numeric($selected_project_id)) {
        // If selected project was deleted, reset selection cleanly (prevents "deleted info" resurfacing)
        if (!$selected_project_details) {
            $selected_project_id = 0;
            unset($_SESSION['selected_project_id']);
        } else {
            $selected_project_details = planning_fetch_project_details($pdo, (int)$selected_project_id, $selected_project_details);
        }
    }

}

// If no project selected but projects exist, select the first one
if (!$selected_project_id && !empty($projects)) {
    $selected_project_id = $projects[0]['id'];
    $_SESSION['selected_project_id'] = $selected_project_id;
    $selected_project_details = $projects[0];
}

// Lookups: regions / zones / woredas
$regions = [];
if (function_exists('get_regions')) {
    $regions = get_regions();
} else {
    try {
        $regions = $pdo->query("SELECT id, name FROM regions ORDER BY name")->fetchAll();
    } catch (Throwable $e) {
        $regions = [];
    }
}

$zones = [];
try {
    $zones = $pdo->query("SELECT id, name, region_id FROM zones ORDER BY name")->fetchAll();
} catch (Throwable $e) {
    $zones = [];
}

$woredas = [];
try {
    $woredas = $pdo->query("SELECT id, name, zone_id FROM woredas ORDER BY name")->fetchAll();
} catch (Throwable $e) {
    $woredas = [];
}

// Build maps for labels
$regionMap = []; $zoneMap = []; $woredaMap = [];
foreach ($regions as $r) $regionMap[$r['id']] = $r['name'];
foreach ($zones as $z) $zoneMap[$z['id']] = $z['name'];
foreach ($woredas as $w) $woredaMap[$w['id']] = $w['name'];

// Standard options
$unitTypes = [
    // People & household counts
    'persons', 'households',

    // Percent / ratio
    '%',

    // Service counts / events
    'sessions', 'visits', 'trainings', 'meetings', 'events', 'referrals', 'cases', 'alerts',

    // Facilities / structures / groups
    'facilities', 'groups', 'sets', 'kits', 'packages', 'items', 'materials',

    // Distance / area / volume
    'km', 'm', 'meter', 'meters', 'cm', 'mm', 'm2', 'm3', 'hectare', 'ha',

    // Weight / volume
    'kg', 'g', 'gram', 'grams', 'ton', 'tons', 'liter', 'litre', 'l', 'ml',

    // Time / frequency
    'frequency', 'times', 'minutes', 'hours', 'days', 'months', 'years'
];


/**
 * Indicator unit rules (numeric type + formatting):
 * - Percent units (%) => decimal, clamped 0..100
 * - Decimal units (kg, km, meter, m2, etc) => decimal
 * - Others (persons, alerts, sessions, etc) => integer
 */
if (!function_exists('is_person_unit_type')) {
    function is_person_unit_type(string $unitType): bool {
        $u = strtolower(trim($unitType));
        return ($u === 'persons' || $u === 'person' || strpos($u, 'person') !== false || strpos($u, 'people') !== false || strpos($u, 'individual') !== false);
    }
}

if (!function_exists('indicator_unit_category')) {
    function indicator_unit_category(string $unitType): string {
        $u = strtolower(trim($unitType));
        if ($u === '%' || $u === 'percent' || $u === 'percentage') return 'percent';

        $decimalUnits = [
            'kg','g','gram','grams','ton','tons',
            'km','m','meter','meters','metre','metres','cm','mm',
            'm2','m3','hectare','ha','sqm',
            'liter','litre','l','ml'
        ];
        if (in_array($u, $decimalUnits, true)) return 'decimal';

        return 'integer';
    }
}

if (!function_exists('normalize_indicator_total_target')) {
    function normalize_indicator_total_target(string $unitType, string $inputMode, $rawValue): float {
        $cat = indicator_unit_category($unitType);
        $v = is_numeric($rawValue) ? (float)$rawValue : 0.0;

        if ($v < 0) $v = 0.0;

        if ($cat === 'percent') {
            if ($v > 100) $v = 100.0;
            return round($v, 2);
        }

        if ($cat === 'decimal') {
            return round($v, 2);
        }

        // integer
        return (float) max(0, (int) round($v));
    }
}

$inputModes = [
    'sadd'           => 'SADD (age/sex disaggregation)',
    'count'          => 'Simple count',
    'percent_pair'   => 'Percent (numerator / denominator pair)',
    'percent_direct' => 'Percent (direct value)'
];

$benefTypes = [
    'host'     => '🏠 Host community',
    'returnee' => '↩️ Returnee',
    'idp'      => '🏕️ IDP',
    'refugee'  => '🛶 Refugee',
    'pwd'      => '♿ Persons with disability',
    'mixed'    => '🔀 Mixed / combined',
    'other'    => '❓ Other'
];

$beneficiary_types = [
    'host_community' => '🏠 Host Community',
    'refugee' => '🛶 Refugee', 
    'returnee' => '↩️ Returnee',
    'idp' => '🏕️ Internally Displaced',
    'pwd' => '♿ Persons with Disability',
    'other' => '❓ Other'
];

$message = $_SESSION['planning_flash_message'] ?? '';
$error = $_SESSION['planning_flash_error'] ?? '';
unset($_SESSION['planning_flash_message'], $_SESSION['planning_flash_error']);

// ------------------------------------------------------
// Handle POST actions (CRUD operations)
// ------------------------------------------------------
if (false && $_SERVER['REQUEST_METHOD'] === 'POST') { // disabled: handled earlier at top

    $action = $_POST['action'] ?? '';

    // Helper to surface DB errors during planning saves
    if (!function_exists('planning_exec')) {
        function planning_exec(PDOStatement $stmt, array $params, string $context, ?string &$errorRef): bool
        {
            try {
                $stmt->execute($params);
                return true;
            } catch (Throwable $e) {
                error_log("Planning save error ($context): " . $e->getMessage());
                $prefix = $errorRef ? ($errorRef . " ") : '';
                $errorRef = $prefix . "Database error while saving $context: " . $e->getMessage();
                return false;
            }
        }
    }

    // Decide where to redirect after POST (PRG pattern)
    $redirect_anchor = null;
    if ($action === 'save_beneficiaries') {
        $redirect_anchor = 'beneficiaries';
    } elseif (isset($_POST['add_result']) || isset($_POST['update_result']) || isset($_POST['delete_result'])) {
        $redirect_anchor = 'results';
    } elseif (isset($_POST['add_impact_indicator']) || isset($_POST['update_impact_indicator']) || isset($_POST['delete_impact_indicator'])) {
        $redirect_anchor = 'indicators';
    } elseif (isset($_POST['add_outcome_indicator']) || isset($_POST['update_outcome_indicator']) || isset($_POST['delete_outcome_indicator'])) {
        $redirect_anchor = 'indicators';
    } elseif (isset($_POST['add_output_indicator']) || isset($_POST['update_output_indicator']) || isset($_POST['delete_output_indicator'])) {
        $redirect_anchor = 'indicators';
    } elseif (isset($_POST['add_activity']) || isset($_POST['update_activity']) || isset($_POST['delete_activity'])) {
        $redirect_anchor = 'activities';
    }

    // Handle beneficiary calculation data
    if ($action === 'save_beneficiaries') {
        $project_id = (int)($_POST['project_id'] ?? 0);
        $beneficiary_data = $_POST['beneficiaries'] ?? [];
        
        // Delete existing beneficiary data for this project
        $stmt = $pdo->prepare("DELETE FROM project_beneficiaries WHERE project_id = ?");
        $stmt->execute([$project_id]);
        
        // Calculate total for percentage calculation
        $grand_total = 0;
        foreach ($beneficiary_data as $data) {
            $women = (int)($data['women'] ?? 0);
            $girls = (int)($data['girls'] ?? 0);
            $men = (int)($data['men'] ?? 0);
            $boys = (int)($data['boys'] ?? 0);
            $pwd = (int)($data['pwd'] ?? 0);
            $total = $women + $girls + $men + $boys;
            $grand_total += $total;
        }
        
        // Insert new beneficiary data
        foreach ($beneficiary_data as $data) {
            $women = (int)($data['women'] ?? 0);
            $girls = (int)($data['girls'] ?? 0);
            $men = (int)($data['men'] ?? 0);
            $boys = (int)($data['boys'] ?? 0);
            $pwd = (int)($data['pwd'] ?? 0);
            $total = $women + $girls + $men + $boys;
            $share_percentage = $grand_total > 0 ? ($total / $grand_total) * 100 : 0;
            
            $stmt = $pdo->prepare("
                INSERT INTO project_beneficiaries 
                (project_id, region_id, zone_id, woreda_id, beneficiary_type, women, girls, men, boys, pwd_count, total, share_percentage)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $project_id,
                $data['region_id'] ?? null,
                $data['zone_id'] ?? null,
                $data['woreda_id'] ?? null,
                $data['beneficiary_type'] ?? 'other',
                $women, $girls, $men, $boys, $pwd, $total, $share_percentage
            ]);
        }
        
        $message = '✅ Beneficiary data saved successfully!';

    } elseif (isset($_POST['update_beneficiary_selection'])) {
        $_SESSION['selected_indicators'][$selected_project_id] = $_POST['selected_indicators'] ?? [];
        $message = '✅ Beneficiary calculation selection updated.';

    } elseif (isset($_POST['share_planning'])) {
        $share_type = $_POST['share_type'] ?? 'link';
        $recipient_email = $_POST['recipient_email'] ?? '';
        $email_subject = $_POST['email_subject'] ?? 'Project Planning Document';
        $email_message = $_POST['email_message'] ?? '';
        
        if ($share_type === 'email' && !empty($recipient_email)) {
            // Basic email sending (you might want to use a proper email library)
            $project_name = $selected_project_details['title'] ?? $selected_project_details['name'] ?? 'Project';
            $headers = "From: planning-system@yourdomain.com\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            
            $email_body = "
            <html>
            <body>
                <h2>Project Planning Document</h2>
                <p><strong>Project:</strong> {$project_name}</p>
                <p><strong>Message:</strong> {$email_message}</p>
                <p>You can access the planning document through the system or contact the project manager for more details.</p>
                <hr>
                <p><em>This email was sent from the Project Planning System</em></p>
            </body>
            </html>
            ";
            
            if (mail($recipient_email, $email_subject, $email_body, $headers)) {
                $message = "📧 Planning shared via email to $recipient_email";
            } else {
                $message = "❌ Failed to send email to $recipient_email";
            }
        } else {
            $message = "🔗 Shareable link generated for project planning";
        }

    // ----- RESULTS (impact / outcome / output) -----
    } elseif (isset($_POST['add_result'])) {
        $project_id = $_POST['project_id'] ?? null;
        $level      = $_POST['level'] ?? 'output';
        $code       = $_POST['code'] ?? '';
        $name       = $_POST['name'] ?? '';

        $stmt = $pdo->prepare(
            "INSERT INTO results_chain (project_id, level, code, name) VALUES (?,?,?,?)"
        );
        $stmt->execute([$project_id, $level, $code, $name]);
        $message = '🎉 Result added successfully!';

    } elseif (isset($_POST['update_result'])) {
        $id         = (int)($_POST['id'] ?? 0);
        $project_id = $_POST['project_id'] ?? null;
        $level      = $_POST['level'] ?? 'output';
        $code       = $_POST['code'] ?? '';
        $name       = $_POST['name'] ?? '';

        if ($id > 0) {
            $stmt = $pdo->prepare(
                "UPDATE results_chain SET project_id = ?, level = ?, code = ?, name = ? WHERE id = ?"
            );
            $stmt->execute([$project_id, $level, $code, $name, $id]);
            $message = '✅ Result updated successfully!';
        }

    } elseif (isset($_POST['delete_result'])) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            // Delete related indicators & unlink activities
            $stmt = $pdo->prepare("DELETE FROM impact_indicators WHERE result_id = ?");
            $stmt->execute([$id]);
            $stmt = $pdo->prepare("DELETE FROM outcome_indicators WHERE result_id = ?");
            $stmt->execute([$id]);
            $stmt = $pdo->prepare("DELETE FROM output_indicators WHERE result_id = ?");
            $stmt->execute([$id]);
            $stmt = $pdo->prepare("UPDATE activities SET output_id = NULL WHERE output_id = ?");
            $stmt->execute([$id]);

            $stmt = $pdo->prepare("DELETE FROM results_chain WHERE id = ?");
            $stmt->execute([$id]);
            $message = '🗑️ Result deleted successfully!';
        }

    // ----- IMPACT INDICATORS -----
    } elseif (isset($_POST['add_impact_indicator'])) {
        $project_id     = (int)($_POST['project_id'] ?? ($selected_project_id ?? 0));
        $result_id      = !empty($_POST['result_id']) ? (int)$_POST['result_id'] : 0;
        $beneficiary    = $_POST['beneficiary_type'] ?? 'host';
        $indicator_code = $_POST['indicator_code'] ?? '';
        $indicator_name = $_POST['indicator_name'] ?? '';
        $unit_type      = $_POST['unit_type'] ?? 'persons';
        $input_mode     = $_POST['input_mode'] ?? 'sadd';
    $total_target_raw = $_POST['total_target'] ?? ($_POST['target_total'] ?? 0);
    $total_target     = normalize_indicator_total_target($unit_type, $input_mode, $total_target_raw);
        $boys_u5        = (int)($_POST['boys_u5'] ?? 0);
        $girls_u5       = (int)($_POST['girls_u5'] ?? 0);
        $boys_5_17      = (int)($_POST['boys_5_17'] ?? 0);
        $girls_5_17     = (int)($_POST['girls_5_17'] ?? 0);
        $men_18_59      = (int)($_POST['men_18_59'] ?? 0);
        $women_18_59    = (int)($_POST['women_18_59'] ?? 0);
        $men_60p        = (int)($_POST['men_60p'] ?? 0);
        $women_60p      = (int)($_POST['women_60p'] ?? 0);
        $pwd_count      = (int)($_POST['pwd_count'] ?? 0);

        if (is_person_unit_type($unit_type) && $input_mode === 'sadd') {
            $total_target = $boys_u5 + $girls_u5 + $boys_5_17 + $girls_5_17 +
                            $men_18_59 + $women_18_59 + $men_60p + $women_60p;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO impact_indicators
             (project_id, result_id, beneficiary_type, indicator_code, indicator_name,
              unit_type, input_mode, total_target,
              boys_u5, girls_u5, boys_5_17, girls_5_17,
              men_18_59, women_18_59, men_60p, women_60p, pwd_count)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $stmt->execute([
            $project_id, $result_id, $beneficiary, $indicator_code, $indicator_name,
            $unit_type, $input_mode, $total_target,
            $boys_u5, $girls_u5, $boys_5_17, $girls_5_17,
            $men_18_59, $women_18_59, $men_60p, $women_60p, $pwd_count
        ]);
        $message = '🎯 Impact indicator added successfully!';

    } elseif (isset($_POST['update_impact_indicator'])) {
    $id             = (int)($_POST['id'] ?? 0);
    $project_id     = $_POST['project_id'] ?? null;
    $result_id      = $_POST['result_id'] ?? null;
    $beneficiary    = $_POST['beneficiary_type'] ?? 'host';
    $indicator_code = $_POST['indicator_code'] ?? '';
    $indicator_name = $_POST['indicator_name'] ?? '';
    $unit_type      = $_POST['unit_type'] ?? 'persons';
    $input_mode     = $_POST['input_mode'] ?? 'sadd';
    $total_target_raw = $_POST['total_target'] ?? ($_POST['target_total'] ?? 0);
    $total_target     = normalize_indicator_total_target($unit_type, $input_mode, $total_target_raw);
    $boys_u5        = (int)($_POST['boys_u5'] ?? 0);
    $girls_u5       = (int)($_POST['girls_u5'] ?? 0);
    $boys_5_17      = (int)($_POST['boys_5_17'] ?? 0);
    $girls_5_17     = (int)($_POST['girls_5_17'] ?? 0);
    $men_18_59      = (int)($_POST['men_18_59'] ?? 0);
    $women_18_59    = (int)($_POST['women_18_59'] ?? 0);
    $men_60p        = (int)($_POST['men_60p'] ?? 0);
    $women_60p      = (int)($_POST['women_60p'] ?? 0);
    $pwd_count      = (int)($_POST['pwd_count'] ?? 0);
    $hh_count       = (int)($_POST['hh_count'] ?? 0);   // 🔹 NEW

    // 🔹 Total = ONLY persons (age/sex), NOT PWD, NOT HH
    if (is_person_unit_type($unit_type) && $input_mode === 'sadd') {
        $total_target = $boys_u5 + $girls_u5 + $boys_5_17 + $girls_5_17 +
                        $men_18_59 + $women_18_59 + $men_60p + $women_60p;
    }

    if ($id > 0) {
        $stmt = $pdo->prepare(
            "UPDATE impact_indicators
             SET project_id = ?, result_id = ?, beneficiary_type = ?, indicator_code = ?, indicator_name = ?,
                 unit_type = ?, input_mode = ?, total_target = ?,
                 boys_u5 = ?, girls_u5 = ?, boys_5_17 = ?, girls_5_17 = ?,
                 men_18_59 = ?, women_18_59 = ?, men_60p = ?, women_60p = ?,
                 pwd_count = ?, hh_count = ?           -- 🔹 add hh_count here
             WHERE id = ?"
        );
        $stmt->execute([
            $project_id, $result_id, $beneficiary, $indicator_code, $indicator_name,
            $unit_type, $input_mode, $total_target,
            $boys_u5, $girls_u5, $boys_5_17, $girls_5_17,
            $men_18_59, $women_18_59, $men_60p, $women_60p,
            $pwd_count, $hh_count,                // 🔹 and here
            $id
        ]);
        $message = '✅ Impact indicator updated successfully!';
    }
}

    } elseif (isset($_POST['delete_impact_indicator'])) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM impact_indicators WHERE id = ?");
            $stmt->execute([$id]);
            $message = '🗑️ Impact indicator deleted successfully!';
        }
     // ----- OUTCOME INDICATORS -----
} elseif (isset($_POST['add_outcome_indicator'])) {
    $project_id     = $_POST['project_id'] ?? null;
    $result_id      = $_POST['result_id'] ?? null;
    $beneficiary    = $_POST['beneficiary_type'] ?? 'host';
    $indicator_code = $_POST['indicator_code'] ?? '';
    $indicator_name = $_POST['indicator_name'] ?? '';
    $unit_type      = $_POST['unit_type'] ?? 'persons';
    $input_mode     = $_POST['input_mode'] ?? 'sadd';
    $total_target_raw = $_POST['total_target'] ?? ($_POST['target_total'] ?? 0);
    $total_target     = normalize_indicator_total_target($unit_type, $input_mode, $total_target_raw);
    $boys_u5        = (int)($_POST['boys_u5'] ?? 0);
    $girls_u5       = (int)($_POST['girls_u5'] ?? 0);
    $boys_5_17      = (int)($_POST['boys_5_17'] ?? 0);
    $girls_5_17     = (int)($_POST['girls_5_17'] ?? 0);
    $men_18_59      = (int)($_POST['men_18_59'] ?? 0);
    $women_18_59    = (int)($_POST['women_18_59'] ?? 0);
    $men_60p        = (int)($_POST['men_60p'] ?? 0);
    $women_60p      = (int)($_POST['women_60p'] ?? 0);
    $pwd_count      = (int)($_POST['pwd_count'] ?? 0);
    $hh_count       = (int)($_POST['hh_count'] ?? 0);   // NEW

    // Total = only persons (SADD), NOT PWD, NOT HH
    if (is_person_unit_type($unit_type) && $input_mode === 'sadd') {
        $total_target = $boys_u5 + $girls_u5 + $boys_5_17 + $girls_5_17 +
                        $men_18_59 + $women_18_59 + $men_60p + $women_60p;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO outcome_indicators
         (project_id, result_id, beneficiary_type, indicator_code, indicator_name,
          unit_type, input_mode, total_target,
          boys_u5, girls_u5, boys_5_17, girls_5_17,
          men_18_59, women_18_59, men_60p, women_60p,
          pwd_count, hh_count)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    $stmt->execute([
        $project_id, $result_id, $beneficiary, $indicator_code, $indicator_name,
        $unit_type, $input_mode, $total_target,
        $boys_u5, $girls_u5, $boys_5_17, $girls_5_17,
        $men_18_59, $women_18_59, $men_60p, $women_60p,
        $pwd_count, $hh_count
    ]);
    $message = '📈 Outcome indicator added successfully!';

} elseif (isset($_POST['update_outcome_indicator'])) {
    $id             = (int)($_POST['id'] ?? 0);
    $project_id     = $_POST['project_id'] ?? null;
    $result_id      = $_POST['result_id'] ?? null;
    $beneficiary    = $_POST['beneficiary_type'] ?? 'host';
    $indicator_code = $_POST['indicator_code'] ?? '';
    $indicator_name = $_POST['indicator_name'] ?? '';
    $unit_type      = $_POST['unit_type'] ?? 'persons';
    $input_mode     = $_POST['input_mode'] ?? 'sadd';
    $total_target_raw = $_POST['total_target'] ?? ($_POST['target_total'] ?? 0);
    $total_target     = normalize_indicator_total_target($unit_type, $input_mode, $total_target_raw);
    $boys_u5        = (int)($_POST['boys_u5'] ?? 0);
    $girls_u5       = (int)($_POST['girls_u5'] ?? 0);
    $boys_5_17      = (int)($_POST['boys_5_17'] ?? 0);
    $girls_5_17     = (int)($_POST['girls_5_17'] ?? 0);
    $men_18_59      = (int)($_POST['men_18_59'] ?? 0);
    $women_18_59    = (int)($_POST['women_18_59'] ?? 0);
    $men_60p        = (int)($_POST['men_60p'] ?? 0);
    $women_60p      = (int)($_POST['women_60p'] ?? 0);
    $pwd_count      = (int)($_POST['pwd_count'] ?? 0);
    $hh_count       = (int)($_POST['hh_count'] ?? 0);   // NEW

    if (is_person_unit_type($unit_type) && $input_mode === 'sadd') {
        $total_target = $boys_u5 + $girls_u5 + $boys_5_17 + $girls_5_17 +
                        $men_18_59 + $women_18_59 + $men_60p + $women_60p;
    }

    if ($id > 0) {
        $stmt = $pdo->prepare(
            "UPDATE outcome_indicators
             SET project_id = ?, result_id = ?, beneficiary_type = ?, indicator_code = ?, indicator_name = ?,
                 unit_type = ?, input_mode = ?, total_target = ?,
                 boys_u5 = ?, girls_u5 = ?, boys_5_17 = ?, girls_5_17 = ?,
                 men_18_59 = ?, women_18_59 = ?, men_60p = ?, women_60p = ?,
                 pwd_count = ?, hh_count = ?
             WHERE id = ?"
        );
        $stmt->execute([
            $project_id, $result_id, $beneficiary, $indicator_code, $indicator_name,
            $unit_type, $input_mode, $total_target,
            $boys_u5, $girls_u5, $boys_5_17, $girls_5_17,
            $men_18_59, $women_18_59, $men_60p, $women_60p,
            $pwd_count, $hh_count,
            $id
        ]);
        $message = '✅ Outcome indicator updated successfully!';
    }

} elseif (isset($_POST['delete_outcome_indicator'])) {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $pdo->prepare("DELETE FROM outcome_indicators WHERE id = ?");
        $stmt->execute([$id]);
        $message = '🗑️ Outcome indicator deleted successfully!';
    }

     // ----- OUTPUT INDICATORS -----
} elseif (isset($_POST['add_output_indicator'])) {
    $project_id     = $_POST['project_id'] ?? null;
    $result_id      = $_POST['result_id'] ?? null;
    $beneficiary    = $_POST['beneficiary_type'] ?? 'host';
    $indicator_code = $_POST['indicator_code'] ?? '';
    $indicator_name = $_POST['indicator_name'] ?? '';
    $unit_type      = $_POST['unit_type'] ?? 'persons';
    $input_mode     = $_POST['input_mode'] ?? 'sadd';
    $target_total_raw = $_POST['target_total'] ?? ($_POST['total_target'] ?? 0);
    $target_total     = normalize_indicator_total_target($unit_type, $input_mode, $target_total_raw);
    $boys_u5        = (int)($_POST['boys_u5'] ?? 0);
    $girls_u5       = (int)($_POST['girls_u5'] ?? 0);
    $boys_5_17      = (int)($_POST['boys_5_17'] ?? 0);
    $girls_5_17     = (int)($_POST['girls_5_17'] ?? 0);
    $men_18_59      = (int)($_POST['men_18_59'] ?? 0);
    $women_18_59    = (int)($_POST['women_18_59'] ?? 0);
    $men_60p        = (int)($_POST['men_60p'] ?? 0);
    $women_60p      = (int)($_POST['women_60p'] ?? 0);
    $pwd_count      = (int)($_POST['pwd_count'] ?? 0);
    $hh_count       = (int)($_POST['hh_count'] ?? 0); // NEW

    // Auto-calc ONLY persons, NOT PWD, NOT HH
    if (is_person_unit_type($unit_type) && $input_mode === 'sadd') {
        $target_total = $boys_u5 + $girls_u5 + $boys_5_17 + $girls_5_17 +
                        $men_18_59 + $women_18_59 + $men_60p + $women_60p;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO output_indicators
         (project_id, result_id, beneficiary_type, indicator_code, indicator_name,
          unit_type, input_mode, target_total,
          boys_u5, girls_u5, boys_5_17, girls_5_17,
          men_18_59, women_18_59, men_60p, women_60p,
          pwd_count, hh_count)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    $stmt->execute([
        $project_id, $result_id, $beneficiary, $indicator_code, $indicator_name,
        $unit_type, $input_mode, $target_total,
        $boys_u5, $girls_u5, $boys_5_17, $girls_5_17,
        $men_18_59, $women_18_59, $men_60p, $women_60p,
        $pwd_count, $hh_count
    ]);
    $message = '📊 Output indicator added successfully!';

} elseif (isset($_POST['update_output_indicator'])) {
    $id             = (int)($_POST['id'] ?? 0);
    $project_id     = $_POST['project_id'] ?? null;
    $result_id      = $_POST['result_id'] ?? null;
    $beneficiary    = $_POST['beneficiary_type'] ?? 'host';
    $indicator_code = $_POST['indicator_code'] ?? '';
    $indicator_name = $_POST['indicator_name'] ?? '';
    $unit_type      = $_POST['unit_type'] ?? 'persons';
    $input_mode     = $_POST['input_mode'] ?? 'sadd';
    $target_total_raw = $_POST['target_total'] ?? ($_POST['total_target'] ?? 0);
    $target_total     = normalize_indicator_total_target($unit_type, $input_mode, $target_total_raw);
    $boys_u5        = (int)($_POST['boys_u5'] ?? 0);
    $girls_u5       = (int)($_POST['girls_u5'] ?? 0);
    $boys_5_17      = (int)($_POST['boys_5_17'] ?? 0);
    $girls_5_17     = (int)($_POST['girls_5_17'] ?? 0);
    $men_18_59      = (int)($_POST['men_18_59'] ?? 0);
    $women_18_59    = (int)($_POST['women_18_59'] ?? 0);
    $men_60p        = (int)($_POST['men_60p'] ?? 0);
    $women_60p      = (int)($_POST['women_60p'] ?? 0);
    $pwd_count      = (int)($_POST['pwd_count'] ?? 0);
    $hh_count       = (int)($_POST['hh_count'] ?? 0); // NEW

    if (is_person_unit_type($unit_type) && $input_mode === 'sadd') {
        $target_total = $boys_u5 + $girls_u5 + $boys_5_17 + $girls_5_17 +
                        $men_18_59 + $women_18_59 + $men_60p + $women_60p;
    }

    if ($id > 0) {
        $stmt = $pdo->prepare(
            "UPDATE output_indicators
             SET project_id = ?, result_id = ?, beneficiary_type = ?, indicator_code = ?, indicator_name = ?,
                 unit_type = ?, input_mode = ?, target_total = ?,
                 boys_u5 = ?, girls_u5 = ?, boys_5_17 = ?, girls_5_17 = ?,
                 men_18_59 = ?, women_18_59 = ?, men_60p = ?, women_60p = ?,
                 pwd_count = ?, hh_count = ?
             WHERE id = ?"
        );
        $stmt->execute([
            $project_id, $result_id, $beneficiary, $indicator_code, $indicator_name,
            $unit_type, $input_mode, $target_total,
            $boys_u5, $girls_u5, $boys_5_17, $girls_5_17,
            $men_18_59, $women_18_59, $men_60p, $women_60p,
            $pwd_count, $hh_count,
            $id
        ]);
        $message = '✅ Output indicator updated successfully!';
    }

} elseif (isset($_POST['delete_output_indicator'])) {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $pdo->prepare("DELETE FROM output_indicators WHERE id = ?");
        $stmt->execute([$id]);
        $message = '🗑️ Output indicator deleted successfully!';
    }
    // ----- PROJECT MANAGEMENT (Edit/Delete/Update) -----
    } elseif ($action === 'update_project' || isset($_POST['update_project'])) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $title = trim($_POST['title'] ?? '');
            $code = trim($_POST['code'] ?? '');
            $status = trim($_POST['status'] ?? 'active');
            $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
            $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
            
            // Handle donor - support both donor_id and donor_other
            $donor_id = !empty($_POST['donor_id']) ? (int)$_POST['donor_id'] : null;
            $donor_other = trim($_POST['donor_other'] ?? '');
            $donor = '';
            
            if ($donor_id && $donor_id > 0 && $_POST['donor_id'] !== 'OTHER') {
                // Get donor name from database
                try {
                    $donorStmt = $pdo->prepare("SELECT name FROM gms_donors WHERE id = ?");
                    $donorStmt->execute([$donor_id]);
                    $donorData = $donorStmt->fetch(PDO::FETCH_ASSOC);
                    $donor = $donorData['name'] ?? '';
                } catch (Exception $e) {
                    error_log("Error fetching donor name: " . $e->getMessage());
                }
            } elseif (!empty($donor_other) && ($_POST['donor_id'] === 'OTHER' || empty($donor_id))) {
                // Create new donor from "Other" option
                try {
                    // Check if donor already exists
                    $checkStmt = $pdo->prepare("SELECT id, name FROM gms_donors WHERE name = ? LIMIT 1");
                    $checkStmt->execute([$donor_other]);
                    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existing) {
                        $donor = $existing['name'];
                        $donor_id = (int)$existing['id'];
                    } else {
                        // Create new donor
                        $insertStmt = $pdo->prepare("INSERT INTO gms_donors (name, short_name, donor_type, is_active) VALUES (?, ?, 'Other', 1)");
                        $insertStmt->execute([$donor_other, $donor_other]);
                        $donor_id = (int)$pdo->lastInsertId();
                        $donor = $donor_other;
                        error_log("Planning: Auto-created new donor: " . $donor_other);
                    }
                } catch (Exception $e) {
                    error_log("Error creating donor in planning update_project: " . $e->getMessage());
                    $donor = $donor_other; // Fallback to text
                }
            } else {
                // Fallback to text input if donor field exists
                $donor = trim($_POST['donor'] ?? '');
            }
            
            // Update project - support both donor (text) and donor_id if column exists
            try {
                // Check if donor_id column exists
                $checkCol = $pdo->query("SHOW COLUMNS FROM projects LIKE 'donor_id'");
                if ($checkCol->rowCount() > 0) {
                    // Use donor_id column
                    $stmt = $pdo->prepare("UPDATE projects SET title = ?, code = ?, donor = ?, donor_id = ?, status = ?, start_date = ?, end_date = ? WHERE id = ?");
                    $stmt->execute([$title, $code, $donor, $donor_id, $status, $start_date, $end_date, $id]);
                } else {
                    // Use only donor text column
                    $stmt = $pdo->prepare("UPDATE projects SET title = ?, code = ?, donor = ?, status = ?, start_date = ?, end_date = ? WHERE id = ?");
                    $stmt->execute([$title, $code, $donor, $status, $start_date, $end_date, $id]);
                }
            } catch (Exception $e) {
                // Fallback to simple update
                $stmt = $pdo->prepare("UPDATE projects SET title = ?, code = ?, donor = ?, status = ?, start_date = ?, end_date = ? WHERE id = ?");
                $stmt->execute([$title, $code, $donor, $status, $start_date, $end_date, $id]);
            }
            
            $message = '✅ Project updated successfully!';
            
            // Redirect to clear edit mode and refresh the page
            header("Location: ?");
            exit;
        }
    } elseif ($action === 'delete_project' || isset($_POST['delete_project'])) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            // Delete related data
            $pdo->prepare("DELETE FROM results_chain WHERE project_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM impact_indicators WHERE project_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM outcome_indicators WHERE project_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM output_indicators WHERE project_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM activities WHERE project_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM project_beneficiaries WHERE project_id = ?")->execute([$id]);
            
            $stmt = $pdo->prepare("DELETE FROM projects WHERE id = ?");
            $stmt->execute([$id]);
            $message = '🗑️ Project deleted successfully!';
            $selected_project_id = null;
            $_SESSION['selected_project_id'] = null;
        }
    } elseif ($action === 'import_template') {
        // Handle template import
        if (isset($_FILES['template_file']) && $_FILES['template_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['template_file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            if (in_array($ext, ['csv', 'xls', 'xlsx'])) {
                $message = '📥 Template import functionality - processing...';
                // Add CSV/Excel parsing logic here
            } else {
                $message = '❌ Invalid file format. Please upload CSV or Excel file.';
            }
        }
    // ----- ACTIVITIES -----
    } elseif (isset($_POST['add_activity'])) {
        $project_id = $_POST['project_id'] ?? null;
        $output_id  = !empty($_POST['output_id']) ? (int)$_POST['output_id'] : null;
        $code       = trim($_POST['code'] ?? '');
        $name       = trim($_POST['name'] ?? '');
        $unit       = trim($_POST['unit'] ?? '');
        $target     = (int)($_POST['target'] ?? 0);

        if ($project_id && $code && $name) {
            $stmt = $pdo->prepare(
                "INSERT INTO activities (project_id, output_id, code, name, unit, target) VALUES (?,?,?,?,?,?)"
            );
            $stmt->execute([$project_id, $output_id, $code, $name, $unit, $target]);
            $message = '🎉 Activity added successfully!';
        } else {
            $message = '❌ Please fill in all required fields (code and name).';
        }

    } elseif (isset($_POST['update_activity'])) {
        $id         = (int)($_POST['id'] ?? 0);
        $project_id = $_POST['project_id'] ?? null;
        $output_id  = !empty($_POST['output_id']) ? (int)$_POST['output_id'] : null;
        $code       = trim($_POST['code'] ?? '');
        $name       = trim($_POST['name'] ?? '');
        $unit       = trim($_POST['unit'] ?? '');
        $target     = (int)($_POST['target'] ?? 0);

        if ($id > 0 && $project_id && $code && $name) {
            $stmt = $pdo->prepare(
                "UPDATE activities SET project_id = ?, output_id = ?, code = ?, name = ?, unit = ?, target = ? WHERE id = ?"
            );
            $stmt->execute([$project_id, $output_id, $code, $name, $unit, $target, $id]);
            $message = '✅ Activity updated successfully!';
        }

    } elseif (isset($_POST['delete_activity'])) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM activities WHERE id = ?");
            $stmt->execute([$id]);
            $message = '🗑️ Activity deleted successfully!';
        }
    }

// ------------------------------------------------------
// Fetch data for display - FILTERED BY SELECTED PROJECT
// ------------------------------------------------------
$where_clause = $selected_project_id ? " WHERE project_id = ?" : "";
$params = $selected_project_id ? [$selected_project_id] : [];

// Build queries with optional project filter
$results_query = "SELECT * FROM results_chain" . $where_clause . " ORDER BY FIELD(level,'impact','outcome','output'), id";
$stmt = $pdo->prepare($results_query);
$stmt->execute($params);
$results = $stmt->fetchAll();

$impactResults_query = "SELECT * FROM results_chain WHERE level = 'impact'" . ($selected_project_id ? " AND project_id = ?" : "") . " ORDER BY project_id, id";
$stmt = $pdo->prepare($impactResults_query);
$stmt->execute($selected_project_id ? [$selected_project_id] : []);
$impactResults = $stmt->fetchAll();

$outcomeResults_query = "SELECT * FROM results_chain WHERE level = 'outcome'" . ($selected_project_id ? " AND project_id = ?" : "") . " ORDER BY project_id, id";
$stmt = $pdo->prepare($outcomeResults_query);
$stmt->execute($selected_project_id ? [$selected_project_id] : []);
$outcomeResults = $stmt->fetchAll();

$outputResults_query = "SELECT * FROM results_chain WHERE level = 'output'" . ($selected_project_id ? " AND project_id = ?" : "") . " ORDER BY project_id, id";
$stmt = $pdo->prepare($outputResults_query);
$stmt->execute($selected_project_id ? [$selected_project_id] : []);
$outputResults = $stmt->fetchAll();

$activities_query = "SELECT a.*, r.code AS output_code, r.name AS output_name, r.project_id AS output_project_id
     FROM activities a
     LEFT JOIN results_chain r ON a.output_id = r.id" . 
     ($selected_project_id ? " WHERE a.project_id = ?" : "") . 
     " ORDER BY a.project_id, a.id";
$stmt = $pdo->prepare($activities_query);
$stmt->execute($selected_project_id ? [$selected_project_id] : []);
$activities = $stmt->fetchAll();

$impactIndicators_query = "SELECT oi.*, rc.code AS result_code, rc.name AS result_name
     FROM impact_indicators oi
     LEFT JOIN results_chain rc ON rc.id = oi.result_id" . 
     ($selected_project_id ? " WHERE oi.project_id = ?" : "") . 
     " ORDER BY oi.project_id, oi.result_id, oi.id";
$stmt = $pdo->prepare($impactIndicators_query);
$stmt->execute($selected_project_id ? [$selected_project_id] : []);
$impactIndicators = $stmt->fetchAll();

$outcomeIndicators_query = "SELECT oi.*, rc.code AS result_code, rc.name AS result_name
     FROM outcome_indicators oi
     LEFT JOIN results_chain rc ON rc.id = oi.result_id" . 
     ($selected_project_id ? " WHERE oi.project_id = ?" : "") . 
     " ORDER BY oi.project_id, oi.result_id, oi.id";
$stmt = $pdo->prepare($outcomeIndicators_query);
$stmt->execute($selected_project_id ? [$selected_project_id] : []);
$outcomeIndicators = $stmt->fetchAll();

$outputIndicators_query = "SELECT oi.*, rc.code AS result_code, rc.name AS result_name
     FROM output_indicators oi
     LEFT JOIN results_chain rc ON rc.id = oi.result_id" . 
     ($selected_project_id ? " WHERE oi.project_id = ?" : "") . 
     " ORDER BY oi.project_id, oi.result_id, oi.id";
$stmt = $pdo->prepare($outputIndicators_query);
$stmt->execute($selected_project_id ? [$selected_project_id] : []);
$outputIndicators = $stmt->fetchAll();

// ------------------------------------------------------
// Handle edit parameters - fetch data for pre-filling forms
// ------------------------------------------------------
$edit_project = null;
$edit_result = null;
$edit_impact_indicator = null;
$edit_outcome_indicator = null;
$edit_output_indicator = null;
$edit_activity = null;

// Handle project edit mode
if (isset($_GET['edit_project'])) {
    $edit_id = (int)$_GET['edit_project'];
    $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_project = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (isset($_GET['edit_result'])) {
    $edit_id = (int)$_GET['edit_result'];
    $stmt = $pdo->prepare("SELECT * FROM results_chain WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_result = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (isset($_GET['edit_impact_indicator'])) {
    $edit_id = (int)$_GET['edit_impact_indicator'];
    $stmt = $pdo->prepare("SELECT * FROM impact_indicators WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_impact_indicator = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (isset($_GET['edit_outcome_indicator'])) {
    $edit_id = (int)$_GET['edit_outcome_indicator'];
    $stmt = $pdo->prepare("SELECT * FROM outcome_indicators WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_outcome_indicator = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (isset($_GET['edit_output_indicator'])) {
    $edit_id = (int)$_GET['edit_output_indicator'];
    $stmt = $pdo->prepare("SELECT * FROM output_indicators WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_output_indicator = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (isset($_GET['edit_activity'])) {
    $edit_id = (int)$_GET['edit_activity'];
    $stmt = $pdo->prepare("SELECT * FROM activities WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_activity = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Fetch beneficiary data for selected project (paginated for performance)
$project_beneficiaries = [];
$beneficiary_total = 0;
$beneficiary_page_size = 25;

if ($selected_project_id) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM project_beneficiaries WHERE project_id=?");
    $stmt->execute([$selected_project_id]);
    $beneficiary_total = (int)$stmt->fetchColumn();

    $limit = (int)$beneficiary_page_size;
    $stmt = $pdo->prepare("
        SELECT pb.*, r.name AS region_name, z.name AS zone_name, w.name AS woreda_name
        FROM project_beneficiaries pb
        LEFT JOIN regions r ON pb.region_id = r.id
        LEFT JOIN zones z ON pb.zone_id = z.id
        LEFT JOIN woredas w ON pb.woreda_id = w.id
        WHERE pb.project_id = ?
        ORDER BY pb.id
        LIMIT $limit OFFSET 0
    ");
    $stmt->execute([$selected_project_id]);
    $project_beneficiaries = $stmt->fetchAll();
}
// ------------------------------------------------------
// Get project lifespan and date information
// ------------------------------------------------------
$project_lifespan = 12; // default
$project_start_date = null;
$project_end_date = null;
$project_months = [];

if ($selected_project_details) {
    $project_start_date = isset($selected_project_details['start_date']) && $selected_project_details['start_date'] ? 
        new DateTime($selected_project_details['start_date']) : null;
    $project_end_date = isset($selected_project_details['end_date']) && $selected_project_details['end_date'] ? 
        new DateTime($selected_project_details['end_date']) : null;
    
    if ($project_start_date && $project_end_date) {
        $interval = $project_start_date->diff($project_end_date);
        $project_lifespan = ($interval->y * 12) + $interval->m + 1;
        
        $current_month = clone $project_start_date;
        for ($i = 0; $i < $project_lifespan; $i++) {
            $project_months[] = [
                'number' => $i + 1,
                'name' => $current_month->format('F Y'),
                'short_name' => $current_month->format('M Y')
            ];
            $current_month->modify('+1 month');
        }
    }
}

// ------------------------------------------------------
// Beneficiary calculation with selectable indicators
// ------------------------------------------------------

// Helper: detect person-based units for planning / summary
if (!function_exists('is_person_unit_planning')) {
    function is_person_unit_planning($unit): bool
    {
        $u = strtolower(trim((string)$unit));
        if ($u === '') {
            return false;
        }
        // Treat anything mentioning person / people / individual as person-based
        return (strpos($u, 'person') !== false)
            || (strpos($u, 'people') !== false)
            || (strpos($u, 'individual') !== false);
    }
}

// 1) Which indicators are selected for the summary?
$selected_indicators = $_SESSION['selected_indicators'][$selected_project_id] ?? [];

// Default to ALL indicators for this project if none explicitly selected
if (empty($selected_indicators)) {
    foreach ($impactIndicators as $ind) {
        $selected_indicators[] = 'impact_' . $ind['id'];
    }
    foreach ($outcomeIndicators as $ind) {
        $selected_indicators[] = 'outcome_' . $ind['id'];
    }
    foreach ($outputIndicators as $ind) {
        $selected_indicators[] = 'output_' . $ind['id'];
    }
}

// Initialise sums (HH added, PWD kept separate)
$sum = [
    'boys_u5'     => 0,
    'girls_u5'    => 0,
    'boys_5_17'   => 0,
    'girls_5_17'  => 0,
    'men_18_59'   => 0,
    'women_18_59' => 0,
    'men_60p'     => 0,
    'women_60p'   => 0,
    'pwd_count'   => 0,
    'hh_count'    => 0,
];

$selected_indicators_details = [];

// 2) Aggregate over IMPACT indicators
foreach ($impactIndicators as $oi) {
    if (!in_array('impact_' . $oi['id'], $selected_indicators, true)) {
        continue;
    }

    $unit = $oi['unit_type'] ?? '';
    $mode = $oi['input_mode'] ?? '';

    // Only count person-based, non-percentage indicators
    if (!is_person_unit_planning($unit)) {
        continue;
    }
    if ($mode === 'percent_pair' || $mode === 'percent_direct') {
        continue;
    }

    $sum['boys_u5']     += (int)($oi['boys_u5'] ?? 0);
    $sum['girls_u5']    += (int)($oi['girls_u5'] ?? 0);
    $sum['boys_5_17']   += (int)($oi['boys_5_17'] ?? 0);
    $sum['girls_5_17']  += (int)($oi['girls_5_17'] ?? 0);
    $sum['men_18_59']   += (int)($oi['men_18_59'] ?? 0);
    $sum['women_18_59'] += (int)($oi['women_18_59'] ?? 0);
    $sum['men_60p']     += (int)($oi['men_60p'] ?? 0);
    $sum['women_60p']   += (int)($oi['women_60p'] ?? 0);
    $sum['pwd_count']   += (int)($oi['pwd_count'] ?? 0);
    $sum['hh_count']    += (int)($oi['hh_count'] ?? 0);

    $selected_indicators_details[] = [
        'type' => 'impact',
        'name' => $oi['indicator_name'],
        'code' => $oi['indicator_code'],
    ];
}

// 3) Aggregate over OUTCOME indicators
foreach ($outcomeIndicators as $oi) {
    if (!in_array('outcome_' . $oi['id'], $selected_indicators, true)) {
        continue;
    }

    $unit = $oi['unit_type'] ?? '';
    $mode = $oi['input_mode'] ?? '';

    if (!is_person_unit_planning($unit)) {
        continue;
    }
    if ($mode === 'percent_pair' || $mode === 'percent_direct') {
        continue;
    }

    $sum['boys_u5']     += (int)($oi['boys_u5'] ?? 0);
    $sum['girls_u5']    += (int)($oi['girls_u5'] ?? 0);
    $sum['boys_5_17']   += (int)($oi['boys_5_17'] ?? 0);
    $sum['girls_5_17']  += (int)($oi['girls_5_17'] ?? 0);
    $sum['men_18_59']   += (int)($oi['men_18_59'] ?? 0);
    $sum['women_18_59'] += (int)($oi['women_18_59'] ?? 0);
    $sum['men_60p']     += (int)($oi['men_60p'] ?? 0);
    $sum['women_60p']   += (int)($oi['women_60p'] ?? 0);
    $sum['pwd_count']   += (int)($oi['pwd_count'] ?? 0);
    $sum['hh_count']    += (int)($oi['hh_count'] ?? 0);

    $selected_indicators_details[] = [
        'type' => 'outcome',
        'name' => $oi['indicator_name'],
        'code' => $oi['indicator_code'],
    ];
}

// 4) Aggregate over OUTPUT indicators
foreach ($outputIndicators as $oi) {
    if (!in_array('output_' . $oi['id'], $selected_indicators, true)) {
        continue;
    }

    $unit = $oi['unit_type'] ?? '';
    $mode = $oi['input_mode'] ?? '';

    if (!is_person_unit_planning($unit)) {
        continue;
    }
    if ($mode === 'percent_pair' || $mode === 'percent_direct') {
        continue;
    }

    $sum['boys_u5']     += (int)($oi['boys_u5'] ?? 0);
    $sum['girls_u5']    += (int)($oi['girls_u5'] ?? 0);
    $sum['boys_5_17']   += (int)($oi['boys_5_17'] ?? 0);
    $sum['girls_5_17']  += (int)($oi['girls_5_17'] ?? 0);
    $sum['men_18_59']   += (int)($oi['men_18_59'] ?? 0);
    $sum['women_18_59'] += (int)($oi['women_18_59'] ?? 0);
    $sum['men_60p']     += (int)($oi['men_60p'] ?? 0);
    $sum['women_60p']   += (int)($oi['women_60p'] ?? 0);
    $sum['pwd_count']   += (int)($oi['pwd_count'] ?? 0);
    $sum['hh_count']    += (int)($oi['hh_count'] ?? 0);

    $selected_indicators_details[] = [
        'type' => 'output',
        'name' => $oi['indicator_name'],
        'code' => $oi['indicator_code'],
    ];
}

// 5) Calculate totals WITHOUT PWD & HH (they are displayed separately)
$boys_total   = $sum['boys_u5'] + $sum['boys_5_17'];
$girls_total  = $sum['girls_u5'] + $sum['girls_5_17'];
$men_total    = $sum['men_18_59'] + $sum['men_60p'];
$women_total  = $sum['women_18_59'] + $sum['women_60p'];
$children_tot = $boys_total + $girls_total;

$total_all    = $boys_total + $girls_total + $men_total + $women_total;
$pct_women    = $total_all > 0 ? round(($women_total * 100) / $total_all, 2) : 0;
$pct_children = $total_all > 0 ? round(($children_tot * 100) / $total_all, 2) : 0;

// HH and PWD totals kept separate
$hh_total  = $sum['hh_count'];
$pwd_total = $sum['pwd_count'];

/// 6) Load Region / Zone / Woreda from project details for display
$project_region          = 'N/A';
$project_zone            = 'N/A';
$project_woreda          = 'N/A';
$project_locations_human = 'N/A';

// Ensure projects.code column exists
if (function_exists('ensure_column') && function_exists('table_has_column')) {
    if (!table_has_column($pdo, 'projects', 'code')) {
        ensure_column($pdo, 'projects', 'code', "VARCHAR(100) NULL");
    }
}

$pid = isset($selected_project_id) ? (int)$selected_project_id : 0;

if ($pid > 0) {
    // Check if code column exists to build dynamic query
    $hasCodeColumn = function_exists('table_has_column') && table_has_column($pdo, 'projects', 'code');
    $codeSelect = $hasCodeColumn ? 'p.code,' : '';
    $codeGroupBy = $hasCodeColumn ? 'p.code,' : '';
    
    // Pull main location from projects + any extra locations from project_locations
    $stmt = $pdo->prepare("
        SELECT 
            p.id,
            " . ($codeSelect ?: '') . "
            p.title,
            pr.name AS primary_region,
            pz.name AS primary_zone,
            pw.name AS primary_woreda,
            GROUP_CONCAT(
                DISTINCT CONCAT(
                    COALESCE(r.name, ''),
                    CASE WHEN z.name IS NULL OR z.name = '' THEN '' ELSE CONCAT(' / ', z.name) END,
                    CASE WHEN w.name IS NULL OR w.name = '' THEN '' ELSE CONCAT(' / ', w.name) END
                )
                ORDER BY r.name, z.name, w.name
                SEPARATOR '; '
            ) AS extra_locations
        FROM projects p
        LEFT JOIN regions  pr ON p.region_id = pr.id
        LEFT JOIN zones    pz ON p.zone_id   = pz.id
        LEFT JOIN woredas  pw ON p.woreda_id = pw.id

        LEFT JOIN project_locations pl ON pl.project_id = p.id
        LEFT JOIN regions  r  ON pl.region_id = r.id
        LEFT JOIN zones    z  ON pl.zone_id   = z.id
        LEFT JOIN woredas  w  ON pl.woreda_id = w.id
        WHERE p.id = ?
        GROUP BY p.id, " . ($codeGroupBy ?: '') . " p.title, pr.name, pz.name, pw.name
        LIMIT 1
    ");
    $stmt->execute([$pid]);
    $project_geo = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($project_geo) {
        $project_region = $project_geo['primary_region'] ?? 'N/A';
        $project_zone   = $project_geo['primary_zone']   ?? 'N/A';
        $project_woreda = $project_geo['primary_woreda'] ?? 'N/A';

        // Build main location string
        $main_loc = trim(
            ($project_region ?: '') .
            (($project_zone   ?: '') ? ' / ' . $project_zone   : '') .
            (($project_woreda ?: '') ? ' / ' . $project_woreda : '')
        );

        $extra = trim($project_geo['extra_locations'] ?? '');

        if ($main_loc === '' && $extra !== '') {
            $project_locations_human = $extra;
        } elseif ($main_loc !== '' && $extra !== '') {
            $project_locations_human = $main_loc . ' | ' . $extra;
        } elseif ($main_loc !== '') {
            $project_locations_human = $main_loc;
        }
    

        // Build a nice full-location string
        $main_loc = trim(
            ($project_region ?: '') .
            (($project_zone   ?: '') ? ' / ' . $project_zone   : '') .
            (($project_woreda ?: '') ? ' / ' . $project_woreda : '')
        );

        $extra = trim($project_geo['extra_locations'] ?? '');

        if ($main_loc === '' && $extra !== '') {
            // Only extra locations
            $project_locations_human = $extra;
        } elseif ($main_loc !== '' && $extra !== '') {
            $project_locations_human = $main_loc . ' | ' . $extra;
        } elseif ($main_loc !== '') {
            $project_locations_human = $main_loc;
        }
    }
}

// ------------------------------------------------------
// Render page
// ------------------------------------------------------
require_once __DIR__ . '/../header.php';
?>

<!-- Welcome Section with Enhanced Animations -->
<div class="welcome-container floating-element">
    <h1 class="welcome-title">🚀 Project Planning Suite</h1>
    <p class="welcome-subtitle">Strategic Planning & Impact Measurement Platform</p>
    <div style="display: flex; justify-content: center; gap: 20px; margin-top: 20px; flex-wrap: wrap;">
        <span style="background: rgba(255,255,255,0.2); padding: 10px 20px; border-radius: 25px; backdrop-filter: blur(10px);">
            📊 Monitor Progress
        </span>
        <span style="background: rgba(255,255,255,0.2); padding: 10px 20px; border-radius: 25px; backdrop-filter: blur(10px);">
            👥 Track Beneficiaries
        </span>
        <span style="background: rgba(255,255,255,0.2); padding: 10px 20px; border-radius: 25px; backdrop-filter: blur(10px);">
            📈 Measure Impact
        </span>
        <span style="background: rgba(255,255,255,0.2); padding: 10px 20px; border-radius: 25px; backdrop-filter: blur(10px);">
            🔗 Collaborate Easily
        </span>
    </div>
</div>

<!-- Project List Table with Edit/Delete -->
<div class="card no-print">
    <h2>📋 Projects List (<?php echo count($projects); ?>)</h2>
    <div style="margin-bottom: 15px; display: flex; gap: 10px; flex-wrap: wrap;">
        <a href="projects.php" class="btn btn-primary">➕ Add New Project</a>
        <button class="btn btn-success" onclick="exportProjectsTemplate()">📥 Download Template</button>
        <button class="btn btn-info" onclick="document.getElementById('importTemplateForm').style.display = 'block'">📤 Import Template</button>
    </div>
    
    <?php if ($edit_project): ?>
    <!-- Edit Project Form -->
    <div class="card" style="background: #fff3cd; border: 2px solid #ffc107; margin-bottom: 20px;">
        <h3 style="color: #856404;">✏️ Edit Project: <?php echo h($edit_project['title'] ?? ''); ?></h3>
        <form method="post" onsubmit="return confirm('Update project details?');">
            <input type="hidden" name="action" value="update_project">
            <input type="hidden" name="id" value="<?php echo $edit_project['id']; ?>">
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin-bottom: 15px;">
                <div>
                    <label><strong>📝 Project Code:</strong>
                        <input type="text" name="code" value="<?php echo h($edit_project['code'] ?? ''); ?>" required style="width: 100%; padding: 8px;">
                    </label>
                </div>
                <div style="grid-column: span 2;">
                    <label><strong>📋 Project Title:</strong>
                        <input type="text" name="title" value="<?php echo h($edit_project['title'] ?? ''); ?>" required style="width: 100%; padding: 8px;">
                    </label>
                </div>
                <div>
                    <label><strong>🤝 Donor:</strong>
                        <select name="donor_id" id="edit_project_donor" onchange="handleEditProjectDonorSelection();" style="width: 100%; padding: 8px;">
                            <?php 
                            $currentDonorId = $edit_project['donor_id'] ?? '';
                            $currentDonorName = $edit_project['donor'] ?? '';
                            $showOtherInput = false;
                            
                            if (!empty($currentDonorName) && empty($currentDonorId)) {
                                try {
                                    $findStmt = $pdo->prepare("SELECT id FROM gms_donors WHERE name = ? LIMIT 1");
                                    $findStmt->execute([$currentDonorName]);
                                    $found = $findStmt->fetch(PDO::FETCH_ASSOC);
                                    if ($found) {
                                        $currentDonorId = $found['id'];
                                    } else {
                                        // Donor not found in dropdown, use "OTHER"
                                        $currentDonorId = 'OTHER';
                                        $showOtherInput = true;
                                    }
                                } catch (Exception $e) {
                                    // On error, use "OTHER"
                                    $currentDonorId = 'OTHER';
                                    $showOtherInput = true;
                                }
                            }
                            
                            // If donor_id is "OTHER", show the input
                            if ($currentDonorId === 'OTHER' || $currentDonorId === '') {
                                $showOtherInput = true;
                            }
                            
                            echo donor_dropdown_options($currentDonorId, true, false);
                            ?>
                        </select>
                        <div id="edit_project_donor_other" style="display: <?php echo $showOtherInput ? 'block' : 'none'; ?>; margin-top: 5px;">
                            <input type="text" name="donor_other" placeholder="Enter donor name" value="<?php echo (!empty($currentDonorName) && (empty($currentDonorId) || $currentDonorId === 'OTHER')) ? h($currentDonorName) : ''; ?>" style="width: 100%; padding: 8px;">
                        </div>
                    </label>
                </div>
                <div>
                    <label><strong>📊 Status:</strong>
                        <select name="status" style="width: 100%; padding: 8px;">
                            <option value="active" <?php echo ($edit_project['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="completed" <?php echo ($edit_project['status'] ?? '') === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="on_hold" <?php echo ($edit_project['status'] ?? '') === 'on_hold' ? 'selected' : ''; ?>>On Hold</option>
                            <option value="cancelled" <?php echo ($edit_project['status'] ?? '') === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </label>
                </div>
                <div>
                    <label><strong>📅 Start Date:</strong>
                        <input type="date" name="start_date" value="<?php echo !empty($edit_project['start_date']) ? date('Y-m-d', strtotime($edit_project['start_date'])) : ''; ?>" style="width: 100%; padding: 8px;">
                    </label>
                </div>
                <div>
                    <label><strong>📅 End Date:</strong>
                        <input type="date" name="end_date" value="<?php echo !empty($edit_project['end_date']) ? date('Y-m-d', strtotime($edit_project['end_date'])) : ''; ?>" style="width: 100%; padding: 8px;">
                    </label>
                </div>
            </div>
            
            <div style="display: flex; gap: 10px; margin-top: 15px;">
                <button type="submit" class="btn btn-success">✅ Update Project</button>
                <a href="?" class="btn btn-secondary">❌ Cancel</a>
            </div>
        </form>
    </div>
    <?php endif; ?>
    
    <form id="importTemplateForm" method="post" enctype="multipart/form-data" style="display: none; margin-bottom: 15px; padding: 15px; background: #f8f9fa; border-radius: 8px;">
        <input type="hidden" name="action" value="import_template">
        <label><strong>📁 Select Template File (CSV/Excel):</strong>
            <input type="file" name="template_file" accept=".csv,.xls,.xlsx" required>
        </label>
        <button type="submit" class="btn btn-primary">Upload & Import</button>
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('importTemplateForm').style.display = 'none'">Cancel</button>
    </form>
    
    <?php if (isset($projects) && is_array($projects)): ?>
    <div class="table-container" style="overflow-x: auto;">
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Code</th>
                    <th>Title</th>
                    <th>Donor</th>
                    <th>Status</th>
                    <th>Start Date</th>
                    <th>End Date</th>
                    <th>Location</th>
                    <th style="width: 200px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($projects as $proj): 
                    $projId = (int)$proj['id'];
                    $editSection = $_GET['edit_section'] ?? '';
                ?>
                    <tr>
                        <td><?php echo $projId; ?></td>
                        <td><?php echo h($proj['code'] ?? ''); ?></td>
                        <td><strong><?php echo h($proj['title'] ?? ''); ?></strong></td>
                        <td>
                            <?php if ($editSection === 'donor_' . $projId): ?>
                                <form method="post" style="display: inline-block; width: 100%;" onsubmit="return confirm('Update donor for this project?');">
                                    <input type="hidden" name="action" value="update_project">
                                    <input type="hidden" name="id" value="<?php echo $projId; ?>">
                                    <input type="hidden" name="title" value="<?php echo h($proj['title'] ?? ''); ?>">
                                    <input type="hidden" name="code" value="<?php echo h($proj['code'] ?? ''); ?>">
                                    <input type="hidden" name="status" value="<?php echo h($proj['status'] ?? 'active'); ?>">
                                    <input type="hidden" name="start_date" value="<?php echo !empty($proj['start_date']) ? date('Y-m-d', strtotime($proj['start_date'])) : ''; ?>">
                                    <input type="hidden" name="end_date" value="<?php echo !empty($proj['end_date']) ? date('Y-m-d', strtotime($proj['end_date'])) : ''; ?>">
                                    <div style="display: flex; gap: 5px; align-items: center;">
                                        <select name="donor_id" id="planning_donor_<?php echo $projId; ?>" onchange="handlePlanningDonorSelection(<?php echo $projId; ?>);" style="flex: 1; min-width: 200px;">
                                            <?php 
                                            $currentDonorId = $proj['donor_id'] ?? '';
                                            $currentDonorName = $proj['donor'] ?? '';
                                            if (!empty($currentDonorName) && empty($currentDonorId)) {
                                                // Try to find donor by name
                                                try {
                                                    $findStmt = $pdo->prepare("SELECT id FROM gms_donors WHERE name = ? LIMIT 1");
                                                    $findStmt->execute([$currentDonorName]);
                                                    $found = $findStmt->fetch(PDO::FETCH_ASSOC);
                                                    if ($found) $currentDonorId = $found['id'];
                                                } catch (Exception $e) {}
                                            }
                                            echo donor_dropdown_options($currentDonorId, true, false);
                                            ?>
                                        </select>
                                        <div id="planning_donor_other_<?php echo $projId; ?>" style="display: none; flex: 1;">
                                            <input type="text" name="donor_other" placeholder="Enter donor name" value="<?php echo (!empty($currentDonorName) && empty($currentDonorId)) ? h($currentDonorName) : ''; ?>" style="width: 100%;">
                                        </div>
                                        <button type="submit" class="btn btn-sm btn-success">✓</button>
                                        <a href="?" class="btn btn-sm btn-secondary">✗</a>
                                    </div>
                                </form>
                            <?php else: ?>
                                <?php echo h($proj['donor'] ?? ''); ?>
                                <button class="btn btn-sm btn-link" onclick="window.location.href='?edit_section=donor_<?php echo $projId; ?>'" title="Edit Donor" style="padding: 2px 5px; font-size: 0.8em;">✏️</button>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge badge-<?php echo ($proj['status'] ?? 'active') === 'active' ? 'success' : 'warning'; ?>">
                            <?php echo ucfirst($proj['status'] ?? 'active'); ?>
                        </span></td>
                        <td><?php echo !empty($proj['start_date']) ? date('Y-m-d', strtotime($proj['start_date'])) : 'N/A'; ?></td>
                        <td><?php echo !empty($proj['end_date']) ? date('Y-m-d', strtotime($proj['end_date'])) : 'N/A'; ?></td>
                        <td><?php 
                            $loc = [];
                            if (!empty($proj['region_name'])) $loc[] = $proj['region_name'];
                            if (!empty($proj['zone_name'])) $loc[] = $proj['zone_name'];
                            if (!empty($proj['woreda_name'])) $loc[] = $proj['woreda_name'];
                            echo $loc ? h(implode(' / ', $loc)) : 'N/A';
                        ?></td>
                        <td>
                            <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                <button class="btn btn-sm btn-warning" onclick="window.location.href='?edit_project=<?php echo $projId; ?>'" title="Edit Project Details">
                                    ✏️ Edit
                                </button>
                                <button class="btn btn-sm btn-primary" onclick="editProject(<?php echo $projId; ?>, 'results')" title="Edit Results">
                                    ✏️ Results
                                </button>
                                <button class="btn btn-sm btn-primary" onclick="editProject(<?php echo $projId; ?>, 'indicators')" title="Edit Indicators">
                                    📊 Indicators
                                </button>
                                <button class="btn btn-sm btn-primary" onclick="editProject(<?php echo $projId; ?>, 'activities')" title="Edit Activities">
                                    🎯 Activities
                                </button>
                                <button class="btn btn-sm btn-primary" onclick="editProject(<?php echo $projId; ?>, 'beneficiaries')" title="Edit Beneficiaries">
                                    👥 Beneficiaries
                                </button>
                                <form method="post" style="display: inline;" onsubmit="return confirm('Delete project: <?php echo addslashes($proj['title']); ?>? This will delete all related data!');">
                                    <input type="hidden" name="action" value="delete_project">
                                    <input type="hidden" name="id" value="<?php echo $projId; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="Delete Project">🗑️</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <p>No projects found. <a href="projects.php">Create your first project</a></p>
    <?php endif; ?>
</div>

<!-- Project Selection -->
<div class="card project-selector">
    <h3 style="color: white; margin-bottom: 15px;">📋 Select Project</h3>
    <select id="projectSelector" onchange="window.location.href = '?project_id=' + this.value">
        <option value="">-- Select a Project --</option>
        <?php foreach ($projects as $proj): ?>
            <option value="<?php echo $proj['id']; ?>" 
                <?php echo ($selected_project_id == $proj['id']) ? 'selected' : ''; ?>>
                📁 <?php echo h($proj['title']); ?> 
                (<?php echo h($proj['code'] ?? 'No Code'); ?>)
            </option>
        <?php endforeach; ?>
    </select>
</div>

<?php if ($message): ?>
    <div class="card success-animation">
        <div class="badge badge-success">
            <?php echo h($message); ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="card" style="border-left:6px solid #c0392b;">
        <div class="badge" style="background:#c0392b;color:#fff;">
            <?php echo h($error); ?>
        </div>
    </div>
<?php endif; ?>

<!-- Guidance Messages -->
<div class="guidance-message">
    <h4>💡 Quick Start Guide</h4>
    <p>1. Select a project above • 2. Add strategic results • 3. Define indicators • 4. Plan activities • 5. Calculate beneficiaries • 6. Export & Share</p>
</div>

<?php if ($selected_project_id && $selected_project_details): ?>
<!-- Project Details Display -->
<div class="card">
    <h2 style="display: flex; align-items: center; gap: 10px;">
        <span style="font-size: 1.5rem;">🔍</span>
        Project Details: <?php echo h($selected_project_details['title']); ?>
    </h2>
    
    <div class="beneficiary-grid">
        <div class="beneficiary-card">
            <h4>📍 Location</h4>
            <div class="number"><?php 
                $location_parts = [];
                if (!empty($selected_project_details['region_name'])) $location_parts[] = $selected_project_details['region_name'];
                if (!empty($selected_project_details['zone_name'])) $location_parts[] = $selected_project_details['zone_name'];
                if (!empty($selected_project_details['woreda_name'])) $location_parts[] = $selected_project_details['woreda_name'];
                echo $location_parts ? h(implode(', ', $location_parts)) : 'Not specified';
            ?></div>
        </div>
        
        <div class="beneficiary-card">
            <h4>⏱️ Duration</h4>
            <div class="number">
                <?php 
                if (isset($selected_project_details['start_date']) && isset($selected_project_details['end_date']) && 
                    $selected_project_details['start_date'] && $selected_project_details['end_date']) {
                    $start = new DateTime($selected_project_details['start_date']);
                    $end = new DateTime($selected_project_details['end_date']);
                    $interval = $start->diff($end);
                    echo $interval->m + ($interval->y * 12) . ' months';
                } else {
                    echo h($selected_project_details['lifespan'] ?? 'Not specified');
                }
                ?>
            </div>
        </div>
        
        <div class="beneficiary-card">
            <h4>💰 Total Fund (USD)</h4>
            <div class="number">$<?php echo number_format($selected_project_details['total_fund_usd'] ?? 0, 2); ?></div>
        </div>
        
        <div class="beneficiary-card">
            <h4>🤝 Donor</h4>
            <div class="number"><?php echo h($selected_project_details['donor'] ?? 'Not specified'); ?></div>
        </div>
    </div>
    
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin-top: 20px;">
        <div>
            <strong>📞 Owner:</strong> <?php echo h($selected_project_details['owner_name'] ?? 'Not specified'); ?><br>
            <strong>📧 Email:</strong> <?php echo h($selected_project_details['owner_email'] ?? 'Not specified'); ?>
        </div>
        <div>
            <strong>🏢 Implementing Partner:</strong> <?php echo h($selected_project_details['ip_name'] ?? 'Not specified'); ?><br>
            <strong>📋 Status:</strong> <span style="text-transform: capitalize;"><?php echo h($selected_project_details['status'] ?? 'active'); ?></span>
        </div>
    </div>
</div>

<!-- Progress Indicator -->
<div class="card">
    <div class="progress-container">
        <div class="progress-bar" style="width: 65%;"></div>
    </div>
    <div style="padding: 20px; text-align: center;">
        <h3 style="color: var(--primary-color); margin: 0;">📊 Planning Progress: 65% Complete</h3>
        <p style="color: #6c757d; margin: 10px 0 0 0;">Continue building your project framework below</p>
    </div>
</div>

<!-- Quick Actions Bar -->
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; padding: 20px;">
        <h3 style="margin: 0; color: var(--primary-color);">⚡ Quick Actions</h3>
        <div class="export-options">
            <button class="btn btn-primary" onclick="window.print()">
                🖨️ Print / PDF
            </button>
            <a href="?action=export_logframe_csv<?php echo $selected_project_id ? '&project_id='.$selected_project_id : ''; ?>" class="btn btn-success">
                📊 Export Excel
            </a>
            <a href="?action=export_logframe_word<?php echo $selected_project_id ? '&project_id='.$selected_project_id : ''; ?>" class="btn btn-info">
                📝 Export Word
            </a>
            <a href="?action=export_logframe_excel<?php echo $selected_project_id ? '&project_id='.$selected_project_id : ''; ?>" class="btn btn-warning">
                📊 Export Excel
            </a>
            <a href="?action=export_template" class="btn btn-secondary">
                📥 Download Template
            </a>
        </div>
    </div>
</div>

<!-- COMPREHENSIVE VIEW & MANAGEMENT SECTION -->
<div class="card" id="comprehensive-view">
    <div class="section-toggle" onclick="toggleSection('comprehensive-view-content')">
        <span>📊</span>
        <span>Comprehensive View & Management</span>
        <div style="margin-left: auto; display: flex; gap: 5px;">
            <button class="btn btn-sm btn-success" onclick="exportComprehensiveView('all')" title="Export All">📥 Export All</button>
            <button class="btn btn-sm btn-info" onclick="window.print()" title="Print">🖨️ Print</button>
        </div>
    </div>
    <div id="comprehensive-view-content" class="section-content" style="display: block;">
        <div style="margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap;">
            <button class="btn btn-primary" onclick="exportComprehensiveView('results')">📊 Export Results</button>
            <button class="btn btn-primary" onclick="exportComprehensiveView('impact_indicators')">🎯 Export Impact Indicators</button>
            <button class="btn btn-primary" onclick="exportComprehensiveView('outcome_indicators')">📈 Export Outcome Indicators</button>
            <button class="btn btn-primary" onclick="exportComprehensiveView('output_indicators')">📊 Export Output Indicators</button>
            <button class="btn btn-primary" onclick="exportComprehensiveView('activities')">🛠️ Export Activities</button>
            <button class="btn btn-success" onclick="exportComprehensiveView('all')">📥 Export All Combined</button>
        </div>

        <!-- Results Section -->
        <div style="margin-bottom: 30px;">
            <h3>🎯 Results (Impact/Outcome/Output)</h3>
            <?php if (isset($results) && is_array($results)): ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Level</th>
                            <th>Code</th>
                            <th>Description</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($results)): ?>
<tr><td colspan=\"4\" style=\"text-align:center;color:#666;padding:12px;\">No results saved yet for this project.</td></tr>
<?php endif; ?>
<?php foreach ($results as $r): ?>
                        <tr>
                            <td><span class="badge badge-<?php echo $r['level'] === 'impact' ? 'danger' : ($r['level'] === 'outcome' ? 'warning' : 'info'); ?>"><?php echo ucfirst($r['level']); ?></span></td>
                            <td><strong><?php echo h($r['code'] ?? 'N/A'); ?></strong></td>
                            <td><?php echo h($r['name'] ?? ''); ?></td>
                            <td>
                                <button class="btn btn-sm btn-primary" onclick="editResult(<?php echo $r['id']; ?>)">✏️ Edit</button>
                                <form method="post" style="display: inline;" onsubmit="return confirm('Delete this result?');">
                                    <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                    <button type="submit" name="delete_result" class="btn btn-sm btn-danger">🗑️ Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p>No results added yet. <a href="#results" onclick="toggleSection('add-result')">Add your first result</a></p>
            <?php endif; ?>
        </div>

        <!-- Impact Indicators Section -->
        <div style="margin-bottom: 30px;">
            <h3>🎯 Impact Indicators</h3>
            <?php if (isset($impactIndicators) && is_array($impactIndicators)): ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Result Code</th>
                            <th>Indicator Code</th>
                            <th>Indicator Name</th>
                            <th>Beneficiary Type</th>
                            <th>Total Target</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($impactIndicators)): ?>
<tr><td colspan=\"18\" style=\"text-align:center;color:#666;padding:12px;\">No impact indicators saved yet for this project.</td></tr>
<?php endif; ?>
<?php foreach ($impactIndicators as $ind): ?>
                        <tr>
                            <td><?php echo h($ind['result_code'] ?? 'N/A'); ?></td>
                            <td><strong><?php echo h($ind['indicator_code'] ?? 'N/A'); ?></strong></td>
                            <td><?php echo h($ind['indicator_name'] ?? ''); ?></td>
                            <td><?php echo h($ind['beneficiary_type'] ?? 'host'); ?></td>
                            <td><?php echo number_format($ind['total_target'] ?? 0); ?></td>
                            <td>
                                <button class="btn btn-sm btn-primary" onclick="editImpactIndicator(<?php echo $ind['id']; ?>)">✏️ Edit</button>
                                <form method="post" style="display: inline;" onsubmit="return confirm('Delete this indicator?');">
                                    <input type="hidden" name="id" value="<?php echo $ind['id']; ?>">
                                    <button type="submit" name="delete_impact_indicator" class="btn btn-sm btn-danger">🗑️ Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p>No impact indicators added yet. <a href="#indicators" onclick="toggleSection('add-impact')">Add your first impact indicator</a></p>
            <?php endif; ?>
        </div>

        <!-- Outcome Indicators Section -->
        <div style="margin-bottom: 30px;">
            <h3>📈 Outcome Indicators</h3>
            <?php if (isset($outcomeIndicators) && is_array($outcomeIndicators)): ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Result Code</th>
                            <th>Indicator Code</th>
                            <th>Indicator Name</th>
                            <th>Beneficiary Type</th>
                            <th>Total Target</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($outcomeIndicators)): ?>
<tr><td colspan=\"18\" style=\"text-align:center;color:#666;padding:12px;\">No outcome indicators saved yet for this project.</td></tr>
<?php endif; ?>
<?php foreach ($outcomeIndicators as $ind): ?>
                        <tr>
                            <td><?php echo h($ind['result_code'] ?? 'N/A'); ?></td>
                            <td><strong><?php echo h($ind['indicator_code'] ?? 'N/A'); ?></strong></td>
                            <td><?php echo h($ind['indicator_name'] ?? ''); ?></td>
                            <td><?php echo h($ind['beneficiary_type'] ?? 'host'); ?></td>
                            <td><?php echo number_format($ind['total_target'] ?? 0); ?></td>
                            <td>
                                <button class="btn btn-sm btn-primary" onclick="editOutcomeIndicator(<?php echo $ind['id']; ?>)">✏️ Edit</button>
                                <form method="post" style="display: inline;" onsubmit="return confirm('Delete this indicator?');">
                                    <input type="hidden" name="id" value="<?php echo $ind['id']; ?>">
                                    <button type="submit" name="delete_outcome_indicator" class="btn btn-sm btn-danger">🗑️ Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p>No outcome indicators added yet. <a href="#indicators" onclick="toggleSection('add-outcome')">Add your first outcome indicator</a></p>
            <?php endif; ?>
        </div>

        <!-- Output Indicators Section -->
        <div style="margin-bottom: 30px;">
            <h3>📊 Output Indicators</h3>
            <?php if (isset($outputIndicators) && is_array($outputIndicators)): ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Result Code</th>
                            <th>Indicator Code</th>
                            <th>Indicator Name</th>
                            <th>Beneficiary Type</th>
                            <th>Total Target</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($outputIndicators)): ?>
<tr><td colspan=\"18\" style=\"text-align:center;color:#666;padding:12px;\">No output indicators saved yet for this project.</td></tr>
<?php endif; ?>
<?php foreach ($outputIndicators as $ind): ?>
                        <tr>
                            <td><?php echo h($ind['result_code'] ?? 'N/A'); ?></td>
                            <td><strong><?php echo h($ind['indicator_code'] ?? 'N/A'); ?></strong></td>
                            <td><?php echo h($ind['indicator_name'] ?? ''); ?></td>
                            <td><?php echo h($ind['beneficiary_type'] ?? 'host'); ?></td>
                            <td><?php echo number_format($ind['target_total'] ?? 0); ?></td>
                            <td>
                                <button class="btn btn-sm btn-primary" onclick="editOutputIndicator(<?php echo $ind['id']; ?>)">✏️ Edit</button>
                                <form method="post" style="display: inline;" onsubmit="return confirm('Delete this indicator?');">
                                    <input type="hidden" name="id" value="<?php echo $ind['id']; ?>">
                                    <button type="submit" name="delete_output_indicator" class="btn btn-sm btn-danger">🗑️ Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p>No output indicators added yet. <a href="#indicators" onclick="toggleSection('add-output')">Add your first output indicator</a></p>
            <?php endif; ?>
        </div>

        <!-- Activities Section -->
        <div style="margin-bottom: 30px;">
            <h3>🛠️ Activities</h3>
            <?php if (isset($activities) && is_array($activities)): ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Output Code</th>
                            <th>Activity Code</th>
                            <th>Activity Name</th>
                            <th>Unit</th>
                            <th>Target</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($activities)): ?>
<tr><td colspan=\"6\" style=\"text-align:center;color:#666;padding:12px;\">No activities saved yet for this project.</td></tr>
<?php endif; ?>
<?php foreach ($activities as $a): ?>
                        <tr>
                            <td><?php echo h($a['output_code'] ?? 'N/A'); ?></td>
                            <td><strong><?php echo h($a['code'] ?? 'N/A'); ?></strong></td>
                            <td><?php echo h($a['name'] ?? ''); ?></td>
                            <td><?php echo h($a['unit'] ?? 'N/A'); ?></td>
                            <td><?php echo number_format($a['target'] ?? 0); ?></td>
                            <td>
                                <button class="btn btn-sm btn-primary" onclick="editActivity(<?php echo $a['id']; ?>)">✏️ Edit</button>
                                <form method="post" style="display: inline;" onsubmit="return confirm('Delete this activity?');">
                                    <input type="hidden" name="id" value="<?php echo $a['id']; ?>">
                                    <button type="submit" name="delete_activity" class="btn btn-sm btn-danger">🗑️ Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p>No activities added yet. <a href="#activities" onclick="toggleSection('add-activity')">Add your first activity</a></p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Social Sharing Section -->
<div class="card">
    <div class="section-toggle" onclick="toggleSection('social-share')">
        <span>📤</span>
        <span>Share Planning</span>
    </div>
    <div id="social-share" class="section-content">
        <form method="post">
            <div class="form-row">
                <label>
                    <strong>📧 Share Via:</strong>
                    <select name="share_type" class="share-type" style="padding: 12px; border-radius: 8px; border: 2px solid #e9ecef;">
                        <option value="link">🔗 Shareable Link</option>
                        <option value="email">📧 Email</option>
                        <option value="whatsapp">💬 WhatsApp</option>
                        <option value="telegram">📱 Telegram</option>
                    </select>
                </label>
                <label class="email-field" style="display: none;">
                    <strong>👤 Recipient Email:</strong>
                    <input type="email" name="recipient_email" placeholder="Enter email address" style="padding: 12px; border-radius: 8px; border: 2px solid #e9ecef;" required>
                </label>
            </div>
            <div class="form-row email-fields" style="display: none;">
                <label>
                    <strong>📝 Email Subject:</strong>
                    <input type="text" name="email_subject" value="Project Planning Document - <?php echo h($selected_project_details['title']); ?>" style="padding: 12px; border-radius: 8px; border: 2px solid #e9ecef;">
                </label>
                <label>
                    <strong>💬 Message:</strong>
                    <textarea name="email_message" placeholder="Add a personal message..." style="padding: 12px; border-radius: 8px; border: 2px solid #e9ecef; height: 100px;"></textarea>
                </label>
            </div>
            <button type="submit" name="share_planning" class="btn btn-primary">
                🚀 Share Now
            </button>
        </form>
        
        <div class="social-share">
            <a href="#" class="share-btn share-email" onclick="shareVia('email')">
                📧 Email
            </a>
            <a href="#" class="share-btn share-whatsapp" onclick="shareVia('whatsapp')">
                💬 WhatsApp
            </a>
            <a href="#" class="share-btn share-telegram" onclick="shareVia('telegram')">
                📱 Telegram
            </a>
            <a href="#" class="share-btn share-link" onclick="copyShareLink()">
                🔗 Copy Link
            </a>
        </div>
    </div>
</div>

<!-- ADD RESULT Section -->
<div class="card" id="results">
    <div class="section-toggle" onclick="toggleSection('add-result')">
        <span>🎯</span>
        <span>Add Strategic Result</span>
        <div style="margin-left: auto; display: flex; gap: 5px;">
            <button class="btn btn-sm btn-success" onclick="window.print()" title="Print this section">🖨️ Print</button>
            <button class="btn btn-sm btn-info" onclick="exportSection('results')" title="Export this section">📥 Export</button>
        </div>
    </div>
    <div id="add-result" class="section-content">
        <form method="post">
            \1
                <!-- track deleted beneficiary row ids -->
                <div id="beneficiaryDeletedIdsContainer"></div>
<?php if ($edit_result): ?>
                <input type="hidden" name="id" value="<?php echo $edit_result['id']; ?>">
                <div class="badge badge-info" style="margin-bottom: 15px;">✏️ Editing Result: <?php echo h($edit_result['code'] ?? ''); ?></div>
            <?php endif; ?>
            <div class="form-row">
                <label>
                    <strong>📊 Level:</strong>
                    <select name="level">
                        <option value="impact" <?php echo ($edit_result && $edit_result['level'] === 'impact') ? 'selected' : ''; ?>>🎯 Impact</option>
                        <option value="outcome" <?php echo ($edit_result && $edit_result['level'] === 'outcome') ? 'selected' : ''; ?>>📈 Outcome</option>
                        <option value="output" <?php echo (!$edit_result || $edit_result['level'] === 'output') ? 'selected' : ''; ?>>📊 Output</option>
                    </select>
                </label>
                <label>
                    <strong>🔤 Code:</strong>
                    <input type="text" name="code" placeholder="e.g. OUT1" value="<?php echo $edit_result ? h($edit_result['code'] ?? '') : ''; ?>" required>
                </label>
            </div>
            <div class="form-row">
                <label style="flex: 2;">
                    <strong>📝 Description:</strong>
                    <input type="text" name="name" placeholder="Enter strategic result description" value="<?php echo $edit_result ? h($edit_result['name'] ?? '') : ''; ?>" required>
                </label>
            </div>
            <div class="form-row">
                <?php if ($edit_result): ?>
                    <button type="submit" name="update_result" class="btn btn-success">
                        ✅ Update Result
                    </button>
                    <a href="?project_id=<?php echo $selected_project_id; ?>" class="btn btn-secondary">❌ Cancel</a>
                <?php else: ?>
                    <button type="submit" name="add_result" class="btn btn-success">
                        💾 Save Result
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="clearForm(this.form)">
                        🗑️ Clear
                    </button>
                <?php endif; ?>
            </div>
        </form>

        <!-- Results Table -->
        <?php if (isset($results) && is_array($results)): ?>
        <div style="margin-top: 30px;">
            <h4>📋 Existing Results</h4>
<p style="margin:6px 0 0;color:#666;">Saved in MySQL table: <code>results_chain</code> (filtered by project).</p>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Level</th>
                            <th>Code</th>
                            <th>Description</th>
                            <th style="width: 180px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $r): ?>
                            <tr>
                                <form method="post">
                                    <input type="hidden" name="project_id" value="<?php echo $selected_project_id; ?>">
                                    <td>
                                        <select name="level">
                                            <option value="impact" <?php echo ($r['level']==='impact'?'selected':''); ?>>Impact</option>
                                            <option value="outcome" <?php echo ($r['level']==='outcome'?'selected':''); ?>>Outcome</option>
                                            <option value="output" <?php echo ($r['level']==='output'?'selected':''); ?>>Output</option>
                                        </select>
                                    </td>
                                    <td><input type="text" name="code" value="<?php echo h($r['code'] ?? ''); ?>" style="width: 100%; padding: 8px;"></td>
                                    <td><input type="text" name="name" value="<?php echo h($r['name'] ?? ''); ?>" required style="width: 100%; padding: 8px;"></td>
                                    <td>
                                        <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                        <button type="submit" name="update_result" class="btn btn-success btn-sm">✅ Update</button>
                                        <button type="submit" name="delete_result" class="btn btn-warning btn-sm" 
                                                onclick="return confirm('Delete this result and all linked indicators/activities?');">
                                            🗑️ Delete
                                        </button>
                                    </td>
                                </form>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ADD IMPACT INDICATOR Section -->
<div class="card" id="indicators">
    <div class="section-toggle" onclick="toggleSection('add-impact')">
        <span>🎯</span>
        <span>Add Impact Indicator</span>
        <div style="margin-left: auto; display: flex; gap: 5px;">
            <button class="btn btn-sm btn-success" onclick="window.print()" title="Print this section">🖨️ Print</button>
            <button class="btn btn-sm btn-info" onclick="exportSection('indicators')" title="Export this section">📥 Export</button>
        </div>
    </div>
    <div id="add-impact" class="section-content">
        <form method="post" class="indicator-form" data-scope="impact-add">
            <input type="hidden" name="project_id" value="<?php echo $selected_project_id; ?>">
            <?php if ($edit_impact_indicator): ?>
                <input type="hidden" name="id" value="<?php echo $edit_impact_indicator['id']; ?>">
                <div class="badge badge-info" style="margin-bottom: 15px;">✏️ Editing Impact Indicator: <?php echo h($edit_impact_indicator['indicator_code'] ?? ''); ?></div>
            <?php endif; ?>
            <div class="form-row">
                <label>Impact
                    <select name="result_id" required>
                        <option value="">-- Select Impact --</option>
                        <?php foreach ($impactResults as $r): ?>
                            <option value="<?php echo $r['id']; ?>" <?php echo ($edit_impact_indicator && $edit_impact_indicator['result_id'] == $r['id']) ? 'selected' : ''; ?>>
                                <?php echo h((($r['code'] ?? '') ? $r['code'].' - ' : '').$r['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Beneficiary type
                    <select name="beneficiary_type">
                        <?php foreach ($benefTypes as $key => $label): ?>
                            <option value="<?php echo h($key); ?>" <?php echo ($edit_impact_indicator && ($edit_impact_indicator['beneficiary_type'] ?? 'host') === $key) ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="form-row">
                <label>Indicator code
                    <input type="text" name="indicator_code" placeholder="e.g. IM1" value="<?php echo $edit_impact_indicator ? h($edit_impact_indicator['indicator_code'] ?? '') : ''; ?>">
                </label>
                <label>Indicator name
                    <input type="text" name="indicator_name" placeholder="Enter indicator description" value="<?php echo $edit_impact_indicator ? h($edit_impact_indicator['indicator_name'] ?? '') : ''; ?>" required>
                </label>
            </div>
            <div class="form-row">
                <label>Unit type
                    <select name="unit_type" class="unit-type">
                        <?php foreach ($unitTypes as $ut): ?>
                            <option value="<?php echo h($ut); ?>" <?php echo ($edit_impact_indicator && ($edit_impact_indicator['unit_type'] ?? 'persons') === $ut) ? 'selected' : ''; ?>><?php echo h($ut); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Input mode
                    <select name="input_mode" class="input-mode">
                        <?php foreach ($inputModes as $key => $label): ?>
                            <option value="<?php echo h($key); ?>" <?php echo ($edit_impact_indicator && ($edit_impact_indicator['input_mode'] ?? 'sadd') === $key) ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="form-row sadd-row">
                <label>Boys &lt;5
                    <input type="number" name="boys_u5" min="0" value="<?php echo $edit_impact_indicator ? (int)($edit_impact_indicator['boys_u5'] ?? 0) : 0; ?>">
                </label>
                <label>Girls &lt;5
                    <input type="number" name="girls_u5" min="0" value="<?php echo $edit_impact_indicator ? (int)($edit_impact_indicator['girls_u5'] ?? 0) : 0; ?>">
                </label>
                <label>Boys 5–17
                    <input type="number" name="boys_5_17" min="0" value="<?php echo $edit_impact_indicator ? (int)($edit_impact_indicator['boys_5_17'] ?? 0) : 0; ?>">
                </label>
                <label>Girls 5–17
                    <input type="number" name="girls_5_17" min="0" value="<?php echo $edit_impact_indicator ? (int)($edit_impact_indicator['girls_5_17'] ?? 0) : 0; ?>">
                </label>
            </div>
            <div class="form-row sadd-row">
                <label>Men 18–59
                    <input type="number" name="men_18_59" min="0" value="<?php echo $edit_impact_indicator ? (int)($edit_impact_indicator['men_18_59'] ?? 0) : 0; ?>">
                </label>
                <label>Women 18–59
                    <input type="number" name="women_18_59" min="0" value="<?php echo $edit_impact_indicator ? (int)($edit_impact_indicator['women_18_59'] ?? 0) : 0; ?>">
                </label>
                <label>Men 60+
                    <input type="number" name="men_60p" min="0" value="<?php echo $edit_impact_indicator ? (int)($edit_impact_indicator['men_60p'] ?? 0) : 0; ?>">
                </label>
                <label>Women 60+
                    <input type="number" name="women_60p" min="0" value="<?php echo $edit_impact_indicator ? (int)($edit_impact_indicator['women_60p'] ?? 0) : 0; ?>">
                </label>
                <label>PWD
                    <input type="number" name="pwd_count" min="0" value="<?php echo $edit_impact_indicator ? (int)($edit_impact_indicator['pwd_count'] ?? 0) : 0; ?>">
                </label>
                <label>Households (HH)
                    <input type="number" name="hh_count" min="0" value="<?php echo $edit_impact_indicator ? (int)($edit_impact_indicator['hh_count'] ?? 0) : 0; ?>">
                </label>
            </div>
            <div class="form-row">
                <label>Total target (auto for person-count)
                    <input type="number" step="0.01" name="total_target" min="0" class="total-target" value="<?php echo $edit_impact_indicator ? (float)($edit_impact_indicator['total_target'] ?? 0) : 0; ?>">
                </label>
                <label>&nbsp;
                    <?php if ($edit_impact_indicator): ?>
                        <button type="submit" name="update_impact_indicator" class="btn btn-success">
                            ✅ Update Impact Indicator
                        </button>
                        <a href="?project_id=<?php echo $selected_project_id; ?>" class="btn btn-secondary">❌ Cancel</a>
                    <?php else: ?>
                        <button type="submit" name="add_impact_indicator" class="btn btn-success">
                            💾 Add &amp; Save Impact Indicator
                        </button>
                    <?php endif; ?>
                </label>
            </div>
        </form>

        <!-- Impact Indicators Table -->
        <?php if (isset($impactIndicators) && is_array($impactIndicators)): ?>
        <div style="margin-top: 30px;">
            <h4>📋 Existing Impact Indicators</h4>
<p style="margin:6px 0 0;color:#666;">Saved in MySQL table: <code>impact_indicators</code> (filtered by project).</p>
            <div style="overflow-x: auto;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Impact</th>
                            <th>Beneficiary</th>
                            <th>Code</th>
                            <th>Indicator</th>
                            <th>Unit</th>
                            <th>Input Mode</th>
                            <th>Boys &lt;5</th>
                            <th>Girls &lt;5</th>
                            <th>Boys 5–17</th>
                            <th>Girls 5–17</th>
                            <th>Men 18–59</th>
                            <th>Women 18–59</th>
                            <th>Men 60+</th>
                            <th>Women 60+</th>
                            <th>PWD</th>
                            <th>HH</th>
                            <th>Total Target</th>
                            <th style="width: 180px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($impactIndicators as $oi): ?>
                            <tr>
                                <form method="post" class="indicator-form">
                                    <input type="hidden" name="project_id" value="<?php echo $selected_project_id; ?>">
                                    <td>
                                        <select name="result_id">
                                            <?php foreach ($impactResults as $r): ?>
                                                <?php $sel = ($r['id']==$oi['result_id'])?'selected':''; ?>
                                                <option value="<?php echo $r['id']; ?>" <?php echo $sel; ?>>
                                                    <?php echo h((($r['code'] ?? '') ? $r['code'].' - ' : '').$r['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <select name="beneficiary_type">
                                            <?php foreach ($benefTypes as $key => $label): ?>
                                                <option value="<?php echo h($key); ?>"
                                                    <?php echo ($key===($oi['beneficiary_type'] ?? 'host')?'selected':''); ?>>
                                                    <?php echo h($label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><input type="text" name="indicator_code" value="<?php echo h($oi['indicator_code'] ?? ''); ?>" style="width: 100%; padding: 5px;"></td>
                                    <td><input type="text" name="indicator_name" value="<?php echo h($oi['indicator_name'] ?? ''); ?>" required style="width: 100%; padding: 5px;"></td>
                                    <td>
                                        <select name="unit_type" class="unit-type" style="padding: 5px;">
                                            <?php foreach ($unitTypes as $ut): ?>
                                                <option value="<?php echo h($ut); ?>"
                                                    <?php echo ($ut===($oi['unit_type'] ?? 'persons')?'selected':''); ?>>
                                                    <?php echo h($ut); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <select name="input_mode" class="input-mode" style="padding: 5px;">
                                            <?php foreach ($inputModes as $key => $label): ?>
                                                <option value="<?php echo h($key); ?>"
                                                    <?php echo ($key===($oi['input_mode'] ?? 'sadd')?'selected':''); ?>>
                                                    <?php echo h($label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>

                                    <!-- SADD + HH columns -->
                                    <td><input type="number" name="boys_u5" min="0" value="<?php echo (int)($oi['boys_u5'] ?? 0); ?>" style="width: 80px; padding: 5px;"></td>
                                    <td><input type="number" name="girls_u5" min="0" value="<?php echo (int)($oi['girls_u5'] ?? 0); ?>" style="width: 80px; padding: 5px;"></td>
                                    <td><input type="number" name="boys_5_17" min="0" value="<?php echo (int)($oi['boys_5_17'] ?? 0); ?>" style="width: 80px; padding: 5px;"></td>
                                    <td><input type="number" name="girls_5_17" min="0" value="<?php echo (int)($oi['girls_5_17'] ?? 0); ?>" style="width: 80px; padding: 5px;"></td>
                                    <td><input type="number" name="men_18_59" min="0" value="<?php echo (int)($oi['men_18_59'] ?? 0); ?>" style="width: 80px; padding: 5px;"></td>
                                    <td><input type="number" name="women_18_59" min="0" value="<?php echo (int)($oi['women_18_59'] ?? 0); ?>" style="width: 80px; padding: 5px;"></td>
                                    <td><input type="number" name="men_60p" min="0" value="<?php echo (int)($oi['men_60p'] ?? 0); ?>" style="width: 80px; padding: 5px;"></td>
                                    <td><input type="number" name="women_60p" min="0" value="<?php echo (int)($oi['women_60p'] ?? 0); ?>" style="width: 80px; padding: 5px;"></td>
                                    <td><input type="number" name="pwd_count" min="0" value="<?php echo (int)($oi['pwd_count'] ?? 0); ?>" style="width: 80px; padding: 5px;"></td>
                                    <td><input type="number" name="hh_count" min="0" value="<?php echo (int)($oi['hh_count'] ?? 0); ?>" style="width: 80px; padding: 5px;"></td>

                                    <td>
                                        <input type="number" step="0.01" name="total_target" min="0"
                                               value="<?php echo (float)($oi['total_target'] ?? 0); ?>"
                                               class="total-target" style="width: 100px; padding: 5px;">
                                    </td>
                                    <td>
                                        <input type="hidden" name="id" value="<?php echo (int)$oi['id']; ?>">
                                        <button type="submit" name="update_impact_indicator" class="btn btn-success btn-sm">
                                              ✏️ Edit &amp; Save
                                            ✅ Update
                                        </button>
                                        <button type="submit" name="delete_impact_indicator" class="btn btn-warning btn-sm"
                                                onclick="return confirm('Delete this impact indicator?');">
                                            🗑️ Delete
                                        </button>
                                    </td>
                                </form>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ADD OUTCOME INDICATOR Section -->
<div class="card">
    <div class="section-toggle" onclick="toggleSection('add-outcome')">
        <span>📈</span>
        <span>Add Outcome Indicator</span>
    </div>
    <div id="add-outcome" class="section-content">
        <form method="post" class="indicator-form" data-scope="outcome-add">
            <input type="hidden" name="project_id" value="<?php echo $selected_project_id; ?>">
            <div class="form-row">
                <label>Outcome
                    <select name="result_id" required>
                        <option value="">-- Select Outcome --</option>
                        <?php foreach ($outcomeResults as $r): ?>
                            <option value="<?php echo $r['id']; ?>">
                                <?php echo h((($r['code'] ?? '') ? $r['code'].' - ' : '').$r['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Beneficiary type
                    <select name="beneficiary_type">
                        <?php foreach ($benefTypes as $key => $label): ?>
                            <option value="<?php echo h($key); ?>"><?php echo h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="form-row">
                <label>Indicator code
                    <input type="text" name="indicator_code" placeholder="e.g. OC1">
                </label>
                <label>Indicator name
                    <input type="text" name="indicator_name" placeholder="Enter indicator description" required>
                </label>
            </div>
            <div class="form-row">
                <label>Unit type
                    <select name="unit_type" class="unit-type">
                        <?php foreach ($unitTypes as $ut): ?>
                            <option value="<?php echo h($ut); ?>"><?php echo h($ut); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Input mode
                    <select name="input_mode" class="input-mode">
                        <?php foreach ($inputModes as $key => $label): ?>
                            <option value="<?php echo h($key); ?>"><?php echo h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="form-row sadd-row">
                <label>Boys &lt;5
                    <input type="number" name="boys_u5" min="0" value="0">
                </label>
                <label>Girls &lt;5
                    <input type="number" name="girls_u5" min="0" value="0">
                </label>
                <label>Boys 5–17
                    <input type="number" name="boys_5_17" min="0" value="0">
                </label>
                <label>Girls 5–17
                    <input type="number" name="girls_5_17" min="0" value="0">
                </label>
            </div>
            <div class="form-row sadd-row">
                <label>Men 18–59
                    <input type="number" name="men_18_59" min="0" value="0">
                </label>
                <label>Women 18–59
                    <input type="number" name="women_18_59" min="0" value="0">
                </label>
                <label>Men 60+
                    <input type="number" name="men_60p" min="0" value="0">
                </label>
                <label>Women 60+
                    <input type="number" name="women_60p" min="0" value="0">
                </label>
                <label>PWD
                    <input type="number" name="pwd_count" min="0" value="0">
                </label>
                <label>Households (HH)
                    <input type="number" name="hh_count" min="0" value="0">
                </label>
            </div>
            <div class="form-row">
                <label>Total target (auto for person-count)
                    <input type="number" step="0.01" name="total_target" min="0" class="total-target" value="0">
                </label>
                <label>&nbsp;
                    <button type="submit" name="add_outcome_indicator" class="btn btn-success">
                        💾 Add &amp; Save Outcome Indicator
                    </button>
                </label>
            </div>
        </form>

        <!-- Outcome Indicators Table -->
        <?php if (isset($outcomeIndicators) && is_array($outcomeIndicators)): ?>
        <div style="margin-top: 30px;">
            <h4>📋 Existing Outcome Indicators</h4>
<p style="margin:6px 0 0;color:#666;">Saved in MySQL table: <code>outcome_indicators</code> (filtered by project).</p>
            <div style="overflow-x: auto;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Outcome</th>
                            <th>Beneficiary</th>
                            <th>Code</th>
                            <th>Indicator</th>
                            <th>Unit</th>
                            <th>Input Mode</th>
                            <th>Boys &lt;5</th>
                            <th>Girls &lt;5</th>
                            <th>Boys 5–17</th>
                            <th>Girls 5–17</th>
                            <th>Men 18–59</th>
                            <th>Women 18–59</th>
                            <th>Men 60+</th>
                            <th>Women 60+</th>
                            <th>PWD</th>
                            <th>HH</th>
                            <th>Total Target</th>
                            <th style="width: 180px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($outcomeIndicators as $oi): ?>
                            <tr>
                                <form method="post" class="indicator-form">
                                    <input type="hidden" name="project_id" value="<?php echo $selected_project_id; ?>">
                                    <td>
                                        <select name="result_id">
                                            <?php foreach ($outcomeResults as $r): ?>
                                                <?php $sel = ($r['id']==$oi['result_id'])?'selected':''; ?>
                                                <option value="<?php echo $r['id']; ?>" <?php echo $sel; ?>>
                                                    <?php echo h((($r['code'] ?? '') ? $r['code'].' - ' : '').$r['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <select name="beneficiary_type">
                                            <?php foreach ($benefTypes as $key => $label): ?>
                                                <option value="<?php echo h($key); ?>"
                                                    <?php echo ($key===($oi['beneficiary_type'] ?? 'host')?'selected':''); ?>>
                                                    <?php echo h($label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><input type="text" name="indicator_code" value="<?php echo h($oi['indicator_code'] ?? ''); ?>" style="width: 100%; padding: 5px;"></td>
                                    <td><input type="text" name="indicator_name" value="<?php echo h($oi['indicator_name'] ?? ''); ?>" required style="width: 100%; padding: 5px;"></td>
                                    <td>
                                        <select name="unit_type" class="unit-type" style="padding: 5px;">
                                            <?php foreach ($unitTypes as $ut): ?>
                                                <option value="<?php echo h($ut); ?>"
                                                    <?php echo ($ut===($oi['unit_type'] ?? 'persons')?'selected':''); ?>>
                                                    <?php echo h($ut); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <select name="input_mode" class="input-mode" style="padding: 5px;">
                                            <?php foreach ($inputModes as $key => $label): ?>
                                                <option value="<?php echo h($key); ?>"
                                                    <?php echo ($key===($oi['input_mode'] ?? 'sadd')?'selected':''); ?>>
                                                    <?php echo h($label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>

                                    <!-- SADD + HH -->
                                    <td><input type="number" name="boys_u5" min="0" value="<?php echo (int)($oi['boys_u5'] ?? 0); ?>" style="width: 80px; padding: 5px;"></td>
                                    <td><input type="number" name="girls_u5" min="0" value="<?php echo (int)($oi['girls_u5'] ?? 0); ?>" style="width: 80px; padding: 5px;"></td>
                                    <td><input type="number" name="boys_5_17" min="0" value="<?php echo (int)($oi['boys_5_17'] ?? 0); ?>" style="width: 80px; padding: 5px;"></td>
                                    <td><input type="number" name="girls_5_17" min="0" value="<?php echo (int)($oi['girls_5_17'] ?? 0); ?>" style="width: 80px; padding: 5px;"></td>
                                    <td><input type="number" name="men_18_59" min="0" value="<?php echo (int)($oi['men_18_59'] ?? 0); ?>" style="width: 80px; padding: 5px;"></td>
                                    <td><input type="number" name="women_18_59" min="0" value="<?php echo (int)($oi['women_18_59'] ?? 0); ?>" style="width: 80px; padding: 5px;"></td>
                                    <td><input type="number" name="men_60p" min="0" value="<?php echo (int)($oi['men_60p'] ?? 0); ?>" style="width: 80px; padding: 5px;"></td>
                                    <td><input type="number" name="women_60p" min="0" value="<?php echo (int)($oi['women_60p'] ?? 0); ?>" style="width: 80px; padding: 5px;"></td>
                                    <td><input type="number" name="pwd_count" min="0" value="<?php echo (int)($oi['pwd_count'] ?? 0); ?>" style="width: 80px; padding: 5px;"></td>
                                    <td><input type="number" name="hh_count" min="0" value="<?php echo (int)($oi['hh_count'] ?? 0); ?>" style="width: 80px; padding: 5px;"></td>

                                    <td>
                                        <input type="number" step="0.01" name="total_target" min="0"
                                               value="<?php echo (float)($oi['total_target'] ?? 0); ?>"
                                               class="total-target" style="width: 100px; padding: 5px;">
                                    </td>
                                    <td>
                                        <input type="hidden" name="id" value="<?php echo (int)$oi['id']; ?>">
                                        <button type="submit" name="update_outcome_indicator" class="btn btn-success btn-sm">
                                                  ✏️ Edit &amp; Save
                                            ✅ Update
                                        </button>
                                        <button type="submit" name="delete_outcome_indicator" class="btn btn-warning btn-sm"
                                                onclick="return confirm('Delete this outcome indicator?');">
                                            🗑️ Delete
                                        </button>
                                    </td>
                                </form>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ADD OUTPUT INDICATOR Section -->
<div class="card">
    <div class="section-toggle" onclick="toggleSection('add-output')">
        <span>📊</span>
        <span>Add Output Indicator</span>
    </div>
    <div id="add-output" class="section-content">
        <form method="post" class="indicator-form" data-scope="output-add">
            <input type="hidden" name="project_id" value="<?php echo $selected_project_id; ?>">
            <div class="form-row">
                <label>Output
                    <select name="result_id" required>
                        <option value="">-- Select Output --</option>
                        <?php foreach ($outputResults as $r): ?>
                            <option value="<?php echo $r['id']; ?>">
                                <?php echo h((($r['code'] ?? '') ? $r['code'].' - ' : '').$r['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Beneficiary type
                    <select name="beneficiary_type">
                        <?php foreach ($benefTypes as $key => $label): ?>
                            <option value="<?php echo h($key); ?>"><?php echo h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="form-row">
                <label>Indicator code
                    <input type="text" name="indicator_code" placeholder="e.g. OP1.1">
                </label>
                <label>Indicator name
                    <input type="text" name="indicator_name" placeholder="Enter indicator description" required>
                </label>
            </div>
            <div class="form-row">
                <label>Unit type
                    <select name="unit_type" class="unit-type">
                        <?php foreach ($unitTypes as $ut): ?>
                            <option value="<?php echo h($ut); ?>"><?php echo h($ut); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Input mode
                    <select name="input_mode" class="input-mode">
                        <?php foreach ($inputModes as $key => $label): ?>
                            <option value="<?php echo h($key); ?>"><?php echo h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="form-row sadd-row">
                <label>Boys &lt;5
                    <input type="number" name="boys_u5" min="0" value="0">
                </label>
                <label>Girls &lt;5
                    <input type="number" name="girls_u5" min="0" value="0">
                </label>
                <label>Boys 5–17
                    <input type="number" name="boys_5_17" min="0" value="0">
                </label>
                <label>Girls 5–17
                    <input type="number" name="girls_5_17" min="0" value="0">
                </label>
            </div>
            <div class="form-row sadd-row">
                <label>Men 18–59
                    <input type="number" name="men_18_59" min="0" value="0">
                </label>
                <label>Women 18–59
                    <input type="number" name="women_18_59" min="0" value="0">
                </label>
                <label>Men 60+
                    <input type="number" name="men_60p" min="0" value="0">
                </label>
                <label>Women 60+
                    <input type="number" name="women_60p" min="0" value="0">
                </label>
                <label>PWD
                    <input type="number" name="pwd_count" min="0" value="0">
                </label>
                <label>Households (HH)
                    <input type="number" name="hh_count" min="0" value="0">
                </label>
            </div>
            <div class="form-row">
                <label>Total target (auto for person-count)
                    <!-- keep name=target_total for compatibility, use class total-target for JS -->
                    <input type="number" step="0.01" name="target_total" min="0" class="total-target" value="0">
                </label>
                <label>&nbsp;
                    <button type="submit" name="add_output_indicator" class="btn btn-success">
                        💾 Add &amp; Save Output Indicator
                    </button>
                </label>
            </div>
        </form>

        <!-- Output Indicators Table -->
        <?php if (isset($outputIndicators) && is_array($outputIndicators)): ?>
        <div style="margin-top: 30px;">
            <h4>📋 Existing Output Indicators</h4>
<p style="margin:6px 0 0;color:#666;">Saved in MySQL table: <code>output_indicators</code> (filtered by project).</p>
            <div style="overflow-x: auto;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Output</th>
                            <th>Beneficiary</th>
                            <th>Code</th>
                            <th>Indicator</th>
                            <th>Unit</th>
                            <th>Input Mode</th>
                            <th>Boys &lt;5</th>
                            <th>Girls &lt;5</th>
                            <th>Boys 5–17</th>
                            <th>Girls 5–17</th>
                            <th>Men 18–59</th>
                            <th>Women 18–59</th>
                            <th>Men 60+</th>
                            <th>Women 60+</th>
                            <th>PWD</th>
                            <th>HH</th>
                            <th>Total Target</th>
                            <th style="width: 180px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($outputIndicators as $oi): ?>
                            <tr>
                                <form method="post" class="indicator-form">
                                    <input type="hidden" name="project_id" value="<?php echo $selected_project_id; ?>">
                                    <td>
                                        <select name="result_id">
                                            <?php foreach ($outputResults as $r): ?>
                                                <?php $sel = ($r['id']==$oi['result_id'])?'selected':''; ?>
                                                <option value="<?php echo $r['id']; ?>" <?php echo $sel; ?>>
                                                    <?php echo h((($r['code'] ?? '') ? $r['code'].' - ' : '').$r['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <select name="beneficiary_type">
                                            <?php foreach ($benefTypes as $key => $label): ?>
                                                <option value="<?php echo h($key); ?>"
                                                    <?php echo ($key===($oi['beneficiary_type'] ?? 'host')?'selected':''); ?>>
                                                    <?php echo h($label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><input type="text" name="indicator_code" value="<?php echo h($oi['indicator_code'] ?? ''); ?>" style="width: 100%; padding: 5px;"></td>
                                    <td><input type="text" name="indicator_name" value="<?php echo h($oi['indicator_name'] ?? ''); ?>" required style="width: 100%; padding: 5px;"></td>
                                    <td>
                                        <select name="unit_type" class="unit-type" style="padding: 5px;">
                                            <?php foreach ($unitTypes as $ut): ?>
                                                <option value="<?php echo h($ut); ?>"
                                                    <?php echo ($ut===($oi['unit_type'] ?? 'persons')?'selected':''); ?>>
                                                    <?php echo h($ut); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <select name="input_mode" class="input-mode" style="padding: 5px;">
                                            <?php foreach ($inputModes as $key => $label): ?>
                                                <option value="<?php echo h($key); ?>"
                                                    <?php echo ($key===($oi['input_mode'] ?? 'sadd')?'selected':''); ?>>
                                                    <?php echo h($label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>

                                    <!-- SADD + HH -->
                                    <td><input type="number" name="boys_u5" min="0" value="<?php echo (int)($oi['boys_u5'] ?? 0); ?>" style="width: 80px; padding: 5px;"></td>
                                    <td><input type="number" name="girls_u5" min="0" value="<?php echo (int)($oi['girls_u5'] ?? 0); ?>" style="width: 80px; padding: 5px;"></td>
                                    <td><input type="number" name="boys_5_17" min="0" value="<?php echo (int)($oi['boys_5_17'] ?? 0); ?>" style="width: 80px; padding: 5px;"></td>
                                    <td><input type="number" name="girls_5_17" min="0" value="<?php echo (int)($oi['girls_5_17'] ?? 0); ?>" style="width: 80px; padding: 5px;"></td>
                                    <td><input type="number" name="men_18_59" min="0" value="<?php echo (int)($oi['men_18_59'] ?? 0); ?>" style="width: 80px; padding: 5px;"></td>
                                    <td><input type="number" name="women_18_59" min="0" value="<?php echo (int)($oi['women_18_59'] ?? 0); ?>" style="width: 80px; padding: 5px;"></td>
                                    <td><input type="number" name="men_60p" min="0" value="<?php echo (int)($oi['men_60p'] ?? 0); ?>" style="width: 80px; padding: 5px;"></td>
                                    <td><input type="number" name="women_60p" min="0" value="<?php echo (int)($oi['women_60p'] ?? 0); ?>" style="width: 80px; padding: 5px;"></td>
                                    <td><input type="number" name="pwd_count" min="0" value="<?php echo (int)($oi['pwd_count'] ?? 0); ?>" style="width: 80px; padding: 5px;"></td>
                                    <td><input type="number" name="hh_count" min="0" value="<?php echo (int)($oi['hh_count'] ?? 0); ?>" style="width: 80px; padding: 5px;"></td>

                                    <td>
                                        <input type="number" step="0.01" name="target_total" min="0"
                                               value="<?php echo (float)($oi['target_total'] ?? 0); ?>"
                                               class="total-target" style="width: 100px; padding: 5px;">
                                    </td>
                                    <td>
                                        <input type="hidden" name="id" value="<?php echo (int)$oi['id']; ?>">
                                        <button type="submit" name="update_output_indicator" class="btn btn-success btn-sm">
                                                ✏️ Edit &amp;  Save
                                            ✅ Update
                                        </button>
                                        <button type="submit" name="delete_output_indicator" class="btn btn-warning btn-sm"
                                                onclick="return confirm('Delete this output indicator?');">
                                            🗑️ Delete
                                        </button>
                                    </td>
                                </form>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
    .readonly-total {
        background-color: #f5f5f5;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    function isPersonUnit(unit) {
        if (!unit) return false;
        unit = unit.toLowerCase();
        // Treat any unit containing "person" or "people" as person-based
        return unit.indexOf('person') !== -1 || unit.indexOf('people') !== -1 || unit.indexOf('individual') !== -1;
    }

    function initIndicatorForm(form) {
        var unitSelect      = form.querySelector('select.unit-type');
        var inputModeSelect = form.querySelector('select.input-mode');
        var totalInput      = form.querySelector('input.total-target');

        if (!unitSelect || !inputModeSelect || !totalInput) {
            return; // not an SADD/indicator form
        }

        // All SADD person fields (excluding HH – HH is separate)
        var saddInputs = form.querySelectorAll(
            'input[name="boys_u5"],'   +
            'input[name="girls_u5"],'  +
            'input[name="boys_5_17"],' +
            'input[name="girls_5_17"],'+
            'input[name="men_18_59"],' +
            'input[name="women_18_59"],' +
            'input[name="men_60p"],'   +
            'input[name="women_60p"],' +
            'input[name="pwd_count"]'
        );

        var hhInput = form.querySelector('input[name="hh_count"]');

        function recalcTotal() {
            var unit      = unitSelect.value || '';
            var inputMode = inputModeSelect.value || '';

            if (!isPersonUnit(unit) || inputMode !== 'sadd') {
                return; // only auto-calc for person + SADD
            }

            var sum = 0;
            saddInputs.forEach(function (inp) {
                var v = parseFloat(inp.value);
                if (!isNaN(v)) sum += v;
            });

            // HH is not included in persons total – separate dimension
            totalInput.value = sum;
        }

        function updateMode() {
            var unit      = unitSelect.value || '';
            var unitLc    = (unit + '').toLowerCase().trim();
            var inputMode = inputModeSelect.value || '';

            // Unit category: percent / decimal / integer
            function unitCategory(u) {
                u = (u + '').toLowerCase().trim();
                if (!u) return 'integer';
                if (u.indexOf('%') !== -1 || u.indexOf('percent') !== -1) return 'percent';

                // Common decimal units
                var decimals = ['kg','kilogram','kilograms','g','gram','grams','l','liter','litre','liters','litres','ml','mg','ton','tons','tonne','tonnes','usd','etb','birr'];
                for (var i=0;i<decimals.length;i++){
                    if (u === decimals[i] || u.indexOf(decimals[i]) !== -1) return 'decimal';
                }
                return 'integer';
            }
            function isHouseholdUnit(u) {
                u = (u + '').toLowerCase().trim();
                return (u === 'hh' || u === 'households' || u.indexOf('household') !== -1);
            }

            var cat = unitCategory(unitLc);
            var isPerson = isPersonUnit(unit);
            var isHHUnit = isHouseholdUnit(unit);

            // Prevent selecting SADD when unit is NOT people
            var saddOpt = inputModeSelect.querySelector('option[value="sadd"]');
            if (saddOpt) saddOpt.disabled = !isPerson;

            if (!isPerson && inputMode === 'sadd') {
                inputModeSelect.value = (cat === 'percent') ? 'percent_direct' : 'count';
                inputMode = inputModeSelect.value;
            }

            var isPersonSADD = isPerson && inputMode === 'sadd';

            // Show/hide SADD rows
            form.querySelectorAll('.sadd-row').forEach(function (row) {
                row.style.display = isPersonSADD ? '' : 'none';
            });

            // Enable/disable breakdown inputs safely
            saddInputs.forEach(function (inp) {
                if (!inp) return;
                if (inp.name === 'hh_count') {
                    inp.disabled = !isHHUnit;
                } else if (inp.name === 'pwd_count') {
                    inp.disabled = !isPerson; // PWD only makes sense for person units
                } else {
                    inp.disabled = !isPersonSADD;
                }
            });

            // Total input behavior + precision
            if (isPersonSADD) {
                totalInput.readOnly = true;
                totalInput.value = '';
                recalcTotal();
            } else {
                totalInput.readOnly = false;
            }

            // Step/min/max based on unit category
            totalInput.removeAttribute('max');
            totalInput.min = '0';
            if (cat === 'percent') {
                totalInput.step = '0.01';
                totalInput.max  = '100';
            } else if (cat === 'decimal') {
                totalInput.step = '0.01';
            } else {
                totalInput.step = '1';
            }

            // Hide the PWD/HH row for non-person units; show HH row only for HH units
            try {
                var pwdEl = form.querySelector('input[name="pwd_count"]');
                var hhEl  = form.querySelector('input[name="hh_count"]');
                var pwdRow = pwdEl ? pwdEl.closest('.form-row') : null;
                var hhRow  = hhEl  ? hhEl.closest('.form-row')  : null;

                if (pwdRow) pwdRow.style.display = isPerson ? '' : 'none';
                if (hhRow)  hhRow.style.display  = isHHUnit ? '' : 'none';
            } catch (e) {}
        }

        saddInputs.forEach(function (inp) {
            inp.addEventListener('input', recalcTotal);
        });

        unitSelect.addEventListener('change', updateMode);
        inputModeSelect.addEventListener('change', updateMode);

        // Initialize once on load
        updateMode();
    }

    document.querySelectorAll('form.indicator-form').forEach(function (form) {
        initIndicatorForm(form);
    });
});
</script>
<style>
    .readonly-total {
        background-color: #f5f5f5;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    function isPersonUnit(unit) {
        if (!unit) return false;
        unit = unit.toLowerCase();
        // Treat any unit containing "person" or similar as person-based
        return (
            unit.indexOf('person') !== -1 ||
            unit.indexOf('people') !== -1 ||
            unit.indexOf('individual') !== -1
        );
    }

    function initIndicatorForm(form) {
        var unitSelect      = form.querySelector('select.unit-type');
        var inputModeSelect = form.querySelector('select.input-mode');
        var totalInput      = form.querySelector('input.total-target');

        if (!unitSelect || !inputModeSelect || !totalInput) {
            return; // not an SADD/indicator form
        }

        // All person-related fields (including PWD + HH) for enabling/disabling
        var personFields = form.querySelectorAll(
            'input[name="boys_u5"],'   +
            'input[name="girls_u5"],'  +
            'input[name="boys_5_17"],' +
            'input[name="girls_5_17"],'+
            'input[name="men_18_59"],' +
            'input[name="women_18_59"],' +
            'input[name="men_60p"],'   +
            'input[name="women_60p"],' +
            'input[name="pwd_count"],' +
            'input[name="hh_count"]'
        );

        // Fields that are counted in TOTAL beneficiaries
        // 👉 EXCLUDING PWD and HH as you requested
        var sumFields = form.querySelectorAll(
            'input[name="boys_u5"],'   +
            'input[name="girls_u5"],'  +
            'input[name="boys_5_17"],' +
            'input[name="girls_5_17"],'+
            'input[name="men_18_59"],' +
            'input[name="women_18_59"],' +
            'input[name="men_60p"],'   +
            'input[name="women_60p"]'
        );

        function recalcTotal() {
            var unit      = unitSelect.value || '';
            var inputMode = inputModeSelect.value || '';

            // Only auto-calc for person-based SADD indicators
            if (!isPersonUnit(unit) || inputMode !== 'sadd') {
                return;
            }

            var sum = 0;
            sumFields.forEach(function (inp) {
                var v = parseFloat(inp.value);
                if (!isNaN(v)) sum += v;
            });

            // PWD & HH are NOT included in total, as requested
            totalInput.value = sum;
        }

        function updateMode() {
            var unit      = unitSelect.value || '';
            var unitLc    = (unit + '').toLowerCase().trim();
            var inputMode = inputModeSelect.value || '';

            function unitCategory(u) {
                u = (u + '').toLowerCase().trim();
                if (!u) return 'integer';
                if (u.indexOf('%') !== -1 || u.indexOf('percent') !== -1) return 'percent';
                var decimals = ['kg','kilogram','kilograms','g','gram','grams','l','liter','litre','liters','litres','ml','mg','ton','tons','tonne','tonnes','usd','etb','birr'];
                for (var i=0;i<decimals.length;i++){
                    if (u === decimals[i] || u.indexOf(decimals[i]) !== -1) return 'decimal';
                }
                return 'integer';
            }
            function isHouseholdUnit(u) {
                u = (u + '').toLowerCase().trim();
                return (u === 'hh' || u === 'households' || u.indexOf('household') !== -1);
            }

            var cat = unitCategory(unitLc);
            var isPerson = isPersonUnit(unit);
            var isHHUnit = isHouseholdUnit(unit);

            // Prevent selecting SADD for non-person units
            var saddOpt = inputModeSelect.querySelector('option[value="sadd"]');
            if (saddOpt) saddOpt.disabled = !isPerson;

            // Force mode for non-person units
            if (!isPerson && inputMode === 'sadd') {
                inputModeSelect.value = (cat === 'percent') ? 'percent_direct' : 'count';
                inputMode = inputModeSelect.value;
            }

            // Force count mode for households
            if (isHHUnit && inputMode !== 'count') {
                inputModeSelect.value = 'count';
                inputMode = 'count';
            }

            var isPersonSADD = isPerson && inputMode === 'sadd';

            // Show/hide SADD rows
            form.querySelectorAll('.sadd-row').forEach(function(row){
                row.style.display = isPersonSADD ? '' : 'none';
            });

            // Enable/disable breakdown fields:
            personFields.forEach(function (inp) {
                if (!inp) return;
                if (inp.name === 'hh_count') {
                    inp.disabled = !isHHUnit;
                } else if (inp.name === 'pwd_count') {
                    inp.disabled = !isPerson; // PWD is only for person units
                } else {
                    inp.disabled = !isPersonSADD;
                }
            });

            // Total input behavior
            if (isPersonSADD) {
                totalInput.readOnly = true;
                totalInput.classList.add('readonly-total');
                recalcTotal();
            } else {
                totalInput.readOnly = false;
                totalInput.classList.remove('readonly-total');
            }

            // Precision rules
            totalInput.removeAttribute('max');
            totalInput.min = '0';
            if (cat === 'percent') {
                totalInput.step = '0.01';
                totalInput.max  = '100';
            } else if (cat === 'decimal') {
                totalInput.step = '0.01';
            } else {
                totalInput.step = '1';
            }

            // Hide PWD row for non-person; show HH row only for HH unit
            try {
                var pwdEl = form.querySelector('input[name="pwd_count"]');
                var hhEl  = form.querySelector('input[name="hh_count"]');
                var pwdRow = pwdEl ? pwdEl.closest('.form-row') : null;
                var hhRow  = hhEl  ? hhEl.closest('.form-row')  : null;
                if (pwdRow) pwdRow.style.display = isPerson ? '' : 'none';
                if (hhRow)  hhRow.style.display  = isHHUnit ? '' : 'none';
            } catch(e) {}
        }

        // Recalculate total whenever the SADD fields (that are part of total) change
        sumFields.forEach(function (inp) {
            inp.addEventListener('input', recalcTotal);
        });

        unitSelect.addEventListener('change', updateMode);
        inputModeSelect.addEventListener('change', updateMode);

        // Initialize once when the page loads
        updateMode();
    }

    // Apply logic to ALL indicator forms:
    // - Add Impact / Outcome / Output forms
    // - Each row form in the existing tables (edit/update rows)
    document.querySelectorAll('form.indicator-form').forEach(function (form) {
        initIndicatorForm(form);
    });
});
</script>

<!-- ADD ACTIVITY Section -->
<div class="card" id="activities">
    <div class="section-toggle" onclick="toggleSection('add-activity')">
        <span>🛠️</span>
        <span>Add Activity</span>
        <div style="margin-left: auto; display: flex; gap: 5px;">
            <button class="btn btn-sm btn-success" onclick="window.print()" title="Print this section">🖨️ Print</button>
            <button class="btn btn-sm btn-info" onclick="exportSection('activities')" title="Export this section">📥 Export</button>
        </div>
    </div>
    <div id="add-activity" class="section-content">
        <form method="post">
            <input type="hidden" name="project_id" value="<?php echo $selected_project_id; ?>">
            <?php if ($edit_activity): ?>
                <input type="hidden" name="id" value="<?php echo $edit_activity['id']; ?>">
                <div class="badge badge-info" style="margin-bottom: 15px;">✏️ Editing Activity: <?php echo h($edit_activity['code'] ?? ''); ?></div>
            <?php endif; ?>
            <div class="form-row">
                <label>Output (link)
                    <select name="output_id" required>
                        <option value="">-- Select Output --</option>
                        <?php foreach ($outputResults as $r): ?>
                            <option value="<?php echo $r['id']; ?>" <?php echo ($edit_activity && $edit_activity['output_id'] == $r['id']) ? 'selected' : ''; ?>>
                                <?php echo h((($r['code'] ?? '') ? $r['code'].' - ' : '').$r['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="form-row">
                <label>Activity code
                    <input type="text" name="code" placeholder="e.g. ACT1.1" value="<?php echo $edit_activity ? h($edit_activity['code'] ?? '') : ''; ?>" required>
                </label>
                <label>Activity name
                    <input type="text" name="name" placeholder="Enter activity description" value="<?php echo $edit_activity ? h($edit_activity['name'] ?? '') : ''; ?>" required>
                </label>
            </div>
            <div class="form-row">
                <label>Unit of measurement
                    <input type="text" name="unit" placeholder="e.g. HHs, persons, sessions" value="<?php echo $edit_activity ? h($edit_activity['unit'] ?? '') : ''; ?>">
                </label>
                <label>Target (planned)
                    <input type="number" name="target" min="0" value="<?php echo $edit_activity ? (int)($edit_activity['target'] ?? 0) : 0; ?>">
                </label>
                <label>&nbsp;
                    <?php if ($edit_activity): ?>
                        <button type="submit" name="update_activity" class="btn btn-success">
                            ✅ Update Activity
                        </button>
                        <a href="?project_id=<?php echo $selected_project_id; ?>" class="btn btn-secondary">❌ Cancel</a>
                    <?php else: ?>
                        <button type="submit" name="add_activity" class="btn btn-success">
                            💾 Add &amp; Save Activity
                        </button>
                    <?php endif; ?>
                </label>
            </div>
        </form>

        <!-- Activities Table -->
        <?php if (isset($activities) && is_array($activities)): ?>
        <div style="margin-top: 30px;">
            <h4>📋 Existing Activities</h4>
<p style="margin:6px 0 0;color:#666;">Saved in MySQL table: <code>activities</code> (filtered by project).</p>
            <div style="overflow-x: auto;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Output</th>
                            <th>Code</th>
                            <th>Activity</th>
                            <th>Unit</th>
                            <th>Target</th>
                            <th style="width: 180px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activities as $a): ?>
                            <tr>
                                <form method="post">
                                    <input type="hidden" name="project_id" value="<?php echo $selected_project_id; ?>">
                                    <td>
                                        <select name="output_id">
                                            <option value="">-- No Output --</option>
                                            <?php foreach ($outputResults as $r): ?>
                                                <?php $sel = ($r['id']==$a['output_id'])?'selected':''; ?>
                                                <option value="<?php echo $r['id']; ?>" <?php echo $sel; ?>>
                                                    <?php echo h((($r['code'] ?? '') ? $r['code'].' - ' : '').$r['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><input type="text" name="code" value="<?php echo h($a['code'] ?? ''); ?>" style="width: 100%; padding: 8px;"></td>
                                    <td><input type="text" name="name" value="<?php echo h($a['name'] ?? ''); ?>" required style="width: 100%; padding: 8px;"></td>
                                    <td><input type="text" name="unit" value="<?php echo h($a['unit'] ?? ''); ?>" style="width: 100%; padding: 8px;"></td>
                                    <td><input type="number" name="target" value="<?php echo (int)($a['target'] ?? 0); ?>" min="0" style="width: 100px; padding: 8px;"></td>
                                    <td>
                                        <input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>">
                                        <button type="submit" name="update_activity" class="btn btn-success btn-sm">✅ Update</button>
                                        <button type="submit" name="delete_activity" class="btn btn-warning btn-sm" 
                                                onclick="return confirm('Delete this activity?');">
                                            🗑️ Delete
                                        </button>
                                    </td>
                                </form>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ACTIVITY WORK PLAN / GANTT PREVIEW -->
<div class="card">
    <div class="section-toggle" onclick="toggleSection('gantt-preview')">
        <span>📅</span>
        <span>Activity Work Plan (Gantt-style Preview)</span>
    </div>
    <div id="gantt-preview" class="section-content">
        <p>
            <strong>Project Duration:</strong> <?php echo $project_lifespan; ?> months 
            (<?php echo $project_start_date ? $project_start_date->format('F Y') : 'Start date not set'; ?> - 
            <?php echo $project_end_date ? $project_end_date->format('F Y') : 'End date not set'; ?>)
        </p>
        <div style="overflow-x: auto;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Impact</th>
                        <th>Outcome</th>
                        <th>Output</th>
                        <th>Activity</th>
                        <th>Budget Code</th>
                        <th>Budget Details</th>
                        <th>Budget (Donor)</th>
                        <th>Budget (ETB)</th>
                        <th>Activity Target</th>
                        <th>Responsible</th>
                        <th>Deliverables</th>
                        <?php foreach ($project_months as $month): ?>
                            <th colspan="4" style="text-align: center; background-color: #e9ecef;">
                                <?php echo $month['name']; ?><br>
                                <small>W1 W2 W3 W4</small>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($activities): ?>
                        <?php foreach ($activities as $a): ?>
                            <?php
                            $output = null;
                            $outcome = null;
                            $impact = null;
                            
                            // Find linked output
                            if ($a['output_id']) {
                                foreach ($outputResults as $out) {
                                    if ($out['id'] == $a['output_id']) {
                                        $output = $out;
                                        break;
                                    }
                                }
                            }
                            ?>
                            <tr>
                                <td><?php echo $impact ? h($impact['name']) : 'General Impact'; ?></td>
                                <td><?php echo $outcome ? h($outcome['name']) : 'Project Outcome'; ?></td>
                                <td><?php echo $output ? h($output['name']) : 'Not linked'; ?></td>
                                <td><strong><?php echo h($a['code']); ?></strong><br><?php echo h($a['name']); ?></td>
                                <td><!-- Budget code --></td>
                                <td><!-- Budget details --></td>
                                <td><!-- Donor budget --></td>
                                <td><!-- ETB budget --></td>
                                <td><?php echo (int)($a['target'] ?? 0) . ' ' . h($a['unit'] ?? ''); ?></td>
                                <td><!-- Responsible --></td>
                                <td><!-- Deliverables --></td>
                                <?php for ($m = 1; $m <= $project_lifespan; $m++): ?>
                                    <td><input type="checkbox" title="Week 1 - <?php echo $project_months[$m-1]['name'] ?? 'Month '.$m; ?>"></td>
                                    <td><input type="checkbox" title="Week 2 - <?php echo $project_months[$m-1]['name'] ?? 'Month '.$m; ?>"></td>
                                    <td><input type="checkbox" title="Week 3 - <?php echo $project_months[$m-1]['name'] ?? 'Month '.$m; ?>"></td>
                                    <td><input type="checkbox" title="Week 4 - <?php echo $project_months[$m-1]['name'] ?? 'Month '.$m; ?>"></td>
                                <?php endfor; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo 11 + ($project_lifespan * 4); ?>" style="text-align: center; padding: 20px;">
                                No activities yet. Add activities above to see the work plan structure.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ENHANCED BENEFICIARY CALCULATION & PROJECT REACH SECTION -->
<div class="card" id="beneficiaries">
    <div class="section-toggle" onclick="toggleSection('beneficiary-calculation')">
        <span>👥</span>
        <span>Beneficiary Calculation & Project Reach</span>
        <div style="margin-left: auto; display: flex; gap: 5px;">
            <button class="btn btn-sm btn-success" onclick="window.print()" title="Print this section">🖨️ Print</button>
            <button class="btn btn-sm btn-info" onclick="exportSection('beneficiaries')" title="Export this section">📥 Export</button>
        </div>
    </div>

    <div id="beneficiary-calculation" class="section-content">

        <!-- ===================================================== -->
        <!-- 1. Beneficiary Summary from Indicators (TOP SECTION)  -->
        <!-- ===================================================== -->
        <div style="margin-bottom: 40px;">
            <h3>📊 Beneficiary Summary from Selected Indicators</h3>

            <form method="post">
                <input type="hidden" name="update_beneficiary_selection" value="1">

                <div class="form-row">
                    <label style="flex: 2;">
                        <strong>🔍 Select Indicators for Beneficiary Calculation:</strong>
                        <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; margin-top: 10px;">
                            <?php foreach ($impactIndicators as $ind): ?>
                                <div>
                                    <input type="checkbox"
                                           name="selected_indicators[]"
                                           value="impact_<?php echo $ind['id']; ?>"
                                           <?php echo in_array('impact_' . $ind['id'], $selected_indicators) ? 'checked' : ''; ?>>
                                    🎯 Impact: <?php echo h($ind['indicator_name']); ?>
                                    (<?php echo h($ind['indicator_code'] ?? 'No code'); ?>)
                                </div>
                            <?php endforeach; ?>

                            <?php foreach ($outcomeIndicators as $ind): ?>
                                <div>
                                    <input type="checkbox"
                                           name="selected_indicators[]"
                                           value="outcome_<?php echo $ind['id']; ?>"
                                           <?php echo in_array('outcome_' . $ind['id'], $selected_indicators) ? 'checked' : ''; ?>>
                                    📈 Outcome: <?php echo h($ind['indicator_name']); ?>
                                    (<?php echo h($ind['indicator_code'] ?? 'No code'); ?>)
                                </div>
                            <?php endforeach; ?>

                            <?php foreach ($outputIndicators as $ind): ?>
                                <div>
                                    <input type="checkbox"
                                           name="selected_indicators[]"
                                           value="output_<?php echo $ind['id']; ?>"
                                           <?php echo in_array('output_' . $ind['id'], $selected_indicators) ? 'checked' : ''; ?>>
                                    📊 Output: <?php echo h($ind['indicator_name']); ?>
                                    (<?php echo h($ind['indicator_code'] ?? 'No code'); ?>)
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </label>
                </div>

                <div class="beneficiary-pagination">
                    <div class="left">
                        <strong>Rows:</strong>
                        <span id="beneShowing"><?php echo (int)count($project_beneficiaries); ?></span>
                        <span>/</span>
                        <span id="beneTotal"><?php echo (int)($beneficiary_total ?? 0); ?></span>
                    </div>
                    <div class="right">
                        <?php if (!empty($beneficiary_total) && count($project_beneficiaries) < (int)$beneficiary_total): ?>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="loadMoreBeneficiaries()">Load more</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="loadAllBeneficiaries()">Load all</button>
                        <?php else: ?>
                            <span class="small" style="opacity:0.8;">All rows loaded</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-row">
                    <button type="submit" class="btn btn-info">
                        🔄 Update Calculation
                    </button>
                </div>
            </form>

            <!-- Beneficiary Summary Display -->
            <div class="stats-grid" style="margin-top: 20px;">
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($total_all); ?></div>
                    <div class="stat-label">Total Beneficiaries</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($women_total); ?></div>
                    <div class="stat-label">Women (<?php echo $pct_women; ?>%)</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($children_tot); ?></div>
                    <div class="stat-label">Children (<?php echo $pct_children; ?>%)</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($sum['pwd_count']); ?></div>
                    <div class="stat-label">Persons with Disabilities</div>
                </div>
            </div>

            <!-- Detailed Breakdown -->
            <div style="margin-top: 30px;">
                <h4>👥 Detailed Age/Sex Breakdown</h4>
                <table class="table">
                    <thead>
                    <tr>
                        <th>Category</th>
                        <th>Boys</th>
                        <th>Girls</th>
                        <th>Men</th>
                        <th>Women</th>
                        <th>Sub-total</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr>
                        <td><strong>Under 5 years</strong></td>
                        <td><?php echo number_format($sum['boys_u5']); ?></td>
                        <td><?php echo number_format($sum['girls_u5']); ?></td>
                        <td>-</td>
                        <td>-</td>
                        <td><?php echo number_format($sum['boys_u5'] + $sum['girls_u5']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>5-17 years</strong></td>
                        <td><?php echo number_format($sum['boys_5_17']); ?></td>
                        <td><?php echo number_format($sum['girls_5_17']); ?></td>
                        <td>-</td>
                        <td>-</td>
                        <td><?php echo number_format($sum['boys_5_17'] + $sum['girls_5_17']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>18-59 years</strong></td>
                        <td>-</td>
                        <td>-</td>
                        <td><?php echo number_format($sum['men_18_59']); ?></td>
                        <td><?php echo number_format($sum['women_18_59']); ?></td>
                        <td><?php echo number_format($sum['men_18_59'] + $sum['women_18_59']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>60+ years</strong></td>
                        <td>-</td>
                        <td>-</td>
                        <td><?php echo number_format($sum['men_60p']); ?></td>
                        <td><?php echo number_format($sum['women_60p']); ?></td>
                        <td><?php echo number_format($sum['men_60p'] + $sum['women_60p']); ?></td>
                    </tr>
                    <tr class="total-row">
                        <td><strong>TOTAL</strong></td>
                        <td><?php echo number_format($boys_total); ?></td>
                        <td><?php echo number_format($girls_total); ?></td>
                        <td><?php echo number_format($men_total); ?></td>
                        <td><?php echo number_format($women_total); ?></td>
                        <td><?php echo number_format($total_all); ?></td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <!-- Selected Indicators -->
            <?php if (isset($selected_indicators_details) && is_array($selected_indicators_details)): ?>
                <div style="margin-top: 30px;">
                    <h4>📋 Indicators Included in Calculation</h4>
                    <ul>
                        <?php foreach ($selected_indicators_details as $detail): ?>
                            <li>
                                <?php if ($detail['type'] === 'impact'): ?>🎯<?php endif; ?>
                                <?php if ($detail['type'] === 'outcome'): ?>📈<?php endif; ?>
                                <?php if ($detail['type'] === 'output'): ?>📊<?php endif; ?>
                                <strong><?php echo h($detail['code']); ?>:</strong>
                                <?php echo h($detail['name']); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>

        <?php
        // ==========================================================
        // 2. Preload PROJECT LOCATIONS for this project so that
        //    Region / Zone / Woreda are auto displayed in the table.
        // ==========================================================
        if (!isset($project_locations)) {
            $project_locations = [];
        }

        if (!empty($selected_project_id) && empty($project_locations)) {
            try {
                $stmt = $pdo->prepare("
                    SELECT pl.id,
                           pl.region_id,
                           pl.zone_id,
                           pl.woreda_id
                    FROM project_locations pl
                    WHERE pl.project_id = :pid
                    ORDER BY pl.id ASC
                ");
                $stmt->execute([':pid' => $selected_project_id]);
                $project_locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $project_locations = [];
            }
        }
        ?>

        <!-- ===================================================== -->
        <!-- 3. Location-Based Beneficiary Calculation (BOTTOM)   -->
        <!-- ===================================================== -->
        <div style="margin-top: 40px;">
            <h3>📍 Location-Based Beneficiary Distribution</h3>
            <p><strong>Plan and calculate beneficiary reach across different locations and demographic groups.</strong></p>

            <form method="post" id="beneficiaryForm">
                <input type="hidden" name="action" value="save_beneficiaries">
                <input type="hidden" name="project_id" value="<?php echo $selected_project_id; ?>">

                
                
                <div class="beneficiary-toolbar">
                    <button type="button" class="btn btn-success btn-sm" onclick="addRow()">➕ Add Row</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="downloadBeneficiaryTemplate()">📄 Template</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="triggerBeneficiaryImport()">⬆️ Import CSV</button>
                    <label class="small" style="display:flex; align-items:center; gap:6px; margin:0;">
                        <input type="checkbox" id="beneficiaryImportReplace" value="1"> Replace existing
                    </label>
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="exportBeneficiariesCSV()">📥 Export CSV</button>
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="printBeneficiariesTable()">🖨️ Print</button>
                    <input type="file" id="beneficiaryImportFile" accept=".csv,text/csv" style="display:none">
                </div>
                <div class="scroll-hint">↔ Scroll horizontally to see all columns.</div>
                <div class="table-scroll" id="beneficiaryTableScroll">
<table class="table" id="beneficiaryTable">
                    <thead>
                    <tr>
                        <th>Region</th>
                        <th>Zone</th>
                        <th>Woreda</th>
                        <th>Beneficiary Type</th>
                        <th>Women</th>
                        <th>Girls</th>
                        <th>Men</th>
                        <th>Boys</th>
                        <th>PWD</th>
                        <th>HH</th>
                        <th>Total</th>
                        <th>Share %</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (isset($project_beneficiaries) && is_array($project_beneficiaries)): ?>
                        <!-- Existing saved beneficiary rows -->
                        <?php foreach ($project_beneficiaries as $index => $beneficiary): ?>
                            <tr>
                                <td>
                                    <input type="hidden" name="beneficiaries[<?php echo $index; ?>][id]" value="<?php echo (int)($beneficiary['id'] ?? 0); ?>">
                                    <select name="beneficiaries[<?php echo $index; ?>][region_id]"
                                            class="region-select"
                                            onchange="updateZones(this)">
                                        <option value="">-- Select Region --</option>
                                        <?php foreach ($regions as $region): ?>
                                            <option value="<?php echo (int)$region['id']; ?>"
                                                <?php echo ((int)($beneficiary['region_id'] ?? 0) === (int)$region['id']) ? 'selected' : ''; ?>>
                                                <?php echo h($region['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <option value="other" <?php echo (empty($beneficiary['region_id']) && !empty($beneficiary['region_other'])) ? 'selected' : ''; ?>>Other...</option>
                                    </select>
                                    <input type="text"
                                           name="beneficiaries[<?php echo $index; ?>][region_other]"
                                           class="region-other"
                                           placeholder="Type region"
                                           value="<?php echo h($beneficiary['region_other'] ?? ''); ?>"
                                           style="margin-top:4px; <?php echo (empty($beneficiary['region_id']) && !empty($beneficiary['region_other'])) ? '' : 'display:none;'; ?>">
                                </td>

                                <td>
                                    <select name="beneficiaries[<?php echo $index; ?>][zone_id]"
                                            class="zone-select"
                                            onchange="updateWoredas(this)">
                                        <option value="">-- Select Zone --</option>
                                        <?php if (!empty($beneficiary['region_id'])): ?>
                                            <?php foreach ($zones as $zone): ?>
                                                <?php if ((int)$zone['region_id'] === (int)$beneficiary['region_id']): ?>
                                                    <option value="<?php echo (int)$zone['id']; ?>"
                                                        <?php echo ((int)($beneficiary['zone_id'] ?? 0) === (int)$zone['id']) ? 'selected' : ''; ?>>
                                                        <?php echo h($zone['name']); ?>
                                                    </option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                        <option value="other" <?php echo (empty($beneficiary['zone_id']) && !empty($beneficiary['zone_other'])) ? 'selected' : ''; ?>>Other...</option>
                                    </select>
                                    <input type="text"
                                           name="beneficiaries[<?php echo $index; ?>][zone_other]"
                                           class="zone-other"
                                           placeholder="Type zone"
                                           value="<?php echo h($beneficiary['zone_other'] ?? ''); ?>"
                                           style="margin-top:4px; <?php echo (empty($beneficiary['zone_id']) && !empty($beneficiary['zone_other'])) ? '' : 'display:none;'; ?>">
                                </td>

                                <td>
                                    <select name="beneficiaries[<?php echo $index; ?>][woreda_id]"
                                            class="woreda-select"
                                            onchange="toggleOther(this.closest('tr'), 'woreda', this.value)">
                                        <option value="">-- Select Woreda --</option>
                                        <?php if (!empty($beneficiary['zone_id'])): ?>
                                            <?php foreach ($woredas as $woreda): ?>
                                                <?php if ((int)$woreda['zone_id'] === (int)$beneficiary['zone_id']): ?>
                                                    <option value="<?php echo (int)$woreda['id']; ?>"
                                                        <?php echo ((int)($beneficiary['woreda_id'] ?? 0) === (int)$woreda['id']) ? 'selected' : ''; ?>>
                                                        <?php echo h($woreda['name']); ?>
                                                    </option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                        <option value="other" <?php echo (empty($beneficiary['woreda_id']) && !empty($beneficiary['woreda_other'])) ? 'selected' : ''; ?>>Other...</option>
                                    </select>
                                    <input type="text"
                                           name="beneficiaries[<?php echo $index; ?>][woreda_other]"
                                           class="woreda-other"
                                           placeholder="Type woreda"
                                           value="<?php echo h($beneficiary['woreda_other'] ?? ''); ?>"
                                           style="margin-top:4px; <?php echo (empty($beneficiary['woreda_id']) && !empty($beneficiary['woreda_other'])) ? '' : 'display:none;'; ?>">
                                </td>

                                <td>
                                    <select name="beneficiaries[<?php echo $index; ?>][beneficiary_type]">
                                        <?php foreach ($beneficiary_types as $key => $label): ?>
                                            <option value="<?php echo h($key); ?>"
                                                <?php echo (($beneficiary['beneficiary_type'] ?? '') === $key) ? 'selected' : ''; ?>>
                                                <?php echo h($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>

                                <td><input type="number" min="0" name="beneficiaries[<?php echo $index; ?>][women]" class="calc-input" value="<?php echo (int)($beneficiary['women'] ?? 0); ?>" oninput="calculateRow(this)"></td>
                                <td><input type="number" min="0" name="beneficiaries[<?php echo $index; ?>][girls]" class="calc-input" value="<?php echo (int)($beneficiary['girls'] ?? 0); ?>" oninput="calculateRow(this)"></td>
                                <td><input type="number" min="0" name="beneficiaries[<?php echo $index; ?>][men]" class="calc-input" value="<?php echo (int)($beneficiary['men'] ?? 0); ?>" oninput="calculateRow(this)"></td>
                                <td><input type="number" min="0" name="beneficiaries[<?php echo $index; ?>][boys]" class="calc-input" value="<?php echo (int)($beneficiary['boys'] ?? 0); ?>" oninput="calculateRow(this)"></td>
                                <td><input type="number" min="0" name="beneficiaries[<?php echo $index; ?>][pwd_count]" class="calc-input" value="<?php echo (int)($beneficiary['pwd_count'] ?? 0); ?>" oninput="calculateRow(this)"></td>
                                <td><input type="number" min="0" name="beneficiaries[<?php echo $index; ?>][hh_count]" class="calc-input" value="<?php echo (int)($beneficiary['hh_count'] ?? 0); ?>" oninput="calculateRow(this)"></td>
                                <td class="row-total"><?php echo (int)($beneficiary['total'] ?? 0); ?></td>
                                <td class="row-percentage"><?php echo (float)($beneficiary['share_percentage'] ?? 0); ?>%</td>
                                <td>
                                    <button type="button" class="btn btn-warning btn-sm" onclick="removeRow(this)">🗑️</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                    <?php elseif (isset($project_locations) && is_array($project_locations)): ?>
                        <!-- Auto rows from PROJECT planned LOCATIONS (no saved beneficiaries yet) -->
                        <?php foreach ($project_locations as $index => $loc): ?>
                            <tr>
                                <td>
                                    <select name="beneficiaries[<?php echo $index; ?>][region_id]"
                                            class="region-select"
                                            onchange="updateZones(this)">
                                        <option value="">-- Select Region --</option>
                                        <?php foreach ($regions as $region): ?>
                                            <option value="<?php echo $region['id']; ?>"
                                                <?php echo ((int)$loc['region_id'] === (int)$region['id']) ? 'selected' : ''; ?>>
                                                <?php echo h($region['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <select name="beneficiaries[<?php echo $index; ?>][zone_id]"
                                            class="zone-select"
                                            onchange="updateWoredas(this)">
                                        <option value="">-- Select Zone --</option>
                                        <?php foreach ($zones as $zone): ?>
                                            <?php if ((int)$zone['region_id'] === (int)$loc['region_id']): ?>
                                                <option value="<?php echo $zone['id']; ?>"
                                                    <?php echo ((int)$loc['zone_id'] === (int)$zone['id']) ? 'selected' : ''; ?>>
                                                    <?php echo h($zone['name']); ?>
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <select name="beneficiaries[<?php echo $index; ?>][woreda_id]"
                                            class="woreda-select">
                                        <option value="">-- Select Woreda --</option>
                                        <?php foreach ($woredas as $woreda): ?>
                                            <?php if ((int)$woreda['zone_id'] === (int)$loc['zone_id']): ?>
                                                <option value="<?php echo $woreda['id']; ?>"
                                                    <?php echo ((int)$loc['woreda_id'] === (int)$woreda['id']) ? 'selected' : ''; ?>>
                                                    <?php echo h($woreda['name']); ?>
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <select name="beneficiaries[<?php echo $index; ?>][beneficiary_type]">
                                        <?php foreach ($beneficiary_types as $key => $label): ?>
                                            <option value="<?php echo $key; ?>"><?php echo h($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input type="number" name="beneficiaries[<?php echo $index; ?>][women]" value="0"
                                           min="0" class="calc-input" onchange="calculateRow(this)"></td>
                                <td><input type="number" name="beneficiaries[<?php echo $index; ?>][girls]" value="0"
                                           min="0" class="calc-input" onchange="calculateRow(this)"></td>
                                <td><input type="number" name="beneficiaries[<?php echo $index; ?>][men]" value="0"
                                           min="0" class="calc-input" onchange="calculateRow(this)"></td>
                                <td><input type="number" name="beneficiaries[<?php echo $index; ?>][boys]" value="0"
                                           min="0" class="calc-input" onchange="calculateRow(this)"></td>
                                <td><input type="number" name="beneficiaries[<?php echo $index; ?>][pwd_count]" value="0"
                                           min="0" class="calc-input" onchange="calculateRow(this)"></td>
                                <td class="row-total">0</td>
                                <td class="row-percentage">0%</td>
                                <td>
                                    <button type="button" class="btn btn-warning btn-sm" onclick="removeRow(this)">🗑️</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                    <?php else: ?>
                        <!-- Fallback single empty row -->
                        <tr>
                            <td>
                                <select name="beneficiaries[0][region_id]" class="region-select" onchange="updateZones(this)">
                                    <option value="">-- Select Region --</option>
                                    <?php foreach ($regions as $region): ?>
                                        <option value="<?php echo $region['id']; ?>"><?php echo h($region['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <select name="beneficiaries[0][zone_id]" class="zone-select" onchange="updateWoredas(this)">
                                    <option value="">-- Select Zone --</option>
                                </select>
                            </td>
                            <td>
                                <select name="beneficiaries[0][woreda_id]" class="woreda-select">
                                    <option value="">-- Select Woreda --</option>
                                </select>
                            </td>
                            <td>
                                <select name="beneficiaries[0][beneficiary_type]">
                                    <?php foreach ($beneficiary_types as $key => $label): ?>
                                        <option value="<?php echo $key; ?>"><?php echo h($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="number" name="beneficiaries[0][women]" value="0" min="0" class="calc-input" onchange="calculateRow(this)"></td>
                            <td><input type="number" name="beneficiaries[0][girls]" value="0" min="0" class="calc-input" onchange="calculateRow(this)"></td>
                            <td><input type="number" name="beneficiaries[0][men]" value="0" min="0" class="calc-input" onchange="calculateRow(this)"></td>
                            <td><input type="number" name="beneficiaries[0][boys]" value="0" min="0" class="calc-input" onchange="calculateRow(this)"></td>
                            <td><input type="number" name="beneficiaries[0][pwd_count]" value="0" min="0" class="calc-input" onchange="calculateRow(this)"></td>
                            <td><input type="number" name="beneficiaries[0][hh_count]" min="0" class="calc-input" onchange="calculateRow(this)"></td>
                            <td class="row-total">0</td>
                            <td class="row-percentage">0%</td>
                            <td>
                                <button type="button" class="btn btn-warning btn-sm" onclick="removeRow(this)">🗑️</button>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                    <tfoot>
                    <tr>
                        <td colspan="4" style="text-align: right;"><strong>Grand Total:</strong></td>
                        <td id="total-women">0</td>
                        <td id="total-girls">0</td>
                        <td id="total-men">0</td>
                        <td id="total-boys">0</td>
                        <td id="total-pwd">0</td>
                        <td id="total-hh">0</td>
                        <td id="grand-total">0</td>
                        <td>100%</td>
                        <td>
                            <button type="button" class="btn btn-success btn-sm" onclick="addRow()">➕ Add Row</button>
                        </td>
                    </tr>
                    </tfoot>
                </table>
</div>

                <div class="form-row">
                    <button type="submit" class="btn btn-primary">
                        💾 Save Beneficiary Data
                    </button>
                </div>
            </form>
        </div>

    </div>
</div>

<?php else: ?>
<!-- No Project Selected Message -->
<div class="card">
    <div style="text-align: center; padding: 40px;">
        <h2 style="color: var(--primary-color);">📋 No Project Selected</h2>
        <p>Please select a project from the dropdown above to start planning.</p>
        <div class="badge badge-info" style="margin-top: 20px;">
            💡 Tip: If no projects are available, create a project first in the project management section.
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Floating Action Button -->
<div class="floating-action">
    <button class="floating-btn" onclick="scrollToTop()" title="Scroll to Top">
        ↑
    </button>
</div>

<script>
// Section toggle functionality
function toggleSection(sectionId) {
    const section = document.getElementById(sectionId);
    if (!section) return;
    section.style.display = 'block';
}

// Clear form function
function clearForm(form) {
    if (form) form.reset();
}

// Scroll to top function
function scrollToTop() {
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// Share functionality
function shareVia(platform) {
    const projectName = "<?php echo h($selected_project_details['title'] ?? 'Project Planning'); ?>";
    const shareUrl   = window.location.href;

    let shareLink = '';
    switch (platform) {
        case 'email':
            shareLink = `mailto:?subject=Project Planning - ${projectName}&body=Check out this project planning: ${shareUrl}`;
            break;
        case 'whatsapp':
            shareLink = `https://wa.me/?text=Check out this project planning: ${projectName} - ${shareUrl}`;
            break;
        case 'telegram':
            shareLink = `https://t.me/share/url?url=${encodeURIComponent(shareUrl)}&text=${encodeURIComponent('Project Planning: ' + projectName)}`;
            break;
    }

    if (shareLink) {
        window.open(shareLink, '_blank');
    }
}

function copyShareLink() {
    const shareUrl = window.location.href;
    navigator.clipboard.writeText(shareUrl).then(() => {
        alert('🔗 Link copied to clipboard!');
    });
}

// Handle donor selection for planning.php
function handlePlanningDonorSelection(projectId) {
    const donorSelect = document.getElementById('planning_donor_' + projectId);
    const otherSection = document.getElementById('planning_donor_other_' + projectId);
    
    if (donorSelect && otherSection) {
        if (donorSelect.value === 'OTHER') {
            otherSection.style.display = 'block';
        } else {
            otherSection.style.display = 'none';
        }
    }
}

// Handle donor selection for edit project form
function handleEditProjectDonorSelection() {
    const donorSelect = document.getElementById('edit_project_donor');
    const otherSection = document.getElementById('edit_project_donor_other');
    
    if (donorSelect && otherSection) {
        if (donorSelect.value === 'OTHER') {
            otherSection.style.display = 'block';
        } else {
            otherSection.style.display = 'none';
        }
    }
}

// Initialize edit project form on page load
document.addEventListener('DOMContentLoaded', function() {
    // Initialize donor selection for edit project form
    const editDonorSelect = document.getElementById('edit_project_donor');
    if (editDonorSelect) {
        handleEditProjectDonorSelection();
        // Also trigger on change
        editDonorSelect.addEventListener('change', handleEditProjectDonorSelection);
    }
});

// Edit project and navigate to specific section
function editProject(projectId, section) {
    const url = '?project_id=' + projectId + (section ? '#' + section : '');
    window.location.href = url;
    // Scroll to section after page loads
    setTimeout(() => {
        const sectionEl = document.getElementById(section);
        if (sectionEl) {
            sectionEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
            // Open the section if it's collapsible
            const toggle = sectionEl.closest('.card').querySelector('.section-toggle');
            if (toggle) {
                const content = sectionEl.closest('.card').querySelector('.section-content');
                if (content && content.style.display === 'none') {
                    toggle.click();
                }
            }
        }
    }, 100);
}

// Export section data
function exportSection(sectionName) {
    const projectId = <?php echo $selected_project_id ?: 0; ?>;
    if (!projectId) {
        alert('Please select a project first');
        return;
    }
    
    // Create export URL
    const url = '?action=export_section&section=' + sectionName + '&project_id=' + projectId;
    window.open(url, '_blank');
}

// Edit functions - navigate to form and pre-fill data
function editResult(id) {
    window.location.href = '?project_id=<?php echo $selected_project_id; ?>&edit_result=' + id + '#results';
    setTimeout(() => {
        toggleSection('add-result');
        // Scroll to the form
        const form = document.querySelector('#add-result form');
        if (form) {
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }, 100);
}

function editImpactIndicator(id) {
    window.location.href = '?project_id=<?php echo $selected_project_id; ?>&edit_impact_indicator=' + id + '#indicators';
    setTimeout(() => {
        toggleSection('add-impact');
        const form = document.querySelector('#add-impact form');
        if (form) {
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }, 100);
}

function editOutcomeIndicator(id) {
    window.location.href = '?project_id=<?php echo $selected_project_id; ?>&edit_outcome_indicator=' + id + '#indicators';
    setTimeout(() => {
        toggleSection('add-outcome');
        const form = document.querySelector('#add-outcome form');
        if (form) {
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }, 100);
}

function editOutputIndicator(id) {
    window.location.href = '?project_id=<?php echo $selected_project_id; ?>&edit_output_indicator=' + id + '#indicators';
    setTimeout(() => {
        toggleSection('add-output');
        const form = document.querySelector('#add-output form');
        if (form) {
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }, 100);
}

function editActivity(id) {
    window.location.href = '?project_id=<?php echo $selected_project_id; ?>&edit_activity=' + id + '#activities';
    setTimeout(() => {
        toggleSection('add-activity');
        const form = document.querySelector('#add-activity form');
        if (form) {
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }, 100);
}

// Export comprehensive view
function exportComprehensiveView(section) {
    const projectId = <?php echo $selected_project_id ?: 0; ?>;
    if (!projectId) {
        alert('Please select a project first');
        return;
    }
    
    let url = '?action=export_section&section=' + section + '&project_id=' + projectId;
    if (section === 'all') {
        url = '?action=export_logframe_csv&project_id=' + projectId;
    }
    window.open(url, '_blank');
}

// Export projects template
function exportProjectsTemplate() {
    const headers = ['code', 'title', 'donor', 'status', 'start_date', 'end_date', 'region_name', 'zone_name', 'woreda_name'];
    const csvContent = "data:text/csv;charset=utf-8," + headers.join(',') + "\n";
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "projects_template.csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Beneficiary table functionality
let rowCount = <?php
    if (isset($project_beneficiaries) && is_array($project_beneficiaries)) {
        echo (int)count($project_beneficiaries);
    } elseif (isset($project_locations) && is_array($project_locations)) {
        echo (int)count($project_locations);
    } else {
        echo 1;
    }
?>;

function addRow() {
    const tbody = document.querySelector('#beneficiaryTable tbody');
    if (!tbody) return;

    const rowCount = tbody.querySelectorAll('tr').length;
    const idx = rowCount;

    const newRow = document.createElement('tr');
    newRow.innerHTML = `
        <td>
            <select name="beneficiaries[${idx}][region_id]" class="form-select region-select" onchange="handleLocationChange(this, 'region')">
                <option value="">Select...</option>
                <?php foreach ($regions_list as $r): ?>
                    <option value="<?php echo (int)$r['id']; ?>"><?php echo htmlspecialchars($r['name']); ?></option>
                <?php endforeach; ?>
                <option value="-1">Other</option>
            </select>
            <input type="text" name="beneficiaries[${idx}][region_other]" class="form-control other-input" placeholder="Specify region" style="display:none;margin-top:4px;">
        </td>
        <td>
            <select name="beneficiaries[${idx}][zone_id]" class="form-select zone-select" onchange="handleLocationChange(this, 'zone')">
                <option value="">Select...</option>
                <option value="-1">Other</option>
            </select>
            <input type="text" name="beneficiaries[${idx}][zone_other]" class="form-control other-input" placeholder="Specify zone" style="display:none;margin-top:4px;">
        </td>
        <td>
            <select name="beneficiaries[${idx}][woreda_id]" class="form-select woreda-select">
                <option value="">Select...</option>
                <option value="-1">Other</option>
            </select>
            <input type="text" name="beneficiaries[${idx}][woreda_other]" class="form-control other-input" placeholder="Specify woreda" style="display:none;margin-top:4px;">
        </td>
        <td>
            <select name="beneficiaries[${idx}][beneficiary_type]" class="form-select">
                <option value="idp">IDP</option>
                <option value="host">Host Community</option>
                <option value="returnee">Returnee</option>
                <option value="refugee">Refugee</option>
                <option value="other">Other</option>
            </select>
        </td>
        <td><input type="number" min="0" name="beneficiaries[${idx}][women]" class="calc-input" value="0" oninput="calculateRow(this)"></td>
        <td><input type="number" min="0" name="beneficiaries[${idx}][girls]" class="calc-input" value="0" oninput="calculateRow(this)"></td>
        <td><input type="number" min="0" name="beneficiaries[${idx}][men]" class="calc-input" value="0" oninput="calculateRow(this)"></td>
        <td><input type="number" min="0" name="beneficiaries[${idx}][boys]" class="calc-input" value="0" oninput="calculateRow(this)"></td>
        <td><input type="number" min="0" name="beneficiaries[${idx}][pwd_count]" class="calc-input" value="0" oninput="calculateRow(this)"></td>
        <td><input type="number" min="0" name="beneficiaries[${idx}][hh_count]" class="calc-input" value="0" oninput="calculateRow(this)"></td>
        <td class="row-total">0</td>
        <td class="row-percentage">0.00%</td>
        <td><button type="button" class="btn btn-warning btn-sm" onclick="removeRow(this)">🗑️</button></td>
    `;

    tbody.appendChild(newRow);
    calculateRow(newRow);
    updateBeneficiaryCounters();
}

function removeRow(button) {
    const row = button.closest('tr');
    if (!row) return;

    const idInput = row.querySelector('input[name*="[id]"]');
    const idVal = parseInt(idInput?.value || "0", 10) || 0;
    if (idVal > 0) {
        const container = document.getElementById('beneficiaryDeletedIdsContainer');
        if (container) {
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'beneficiaries_deleted_ids[]';
            hidden.value = String(idVal);
            container.appendChild(hidden);
        }
    }

    row.remove();
    calculateTotals();
    syncOtherInputs();
    updateBeneficiaryCounters();

    const imp = document.getElementById('beneficiaryImportFile');
    if (imp) {
        imp.addEventListener('change', (e) => {
            const f = e.target.files && e.target.files[0];
            if (f) importBeneficiaryCSV(f);
        });
    }

    const beneForm = document.getElementById('beneficiaryForm');
    if (beneForm) {
        beneForm.addEventListener('submit', (e) => {
            if (!validateBeneficiaryForm()) {
                e.preventDefault();
                e.stopPropagation();
            }
        });
    }

    updateBeneficiaryCounters();
}

function calculateRow(inputOrRow) {
    const row = (inputOrRow && inputOrRow.closest) ? (inputOrRow.closest('tr') || inputOrRow) : inputOrRow;
    if (!row) return;

    const womenEl = row.querySelector('input[name*="[women]"]');
    const girlsEl = row.querySelector('input[name*="[girls]"]');
    const menEl   = row.querySelector('input[name*="[men]"]');
    const boysEl  = row.querySelector('input[name*="[boys]"]');
    const pwdEl   = row.querySelector('input[name*="[pwd_count]"]') || row.querySelector('input[name*="[pwd]"]');
    const hhEl    = row.querySelector('input[name*="[hh_count]"]');

    const women = Math.max(0, (parseInt(womenEl?.value || "0", 10) || 0));
    const girls = Math.max(0, (parseInt(girlsEl?.value || "0", 10) || 0));
    const men   = Math.max(0, (parseInt(menEl?.value   || "0", 10) || 0));
    const boys  = Math.max(0, (parseInt(boysEl?.value  || "0", 10) || 0));
    const pwd   = Math.max(0, (parseInt(pwdEl?.value   || "0", 10) || 0));
    const hh    = Math.max(0, (parseInt(hhEl?.value    || "0", 10) || 0));

    const peopleTotal = women + girls + men + boys + pwd;
    const total = (peopleTotal > 0) ? peopleTotal : hh;

    const totalCell = row.querySelector('.row-total');
    if (totalCell) totalCell.textContent = total;

    calculateTotals();
}

function calculateTotals() {
    let totalWomen = 0, totalGirls = 0, totalMen = 0, totalBoys = 0, totalPwd = 0, totalHH = 0, grandTotal = 0;

    const rows = document.querySelectorAll('#beneficiaryTable tbody tr');
    rows.forEach(row => {
        const women = Math.max(0, parseInt(row.querySelector('input[name*="[women]"]')?.value || "0", 10) || 0);
        const girls = Math.max(0, parseInt(row.querySelector('input[name*="[girls]"]')?.value || "0", 10) || 0);
        const men   = Math.max(0, parseInt(row.querySelector('input[name*="[men]"]')?.value   || "0", 10) || 0);
        const boys  = Math.max(0, parseInt(row.querySelector('input[name*="[boys]"]')?.value  || "0", 10) || 0);
        const pwd   = Math.max(0, parseInt((row.querySelector('input[name*="[pwd_count]"]') || row.querySelector('input[name*="[pwd]"]'))?.value || "0", 10) || 0);
        const hh    = Math.max(0, parseInt(row.querySelector('input[name*="[hh_count]"]')?.value || "0", 10) || 0);

        totalWomen += women;
        totalGirls += girls;
        totalMen   += men;
        totalBoys  += boys;
        totalPwd   += pwd;
        totalHH    += hh;

        const peopleTotal = women + girls + men + boys + pwd;
        const rowTotal = (peopleTotal > 0) ? peopleTotal : hh;
        grandTotal += rowTotal;

        const totalCell = row.querySelector('.row-total');
        if (totalCell) totalCell.textContent = rowTotal;
    });

    const tw = document.getElementById('total-women');
    const tg = document.getElementById('total-girls');
    const tm = document.getElementById('total-men');
    const tb = document.getElementById('total-boys');
    const tp = document.getElementById('total-pwd');
    const th = document.getElementById('total-hh');
    const gt = document.getElementById('grand-total');

    if (tw) tw.textContent = totalWomen;
    if (tg) tg.textContent = totalGirls;
    if (tm) tm.textContent = totalMen;
    if (tb) tb.textContent = totalBoys;
    if (tp) tp.textContent = totalPwd;
    if (th) th.textContent = totalHH;
    if (gt) gt.textContent = grandTotal;

    // Update percentages per row
    rows.forEach(row => {
        const rowTotal = parseInt(row.querySelector('.row-total')?.textContent || "0", 10) || 0;
        const pct = grandTotal > 0 ? (rowTotal / grandTotal) * 100 : 0;
        const pctCell = row.querySelector('.row-percentage');
        if (pctCell) pctCell.textContent = pct.toFixed(2) + '%';
    });
}
);

    const tw = document.getElementById('total-women');
    const tg = document.getElementById('total-girls');
    const tm = document.getElementById('total-men');
    const tb = document.getElementById('total-boys');
    const tp = document.getElementById('total-pwd');
    const gt = document.getElementById('grand-total');

    if (tw) tw.textContent = totalWomen;
    if (tg) tg.textContent = totalGirls;
    if (tm) tm.textContent = totalMen;
    if (tb) tb.textContent = totalBoys;
    if (tp) tp.textContent = totalPwd;
    if (gt) gt.textContent = grandTotal;

    // Update percentages per row
    rows.forEach(row => {
        const women = parseInt(row.querySelector('input[name*="[women]"]')?.value || "0", 10) || 0;
        const girls = parseInt(row.querySelector('input[name*="[girls]"]')?.value || "0", 10) || 0;
        const men   = parseInt(row.querySelector('input[name*="[men]"]')?.value   || "0", 10) || 0;
        const boys  = parseInt(row.querySelector('input[name*="[boys]"]')?.value  || "0", 10) || 0;
        const pwd   = parseInt((row.querySelector('input[name*="[pwd_count]"]') || row.querySelector('input[name*="[pwd]"]'))?.value || "0", 10) || 0;

        const rowTotal = women + girls + men + boys + pwd;
        const percentage = grandTotal > 0 ? ((rowTotal / grandTotal) * 100).toFixed(2) : "0.00";

        const percCell = row.querySelector('.row-percentage');
        if (percCell) percCell.textContent = percentage + '%';
    });
}
// Toggle 'Other' text inputs for a beneficiary row
function toggleOther(row, level, value) {
    if (!row) return;
    const input = row.querySelector(`.${level}-other`);
    if (!input) return;

    if (value === 'other') {
        input.style.display = '';
        input.required = true;
    } else {
        input.style.display = 'none';
        input.required = false;
    }
}

function syncOtherInputs() {
    document.querySelectorAll('#beneficiaryTable tbody tr').forEach(tr => {
        const r = tr.querySelector('.region-select');
        const z = tr.querySelector('.zone-select');
        const w = tr.querySelector('.woreda-select');
        if (r) toggleOther(tr, 'region', r.value);
        if (z) toggleOther(tr, 'zone', z.value);
        if (w) toggleOther(tr, 'woreda', w.value);
    });
}

function updateBeneficiaryCounters() {
    const showing = document.querySelectorAll('#beneficiaryTable tbody tr').length;
    const showingEl = document.getElementById('beneShowing');
    if (showingEl) showingEl.textContent = String(showing);
}

// CSV Template download
function downloadBeneficiaryTemplate() {
    window.location.href = 'planning.php?action=download_beneficiary_template_csv';
}

// Import CSV
function triggerBeneficiaryImport() {
    const fileInput = document.getElementById('beneficiaryImportFile');
    if (fileInput) fileInput.click();
}

async function importBeneficiaryCSV(file) {
    const pid = <?php echo (int)($selected_project_id ?? 0); ?>;
    if (!pid) return alert('Select a project first.');
    if (!file) return;

    const replace = document.getElementById('beneficiaryImportReplace')?.checked ? 1 : 0;

    const fd = new FormData();
    fd.append('action', 'import_beneficiaries_csv');
    fd.append('project_id', String(pid));
    fd.append('replace_existing', String(replace));
    fd.append('beneficiary_csv', file);

    const resp = await fetch('planning.php', { method: 'POST', body: fd, credentials: 'same-origin' });
    if (resp.redirected) window.location.href = resp.url;
    else window.location.reload();
}

// Pagination / lazy loading
let beneficiaryTotal = <?php echo (int)($beneficiary_total ?? 0); ?>;
let beneficiaryNextOffset = <?php echo (int)count($project_beneficiaries); ?>;
const beneficiaryLimit = 25;

async function loadMoreBeneficiaries() {
    const pid = <?php echo (int)($selected_project_id ?? 0); ?>;
    if (!pid) return alert('Select a project first.');
    if (beneficiaryNextOffset >= beneficiaryTotal) return;

    const url = `planning.php?action=beneficiary_rows_json&project_id=${pid}&offset=${beneficiaryNextOffset}&limit=${beneficiaryLimit}`;
    const res = await fetch(url, { credentials: 'same-origin' });
    const data = await res.json();
    if (!data.ok) return alert(data.error || 'Failed to load more rows.');

    appendBeneficiaryRows(data.rows || []);
    beneficiaryTotal = data.total || beneficiaryTotal;
    beneficiaryNextOffset = data.next_offset || (beneficiaryNextOffset + beneficiaryLimit);

    updateBeneficiaryCounters();
    calculateTotals();
    syncOtherInputs();
}

async function loadAllBeneficiaries() {
    while (beneficiaryNextOffset < beneficiaryTotal) {
        // eslint-disable-next-line no-await-in-loop
        await loadMoreBeneficiaries();
    }
}

function appendBeneficiaryRows(rows) {
    const tbody = document.querySelector('#beneficiaryTable tbody');
    if (!tbody) return;

    const regions = <?php echo json_encode($regions); ?>;
    const zones   = <?php echo json_encode($zones); ?>;
    const woredas = <?php echo json_encode($woredas); ?>;
    const btypes  = <?php echo json_encode($beneficiary_types); ?>;

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function opt(label, value, selected) {
        return `<option value="${String(value)}"${selected ? ' selected' : ''}>${label}</option>`;
    }

    function buildRegionOptions(selectedId, isOtherSelected) {
        let html = opt('-- Select Region --','', !selectedId && !isOtherSelected);
        regions.forEach(r => { html += opt(escapeHtml(r.name), r.id, selectedId && parseInt(selectedId)===parseInt(r.id)); });
        html += opt('Other...', 'other', !!isOtherSelected);
        return html;
    }

    function buildZoneOptions(regionId, selectedId, isOtherSelected) {
        let html = opt('-- Select Zone --','', !selectedId && !isOtherSelected);
        if (regionId) zones.forEach(z => { if (parseInt(z.region_id)===parseInt(regionId)) html += opt(escapeHtml(z.name), z.id, selectedId && parseInt(selectedId)===parseInt(z.id)); });
        html += opt('Other...', 'other', !!isOtherSelected);
        return html;
    }

    function buildWoredaOptions(zoneId, selectedId, isOtherSelected) {
        let html = opt('-- Select Woreda --','', !selectedId && !isOtherSelected);
        if (zoneId) woredas.forEach(w => { if (parseInt(w.zone_id)===parseInt(zoneId)) html += opt(escapeHtml(w.name), w.id, selectedId && parseInt(selectedId)===parseInt(w.id)); });
        html += opt('Other...', 'other', !!isOtherSelected);
        return html;
    }

    function buildBTypeOptions(selected) {
        let html = '';
        Object.keys(btypes).forEach(k => {
            html += opt(escapeHtml(btypes[k]), k, selected===k);
        });
        return html;
    }

    rows.forEach(r => {
        const idx = rowCount;

        const regionId = r.region_id ? String(r.region_id) : '';
        const zoneId   = r.zone_id ? String(r.zone_id) : '';
        const woredaId = r.woreda_id ? String(r.woreda_id) : '';

        const regionIsOther = (!regionId && (r.region_other || '')) ? true : false;
        const zoneIsOther   = (!zoneId && (r.zone_other || '')) ? true : false;
        const woredaIsOther = (!woredaId && (r.woreda_other || '')) ? true : false;

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>
                <input type="hidden" name="beneficiaries[${idx}][id]" value="${escapeHtml(String(r.id||''))}">
                <select name="beneficiaries[${idx}][region_id]" class="region-select" onchange="updateZones(this)">
                    ${buildRegionOptions(regionId, regionIsOther)}
                </select>
                <input type="text" name="beneficiaries[${idx}][region_other]" class="region-other" placeholder="Type region" value="${escapeHtml(String(r.region_other||''))}" style="${regionIsOther ? '' : 'display:none;'} margin-top:4px;">
            </td>
            <td>
                <select name="beneficiaries[${idx}][zone_id]" class="zone-select" onchange="updateWoredas(this)">
                    ${buildZoneOptions(regionId, zoneId, zoneIsOther)}
                </select>
                <input type="text" name="beneficiaries[${idx}][zone_other]" class="zone-other" placeholder="Type zone" value="${escapeHtml(String(r.zone_other||''))}" style="${zoneIsOther ? '' : 'display:none;'} margin-top:4px;">
            </td>
            <td>
                <select name="beneficiaries[${idx}][woreda_id]" class="woreda-select" onchange="toggleOther(this.closest('tr'),'woreda', this.value)">
                    ${buildWoredaOptions(zoneId, woredaId, woredaIsOther)}
                </select>
                <input type="text" name="beneficiaries[${idx}][woreda_other]" class="woreda-other" placeholder="Type woreda" value="${escapeHtml(String(r.woreda_other||''))}" style="${woredaIsOther ? '' : 'display:none;'} margin-top:4px;">
            </td>
            <td>
                <select name="beneficiaries[${idx}][beneficiary_type]">${buildBTypeOptions(String(r.beneficiary_type||'other'))}</select>
            </td>
            <td><input type="number" min="0" name="beneficiaries[${idx}][women]" class="calc-input" value="${escapeHtml(String(r.women||0))}" oninput="calculateRow(this)"></td>
            <td><input type="number" min="0" name="beneficiaries[${idx}][girls]" class="calc-input" value="${escapeHtml(String(r.girls||0))}" oninput="calculateRow(this)"></td>
            <td><input type="number" min="0" name="beneficiaries[${idx}][men]" class="calc-input" value="${escapeHtml(String(r.men||0))}" oninput="calculateRow(this)"></td>
            <td><input type="number" min="0" name="beneficiaries[${idx}][boys]" class="calc-input" value="${escapeHtml(String(r.boys||0))}" oninput="calculateRow(this)"></td>
            <td><input type="number" min="0" name="beneficiaries[${idx}][pwd_count]" class="calc-input" value="${escapeHtml(String(r.pwd_count||0))}" oninput="calculateRow(this)"></td>
            <td class="row-total">${escapeHtml(String(r.total||0))}</td>
            <td class="row-percentage">${escapeHtml(String(r.share_percentage||0))}%</td>
            <td><button type="button" class="btn btn-warning btn-sm" onclick="removeRow(this)">🗑️</button></td>
        `;

        tbody.appendChild(tr);
        rowCount++;
        calculateRow(tr);
    });
}

function validateBeneficiaryForm() {
    let ok = true;
    const rows = document.querySelectorAll('#beneficiaryTable tbody tr');
    rows.forEach(tr => {
        tr.querySelectorAll('.invalid-field').forEach(el => el.classList.remove('invalid-field'));

        const region = tr.querySelector('.region-select');
        const zone   = tr.querySelector('.zone-select');
        const woreda = tr.querySelector('.woreda-select');

        const regionOther = tr.querySelector('.region-other');
        const zoneOther = tr.querySelector('.zone-other');
        const woredaOther = tr.querySelector('.woreda-other');

        function mark(el) { if (el) el.classList.add('invalid-field'); ok = false; }

        if (!region?.value) mark(region);
        if (region?.value === 'other' && (!regionOther || !regionOther.value.trim())) mark(regionOther);

        if (!zone?.value) mark(zone);
        if (zone?.value === 'other' && (!zoneOther || !zoneOther.value.trim())) mark(zoneOther);

        if (!woreda?.value) mark(woreda);
        if (woreda?.value === 'other' && (!woredaOther || !woredaOther.value.trim())) mark(woredaOther);

        tr.querySelectorAll('input[type="number"]').forEach(inp => {
            const v = parseInt(inp.value || "0", 10) || 0;
            if (v < 0) mark(inp);
        });
    });

    if (!ok) alert('Please fix highlighted fields (required & non-negative) before saving.');
    return ok;
}


function downloadTextFile(filename, content, mimeType = 'text/plain;charset=utf-8') {
    const blob = new Blob([content], { type: mimeType });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 500);
}

function exportBeneficiariesCSV() {
    const pid = <?php echo (int)($selected_project_id ?? 0); ?>;
    if (!pid) return alert('Select a project first.');
    window.location.href = `planning.php?action=export_beneficiaries_csv&project_id=${pid}`;
}

function printBeneficiariesTable() {
    const table = document.getElementById('beneficiaryTable');
    if (!table) return alert('Beneficiary table not found.');

    try { calculateTotals(); } catch (e) {}

    const win = window.open('', '_blank');
    if (!win) return alert('Popup blocked. Please allow popups to print.');

    const css = `
        <style>
            body{font-family:Arial, sans-serif; padding:20px;}
            h2{margin:0 0 10px 0;}
            table{border-collapse:collapse; width:100%;}
            th,td{border:1px solid #ccc; padding:6px; font-size:12px;}
            th{background:#f5f5f5;}
        </style>
    `;

    const title = `<h2>Location-Based Beneficiary Distribution</h2>`;
    win.document.write(css + title + table.outerHTML);
    win.document.close();
    win.focus();
    win.print();
}

// ============ Location dropdown functionality ============
function updateZones(regionSelect) {
    const regionId     = regionSelect.value;
    const row          = regionSelect.closest('tr');
    const zoneSelect   = row.querySelector('.zone-select');
    const woredaSelect = row.querySelector('.woreda-select');

    toggleOther(row, 'region', regionId);

    zoneSelect.innerHTML   = '<option value="">-- Select Zone --</option>';
    woredaSelect.innerHTML = '<option value="">-- Select Woreda --</option>';

    if (regionId === 'other') {
        zoneSelect.insertAdjacentHTML('beforeend', '<option value="other" selected>Other...</option>');
        toggleOther(row, 'zone', 'other');
        woredaSelect.insertAdjacentHTML('beforeend', '<option value="other" selected>Other...</option>');
        toggleOther(row, 'woreda', 'other');
        return;
    }

    if (!regionId) {
        zoneSelect.insertAdjacentHTML('beforeend', '<option value="other">Other...</option>');
        woredaSelect.insertAdjacentHTML('beforeend', '<option value="other">Other...</option>');
        toggleOther(row, 'zone', zoneSelect.value);
        toggleOther(row, 'woreda', woredaSelect.value);
        return;
    }

    const zones = <?php echo json_encode($zones); ?>;
    zones.forEach(zone => {
        if (parseInt(zone.region_id) === parseInt(regionId)) {
            const option = document.createElement('option');
            option.value = zone.id;
            option.textContent = zone.name;
            zoneSelect.appendChild(option);
        }
    });

    zoneSelect.insertAdjacentHTML('beforeend', '<option value="other">Other...</option>');
    woredaSelect.insertAdjacentHTML('beforeend', '<option value="other">Other...</option>');
    toggleOther(row, 'zone', zoneSelect.value);
    toggleOther(row, 'woreda', woredaSelect.value);
}

function updateWoredas(zoneSelect) {
    const zoneId = zoneSelect.value;
    const row = zoneSelect.closest('tr');
    const woredaSelect = row.querySelector('.woreda-select');

    toggleOther(row, 'zone', zoneId);

    woredaSelect.innerHTML = '<option value="">-- Select Woreda --</option>';

    if (zoneId === 'other') {
        woredaSelect.insertAdjacentHTML('beforeend', '<option value="other" selected>Other...</option>');
        toggleOther(row, 'woreda', 'other');
        return;
    }

    if (!zoneId) {
        woredaSelect.insertAdjacentHTML('beforeend', '<option value="other">Other...</option>');
        toggleOther(row, 'woreda', woredaSelect.value);
        return;
    }

    const woredas = <?php echo json_encode($woredas); ?>;
    woredas.forEach(woreda => {
        if (parseInt(woreda.zone_id) === parseInt(zoneId)) {
            const option = document.createElement('option');
            option.value = woreda.id;
            option.textContent = woreda.name;
            woredaSelect.appendChild(option);
        }
    });

    woredaSelect.insertAdjacentHTML('beforeend', '<option value="other">Other...</option>');
    toggleOther(row, 'woreda', woredaSelect.value);
}

// Initialize calculations on page load
document.addEventListener('DOMContentLoaded', function() {
    calculateTotals();

    // Share type toggle
    const shareTypeSelect      = document.querySelector('.share-type');
    const emailFields          = document.querySelector('.email-field');
    const emailAdditionalFields = document.querySelector('.email-fields');

    if (shareTypeSelect && emailFields && emailAdditionalFields) {
        shareTypeSelect.addEventListener('change', function() {
            if (this.value === 'email') {
                emailFields.style.display = 'block';
                emailAdditionalFields.style.display = 'flex';
            } else {
                emailFields.style.display = 'none';
                emailAdditionalFields.style.display = 'none';
            }
        });
    }

    // Auto-calculate + validate totals for indicator forms (persons vs non-person units)
    document.querySelectorAll('.indicator-form').forEach(form => {
        const unitType  = form.querySelector('.unit-type');
        const inputMode = form.querySelector('.input-mode');
        if (!unitType || !inputMode) return;

        // Total input may be named differently in some sections (keep compatibility)
        let totalInput = form.querySelector('input.total-target')
            || form.querySelector('input[name="total_target"]')
            || form.querySelector('input[name="target_total"]');

        if (!totalInput) return;
        totalInput.classList.add('total-target');

        const ageSexNames = ['boys_u5','girls_u5','boys_5_17','girls_5_17','men_18_59','women_18_59','men_60p','women_60p'];
        const ageSexInputs = ageSexNames
            .map(n => form.querySelector(`input[name="${n}"]`))
            .filter(Boolean);

        const pwdInput = form.querySelector('input[name="pwd_count"]');
        const hhInput  = form.querySelector('input[name="hh_count"]');

        const totalLabel = totalInput.closest('label');
        function setLabelText(txt) {
            if (!totalLabel) return;
            const textNode = Array.from(totalLabel.childNodes).find(n => n.nodeType === Node.TEXT_NODE);
            if (textNode) textNode.textContent = txt + " ";
        }

        const DECIMAL_UNITS = new Set([
            'kg','g','gram','grams','ton','tons',
            'km','m','meter','meters','metre','metres','cm','mm',
            'm2','m3','hectare','ha','sqm',
            'liter','litre','l','ml'
        ]);

        function unitCategory(u) {
            u = (u || '').toLowerCase().trim();
            if (u === '%' || u === 'percent' || u === 'percentage') return 'percent';
            if (DECIMAL_UNITS.has(u)) return 'decimal';
            return 'integer';
        }

        function applyNumericRules() {
            const u = unitType.value || '';
            const cat = unitCategory(u);

            totalInput.type = 'number';
            totalInput.min  = '0';
            totalInput.removeAttribute('max');

            if (cat === 'percent') {
                totalInput.step = '0.01';
                totalInput.max  = '100';
                setLabelText('Percent (%)');
            } else if (cat === 'decimal') {
                totalInput.step = '0.01';
                setLabelText('Total target');
            } else {
                totalInput.step = '1';
                setLabelText('Total target');
            }
        }

        function clampValue() {
            const cat = unitCategory(unitType.value);
            let v = parseFloat(totalInput.value);
            if (isNaN(v) || v < 0) v = 0;

            if (cat === 'percent') {
                if (v > 100) v = 100;
                totalInput.value = v.toFixed(2);
            } else if (cat === 'decimal') {
                totalInput.value = v.toFixed(2);
            } else {
                totalInput.value = String(Math.max(0, Math.round(v)));
            }
        }

        function recalcPersonsTotal() {
            // Auto-total only for PERSONS + SADD (exclude PWD + HH)
            if ((unitType.value || '').toLowerCase() === 'persons' && (inputMode.value || '') === 'sadd') {
                let total = 0;
                ageSexInputs.forEach(inp => {
                    const v = parseFloat(inp.value);
                    if (!isNaN(v)) total += v;
                });
                totalInput.value = String(Math.max(0, Math.round(total)));
                totalInput.readOnly = true;
            } else {
                totalInput.readOnly = false;
            }
        }

        function refreshAll() {
            applyNumericRules();
            recalcPersonsTotal();
            // For non-person or non-SADD, keep stored value consistent with numeric rules
            if (!(((unitType.value || '').toLowerCase() === 'persons') && (inputMode.value || '') === 'sadd')) {
                clampValue();
            }
        }

        unitType.addEventListener('change', refreshAll);
        inputMode.addEventListener('change', refreshAll);

        ageSexInputs.forEach(inp => inp.addEventListener('input', recalcPersonsTotal));
        if (pwdInput) pwdInput.addEventListener('input', recalcPersonsTotal); // does not affect total (kept for consistency)
        if (hhInput)  hhInput.addEventListener('input', recalcPersonsTotal);  // does not affect total (kept for consistency)

        // When user manually types totals (non-person / non-SADD), enforce numeric rules
        totalInput.addEventListener('blur', clampValue);

        // Initialize once on load
        refreshAll();
    });
});
// Print functionality
function printPlanning() {
    window.print();
}
</script>

</body>
</html>