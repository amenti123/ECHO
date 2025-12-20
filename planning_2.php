<?php
/**
 * PROJECT PLANNING SUITE — ULTRA-ROBUST (NO-JS REQUIRED)
 * ----------------------------------------------------------------------
 * This build is designed to work even when:
 * - Bootstrap CDN is blocked/offline
 * - Browser blocks JS
 * - Modals/accordion don't open
 *
 * Instead of Bootstrap modals, it uses server-side Edit forms (prefilled),
 * and native HTML <details> sections (no JS needed) for collapsible cards.
 *
 * FIXES THE "NOT OPENING / BUTTONS NOT WORKING" issue by removing
 * the dependency on Bootstrap JS and AJAX prefill.
 *
 * INCLUDED:
 * ✅ Project selection (GET+SESSION)
 * ✅ Results CRUD (objective/impact/outcome/output)
 * ✅ Indicators CRUD (impact/outcome/output) with correct SADD total rules
 * ✅ Activities CRUD (auto detects existing activities table)
 * ✅ Gantt preview (based on activity start/end)
 * ✅ Beneficiaries CRUD + totals + share %
 * ✅ Export (Full + per section) + template CSV downloads
 *
 * Notes:
 * - SADD Total excludes PWD (PWD recorded separately to avoid double count)
 * - If Unit is HH, Total is manual (not forced by SADD)
 * ----------------------------------------------------------------------
 */

declare(strict_types=1);

// ------------------------------------------------------
// ROBUST HELPERS LOADING
// ------------------------------------------------------
$rootPath = dirname(__DIR__); // parent of pages/
if (!function_exists('smart_require_once')) {
    function smart_require_once(string $label, array $candidates): void
    {
        $tried = [];
        foreach ($candidates as $path) {
            $tried[] = (string)$path;
            if (is_string($path) && $path !== '' && file_exists($path)) {
                require_once $path;
                return;
            }
        }

        $common_paths = [
            dirname(__DIR__) . '/helpers.php',
            dirname(__DIR__) . '/includes/helpers.php',
            $_SERVER['DOCUMENT_ROOT'] . '/helpers.php',
            $_SERVER['DOCUMENT_ROOT'] . '/hrs/helpers.php',
            $_SERVER['DOCUMENT_ROOT'] . '/health_reporting_system/helpers.php',
            $_SERVER['DOCUMENT_ROOT'] . '/health_reporting_system/includes/helpers.php',
        ];

        foreach ($common_paths as $path) {
            if (file_exists($path)) {
                require_once $path;
                return;
            }
        }

        http_response_code(500);
        $msg = $label . " file not found.\n\nTried:\n" . implode("\n", array_merge($tried, $common_paths));
        die("Fatal Error: " . nl2br(htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')));
    }
}

$script_dir = dirname($_SERVER['SCRIPT_FILENAME'] ?? __FILE__);
$script_parent = dirname($script_dir);

smart_require_once('helpers.php', [
    $rootPath . '/helpers.php',
    $rootPath . '/includes/helpers.php',
    __DIR__ . '/../helpers.php',
    __DIR__ . '/../includes/helpers.php',
    $script_parent . '/helpers.php',
    $script_parent . '/includes/helpers.php',
    dirname(__DIR__) . '/helpers.php',
    dirname(__DIR__) . '/includes/helpers.php',
    __DIR__ . '/helpers.php',
]);

if (session_status() === PHP_SESSION_NONE) session_start();

if (!function_exists('require_login') || !function_exists('getPDO')) {
    http_response_code(500);
    die("Fatal Error: helpers.php must provide require_login() and getPDO().");
}

require_login();
$pdo = getPDO();

// ------------------------------------------------------
// UTILITIES
// ------------------------------------------------------
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function now_str(): string { return date('Y-m-d H:i:s'); }
function is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; }

function flash_add(string $type, string $msg): void { $_SESSION['planning_flash'][$type][] = $msg; }
function flash_take(): array { $f = $_SESSION['planning_flash'] ?? []; unset($_SESSION['planning_flash']); return $f; }

if (!function_exists('table_exists')) {
    function table_exists(PDO $pdo, string $table): bool {
        try { $st=$pdo->prepare("SHOW TABLES LIKE ?"); $st->execute([$table]); return (bool)$st->fetch(PDO::FETCH_NUM); }
        catch (Throwable $e) { return false; }
    }
}

if (!function_exists('table_has_column')) {
if (!function_exists('table_has_column')) {
    function table_has_column(PDO $pdo, string $table, string $col): bool {
        try { $st=$pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?"); $st->execute([$col]); return (bool)$st->fetch(PDO::FETCH_ASSOC); }
        catch (Throwable $e) { return false; }
    }
}
}


if (!function_exists('safe_exec')) {
    function safe_exec(PDO $pdo, string $sql): void { try { $pdo->exec($sql); } catch (Throwable $e) {} }
}


function normalize_unit(string $u): string {
    $u = strtolower(trim($u));
    if (in_array($u, ['hh','household','households'], true)) return 'hh';
    return $u;
}
function sum_sadd(array $d): int {
    $keys = ['boys_u5','girls_u5','boys_5_17','girls_5_17','men_18_59','women_18_59','men_60p','women_60p'];
    $sum = 0;
    foreach ($keys as $k) $sum += (int)($d[$k] ?? 0);
    return $sum;
}

// ------------------------------------------------------
// ENSURE TABLES (non-destructive)
// ------------------------------------------------------
function ensure_tables(PDO $pdo): void
{
    safe_exec($pdo, "CREATE TABLE IF NOT EXISTS results_chain (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        level ENUM('objective','impact','outcome','output') NOT NULL,
        code VARCHAR(80) NULL,
        name TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(project_id),
        INDEX(level)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach (['impact_indicators','outcome_indicators','output_indicators'] as $t) {
        safe_exec($pdo, "CREATE TABLE IF NOT EXISTS `$t` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            result_id INT NOT NULL,
            beneficiary_type ENUM('host','returnee','idp','refugee','pwd','mixed','other') NOT NULL DEFAULT 'host',
            indicator_code VARCHAR(80),
            indicator_name TEXT NOT NULL,
            unit_type VARCHAR(50) DEFAULT 'persons',
            input_mode ENUM('sadd','count','percent_pair','percent_direct') NOT NULL DEFAULT 'sadd',
            total_target INT DEFAULT 0,
            boys_u5 INT DEFAULT 0,
            girls_u5 INT DEFAULT 0,
            boys_5_17 INT DEFAULT 0,
            girls_5_17 INT DEFAULT 0,
            men_18_59 INT DEFAULT 0,
            women_18_59 INT DEFAULT 0,
            men_60p INT DEFAULT 0,
            women_60p INT DEFAULT 0,
            pwd_count INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX(project_id),
            INDEX(result_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Back-compat: if old target_total exists, add total_target and sync once.
        if (table_has_column($pdo, $t, 'target_total') && !table_has_column($pdo, $t, 'total_target')) {
            safe_exec($pdo, "ALTER TABLE `$t` ADD COLUMN total_target INT DEFAULT 0");
            safe_exec($pdo, "UPDATE `$t` SET total_target = COALESCE(total_target,0) + COALESCE(target_total,0) WHERE COALESCE(total_target,0)=0");
        }
    }

    safe_exec($pdo, "CREATE TABLE IF NOT EXISTS project_beneficiaries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        region_id INT NULL,
        zone_id INT NULL,
        woreda_id INT NULL,
        beneficiary_type VARCHAR(60) NOT NULL,
        women INT DEFAULT 0,
        girls INT DEFAULT 0,
        men INT DEFAULT 0,
        boys INT DEFAULT 0,
        pwd_count INT DEFAULT 0,
        total INT DEFAULT 0,
        share_percentage DECIMAL(5,2) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(project_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Create planning_activities only if no compatible activity table found
    $candidates = ['activities','project_activities','planning_activities'];
    $found = null;
    foreach ($candidates as $t) {
        if (table_exists($pdo, $t) && table_has_column($pdo, $t, 'project_id')) {
            $ok = table_has_column($pdo, $t, 'activity_name') || table_has_column($pdo, $t, 'name');
            if ($ok) { $found = $t; break; }
        }
    }
    if ($found === null) {
        safe_exec($pdo, "CREATE TABLE IF NOT EXISTS planning_activities (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            output_result_id INT NULL,
            activity_code VARCHAR(80) NULL,
            activity_name TEXT NOT NULL,
            unit_type VARCHAR(50) DEFAULT 'sessions',
            target_total INT DEFAULT 0,
            start_date DATE NULL,
            end_date DATE NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX(project_id),
            INDEX(output_result_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
ensure_tables($pdo);

// ------------------------------------------------------
// ACTIVITY TABLE RESOLUTION (supports your existing schema)
// ------------------------------------------------------
function resolve_activity_table(PDO $pdo): array
{
    $candidates = ['activities','project_activities','planning_activities'];
    foreach ($candidates as $t) {
        if (!table_exists($pdo, $t)) continue;
        if (!table_has_column($pdo, $t, 'project_id')) continue;

        $map = [
            'table' => $t,
            'id' => 'id',
            'project_id' => 'project_id',
            'output_result_id' => table_has_column($pdo, $t, 'output_result_id') ? 'output_result_id' :
                                 (table_has_column($pdo, $t, 'output_id') ? 'output_id' : null),
            'code' => table_has_column($pdo, $t, 'activity_code') ? 'activity_code' :
                      (table_has_column($pdo, $t, 'code') ? 'code' : null),
            'name' => table_has_column($pdo, $t, 'activity_name') ? 'activity_name' :
                      (table_has_column($pdo, $t, 'name') ? 'name' : null),
            'unit' => table_has_column($pdo, $t, 'unit_type') ? 'unit_type' :
                      (table_has_column($pdo, $t, 'unit') ? 'unit' : null),
            'target' => table_has_column($pdo, $t, 'target_total') ? 'target_total' :
                        (table_has_column($pdo, $t, 'target') ? 'target' : null),
            'start' => table_has_column($pdo, $t, 'start_date') ? 'start_date' :
                       (table_has_column($pdo, $t, 'start') ? 'start' : null),
            'end' => table_has_column($pdo, $t, 'end_date') ? 'end_date' :
                     (table_has_column($pdo, $t, 'end') ? 'end' : null),
        ];

        if ($map['name'] !== null) {
            // safe fallbacks (won't crash)
            if ($map['unit'] === null) $map['unit'] = $map['name'];
            if ($map['target'] === null) $map['target'] = $map['name'];
            return $map;
        }
    }

    return [
        'table' => 'planning_activities',
        'id' => 'id',
        'project_id' => 'project_id',
        'output_result_id' => 'output_result_id',
        'code' => 'activity_code',
        'name' => 'activity_name',
        'unit' => 'unit_type',
        'target' => 'target_total',
        'start' => 'start_date',
        'end' => 'end_date',
    ];
}
$ACT = resolve_activity_table($pdo);

// ------------------------------------------------------
// PROJECT LIST WRAPPER (supports helpers with or without PDO parameter)
// ------------------------------------------------------
function planning_get_projects(PDO $pdo): array
{
    // Prefer system helper if available
    if (function_exists('get_projects')) {
        try {
            $rf = new ReflectionFunction('get_projects');
            if ($rf->getNumberOfParameters() >= 1) return (array)get_projects($pdo);
            return (array)get_projects();
        } catch (Throwable $e) {
            // try both signatures
            try { return (array)get_projects($pdo); } catch (Throwable $e2) {}
            try { return (array)get_projects(); } catch (Throwable $e3) {}
        }
    }

    // Fallback: direct query (works even if helpers don't provide get_projects())
    $candidates = [
        "SELECT id, title FROM projects ORDER BY id DESC",
        "SELECT id, name AS title FROM projects ORDER BY id DESC",
        "SELECT id, project_title AS title FROM projects ORDER BY id DESC",
    ];
    foreach ($candidates as $sql) {
        try {
            $st = $pdo->query($sql);
            $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
            if ($rows) return $rows;
        } catch (Throwable $e) {}
    }

    return [];
}


// ------------------------------------------------------
// PROJECT SELECTION (GET+SESSION)
// ------------------------------------------------------
$selected_project_id = (int)($_SESSION['selected_project_id'] ?? 0);
if (isset($_GET['project_id'])) {
    $pid = (int)($_GET['project_id'] ?: 0);
    $_SESSION['selected_project_id'] = $pid;
    $selected_project_id = $pid;
}

// ------------------------------------------------------
// BENEFICIARY SHARE RECALC
// ------------------------------------------------------
function recalc_beneficiary_shares(PDO $pdo, int $project_id): void
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) AS t FROM project_beneficiaries WHERE project_id=?");
    $stmt->execute([$project_id]);
    $grand = (int)($stmt->fetch(PDO::FETCH_ASSOC)['t'] ?? 0);

    if ($grand <= 0) {
        $pdo->prepare("UPDATE project_beneficiaries SET share_percentage=0 WHERE project_id=?")->execute([$project_id]);
        return;
    }

    $stmt = $pdo->prepare("SELECT id,total FROM project_beneficiaries WHERE project_id=?");
    $stmt->execute([$project_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pct = ((int)$r['total'] / $grand) * 100.0;
        $pdo->prepare("UPDATE project_beneficiaries SET share_percentage=? WHERE id=? AND project_id=?")
            ->execute([round($pct, 2), (int)$r['id'], $project_id]);
    }
}

// ------------------------------------------------------
// EXPORT HELPERS
// ------------------------------------------------------
function csv_out(array $rows, string $filename): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    $out = fopen('php://output', 'w');
    foreach ($rows as $r) fputcsv($out, $r);
    fclose($out);
    exit;
}
function html_out(string $title, string $htmlBody, string $filename): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    echo "<!doctype html><html><head><meta charset='utf-8'><title>".h($title)."</title>
    <style>
        body{font-family:Arial, sans-serif; font-size:12px;}
        table{border-collapse:collapse;width:100%;}
        th,td{border:1px solid #333;padding:6px;vertical-align:top;}
        th{background:#f1f1f1;}
        .muted{color:#666;}
    </style></head><body>";
    echo $htmlBody;
    echo "</body></html>";
    exit;
}

// ------------------------------------------------------
// POST HANDLER (CRUD)
// ------------------------------------------------------
if (is_post()) {
    $project_id = (int)($_POST['project_id'] ?? $selected_project_id ?? 0);
    $action_type = (string)($_POST['action_type'] ?? '');
    $anchor = (string)($_POST['anchor'] ?? 'top');

    if ($project_id <= 0) {
        flash_add('danger', '❌ Please select a project first.');
        header("Location: planning.php#top");
        exit;
    }
    $_SESSION['selected_project_id'] = $project_id;

    try {
        // RESULTS
        if (in_array($action_type, ['add_result','update_result'], true)) {
            $id = (int)($_POST['id'] ?? 0);
            $level = (string)($_POST['level'] ?? '');
            $code = trim((string)($_POST['code'] ?? ''));
            $name = trim((string)($_POST['name'] ?? ''));

            if (!in_array($level, ['objective','impact','outcome','output'], true)) throw new RuntimeException("Invalid result level.");
            if ($name === '') throw new RuntimeException("Result description is required.");

            if ($action_type === 'add_result') {
                $st = $pdo->prepare("INSERT INTO results_chain (project_id, level, code, name) VALUES (?,?,?,?)");
                $st->execute([$project_id, $level, ($code !== '' ? $code : null), $name]);
                flash_add('success', '✅ Result saved.');
            } else {
                if ($id <= 0) throw new RuntimeException("Missing result id.");
                $st = $pdo->prepare("UPDATE results_chain SET level=?, code=?, name=? WHERE id=? AND project_id=?");
                $st->execute([$level, ($code !== '' ? $code : null), $name, $id, $project_id]);
                flash_add('success', '✅ Result updated.');
            }
        }
        if ($action_type === 'delete_result') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException("Missing result id.");
            $pdo->prepare("DELETE FROM results_chain WHERE id=? AND project_id=?")->execute([$id, $project_id]);
            flash_add('success', '🗑️ Result deleted.');
        }

        // INDICATORS
        $indicatorMap = ['impact'=>'impact_indicators','outcome'=>'outcome_indicators','output'=>'output_indicators'];
        if (in_array($action_type, ['add_indicator','update_indicator'], true)) {
            $scope = (string)($_POST['scope'] ?? '');
            if (!isset($indicatorMap[$scope])) throw new RuntimeException("Invalid indicator scope.");
            $table = $indicatorMap[$scope];

            $id = (int)($_POST['id'] ?? 0);
            $result_id = (int)($_POST['result_id'] ?? 0);
            if ($result_id <= 0) throw new RuntimeException("Please select the linked result.");
            $indicator_name = trim((string)($_POST['indicator_name'] ?? ''));
            if ($indicator_name === '') throw new RuntimeException("Indicator name is required.");

            $payload = [
                'project_id' => $project_id,
                'result_id' => $result_id,
                'beneficiary_type' => (string)($_POST['beneficiary_type'] ?? 'host'),
                'indicator_code' => trim((string)($_POST['indicator_code'] ?? '')),
                'indicator_name' => $indicator_name,
                'unit_type' => trim((string)($_POST['unit_type'] ?? 'persons')) ?: 'persons',
                'input_mode' => (string)($_POST['input_mode'] ?? 'sadd'),
                'total_target' => (int)($_POST['total_target'] ?? 0),
                'boys_u5' => (int)($_POST['boys_u5'] ?? 0),
                'girls_u5' => (int)($_POST['girls_u5'] ?? 0),
                'boys_5_17' => (int)($_POST['boys_5_17'] ?? 0),
                'girls_5_17' => (int)($_POST['girls_5_17'] ?? 0),
                'men_18_59' => (int)($_POST['men_18_59'] ?? 0),
                'women_18_59' => (int)($_POST['women_18_59'] ?? 0),
                'men_60p' => (int)($_POST['men_60p'] ?? 0),
                'women_60p' => (int)($_POST['women_60p'] ?? 0),
                'pwd_count' => (int)($_POST['pwd_count'] ?? 0),
            ];

            // SADD server rule:
            // - total_target = sum of SADD (excluding pwd)
            // - if unit = hh => keep manual (do not overwrite)
            if ($payload['input_mode'] === 'sadd') {
                if (normalize_unit($payload['unit_type']) !== 'hh') {
                    $payload['total_target'] = sum_sadd($payload);
                }
            }

            if ($action_type === 'add_indicator') {
                $sql = "INSERT INTO `$table` (
                    project_id,result_id,beneficiary_type,indicator_code,indicator_name,unit_type,input_mode,total_target,
                    boys_u5,girls_u5,boys_5_17,girls_5_17,men_18_59,women_18_59,men_60p,women_60p,pwd_count
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
                $st = $pdo->prepare($sql);
                $st->execute([
                    $payload['project_id'],$payload['result_id'],$payload['beneficiary_type'],
                    ($payload['indicator_code'] !== '' ? $payload['indicator_code'] : null),
                    $payload['indicator_name'],$payload['unit_type'],$payload['input_mode'],$payload['total_target'],
                    $payload['boys_u5'],$payload['girls_u5'],$payload['boys_5_17'],$payload['girls_5_17'],
                    $payload['men_18_59'],$payload['women_18_59'],$payload['men_60p'],$payload['women_60p'],$payload['pwd_count']
                ]);

                if (table_has_column($pdo, $table, 'target_total')) {
                    $newId = (int)$pdo->lastInsertId();
                    $pdo->prepare("UPDATE `$table` SET target_total=? WHERE id=?")->execute([$payload['total_target'], $newId]);
                }

                flash_add('success', "✅ {$scope} indicator saved.");
            } else {
                if ($id <= 0) throw new RuntimeException("Missing indicator id.");
                $sql = "UPDATE `$table` SET
                    result_id=?,beneficiary_type=?,indicator_code=?,indicator_name=?,unit_type=?,input_mode=?,total_target=?,
                    boys_u5=?,girls_u5=?,boys_5_17=?,girls_5_17=?,men_18_59=?,women_18_59=?,men_60p=?,women_60p=?,pwd_count=?
                    WHERE id=? AND project_id=?";
                $st = $pdo->prepare($sql);
                $st->execute([
                    $payload['result_id'],$payload['beneficiary_type'],
                    ($payload['indicator_code'] !== '' ? $payload['indicator_code'] : null),
                    $payload['indicator_name'],$payload['unit_type'],$payload['input_mode'],$payload['total_target'],
                    $payload['boys_u5'],$payload['girls_u5'],$payload['boys_5_17'],$payload['girls_5_17'],
                    $payload['men_18_59'],$payload['women_18_59'],$payload['men_60p'],$payload['women_60p'],$payload['pwd_count'],
                    $id,$project_id
                ]);

                if (table_has_column($pdo, $table, 'target_total')) {
                    $pdo->prepare("UPDATE `$table` SET target_total=? WHERE id=? AND project_id=?")->execute([$payload['total_target'], $id, $project_id]);
                }

                flash_add('success', "✅ {$scope} indicator updated.");
            }
        }
        if ($action_type === 'delete_indicator') {
            $scope = (string)($_POST['scope'] ?? '');
            $indicatorMap = ['impact'=>'impact_indicators','outcome'=>'outcome_indicators','output'=>'output_indicators'];
            if (!isset($indicatorMap[$scope])) throw new RuntimeException("Invalid indicator scope.");
            $table = $indicatorMap[$scope];
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException("Missing indicator id.");
            $pdo->prepare("DELETE FROM `$table` WHERE id=? AND project_id=?")->execute([$id, $project_id]);
            flash_add('success', "🗑️ {$scope} indicator deleted.");
        }

        // ACTIVITIES
        if (in_array($action_type, ['add_activity','update_activity'], true)) {
            $id = (int)($_POST['id'] ?? 0);
            $output_result_id = (int)($_POST['output_result_id'] ?? 0);
            $code = trim((string)($_POST['activity_code'] ?? ''));
            $name = trim((string)($_POST['activity_name'] ?? ''));
            $unit = trim((string)($_POST['unit_type'] ?? 'sessions')) ?: 'sessions';
            $target = (int)($_POST['target_total'] ?? 0);
            $start = ($_POST['start_date'] ?? '') !== '' ? (string)$_POST['start_date'] : null;
            $end   = ($_POST['end_date'] ?? '') !== '' ? (string)$_POST['end_date'] : null;

            if ($name === '') throw new RuntimeException("Activity name is required.");

            $table = $ACT['table'];
            $colOut = $ACT['output_result_id'];
            $colCode = $ACT['code'];
            $colName = $ACT['name'];
            $colUnit = $ACT['unit'];
            $colTarget = $ACT['target'];
            $colStart = $ACT['start'];
            $colEnd = $ACT['end'];

            if ($action_type === 'add_activity') {
                $fields = ['project_id']; $vals = [$project_id];
                if ($colOut !== null) { $fields[] = $colOut; $vals[] = ($output_result_id > 0 ? $output_result_id : null); }
                if ($colCode !== null) { $fields[] = $colCode; $vals[] = ($code !== '' ? $code : null); }
                $fields[] = $colName; $vals[] = $name;
                if ($colUnit !== null && $colUnit !== $colName) { $fields[] = $colUnit; $vals[] = $unit; }
                if ($colTarget !== null && $colTarget !== $colName) { $fields[] = $colTarget; $vals[] = $target; }
                if ($colStart !== null) { $fields[] = $colStart; $vals[] = $start; }
                if ($colEnd !== null) { $fields[] = $colEnd; $vals[] = $end; }

                $ph = implode(',', array_fill(0, count($fields), '?'));
                $sql = "INSERT INTO `$table` (`".implode('`,`',$fields)."`) VALUES ($ph)";
                $pdo->prepare($sql)->execute($vals);
                flash_add('success', "✅ Activity saved.");
            } else {
                if ($id <= 0) throw new RuntimeException("Missing activity id.");
                $sets = []; $vals = [];
                if ($colOut !== null) { $sets[] = "`$colOut`=?"; $vals[] = ($output_result_id > 0 ? $output_result_id : null); }
                if ($colCode !== null) { $sets[] = "`$colCode`=?"; $vals[] = ($code !== '' ? $code : null); }
                $sets[] = "`$colName`=?"; $vals[] = $name;
                if ($colUnit !== null && $colUnit !== $colName) { $sets[] = "`$colUnit`=?"; $vals[] = $unit; }
                if ($colTarget !== null && $colTarget !== $colName) { $sets[] = "`$colTarget`=?"; $vals[] = $target; }
                if ($colStart !== null) { $sets[] = "`$colStart`=?"; $vals[] = $start; }
                if ($colEnd !== null) { $sets[] = "`$colEnd`=?"; $vals[] = $end; }

                $vals[] = $id; $vals[] = $project_id;
                $sql = "UPDATE `$table` SET ".implode(',',$sets)." WHERE `{$ACT['id']}`=? AND `{$ACT['project_id']}`=?";
                $pdo->prepare($sql)->execute($vals);
                flash_add('success', "✅ Activity updated.");
            }
        }
        if ($action_type === 'delete_activity') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException("Missing activity id.");
            $pdo->prepare("DELETE FROM `{$ACT['table']}` WHERE `{$ACT['id']}`=? AND `{$ACT['project_id']}`=?")->execute([$id, $project_id]);
            flash_add('success', "🗑️ Activity deleted.");
        }

        // BENEFICIARIES
        if (in_array($action_type, ['add_beneficiary','update_beneficiary'], true)) {
            $id = (int)($_POST['id'] ?? 0);
            $type = trim((string)($_POST['beneficiary_type'] ?? ''));
            if ($type === '') throw new RuntimeException("Beneficiary type is required.");

            $region_id = ($_POST['region_id'] ?? '') !== '' ? (int)$_POST['region_id'] : null;
            $zone_id = ($_POST['zone_id'] ?? '') !== '' ? (int)$_POST['zone_id'] : null;
            $woreda_id = ($_POST['woreda_id'] ?? '') !== '' ? (int)$_POST['woreda_id'] : null;

            $women = (int)($_POST['women'] ?? 0);
            $girls = (int)($_POST['girls'] ?? 0);
            $men = (int)($_POST['men'] ?? 0);
            $boys = (int)($_POST['boys'] ?? 0);
            $pwd  = (int)($_POST['pwd_count'] ?? 0);

            $total = $women + $girls + $men + $boys; // exclude PWD

            if ($action_type === 'add_beneficiary') {
                $st = $pdo->prepare("INSERT INTO project_beneficiaries
                    (project_id,region_id,zone_id,woreda_id,beneficiary_type,women,girls,men,boys,pwd_count,total)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                $st->execute([$project_id,$region_id,$zone_id,$woreda_id,$type,$women,$girls,$men,$boys,$pwd,$total]);
                recalc_beneficiary_shares($pdo, $project_id);
                flash_add('success', "✅ Beneficiary entry saved.");
            } else {
                if ($id <= 0) throw new RuntimeException("Missing beneficiary id.");
                $st = $pdo->prepare("UPDATE project_beneficiaries SET
                    region_id=?,zone_id=?,woreda_id=?,beneficiary_type=?,women=?,girls=?,men=?,boys=?,pwd_count=?,total=?
                    WHERE id=? AND project_id=?");
                $st->execute([$region_id,$zone_id,$woreda_id,$type,$women,$girls,$men,$boys,$pwd,$total,$id,$project_id]);
                recalc_beneficiary_shares($pdo, $project_id);
                flash_add('success', "✅ Beneficiary entry updated.");
            }
        }
        if ($action_type === 'delete_beneficiary') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException("Missing beneficiary id.");
            $pdo->prepare("DELETE FROM project_beneficiaries WHERE id=? AND project_id=?")->execute([$id, $project_id]);
            recalc_beneficiary_shares($pdo, $project_id);
            flash_add('success', "🗑️ Beneficiary entry deleted.");
        }

    } catch (Throwable $e) {
        error_log("planning.php POST error: ".$e->getMessage());
        flash_add('danger', "❌ ".$e->getMessage());
    }

    header("Location: planning.php?project_id=".$project_id."#".$anchor);
    exit;
}

// ------------------------------------------------------
// EXPORTS / TEMPLATES
// ------------------------------------------------------
$action = (string)($_GET['action'] ?? '');
if ($action !== '') {
    $project_id = (int)($_GET['project_id'] ?? $selected_project_id ?? 0);
    if ($project_id <= 0) { http_response_code(400); die("Please select a project first."); }

    // Project details
    $project = null; $locationStr = '';
    try {
        $st = $pdo->prepare("SELECT p.*, r.name AS region_name, z.name AS zone_name, w.name AS woreda_name
                             FROM projects p
                             LEFT JOIN regions r ON p.region_id=r.id
                             LEFT JOIN zones z ON p.zone_id=z.id
                             LEFT JOIN woredas w ON p.woreda_id=w.id
                             WHERE p.id=?");
        $st->execute([$project_id]);
        $project = $st->fetch(PDO::FETCH_ASSOC);
        $locationStr = trim(($project['region_name'] ?? '')." / ".($project['zone_name'] ?? '')." / ".($project['woreda_name'] ?? ''));
    } catch (Throwable $e) {}

    $projectTitle = $project['title'] ?? $project['name'] ?? ("Project #".$project_id);

    // Fetch datasets
    $st = $pdo->prepare("SELECT * FROM results_chain WHERE project_id=? ORDER BY FIELD(level,'objective','impact','outcome','output'), id ASC");
    $st->execute([$project_id]);
    $results = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare("SELECT * FROM impact_indicators WHERE project_id=? ORDER BY id ASC");
    $st->execute([$project_id]);
    $impactIndicators = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare("SELECT * FROM outcome_indicators WHERE project_id=? ORDER BY id ASC");
    $st->execute([$project_id]);
    $outcomeIndicators = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare("SELECT * FROM output_indicators WHERE project_id=? ORDER BY id ASC");
    $st->execute([$project_id]);
    $outputIndicators = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare("SELECT * FROM `{$ACT['table']}` WHERE `{$ACT['project_id']}`=? ORDER BY `{$ACT['id']}` ASC");
    $st->execute([$project_id]);
    $activities = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare("SELECT * FROM project_beneficiaries WHERE project_id=? ORDER BY id ASC");
    $st->execute([$project_id]);
    $beneficiaries = $st->fetchAll(PDO::FETCH_ASSOC);

    $resultMap = [];
    foreach ($results as $r) {
        $rid = (int)$r['id'];
        $resultMap[$rid] = strtoupper((string)$r['level'])." ".($r['code'] ?? ("#".$rid))." - ".mb_substr((string)$r['name'], 0, 120);
    }

    // Template CSV
    if ($action === 'export_template') {
        $section = (string)($_GET['section'] ?? 'results');
        $rows = [];
        if ($section === 'results') {
            $rows[] = ['level','code','description'];
            $rows[] = ['outcome','Outcome 1','Example outcome description...'];
        } elseif (in_array($section, ['impact_indicators','outcome_indicators','output_indicators'], true)) {
            $rows[] = ['result_id','beneficiary_type','indicator_code','indicator_name','unit_type','input_mode','boys_u5','girls_u5','boys_5_17','girls_5_17','men_18_59','women_18_59','men_60p','women_60p','pwd_count','total_target'];
            $rows[] = ['(numeric)','host','IND-1','Example indicator...','persons','sadd','0','0','0','0','0','0','0','0','0','0'];
        } elseif ($section === 'activities') {
            $rows[] = ['output_result_id','activity_code','activity_name','unit_type','target_total','start_date','end_date'];
            $rows[] = ['(numeric)','A1','Example activity...','sessions','0','2025-01-01','2025-01-31'];
        } elseif ($section === 'beneficiaries') {
            $rows[] = ['beneficiary_type','women','girls','men','boys','pwd_count','region_id','zone_id','woreda_id'];
            $rows[] = ['IDPs','0','0','0','0','0','','',''];
        } else {
            $rows[] = ['info']; $rows[] = ['Unknown section'];
        }
        csv_out($rows, "planning_template_{$section}_{$project_id}.csv");
    }

    // Full export rows
    $fullRows = [];
    $fullRows[] = ["Health Reporting System - Project Planning Suite"];
    $fullRows[] = ["Export Date", now_str()];
    $fullRows[] = ["Project", $projectTitle];
    $fullRows[] = ["Location", $locationStr];
    $fullRows[] = [];

    $fullRows[] = ["RESULTS"];
    $fullRows[] = ["Level","Code","Description"];
    foreach ($results as $r) $fullRows[] = [$r['level'], $r['code'], $r['name']];
    $fullRows[] = [];

    $addInd = function(string $title, array $inds) use (&$fullRows, $resultMap) {
        $fullRows[] = [$title];
        $fullRows[] = ["Linked Result","Indicator Code","Indicator","Unit","Mode","Total Target","Boys <5","Girls <5","Boys 5-17","Girls 5-17","Men 18-59","Women 18-59","Men 60+","Women 60+","PWD"];
        foreach ($inds as $i) {
            $rid = (int)$i['result_id'];
            $fullRows[] = [
                $resultMap[$rid] ?? ('Result #'.$rid),
                $i['indicator_code'],
                $i['indicator_name'],
                $i['unit_type'],
                $i['input_mode'],
                $i['total_target'] ?? $i['target_total'] ?? 0,
                $i['boys_u5'],$i['girls_u5'],$i['boys_5_17'],$i['girls_5_17'],$i['men_18_59'],$i['women_18_59'],$i['men_60p'],$i['women_60p'],$i['pwd_count']
            ];
        }
        $fullRows[] = [];
    };
    $addInd("IMPACT INDICATORS", $impactIndicators);
    $addInd("OUTCOME INDICATORS", $outcomeIndicators);
    $addInd("OUTPUT INDICATORS", $outputIndicators);

    $fullRows[] = ["ACTIVITIES"];
    $fullRows[] = ["Linked Output","Code","Activity","Unit","Target","Start","End"];
    foreach ($activities as $a) {
        $outId = (int)($ACT['output_result_id'] ? ($a[$ACT['output_result_id']] ?? 0) : 0);
        $fullRows[] = [
            $outId ? ($resultMap[$outId] ?? ('Output #'.$outId)) : '',
            $ACT['code'] ? ($a[$ACT['code']] ?? '') : '',
            $a[$ACT['name']] ?? '',
            $ACT['unit'] ? ($a[$ACT['unit']] ?? '') : '',
            $ACT['target'] ? ($a[$ACT['target']] ?? 0) : 0,
            $ACT['start'] ? ($a[$ACT['start']] ?? '') : '',
            $ACT['end'] ? ($a[$ACT['end']] ?? '') : ''
        ];
    }
    $fullRows[] = [];

    $fullRows[] = ["BENEFICIARIES"];
    $fullRows[] = ["Type","Women","Girls","Men","Boys","PWD (separate)","Total (W+G+M+B)","Share %"];
    foreach ($beneficiaries as $b) $fullRows[] = [$b['beneficiary_type'],$b['women'],$b['girls'],$b['men'],$b['boys'],$b['pwd_count'],$b['total'],$b['share_percentage']];

    // Full export actions
    if (in_array($action, ['export_logframe_csv','export_logframe_excel'], true)) {
        csv_out($fullRows, "project_planning_full_{$project_id}.csv");
    }
    if (in_array($action, ['export_logframe_word','export_logframe_pdf','export_logframe_html'], true)) {
        $html = "<h1>Project Planning Export</h1>
                 <p class='muted'><b>Project:</b> ".h($projectTitle)." | <b>Location:</b> ".h($locationStr ?: 'Not specified')." | <b>Export Date:</b> ".h(now_str())."</p>";
        $html .= "<table>";
        foreach ($fullRows as $row) {
            if (!$row) { $html .= "<tr><td colspan='30' style='border:none;height:10px'></td></tr>"; continue; }
            $isSection = (count($row)===1 && strtoupper((string)$row[0])===(string)$row[0] && strlen((string)$row[0])<=30);
            if ($isSection) { $html .= "<tr><th colspan='30' style='background:#ddd'>".h($row[0])."</th></tr>"; continue; }
            $html .= "<tr>";
            foreach ($row as $cell) $html .= "<td>".h($cell)."</td>";
            $html .= "</tr>";
        }
        $html .= "</table>";
        html_out("Project Planning Export", $html, "project_planning_full_{$project_id}.html");
    }

    // Per-section export
    if ($action === 'export_section') {
        $section = (string)($_GET['section'] ?? '');
        $format = (string)($_GET['format'] ?? 'csv');
        if ($section === '') { http_response_code(400); die("Missing section."); }

        $rows = [];
        if ($section === 'results') {
            $rows[] = ["Level","Code","Description"];
            foreach ($results as $r) $rows[] = [$r['level'],$r['code'],$r['name']];
        } elseif (in_array($section, ['impact_indicators','outcome_indicators','output_indicators'], true)) {
            $inds = ($section==='impact_indicators') ? $impactIndicators : (($section==='outcome_indicators') ? $outcomeIndicators : $outputIndicators);
            $rows[] = ["Linked Result","Indicator Code","Indicator","Unit","Mode","Total Target","Boys <5","Girls <5","Boys 5-17","Girls 5-17","Men 18-59","Women 18-59","Men 60+","Women 60+","PWD"];
            foreach ($inds as $i) {
                $rid=(int)$i['result_id'];
                $rows[] = [$resultMap[$rid] ?? ('Result #'.$rid), $i['indicator_code'], $i['indicator_name'], $i['unit_type'], $i['input_mode'],
                    $i['total_target'] ?? $i['target_total'] ?? 0, $i['boys_u5'],$i['girls_u5'],$i['boys_5_17'],$i['girls_5_17'],$i['men_18_59'],$i['women_18_59'],$i['men_60p'],$i['women_60p'],$i['pwd_count']];
            }
        } elseif ($section === 'activities') {
            $rows[] = ["Linked Output","Code","Activity","Unit","Target","Start","End"];
            foreach ($activities as $a) {
                $outId = (int)($ACT['output_result_id'] ? ($a[$ACT['output_result_id']] ?? 0) : 0);
                $rows[] = [
                    $outId ? ($resultMap[$outId] ?? ('Output #'.$outId)) : '',
                    $ACT['code'] ? ($a[$ACT['code']] ?? '') : '',
                    $a[$ACT['name']] ?? '',
                    $ACT['unit'] ? ($a[$ACT['unit']] ?? '') : '',
                    $ACT['target'] ? ($a[$ACT['target']] ?? 0) : 0,
                    $ACT['start'] ? ($a[$ACT['start']] ?? '') : '',
                    $ACT['end'] ? ($a[$ACT['end']] ?? '') : ''
                ];
            }
        } elseif ($section === 'beneficiaries') {
            $rows[] = ["Type","Women","Girls","Men","Boys","PWD (separate)","Total","Share %"];
            foreach ($beneficiaries as $b) $rows[] = [$b['beneficiary_type'],$b['women'],$b['girls'],$b['men'],$b['boys'],$b['pwd_count'],$b['total'],$b['share_percentage']];
        } else {
            $rows[] = ["Unknown section"];
        }

        if ($format === 'html') {
            $html = "<h2>Planning Section: ".h(strtoupper(str_replace('_',' ',$section)))."</h2>
                     <p class='muted'><b>Project:</b> ".h($projectTitle)." | <b>Location:</b> ".h($locationStr ?: 'Not specified')." | <b>Date:</b> ".h(now_str())."</p>";
            $html .= "<table>";
            foreach ($rows as $i=>$r) {
                $html .= "<tr>";
                foreach ($r as $c) $html .= ($i===0 ? "<th>".h($c)."</th>" : "<td>".h($c)."</td>");
                $html .= "</tr>";
            }
            $html .= "</table>";
            html_out("Planning Section Export", $html, "planning_{$section}_{$project_id}.html");
        } else {
            csv_out($rows, "planning_{$section}_{$project_id}.csv");
        }
    }
}

// ------------------------------------------------------
// FETCH DATA FOR UI
// ------------------------------------------------------
$flash = flash_take();

$project_details = null;
$project_location = '';
if ($selected_project_id > 0) {
    try {
        $st = $pdo->prepare("SELECT p.*, r.name AS region_name, z.name AS zone_name, w.name AS woreda_name
                             FROM projects p
                             LEFT JOIN regions r ON p.region_id=r.id
                             LEFT JOIN zones z ON p.zone_id=z.id
                             LEFT JOIN woredas w ON p.woreda_id=w.id
                             WHERE p.id=?");
        $st->execute([$selected_project_id]);
        $project_details = $st->fetch(PDO::FETCH_ASSOC);
        if ($project_details) {
            $project_location = trim(($project_details['region_name'] ?? '') . ' / ' .
                                     ($project_details['zone_name'] ?? '') . ' / ' .
                                     ($project_details['woreda_name'] ?? ''));
        }
    } catch (Throwable $e) {}
}

$results = $impactResults = $outcomeResults = $outputResults = [];
$impactIndicators = $outcomeIndicators = $outputIndicators = [];
$activities = $beneficiaries = [];

if ($selected_project_id > 0) {
    $st = $pdo->prepare("SELECT * FROM results_chain WHERE project_id=? ORDER BY FIELD(level,'objective','impact','outcome','output'), id DESC");
    $st->execute([$selected_project_id]);
    $results = $st->fetchAll(PDO::FETCH_ASSOC);

    $impactResults = array_values(array_filter($results, fn($r)=>($r['level'] ?? '')==='impact'));
    $outcomeResults = array_values(array_filter($results, fn($r)=>($r['level'] ?? '')==='outcome'));
    $outputResults = array_values(array_filter($results, fn($r)=>($r['level'] ?? '')==='output'));

    $st = $pdo->prepare("SELECT * FROM impact_indicators WHERE project_id=? ORDER BY id DESC");
    $st->execute([$selected_project_id]);
    $impactIndicators = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare("SELECT * FROM outcome_indicators WHERE project_id=? ORDER BY id DESC");
    $st->execute([$selected_project_id]);
    $outcomeIndicators = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare("SELECT * FROM output_indicators WHERE project_id=? ORDER BY id DESC");
    $st->execute([$selected_project_id]);
    $outputIndicators = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare("SELECT * FROM `{$ACT['table']}` WHERE `{$ACT['project_id']}`=? ORDER BY `{$ACT['id']}` DESC");
    $st->execute([$selected_project_id]);
    $activities = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare("SELECT * FROM project_beneficiaries WHERE project_id=? ORDER BY id DESC");
    $st->execute([$selected_project_id]);
    $beneficiaries = $st->fetchAll(PDO::FETCH_ASSOC);
}

$resultMap = [];
foreach ($results as $r) {
    $rid = (int)$r['id'];
    $resultMap[$rid] = strtoupper((string)$r['level'])." ".($r['code'] ?? ("#".$rid))." - ".mb_substr((string)$r['name'], 0, 120);
}

$resultLabel = function(array $r): string {
    $c = trim((string)($r['code'] ?? ''));
    $t = trim((string)($r['name'] ?? ''));
    $short = mb_substr($t, 0, 120);
    return trim(($c !== '' ? ($c.' - ') : '') . $short);
};

// ------------------------------------------------------
// EDIT CONTEXT (SERVER SIDE)
// ------------------------------------------------------
$edit = (string)($_GET['edit'] ?? '');
$edit_id = (int)($_GET['id'] ?? 0);

$edit_result = null;
$edit_indicator = null;
$edit_activity = null;
$edit_beneficiary = null;

if ($selected_project_id > 0 && $edit !== '' && $edit_id > 0) {
    try {
        if ($edit === 'result') {
            $st = $pdo->prepare("SELECT * FROM results_chain WHERE id=? AND project_id=?");
            $st->execute([$edit_id, $selected_project_id]);
            $edit_result = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (in_array($edit, ['impact_indicator','outcome_indicator','output_indicator'], true)) {
            $tbl = $edit === 'impact_indicator' ? 'impact_indicators' : ($edit === 'outcome_indicator' ? 'outcome_indicators' : 'output_indicators');
            $st = $pdo->prepare("SELECT * FROM `$tbl` WHERE id=? AND project_id=?");
            $st->execute([$edit_id, $selected_project_id]);
            $edit_indicator = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($edit_indicator) $edit_indicator['_scope'] = str_replace('_indicator','',$edit);
        }
        if ($edit === 'activity') {
            $st = $pdo->prepare("SELECT * FROM `{$ACT['table']}` WHERE `{$ACT['id']}`=? AND `{$ACT['project_id']}`=?");
            $st->execute([$edit_id, $selected_project_id]);
            $edit_activity = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if ($edit === 'beneficiary') {
            $st = $pdo->prepare("SELECT * FROM project_beneficiaries WHERE id=? AND project_id=?");
            $st->execute([$edit_id, $selected_project_id]);
            $edit_beneficiary = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    } catch (Throwable $e) {}
}

// ------------------------------------------------------
// GANTT HELPERS (no JS)
// ------------------------------------------------------
function month_range(?string $start, ?string $end): array
{
    if (!$start || !$end) {
        $endDt = new DateTime('first day of this month');
        $startDt = (clone $endDt)->modify('-11 months');
    } else {
        $startDt = new DateTime($start); $startDt->modify('first day of this month');
        $endDt = new DateTime($end); $endDt->modify('first day of this month');
        if ($startDt > $endDt) { $tmp=$startDt; $startDt=$endDt; $endDt=$tmp; }
    }
    $months=[]; $c=clone $startDt;
    while ($c <= $endDt) { $months[]=$c->format('Y-m'); $c->modify('+1 month'); if (count($months)>36) break; }
    return $months;
}
function month_label(string $ym): string {
    $dt = DateTime::createFromFormat('Y-m', $ym);
    return $dt ? $dt->format('M Y') : $ym;
}
function is_month_in_range(string $ym, ?string $start, ?string $end): bool
{
    if (!$start && !$end) return false;
    $m = DateTime::createFromFormat('Y-m', $ym);
    if (!$m) return false;
    $mStart = (clone $m)->modify('first day of this month');
    $mEnd   = (clone $m)->modify('last day of this month');
    $s = $start ? new DateTime($start) : null;
    $e = $end ? new DateTime($end) : null;
    if ($s) $s->setTime(0,0,0);
    if ($e) $e->setTime(23,59,59);
    if ($s && $mEnd < $s) return false;
    if ($e && $mStart > $e) return false;
    return true;
}

// ------------------------------------------------------
// RENDER
// ------------------------------------------------------
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Project Planning Suite</title>
<style>
    body{font-family:Arial, sans-serif; background:#f6f7f9; margin:0; padding:14px;}
    .wrap{max-width:1600px; margin:0 auto;}
    .card{background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px; margin-bottom:12px;}
    .row{display:flex; gap:10px; flex-wrap:wrap; align-items:center;}
    .row > *{flex:0 0 auto;}
    select,input,textarea{width:100%; padding:8px; border:1px solid #d1d5db; border-radius:8px; font-size:14px;}
    textarea{min-height:80px;}
    .btn{display:inline-block; padding:8px 10px; border-radius:8px; border:1px solid #d1d5db; background:#fff; cursor:pointer; text-decoration:none; color:#111;}
    .btn.primary{background:#2563eb; border-color:#2563eb; color:#fff;}
    .btn.success{background:#16a34a; border-color:#16a34a; color:#fff;}
    .btn.danger{background:#dc2626; border-color:#dc2626; color:#fff;}
    .btn.gray{background:#f3f4f6;}
    .btn[aria-disabled="true"]{opacity:.55; pointer-events:none;}
    .muted{color:#6b7280; font-size:12px;}
    .title{font-size:18px; font-weight:700; margin:0 0 6px 0;}
    details{border:1px solid #e5e7eb; border-radius:10px; background:#fff; margin-bottom:12px;}
    summary{cursor:pointer; padding:12px 14px; font-weight:700; user-select:none;}
    .section{padding:12px 14px; border-top:1px solid #e5e7eb;}
    table{width:100%; border-collapse:collapse;}
    th,td{border:1px solid #e5e7eb; padding:8px; vertical-align:top; font-size:13px;}
    th{background:#f3f4f6;}
    .actions form{display:inline;}
    .badge{display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:700; background:#111; color:#fff;}
    .grid2{display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:10px;}
    .grid3{display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:10px;}
    .grid4{display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:10px;}
    .hr{height:1px; background:#e5e7eb; margin:12px 0;}
    .gantt td,.gantt th{font-size:11px; text-align:center;}
    .bar{background:#d1fae5;}
</style>
</head>
<body>
<div class="wrap" id="top">

    <div class="card">
        <div class="title">Select Project <span style="color:#dc2626">(Required)</span></div>
        <form method="get" class="row">
            <div style="min-width:360px; flex: 1 1 360px;">
                <select name="project_id" onchange="this.form.submit()">
                    <option value="">-- Select a Project --</option>
                    <?php foreach (planning_get_projects($pdo) as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= ($selected_project_id == (int)$p['id'] ? 'selected' : '') ?>>
                            <?= h($p['title'] ?? $p['name'] ?? ('Project '.$p['id'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="muted">This version does not depend on Bootstrap JS. Sections open normally (native HTML).</div>
            </div>
        </form>

        <?php if ($project_details): ?>
            <div class="card" style="background:#eff6ff; border-color:#bfdbfe; margin-top:12px;">
                <div><b>Selected Project:</b> <?= h($project_details['title'] ?? $project_details['name']) ?></div>
                <div><b>Location:</b> <?= h($project_location) ?: 'Not specified' ?></div>
                <?php if (!empty($project_details['start_date']) && !empty($project_details['end_date'])): ?>
                    <div><b>Duration:</b> <?= h(date('M Y', strtotime($project_details['start_date']))) ?> - <?= h(date('M Y', strtotime($project_details['end_date']))) ?></div>
                <?php endif; ?>
                <div class="muted">Activity table used: <b><?= h($ACT['table']) ?></b></div>
            </div>
        <?php else: ?>
            <div class="card" style="background:#fff7ed; border-color:#fed7aa; margin-top:12px;">
                <b>No project selected:</b> Add/Edit/Delete/Export are disabled until you select a project.
            </div>
        <?php endif; ?>
    </div>

    <?php foreach (($flash['success'] ?? []) as $m): ?>
        <div class="card" style="background:#ecfdf5; border-color:#bbf7d0;"><b><?= $m ?></b></div>
    <?php endforeach; ?>
    <?php foreach (($flash['danger'] ?? []) as $m): ?>
        <div class="card" style="background:#fef2f2; border-color:#fecaca;"><b><?= $m ?></b></div>
    <?php endforeach; ?>

    <?php
        $hasProject = ($selected_project_id > 0);
        $disabledAttr = $hasProject ? '' : 'aria-disabled="true"';
        $pid = (int)$selected_project_id;
    ?>

    <div class="card">
        <div class="row" style="justify-content:space-between;">
            <div>
                <div class="title">📤 Share Planning</div>
                <div class="muted">Exports work even offline. HTML exports can be printed to PDF or copied into Word.</div>
            </div>
            <div class="row">
                <a class="btn primary" <?= $disabledAttr ?> href="planning.php?action=export_logframe_html&project_id=<?= $pid ?>">Download/Share (HTML)</a>
                <a class="btn gray" <?= $disabledAttr ?> href="planning.php?action=export_logframe_csv&project_id=<?= $pid ?>">Download CSV</a>
                <a class="btn gray" <?= $disabledAttr ?> href="planning.php?action=export_template&section=results&project_id=<?= $pid ?>">⬇️ Results Template</a>
            </div>
        </div>
    </div>

    <!-- RESULTS -->
    <details open id="results">
        <summary>🎯 Results (Objective / Impact / Outcome / Output)</summary>
        <div class="section">
            <div class="row" style="margin-bottom:10px;">
                <a class="btn gray" <?= $disabledAttr ?> href="planning.php?action=export_section&section=results&format=csv&project_id=<?= $pid ?>">📥 Export CSV</a>
                <a class="btn gray" <?= $disabledAttr ?> href="planning.php?action=export_section&section=results&format=html&project_id=<?= $pid ?>">📥 Export HTML</a>
                <a class="btn gray" <?= $disabledAttr ?> href="planning.php?action=export_template&section=results&project_id=<?= $pid ?>">⬇️ Template CSV</a>
            </div>

            <!-- Add/Edit Result Form -->
            <div class="card" style="background:#f9fafb;">
                <div class="title"><?= $edit_result ? '✏️ Edit Result' : '➕ Add Result' ?></div>
                <form method="post">
                    <input type="hidden" name="project_id" value="<?= $pid ?>">
                    <input type="hidden" name="anchor" value="results">
                    <input type="hidden" name="action_type" value="<?= $edit_result ? 'update_result' : 'add_result' ?>">
                    <input type="hidden" name="id" value="<?= (int)($edit_result['id'] ?? 0) ?>">

                    <div class="grid3">
                        <div>
                            <label class="muted">Level</label>
                            <select name="level" required>
                                <?php $lvl = (string)($edit_result['level'] ?? 'outcome'); ?>
                                <?php foreach (['objective'=>'Objective','impact'=>'Impact','outcome'=>'Outcome','output'=>'Output'] as $k=>$v): ?>
                                    <option value="<?= $k ?>" <?= ($lvl===$k?'selected':'') ?>><?= $v ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="muted">Code (optional)</label>
                            <input name="code" value="<?= h($edit_result['code'] ?? '') ?>" placeholder="e.g., Outcome 1">
                        </div>
                        <div>
                            <label class="muted">Description</label>
                            <textarea name="name" required><?= h($edit_result['name'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <div class="row" style="margin-top:10px;">
                        <button class="btn <?= $edit_result ? 'primary' : 'success' ?>" <?= $disabledAttr ?>>Save</button>
                        <?php if ($edit_result): ?>
                            <a class="btn gray" href="planning.php?project_id=<?= $pid ?>#results">Cancel Edit</a>
                        <?php endif; ?>
                    </div>
                </form>
                <div class="muted">Define results first. Indicators and activities link to these results.</div>
            </div>

            <div class="hr"></div>

            <div class="card">
                <div class="title">Results List</div>
                <table>
                    <thead><tr><th>Level</th><th>Code</th><th>Description</th><th style="width:210px">Actions</th></tr></thead>
                    <tbody>
                    <?php if (!$results): ?>
                        <tr><td colspan="4" class="muted">No results added yet.</td></tr>
                    <?php else: foreach ($results as $r): ?>
                        <tr>
                            <td><span class="badge"><?= h($r['level']) ?></span></td>
                            <td><?= h($r['code'] ?? '') ?></td>
                            <td><?= h($r['name'] ?? '') ?></td>
                            <td class="actions">
                                <a class="btn gray" <?= $disabledAttr ?> href="planning.php?project_id=<?= $pid ?>&edit=result&id=<?= (int)$r['id'] ?>#results">✏️ Edit</a>
                                <form method="post" onsubmit="return confirm('Delete this result?');">
                                    <input type="hidden" name="project_id" value="<?= $pid ?>">
                                    <input type="hidden" name="anchor" value="results">
                                    <input type="hidden" name="action_type" value="delete_result">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <button class="btn danger" <?= $disabledAttr ?>>🗑️ Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </details>

    <?php
        // Helper to render indicator section with server-side edit
        $indicator_sections = [
            'impact' => ['title'=>'🎯 Impact Indicators','tbl'=>'impact_indicators','rows'=>$impactIndicators,'results'=>$impactResults,'editKey'=>'impact_indicator'],
            'outcome'=> ['title'=>'📈 Outcome Indicators','tbl'=>'outcome_indicators','rows'=>$outcomeIndicators,'results'=>$outcomeResults,'editKey'=>'outcome_indicator'],
            'output' => ['title'=>'📊 Output Indicators','tbl'=>'output_indicators','rows'=>$outputIndicators,'results'=>$outputResults,'editKey'=>'output_indicator'],
        ];
    ?>

    <?php foreach ($indicator_sections as $scope=>$meta): ?>
        <details id="<?= $scope ?>_indicators">
            <summary><?= h($meta['title']) ?></summary>
            <div class="section">
                <div class="row" style="margin-bottom:10px;">
                    <a class="btn gray" <?= $disabledAttr ?> href="planning.php?action=export_section&section=<?= $scope ?>_indicators&format=csv&project_id=<?= $pid ?>">📥 Export CSV</a>
                    <a class="btn gray" <?= $disabledAttr ?> href="planning.php?action=export_section&section=<?= $scope ?>_indicators&format=html&project_id=<?= $pid ?>">📥 Export HTML</a>
                    <a class="btn gray" <?= $disabledAttr ?> href="planning.php?action=export_template&section=<?= $scope ?>_indicators&project_id=<?= $pid ?>">⬇️ Template CSV</a>
                </div>

                <?php
                    $isEditingThis = ($edit_indicator && ($edit_indicator['_scope'] ?? '') === $scope);
                    $ei = $isEditingThis ? $edit_indicator : null;
                    $unit = (string)($ei['unit_type'] ?? 'persons');
                    $mode = (string)($ei['input_mode'] ?? 'sadd');
                ?>

                <div class="card" style="background:#f9fafb;">
                    <div class="title"><?= $isEditingThis ? '✏️ Edit '.$scope.' Indicator' : '➕ Add '.$scope.' Indicator' ?></div>
                    <form method="post">
                        <input type="hidden" name="project_id" value="<?= $pid ?>">
                        <input type="hidden" name="anchor" value="<?= $scope ?>_indicators">
                        <input type="hidden" name="action_type" value="<?= $isEditingThis ? 'update_indicator' : 'add_indicator' ?>">
                        <input type="hidden" name="scope" value="<?= $scope ?>">
                        <input type="hidden" name="id" value="<?= (int)($ei['id'] ?? 0) ?>">

                        <div class="grid3">
                            <div>
                                <label class="muted">Linked Result</label>
                                <select name="result_id" required>
                                    <option value="">-- Select Result --</option>
                                    <?php foreach (($meta['results'] ?? []) as $r): ?>
                                        <?php $rid=(int)$r['id']; $sel = ($ei && (int)$ei['result_id']===$rid) ? 'selected' : ''; ?>
                                        <option value="<?= $rid ?>" <?= $sel ?>><?= h($resultLabel($r)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="muted">If empty: add results first under Results section.</div>
                            </div>
                            <div>
                                <label class="muted">Indicator Code</label>
                                <input name="indicator_code" value="<?= h($ei['indicator_code'] ?? '') ?>" placeholder="e.g., OI-1">
                            </div>
                            <div>
                                <label class="muted">Indicator Name</label>
                                <textarea name="indicator_name" required><?= h($ei['indicator_name'] ?? '') ?></textarea>
                            </div>
                        </div>

                        <div class="grid4" style="margin-top:10px;">
                            <div>
                                <label class="muted">Beneficiary Type</label>
                                <?php $bt=(string)($ei['beneficiary_type'] ?? 'host'); ?>
                                <select name="beneficiary_type">
                                    <?php foreach (['host','idp','returnee','refugee','mixed','pwd','other'] as $k): ?>
                                        <option value="<?= $k ?>" <?= ($bt===$k?'selected':'') ?>><?= strtoupper($k) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="muted">Unit</label>
                                <select name="unit_type">
                                    <?php foreach (['persons'=>'Persons','hh'=>'HH','sessions'=>'Sessions','facilities'=>'Facilities','percent'=>'Percent','other'=>'Other'] as $k=>$v): ?>
                                        <option value="<?= $k ?>" <?= ($unit===$k?'selected':'') ?>><?= $v ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="muted">HH keeps Total manual (not forced by SADD).</div>
                            </div>
                            <div>
                                <label class="muted">Input Mode</label>
                                <select name="input_mode">
                                    <?php foreach (['sadd'=>'SADD','count'=>'Count','percent_pair'=>'Percent pair','percent_direct'=>'Percent direct'] as $k=>$v): ?>
                                        <option value="<?= $k ?>" <?= ($mode===$k?'selected':'') ?>><?= $v ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="muted">Total Target</label>
                                <input type="number" name="total_target" min="0" value="<?= (int)($ei['total_target'] ?? ($ei['target_total'] ?? 0)) ?>">
                                <div class="muted">SADD auto-total is applied on save (excludes PWD).</div>
                            </div>
                        </div>

                        <div class="hr"></div>

                        <div class="grid4">
                            <div><label class="muted">Boys &lt;5</label><input type="number" name="boys_u5" min="0" value="<?= (int)($ei['boys_u5'] ?? 0) ?>"></div>
                            <div><label class="muted">Girls &lt;5</label><input type="number" name="girls_u5" min="0" value="<?= (int)($ei['girls_u5'] ?? 0) ?>"></div>
                            <div><label class="muted">Boys 5-17</label><input type="number" name="boys_5_17" min="0" value="<?= (int)($ei['boys_5_17'] ?? 0) ?>"></div>
                            <div><label class="muted">Girls 5-17</label><input type="number" name="girls_5_17" min="0" value="<?= (int)($ei['girls_5_17'] ?? 0) ?>"></div>
                            <div><label class="muted">Men 18-59</label><input type="number" name="men_18_59" min="0" value="<?= (int)($ei['men_18_59'] ?? 0) ?>"></div>
                            <div><label class="muted">Women 18-59</label><input type="number" name="women_18_59" min="0" value="<?= (int)($ei['women_18_59'] ?? 0) ?>"></div>
                            <div><label class="muted">Men 60+</label><input type="number" name="men_60p" min="0" value="<?= (int)($ei['men_60p'] ?? 0) ?>"></div>
                            <div><label class="muted">Women 60+</label><input type="number" name="women_60p" min="0" value="<?= (int)($ei['women_60p'] ?? 0) ?>"></div>
                        </div>

                        <div class="grid2" style="margin-top:10px;">
                            <div>
                                <label class="muted">PWD (separate)</label>
                                <input type="number" name="pwd_count" min="0" value="<?= (int)($ei['pwd_count'] ?? 0) ?>">
                                <div class="muted">Not included in Total to avoid double count.</div>
                            </div>
                        </div>

                        <div class="row" style="margin-top:10px;">
                            <button class="btn <?= $isEditingThis ? 'primary' : 'success' ?>" <?= $disabledAttr ?>>Save</button>
                            <?php if ($isEditingThis): ?>
                                <a class="btn gray" href="planning.php?project_id=<?= $pid ?>#<?= $scope ?>_indicators">Cancel Edit</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <div class="card">
                    <div class="title"><?= strtoupper($scope) ?> Indicator List</div>
                    <table>
                        <thead>
                            <tr>
                                <th style="width:220px">Linked Result</th>
                                <th style="width:120px">Code</th>
                                <th>Indicator</th>
                                <th style="width:90px">Unit</th>
                                <th style="width:110px">Mode</th>
                                <th style="width:90px">Total</th>
                                <th style="width:240px">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$meta['rows']): ?>
                            <tr><td colspan="7" class="muted">No indicators added yet.</td></tr>
                        <?php else: foreach ($meta['rows'] as $r): ?>
                            <?php
                                $rid = (int)($r['result_id'] ?? 0);
                                $linked = $rid ? ($resultMap[$rid] ?? ('Result #'.$rid)) : '';
                                $total = (int)($r['total_target'] ?? ($r['target_total'] ?? 0));
                            ?>
                            <tr>
                                <td><?= h($linked) ?></td>
                                <td><?= h($r['indicator_code'] ?? '') ?></td>
                                <td><?= h($r['indicator_name'] ?? '') ?></td>
                                <td><?= h($r['unit_type'] ?? '') ?></td>
                                <td><?= h($r['input_mode'] ?? '') ?></td>
                                <td><b><?= $total ?></b></td>
                                <td class="actions">
                                    <a class="btn gray" <?= $disabledAttr ?> href="planning.php?project_id=<?= $pid ?>&edit=<?= $meta['editKey'] ?>&id=<?= (int)$r['id'] ?>#<?= $scope ?>_indicators">✏️ Edit</a>
                                    <form method="post" onsubmit="return confirm('Delete this indicator?');">
                                        <input type="hidden" name="project_id" value="<?= $pid ?>">
                                        <input type="hidden" name="anchor" value="<?= $scope ?>_indicators">
                                        <input type="hidden" name="action_type" value="delete_indicator">
                                        <input type="hidden" name="scope" value="<?= $scope ?>">
                                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                        <button class="btn danger" <?= $disabledAttr ?>>🗑️ Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                    <div class="muted">SADD Total auto-calculates on save and excludes PWD. HH unit keeps total manual.</div>
                </div>

            </div>
        </details>
    <?php endforeach; ?>

    <!-- ACTIVITIES -->
    <details id="activities">
        <summary>🛠️ Activities</summary>
        <div class="section">
            <div class="row" style="margin-bottom:10px;">
                <a class="btn gray" <?= $disabledAttr ?> href="planning.php?action=export_section&section=activities&format=csv&project_id=<?= $pid ?>">📥 Export CSV</a>
                <a class="btn gray" <?= $disabledAttr ?> href="planning.php?action=export_section&section=activities&format=html&project_id=<?= $pid ?>">📥 Export HTML</a>
                <a class="btn gray" <?= $disabledAttr ?> href="planning.php?action=export_template&section=activities&project_id=<?= $pid ?>">⬇️ Template CSV</a>
            </div>

            <?php $isEditAct = (bool)$edit_activity; $ea = $edit_activity; ?>
            <div class="card" style="background:#f9fafb;">
                <div class="title"><?= $isEditAct ? '✏️ Edit Activity' : '➕ Add Activity' ?></div>
                <form method="post">
                    <input type="hidden" name="project_id" value="<?= $pid ?>">
                    <input type="hidden" name="anchor" value="activities">
                    <input type="hidden" name="action_type" value="<?= $isEditAct ? 'update_activity' : 'add_activity' ?>">
                    <input type="hidden" name="id" value="<?= (int)($ea[$ACT['id']] ?? 0) ?>">

                    <div class="grid3">
                        <div>
                            <label class="muted">Linked Output (optional)</label>
                            <select name="output_result_id">
                                <option value="">-- No link --</option>
                                <?php foreach ($outputResults as $or): ?>
                                    <?php $rid=(int)$or['id']; $sel = ($isEditAct && $ACT['output_result_id'] && (int)($ea[$ACT['output_result_id']] ?? 0)===$rid) ? 'selected' : ''; ?>
                                    <option value="<?= $rid ?>" <?= $sel ?>><?= h($resultLabel($or)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="muted">Code</label>
                            <input name="activity_code" value="<?= h($isEditAct ? ($ACT['code'] ? ($ea[$ACT['code']] ?? '') : '') : '') ?>" placeholder="e.g., A1">
                        </div>
                        <div>
                            <label class="muted">Unit</label>
                            <input name="unit_type" value="<?= h($isEditAct ? ($ACT['unit'] ? ($ea[$ACT['unit']] ?? 'sessions') : 'sessions') : 'sessions') ?>" placeholder="sessions">
                        </div>
                    </div>

                    <div style="margin-top:10px;">
                        <label class="muted">Activity Name</label>
                        <textarea name="activity_name" required><?= h($isEditAct ? ($ea[$ACT['name']] ?? '') : '') ?></textarea>
                    </div>

                    <div class="grid3" style="margin-top:10px;">
                        <div>
                            <label class="muted">Target</label>
                            <input type="number" name="target_total" min="0" value="<?= (int)($isEditAct ? ($ACT['target'] ? ($ea[$ACT['target']] ?? 0) : 0) : 0) ?>">
                        </div>
                        <div>
                            <label class="muted">Start Date</label>
                            <input type="date" name="start_date" value="<?= h($isEditAct ? ($ACT['start'] ? ($ea[$ACT['start']] ?? '') : '') : '') ?>">
                        </div>
                        <div>
                            <label class="muted">End Date</label>
                            <input type="date" name="end_date" value="<?= h($isEditAct ? ($ACT['end'] ? ($ea[$ACT['end']] ?? '') : '') : '') ?>">
                        </div>
                    </div>

                    <div class="row" style="margin-top:10px;">
                        <button class="btn <?= $isEditAct ? 'primary' : 'success' ?>" <?= $disabledAttr ?>>Save</button>
                        <?php if ($isEditAct): ?>
                            <a class="btn gray" href="planning.php?project_id=<?= $pid ?>#activities">Cancel Edit</a>
                        <?php endif; ?>
                    </div>
                </form>
                <div class="muted">This module writes to: <b><?= h($ACT['table']) ?></b> for cross-app reuse.</div>
            </div>

            <div class="card">
                <div class="title">Activity List</div>
                <table>
                    <thead>
                        <tr>
                            <th style="width:240px">Linked Output</th>
                            <th style="width:120px">Code</th>
                            <th>Activity</th>
                            <th style="width:120px">Unit</th>
                            <th style="width:90px">Target</th>
                            <th style="width:110px">Start</th>
                            <th style="width:110px">End</th>
                            <th style="width:240px">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$activities): ?>
                        <tr><td colspan="8" class="muted">No activities added yet.</td></tr>
                    <?php else: foreach ($activities as $a): ?>
                        <?php
                            $outId = (int)($ACT['output_result_id'] ? ($a[$ACT['output_result_id']] ?? 0) : 0);
                            $linked = $outId ? ($resultMap[$outId] ?? ('Output #'.$outId)) : '-';
                            $codeVal = $ACT['code'] ? ($a[$ACT['code']] ?? '') : '';
                            $nameVal = $a[$ACT['name']] ?? '';
                            $unitVal = $ACT['unit'] ? ($a[$ACT['unit']] ?? '') : '';
                            $tgtVal  = $ACT['target'] ? ($a[$ACT['target']] ?? 0) : 0;
                            $stVal   = $ACT['start'] ? ($a[$ACT['start']] ?? '') : '';
                            $enVal   = $ACT['end'] ? ($a[$ACT['end']] ?? '') : '';
                        ?>
                        <tr>
                            <td><?= h($linked) ?></td>
                            <td><?= h($codeVal) ?></td>
                            <td><?= h($nameVal) ?></td>
                            <td><?= h($unitVal) ?></td>
                            <td><?= (int)$tgtVal ?></td>
                            <td><?= h($stVal) ?></td>
                            <td><?= h($enVal) ?></td>
                            <td class="actions">
                                <a class="btn gray" <?= $disabledAttr ?> href="planning.php?project_id=<?= $pid ?>&edit=activity&id=<?= (int)($a[$ACT['id']] ?? 0) ?>#activities">✏️ Edit</a>
                                <form method="post" onsubmit="return confirm('Delete this activity?');">
                                    <input type="hidden" name="project_id" value="<?= $pid ?>">
                                    <input type="hidden" name="anchor" value="activities">
                                    <input type="hidden" name="action_type" value="delete_activity">
                                    <input type="hidden" name="id" value="<?= (int)($a[$ACT['id']] ?? 0) ?>">
                                    <button class="btn danger" <?= $disabledAttr ?>>🗑️ Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </details>

    <!-- GANTT -->
    <details id="gantt">
        <summary>📅 Activity Work Plan (Gantt-style Preview)</summary>
        <div class="section">
            <div class="card" style="background:#f9fafb;">
                <div class="muted">
                    Uses activity Start/End dates. If a date is blank, no bar will appear.
                    If project start/end missing, last 12 months are shown.
                </div>
            </div>

            <?php $months = month_range($project_details['start_date'] ?? null, $project_details['end_date'] ?? null); ?>

            <div class="card">
                <table class="gantt">
                    <thead>
                        <tr>
                            <th style="min-width:280px; text-align:left">Activity</th>
                            <?php foreach ($months as $m): ?>
                                <th><?= h(month_label($m)) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$activities): ?>
                            <tr><td colspan="<?= count($months)+1 ?>" class="muted">No activities yet.</td></tr>
                        <?php else: foreach ($activities as $a): ?>
                            <?php
                                $nameVal = $a[$ACT['name']] ?? '';
                                $stVal = $ACT['start'] ? ($a[$ACT['start']] ?? null) : null;
                                $enVal = $ACT['end'] ? ($a[$ACT['end']] ?? null) : null;
                            ?>
                            <tr>
                                <td style="text-align:left">
                                    <?= h(mb_substr((string)$nameVal, 0, 120)) ?>
                                    <div class="muted"><?= h($stVal ?: '') ?> → <?= h($enVal ?: '') ?></div>
                                </td>
                                <?php foreach ($months as $m): ?>
                                    <?php $in = ($stVal || $enVal) ? is_month_in_range($m, $stVal, $enVal) : false; ?>
                                    <td class="<?= $in ? 'bar' : '' ?>"><?= $in ? '■' : '' ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </details>

    <!-- BENEFICIARIES -->
    <details id="beneficiaries">
        <summary>👥 Beneficiary Calculation & Project Reach</summary>
        <div class="section">
            <div class="row" style="margin-bottom:10px;">
                <a class="btn gray" <?= $disabledAttr ?> href="planning.php?action=export_section&section=beneficiaries&format=csv&project_id=<?= $pid ?>">📥 Export CSV</a>
                <a class="btn gray" <?= $disabledAttr ?> href="planning.php?action=export_section&section=beneficiaries&format=html&project_id=<?= $pid ?>">📥 Export HTML</a>
                <a class="btn gray" <?= $disabledAttr ?> href="planning.php?action=export_template&section=beneficiaries&project_id=<?= $pid ?>">⬇️ Template CSV</a>
            </div>

            <div class="card" style="background:#f9fafb;">
                <div class="title"><?= $edit_beneficiary ? '✏️ Edit Beneficiary Entry' : '➕ Add Beneficiary Entry' ?></div>
                <?php $eb = $edit_beneficiary; ?>
                <form method="post">
                    <input type="hidden" name="project_id" value="<?= $pid ?>">
                    <input type="hidden" name="anchor" value="beneficiaries">
                    <input type="hidden" name="action_type" value="<?= $eb ? 'update_beneficiary' : 'add_beneficiary' ?>">
                    <input type="hidden" name="id" value="<?= (int)($eb['id'] ?? 0) ?>">

                    <div class="grid4">
                        <div>
                            <label class="muted">Beneficiary Type</label>
                            <input name="beneficiary_type" required value="<?= h($eb['beneficiary_type'] ?? '') ?>" placeholder="e.g., IDPs, Host, Returnees">
                        </div>
                        <div><label class="muted">Women</label><input type="number" name="women" min="0" value="<?= (int)($eb['women'] ?? 0) ?>"></div>
                        <div><label class="muted">Girls</label><input type="number" name="girls" min="0" value="<?= (int)($eb['girls'] ?? 0) ?>"></div>
                        <div><label class="muted">Men</label><input type="number" name="men" min="0" value="<?= (int)($eb['men'] ?? 0) ?>"></div>
                        <div><label class="muted">Boys</label><input type="number" name="boys" min="0" value="<?= (int)($eb['boys'] ?? 0) ?>"></div>
                        <div><label class="muted">PWD (separate)</label><input type="number" name="pwd_count" min="0" value="<?= (int)($eb['pwd_count'] ?? 0) ?>"></div>
                        <div><label class="muted">Region ID</label><input type="number" name="region_id" min="0" value="<?= h($eb['region_id'] ?? '') ?>"></div>
                        <div><label class="muted">Zone ID</label><input type="number" name="zone_id" min="0" value="<?= h($eb['zone_id'] ?? '') ?>"></div>
                        <div><label class="muted">Woreda ID</label><input type="number" name="woreda_id" min="0" value="<?= h($eb['woreda_id'] ?? '') ?>"></div>
                    </div>

                    <div class="row" style="margin-top:10px;">
                        <button class="btn <?= $eb ? 'primary' : 'success' ?>" <?= $disabledAttr ?>>Save</button>
                        <?php if ($eb): ?>
                            <a class="btn gray" href="planning.php?project_id=<?= $pid ?>#beneficiaries">Cancel Edit</a>
                        <?php endif; ?>
                    </div>
                </form>

                <div class="muted"><b>Fixed calculation:</b> Total = Women + Girls + Men + Boys. PWD is kept separate to avoid double counting.</div>
            </div>

            <div class="card">
                <div class="title">Beneficiary List</div>
                <table>
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th style="width:90px">Women</th>
                            <th style="width:90px">Girls</th>
                            <th style="width:90px">Men</th>
                            <th style="width:90px">Boys</th>
                            <th style="width:110px">PWD (sep.)</th>
                            <th style="width:90px">Total</th>
                            <th style="width:90px">Share %</th>
                            <th style="width:240px">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$beneficiaries): ?>
                        <tr><td colspan="9" class="muted">No beneficiary data added yet.</td></tr>
                    <?php else: foreach ($beneficiaries as $b): ?>
                        <tr>
                            <td><?= h($b['beneficiary_type'] ?? '') ?></td>
                            <td><?= (int)($b['women'] ?? 0) ?></td>
                            <td><?= (int)($b['girls'] ?? 0) ?></td>
                            <td><?= (int)($b['men'] ?? 0) ?></td>
                            <td><?= (int)($b['boys'] ?? 0) ?></td>
                            <td><?= (int)($b['pwd_count'] ?? 0) ?></td>
                            <td><b><?= (int)($b['total'] ?? 0) ?></b></td>
                            <td><?= h($b['share_percentage'] ?? 0) ?></td>
                            <td class="actions">
                                <a class="btn gray" <?= $disabledAttr ?> href="planning.php?project_id=<?= $pid ?>&edit=beneficiary&id=<?= (int)$b['id'] ?>#beneficiaries">✏️ Edit</a>
                                <form method="post" onsubmit="return confirm('Delete this beneficiary entry?');">
                                    <input type="hidden" name="project_id" value="<?= $pid ?>">
                                    <input type="hidden" name="anchor" value="beneficiaries">
                                    <input type="hidden" name="action_type" value="delete_beneficiary">
                                    <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                                    <button class="btn danger" <?= $disabledAttr ?>>🗑️ Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </details>

    <div class="card">
        <div class="muted">
            If something still "does not work", it is almost always a PHP error shown in:
            <b>XAMPP → Apache → Logs → error.log</b>.
            This build removes Bootstrap-JS and AJAX dependencies so UI cannot be "locked" by JS/CDN.
        </div>
    </div>

</div>
</body>
</html>
