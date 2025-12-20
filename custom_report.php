<?php 
// custom_report.php – Advanced Nexus Ethiopia Custom Project Report
// Linked with: projects.php, planning.php, enter_data.php, budget.php

require_once __DIR__ . '/../helpers.php';
require_login();
$pdo = getPDO();

// Start output buffering so CSV/Word headers always work
if (function_exists('ob_start') && !ob_get_level()) {
    ob_start();
}

// Error handling at the start
if (!$pdo) {
    die("Database connection failed. Please check your configuration.");
}

/* ---------------------- small helpers ---------------------- */

if (!function_exists('table_has_column')) {
    function table_has_column(PDO $pdo, string $table, string $column): bool {
        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
            $stmt->execute([$column]);
            return (bool)$stmt->fetch();
        } catch (Exception $e) {
            error_log("Column check failed: " . $e->getMessage());
            return false;
        }
    }
}

// Safe HTML escape
if (!function_exists('h')) {
    function h(string $v): string {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}

// Format numbers with commas
if (!function_exists('format_number')) {
    function format_number($num): string {
        return number_format((float)$num);
    }
}

// Calculate percentage safely
if (!function_exists('safe_percentage')) {
    function safe_percentage($part, $total, $decimal = 2): string {
        if ($total == 0 || $total === null) return '0';
        return number_format(($part / $total) * 100, $decimal);
    }
}

/* ---------------------- MISSING FUNCTIONS FIX ---------------------- */

if (!function_exists('get_regions')) {
    function get_regions() {
        global $pdo;
        try {
            $stmt = $pdo->query("SELECT id, name FROM regions ORDER BY name");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting regions: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('get_zones_by_region')) {
    function get_zones_by_region($region_id) {
        global $pdo;
        try {
            $stmt = $pdo->prepare("SELECT id, name FROM zones WHERE region_id = ? ORDER BY name");
            $stmt->execute([$region_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting zones: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('get_woredas_by_zone')) {
    function get_woredas_by_zone($zone_id) {
        global $pdo;
        try {
            $stmt = $pdo->prepare("SELECT id, name FROM woredas WHERE zone_id = ? ORDER BY name");
            $stmt->execute([$zone_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting woredas: " . $e->getMessage());
            return [];
        }
    }
}

// Fallback: all zones/woredas (used when region/zone not selected)
if (!function_exists('get_all_zones')) {
    function get_all_zones() {
        global $pdo;
        try {
            $stmt = $pdo->query("SELECT id, name FROM zones ORDER BY name");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting all zones: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('get_all_woredas')) {
    function get_all_woredas() {
        global $pdo;
        try {
            $stmt = $pdo->query("SELECT id, name FROM woredas ORDER BY name");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting all woredas: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('get_projects')) {
    function get_projects() {
        global $pdo;
        try {
            $stmt = $pdo->query("SELECT id, title, name FROM projects ORDER BY title, name");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting projects: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('get_indicators')) {
    function get_indicators($project_id = null) {
        global $pdo;
        try {
            // Check if target_value column exists
            $hasTargetValueCol = table_has_column($pdo, 'indicators', 'target_value');
            $targetSelect = $hasTargetValueCol 
                ? 'COALESCE(total_target, target_value, 0) as target_value'
                : 'COALESCE(total_target, 0) as target_value';
            
            $sql = "SELECT id, code, name, unit_type, {$targetSelect} FROM indicators";
            $params = [];
            if ($project_id) {
                $sql .= " WHERE project_id = ?";
                $params[] = $project_id;
            }
            $sql .= " ORDER BY code";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting indicators: " . $e->getMessage());
            // Try without target_value if column doesn't exist
            try {
                $sql = "SELECT id, code, name, unit_type, COALESCE(total_target, 0) as target_value FROM indicators";
                if ($project_id) {
                    $sql .= " WHERE project_id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$project_id]);
                } else {
                    $stmt = $pdo->query($sql);
                }
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e2) {
                return [];
            }
        }
    }
}

/* ---------------------- check optional columns ---------------------- */

// These checks allow custom_report.php to be used on older DBs gracefully
$hasRegionCol = table_has_column($pdo, 'reports', 'region_id');
$hasZoneCol   = table_has_column($pdo, 'reports', 'zone_id');
$hasWoredaCol = table_has_column($pdo, 'reports', 'woreda_id');

/* ---------------------- reference data ---------------------- */

try {
    $projects      = get_projects();
    $regions       = get_regions();
    // Get indicators for selected project if available
    $selectedProjectId = $_GET['project_id'] ?? '';
    $indicatorsAll = get_indicators($selectedProjectId ? (int)$selectedProjectId : null);
} catch (Exception $e) {
    error_log("Error loading reference data: " . $e->getMessage());
    $projects = $regions = $indicatorsAll = [];
}

/* ---------------------- helper for multi-select GET values ---------------------- */

function normalize_multi($param) {
    $result = [];
    if (!isset($_GET[$param])) {
        return $result;
    }
    $val = $_GET[$param];
    if (is_array($val)) {
        foreach ($val as $v) {
            if ($v !== '' && $v !== null) {
                $result[] = $v;
            }
        }
    } else {
        if ($val !== '' && $val !== null) {
            $result[] = $val;
        }
    }
    return $result;
}

/* ---------------------- filter inputs ---------------------- */

$selected_region_id = $_GET['region_id'] ?? '';
$selected_zone_id   = $_GET['zone_id'] ?? '';

$indicatorIdsRaw = normalize_multi('indicator_id');
$indicatorIds = [];
foreach ($indicatorIdsRaw as $v) {
    $indicatorIds[] = (int)$v;
}

$benefTypesRaw = normalize_multi('benef_type');
$benefTypes = [];
foreach ($benefTypesRaw as $v) {
    $benefTypes[] = (string)$v;
}

$woredaIdsRaw = normalize_multi('woreda_id');
$woredaIds = [];
foreach ($woredaIdsRaw as $v) {
    if ($v !== '') {
        $woredaIds[] = (int)$v;
    }
}

/* --- Cascading geography lists for filters (auto full if not filtered) --- */

if ($selected_region_id) {
    $zones = get_zones_by_region($selected_region_id);
} else {
    $zones = get_all_zones();
}

if ($selected_zone_id) {
    $woredas = get_woredas_by_zone($selected_zone_id);
} else {
    $woredas = get_all_woredas();
}

/* ---------------------- read filters ---------------------- */

$filters = [
    'project_id'   => $_GET['project_id']   ?? '',
    'region_id'    => $selected_region_id,
    'zone_id'      => $selected_zone_id,
    'woreda_id'    => isset($woredaIds[0]) ? $woredaIds[0] : '',
    'period_type'  => $_GET['period_type']  ?? '',
    'year_from'    => $_GET['year_from']    ?? '',
    'year_to'      => $_GET['year_to']      ?? '',
    'month_from'   => $_GET['month_from']   ?? '',
    'month_to'     => $_GET['month_to']     ?? '',
    'quick_range'  => $_GET['quick_range']  ?? '',
    'custom_period_value' => $_GET['custom_period_value'] ?? '',
    // keep first selected for backward compatibility / synthetic rows
    'indicator_id' => isset($indicatorIds[0]) ? $indicatorIds[0] : '',
    'benef_type'   => isset($benefTypes[0])   ? $benefTypes[0]   : '',
    'display_mode' => $_GET['display_mode']  ?? 'table'
];

$projectSelected = ($filters['project_id'] !== '' && $filters['project_id'] !== 'all');

/* ---------------------- containers ---------------------- */

$reportRows    = [];
$reportIds     = [];
$indicatorAgg  = [];
$chartAgg      = [];
$analyticsData = [];

// For Nexus Ethiopia header (date range)
$dateStartList = [];
$dateEndList   = [];

/* ---------------------- project details (from projects.php / DB) ---------------------- */

$projectHeaderTitle   = 'All projects';
$projectCode          = '';
$projectDescription   = '';
$projectMainSectors   = '';
$projectSpecificAreas = '';
$projectLocation      = '';
$projectDonor         = '';

// Store project geography for auto-population
$projectRegionId = '';
$projectZoneId = '';
$projectWoredaIds = [];

if ($projectSelected) {
    $pid = (int)$filters['project_id'];
    $projectHeaderTitle = 'Project #' . $pid;

    foreach ($projects as $p) {
        if ((int)$p['id'] === $pid) {
            $projectHeaderTitle = $p['title'] ?? ($p['name'] ?? $projectHeaderTitle);
            break;
        }
    }

    try {
        $stmtProj = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
        $stmtProj->execute([$pid]);
        $projectDetails = $stmtProj->fetch(PDO::FETCH_ASSOC);

        if ($projectDetails) {
            $projectCode        = $projectDetails['code']                ?? ($projectDetails['project_code'] ?? '');
            $projectDescription = $projectDetails['description']         ?? ($projectDetails['project_description'] ?? '');
            $projectMainSectors = $projectDetails['main_programme_sectors'] 
                                   ?? ($projectDetails['main_sectors'] ?? '');
            $projectSpecificAreas = $projectDetails['project_specific_areas'] 
                                     ?? ($projectDetails['specific_areas'] ?? '');
            $projectLocation    = $projectDetails['project_location']    ?? ($projectDetails['location'] ?? '');
            $projectDonor       = $projectDetails['donor']               ?? ($projectDetails['donor_name'] ?? '');
            
            // Auto-populate geography from project
            $projectRegionId = $projectDetails['region_id'] ?? '';
            $projectZoneId = $projectDetails['zone_id'] ?? '';
            
            // Get project locations from project_locations table if exists
            try {
                $stmtLoc = $pdo->prepare("SELECT region_id, zone_id, woreda_id FROM project_locations WHERE project_id = ?");
                $stmtLoc->execute([$pid]);
                $projectLocations = $stmtLoc->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($projectLocations)) {
                    // Use first location as default, or collect all woredas
                    $firstLoc = $projectLocations[0];
                    if (empty($projectRegionId)) $projectRegionId = $firstLoc['region_id'] ?? '';
                    if (empty($projectZoneId)) $projectZoneId = $firstLoc['zone_id'] ?? '';
                    foreach ($projectLocations as $loc) {
                        if (!empty($loc['woreda_id'])) {
                            $projectWoredaIds[] = (int)$loc['woreda_id'];
                        }
                    }
                }
            } catch (Exception $e) {
                // project_locations table may not exist
            }
            
            // If no geography filters set, auto-populate from project
            if (empty($filters['region_id']) && !empty($projectRegionId)) {
                $filters['region_id'] = $projectRegionId;
                $selected_region_id = $projectRegionId;
            }
            if (empty($filters['zone_id']) && !empty($projectZoneId)) {
                $filters['zone_id'] = $projectZoneId;
                $selected_zone_id = $projectZoneId;
            }
            if (empty($woredaIds) && !empty($projectWoredaIds)) {
                $woredaIds = $projectWoredaIds;
            }
        }
    } catch (Exception $e) {
        error_log("Error loading project details: " . $e->getMessage());
    }
} elseif ($filters['project_id'] === 'all') {
    $projectHeaderTitle = 'All Projects';
}

/* ---------------------- planning / budget maps ---------------------- */

$planMap   = []; // key: indicator_id => ['plan_period' => float, 'plan_cumulative' => float]
$budgetMap = []; // key: indicator_id => ['planned' => float, 'used' => float]

try {
    // Check plan table
    $stmtT = $pdo->query("SHOW TABLES LIKE 'indicator_plans'");
    $hasPlanTable = (bool)$stmtT->fetch();

    if ($projectSelected && $hasPlanTable) {
        $planWhere  = ["project_id = ?"];
        $planParams = [(int)$filters['project_id']];
    } elseif ($filters['project_id'] === 'all' && $hasPlanTable) {
        $planWhere  = [];
        $planParams = [];

        if ($filters['period_type'] !== '') {
            $planWhere[]  = "period_type = ?";
            $planParams[] = $filters['period_type'];
        }
        if ($filters['year_from'] !== '') {
            $planWhere[]  = "year >= ?";
            $planParams[] = (int)$filters['year_from'];
        }
        if ($filters['year_to'] !== '') {
            $planWhere[]  = "year <= ?";
            $planParams[] = (int)$filters['year_to'];
        }
        if ($filters['month_from'] !== '') {
            $planWhere[]  = "(month IS NOT NULL AND month >= ?)";
            $planParams[] = (int)$filters['month_from'];
        }
        if ($filters['month_to'] !== '') {
            $planWhere[]  = "(month IS NOT NULL AND month <= ?)";
            $planParams[] = (int)$filters['month_to'];
        }

        $planWhereSql = !empty($planWhere) ? implode(' AND ', $planWhere) : '1=1';

        $sqlPlan = "
            SELECT
                indicator_id,
                SUM(plan_value) AS plan_value
            FROM indicator_plans
            WHERE {$planWhereSql}
            GROUP BY indicator_id
        ";
        $stmtP = $pdo->prepare($sqlPlan);
        $stmtP->execute($planParams);
        $planRows = $stmtP->fetchAll();

        foreach ($planRows as $pr) {
            $indId = (int)$pr['indicator_id'];
            $planMap[$indId] = [
                'plan_period'     => (float)$pr['plan_value'],
                'plan_cumulative' => (float)$pr['plan_value'] // for now same as period plan
            ];
        }
    }

    // Check budget table
    $stmtT2 = $pdo->query("SHOW TABLES LIKE 'indicator_budget'");
    $hasBudgetTable = (bool)$stmtT2->fetch();

    if ($projectSelected && $hasBudgetTable) {
        $bWhere  = ["project_id = ?"];
        $bParams = [(int)$filters['project_id']];
    } elseif ($filters['project_id'] === 'all' && $hasBudgetTable) {
        $bWhere  = [];
        $bParams = [];

        if ($filters['period_type'] !== '') {
            $bWhere[]  = "period_type = ?";
            $bParams[] = $filters['period_type'];
        }
        if ($filters['year_from'] !== '') {
            $bWhere[]  = "year >= ?";
            $bParams[] = (int)$filters['year_from'];
        }
        if ($filters['year_to'] !== '') {
            $bWhere[]  = "year <= ?";
            $bParams[] = (int)$filters['year_to'];
        }
        if ($filters['month_from'] !== '') {
            $bWhere[]  = "(month IS NOT NULL AND month >= ?)";
            $bParams[] = (int)$filters['month_from'];
        }
        if ($filters['month_to'] !== '') {
            $bWhere[]  = "(month IS NOT NULL AND month <= ?)";
            $bParams[] = (int)$filters['month_to'];
        }

        $bWhereSql = !empty($bWhere) ? implode(' AND ', $bWhere) : '1=1';

        $sqlBudget = "
            SELECT
                indicator_id,
                SUM(budget_planned) AS budget_planned,
                SUM(budget_used)    AS budget_used
            FROM indicator_budget
            WHERE {$bWhereSql}
            GROUP BY indicator_id
        ";
        $stmtB = $pdo->prepare($sqlBudget);
        $stmtB->execute($bParams);
        $budgetRows = $stmtB->fetchAll();

        foreach ($budgetRows as $br) {
            $indId = (int)$br['indicator_id'];
            $budgetMap[$indId] = [
                'planned' => (float)$br['budget_planned'],
                'used'    => (float)$br['budget_used']
            ];
        }
    }
} catch (Exception $e) {
    error_log("Planning/Budget map error: " . $e->getMessage());
}

/* ---------------------- fetch report meta & SADD only if project selected ---------------------- */

if ($projectSelected) {
    try {
        // Build SELECT for reports
        $sql = "SELECT r.*";

        if ($hasRegionCol) {
            $sql .= ", rg.name AS region_name";
        }
        if ($hasZoneCol) {
            $sql .= ", z.name AS zone_name";
        }
        if ($hasWoredaCol) {
            $sql .= ", w.name AS woreda_name";
        }

        $sql .= " FROM reports r";

        if ($hasRegionCol) {
            $sql .= " LEFT JOIN regions rg ON r.region_id = rg.id";
        }
        if ($hasZoneCol) {
            $sql .= " LEFT JOIN zones z ON r.zone_id = z.id";
        }
        if ($hasWoredaCol) {
            $sql .= " LEFT JOIN woredas w ON r.woreda_id = w.id";
        }

        $sql .= " WHERE 1=1";
        $params = [];

        // project filter (skip if "all" is selected)
        if ($projectSelected) {
            $sql      .= " AND r.project_id = ?";
            $params[]  = (int)$filters['project_id'];
        }

        // geography filters
        if ($filters['region_id'] !== '' && $hasRegionCol) {
            $sql      .= " AND r.region_id = ?";
            $params[]  = $filters['region_id'];
        }
        if ($filters['zone_id'] !== '' && $hasZoneCol) {
            $sql      .= " AND r.zone_id = ?";
            $params[]  = $filters['zone_id'];
        }

        if (!empty($woredaIds) && $hasWoredaCol) {
            $ph = implode(',', array_fill(0, count($woredaIds), '?'));
            $sql .= " AND r.woreda_id IN ($ph)";
            foreach ($woredaIds as $wid) {
                $params[] = $wid;
            }
        }

        // year range
        if ($filters['year_from'] !== '') {
            $sql      .= " AND r.year >= ?";
            $params[]  = (int)$filters['year_from'];
        }
        if ($filters['year_to'] !== '') {
            $sql      .= " AND r.year <= ?";
            $params[]  = (int)$filters['year_to'];
        }

        // month range
        if ($filters['month_from'] !== '') {
            $sql      .= " AND (r.month IS NOT NULL AND r.month >= ?)";
            $params[]  = (int)$filters['month_from'];
        }
        if ($filters['month_to'] !== '') {
            $sql      .= " AND (r.month IS NOT NULL AND r.month <= ?)";
            $params[]  = (int)$filters['month_to'];
        }

        // period type
        if ($filters['period_type'] !== '') {
            $sql      .= " AND r.period_type = ?";
            $params[]  = $filters['period_type'];
        }

        // quick ranges (on start_date) - only if month_from and month_to are not both set to same month
        $hasSpecificMonth = ($filters['month_from'] !== '' && $filters['month_to'] !== '' && $filters['month_from'] == $filters['month_to']);
        
        if ($filters['quick_range'] !== '' && !$hasSpecificMonth) {
            $today = new DateTime();
            $fromDate = null;

            switch ($filters['quick_range']) {
                // weeks
                case 'past_1w':  $fromDate = (clone $today)->modify('-1 week');   break;
                case 'past_2w':  $fromDate = (clone $today)->modify('-2 weeks');  break;
                case 'past_3w':  $fromDate = (clone $today)->modify('-3 weeks');  break;
                case 'past_4w':  $fromDate = (clone $today)->modify('-4 weeks');  break;
                // months (2–12 and long ranges)
                case 'past_2m':   $fromDate = (clone $today)->modify('-2 months');   break;
                case 'past_3m':   $fromDate = (clone $today)->modify('-3 months');   break;
                case 'past_4m':   $fromDate = (clone $today)->modify('-4 months');   break;
                case 'past_5m':   $fromDate = (clone $today)->modify('-5 months');   break;
                case 'past_6m':   $fromDate = (clone $today)->modify('-6 months');   break;
                case 'past_7m':   $fromDate = (clone $today)->modify('-7 months');   break;
                case 'past_8m':   $fromDate = (clone $today)->modify('-8 months');   break;
                case 'past_9m':   $fromDate = (clone $today)->modify('-9 months');   break;
                case 'past_10m':  $fromDate = (clone $today)->modify('-10 months');  break;
                case 'past_11m':  $fromDate = (clone $today)->modify('-11 months');  break;
                case 'past_12m':  $fromDate = (clone $today)->modify('-12 months');  break;
                case 'past_24m':  $fromDate = (clone $today)->modify('-24 months');  break;
                case 'past_36m':  $fromDate = (clone $today)->modify('-36 months');  break;
                case 'past_48m':  $fromDate = (clone $today)->modify('-48 months');  break;
                // Custom periods
                case 'custom_weeks':
                    $customValue = (int)($filters['custom_period_value'] ?? 0);
                    if ($customValue > 0) {
                        $fromDate = (clone $today)->modify('-' . $customValue . ' weeks');
                    }
                    break;
                case 'custom_months':
                    $customValue = (int)($filters['custom_period_value'] ?? 0);
                    if ($customValue > 0) {
                        $fromDate = (clone $today)->modify('-' . $customValue . ' months');
                    }
                    break;
            }

            if ($fromDate) {
                $sql      .= " AND r.start_date >= ?";
                $params[]  = $fromDate->format('Y-m-d');
            }
        }

        $sql .= " ORDER BY r.year DESC, r.month DESC, r.week DESC, r.created_at DESC LIMIT 500";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $reportRows = $stmt->fetchAll();

        foreach ($reportRows as $rr) {
            if (!empty($rr['id'])) {
                $reportIds[] = (int)$rr['id'];
            }
            if (!empty($rr['start_date'])) {
                $dateStartList[] = $rr['start_date'];
            }
            if (!empty($rr['end_date'])) {
                $dateEndList[] = $rr['end_date'];
            }
        }

        // NEW: Analytics data collection
        $analyticsData = [
            'total_reports' => count($reportRows),
            'reports_by_period_type' => [],
            'reports_by_region' => [],
            'reports_by_month' => []
        ];

        foreach ($reportRows as $report) {
            // Period type analytics
            $periodType = $report['period_type'] ?? 'unknown';
            if (!isset($analyticsData['reports_by_period_type'][$periodType])) {
                $analyticsData['reports_by_period_type'][$periodType] = 0;
            }
            $analyticsData['reports_by_period_type'][$periodType]++;

            // Region analytics
            $regionName = $report['region_name'] ?? 'Unknown';
            if (!isset($analyticsData['reports_by_region'][$regionName])) {
                $analyticsData['reports_by_region'][$regionName] = 0;
            }
            $analyticsData['reports_by_region'][$regionName]++;

            // Monthly analytics
            if (!empty($report['month']) && !empty($report['year'])) {
                $monthKey = $report['year'] . '-' . str_pad($report['month'], 2, '0', STR_PAD_LEFT);
                if (!isset($analyticsData['reports_by_month'][$monthKey])) {
                    $analyticsData['reports_by_month'][$monthKey] = 0;
                }
                $analyticsData['reports_by_month'][$monthKey]++;
            }
        }

        // Aggregate indicator SADD data from report_values
        if ($reportIds) {
            $placeholders = implode(',', array_fill(0, count($reportIds), '?'));

            // Check if target_value column exists, if not use total_target
            $hasTargetValueCol = table_has_column($pdo, 'indicators', 'target_value');
            $targetSelect = $hasTargetValueCol 
                ? 'COALESCE(i.total_target, i.target_value, 0) AS target_value'
                : 'COALESCE(i.total_target, 0) AS target_value';
            $targetGroupBy = $hasTargetValueCol ? 'i.target_value' : 'i.total_target';

            $sqlInd = "
                SELECT
                    rv.indicator_id,
                    rv.beneficiary_type,
                    i.code AS indicator_code,
                    i.name AS indicator_name,
                    i.unit_type,
                    {$targetSelect},
                    COALESCE(SUM(rv.boys_u5),0)     AS boys_u5,
                    COALESCE(SUM(rv.girls_u5),0)    AS girls_u5,
                    COALESCE(SUM(rv.boys_5_17),0)   AS boys_5_17,
                    COALESCE(SUM(rv.girls_5_17),0)  AS girls_5_17,
                    COALESCE(SUM(rv.men_18_59),0)   AS men_18_59,
                    COALESCE(SUM(rv.women_18_59),0) AS women_18_59,
                    COALESCE(SUM(rv.men_60p),0)     AS men_60p,
                    COALESCE(SUM(rv.women_60p),0)   AS women_60p,
                    COALESCE(SUM(rv.pwd_count),0)   AS pwd_count,
                    COALESCE(SUM(rv.non_beneficiary),0) AS non_beneficiary
                FROM report_values rv
                JOIN indicators i ON rv.indicator_id = i.id
                WHERE rv.report_id IN ($placeholders)
            ";

            $paramsInd = $reportIds;

            // multi-indicator filter
            if (!empty($indicatorIds)) {
                $ph = implode(',', array_fill(0, count($indicatorIds), '?'));
                $sqlInd   .= " AND rv.indicator_id IN ($ph)";
                $paramsInd = array_merge($paramsInd, $indicatorIds);
            }

            // multi-beneficiary filter
            if (!empty($benefTypes)) {
                $ph = implode(',', array_fill(0, count($benefTypes), '?'));
                $sqlInd   .= " AND rv.beneficiary_type IN ($ph)";
                $paramsInd = array_merge($paramsInd, $benefTypes);
            }

            $sqlInd .= "
                GROUP BY
                    rv.indicator_id,
                    rv.beneficiary_type,
                    i.code,
                    i.name,
                    i.unit_type,
                    {$targetGroupBy}
                ORDER BY i.code, rv.beneficiary_type
            ";

            $stmtInd = $pdo->prepare($sqlInd);
            $stmtInd->execute($paramsInd);
            $indicatorAgg = $stmtInd->fetchAll();

            // build chart aggregate (sum across beneficiary types)
            foreach ($indicatorAgg as $row) {
                $boys  = (int)$row['boys_u5'] + (int)$row['boys_5_17'];
                $girls = (int)$row['girls_u5'] + (int)$row['girls_5_17'];
                $men   = (int)$row['men_18_59'] + (int)$row['men_60p'];
                $women = (int)$row['women_18_59'] + (int)$row['women_60p'];

                $totalPersons = $boys + $girls + $men + $women;

                $label = $row['indicator_code'] . ' – ' . $row['indicator_name'];
                if (!isset($chartAgg[$label])) {
                    $chartAgg[$label] = 0;
                }
                $chartAgg[$label] += $totalPersons;
            }
        }

        // If a specific indicator is chosen but there is NO data yet,
        // create synthetic zero rows so at least the selected indicator appears.
        if ($filters['indicator_id'] !== '' && !$indicatorAgg) {
            $hasTargetValueCol = table_has_column($pdo, 'indicators', 'target_value');
            $targetSelect = $hasTargetValueCol 
                ? 'COALESCE(total_target, target_value, 0) as target_value'
                : 'COALESCE(total_target, 0) as target_value';
            $stmt = $pdo->prepare("SELECT id, code, name, unit_type, {$targetSelect} FROM indicators WHERE id = ?");
            $stmt->execute([(int)$filters['indicator_id']]);
            if ($ind = $stmt->fetch()) {
                $syntheticBenefTypes = ['host','idp','returnee','refugee','pwd','total'];
                foreach ($syntheticBenefTypes as $bt) {
                    $indicatorAgg[] = [
                        'indicator_id'      => $ind['id'],
                        'beneficiary_type'  => $bt,
                        'indicator_code'    => $ind['code'],
                        'indicator_name'    => $ind['name'],
                        'unit_type'         => $ind['unit_type'],
                        'target_value'      => $ind['target_value'],
                        'boys_u5'           => 0,
                        'girls_u5'          => 0,
                        'boys_5_17'         => 0,
                        'girls_5_17'        => 0,
                        'men_18_59'         => 0,
                        'women_18_59'       => 0,
                        'men_60p'           => 0,
                        'women_60p'         => 0,
                        'pwd_count'         => 0,
                        'non_beneficiary'   => 0
                    ];
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error fetching reports: " . $e->getMessage());
        echo "<div class='alert alert-error'>Error loading report data: " . h($e->getMessage()) . "</div>";
    }
}

/* ---------------------- Nexus header derived values ---------------------- */

// beneficiary labels
$benefLabels = [
    'host'       => 'Host community',
    'idp'        => 'IDP',
    'returnee'   => 'Returnee',
    'refugee'    => 'Refugee',
    'pwd'        => 'People with disability',
    'total'      => 'Total',
    'non_person' => 'Non person'
];

// region, zone, woreda header labels
$regionHeader = 'All';
if ($filters['region_id'] !== '') {
    foreach ($regions as $r) {
        if ((string)$r['id'] === (string)$filters['region_id']) {
            $regionHeader = $r['name'];
            break;
        }
    }
}

$zoneHeader = 'All';
if ($filters['zone_id'] !== '') {
    foreach ($zones as $z) {
        if ((string)$z['id'] === (string)$filters['zone_id']) {
            $zoneHeader = $z['name'];
            break;
        }
    }
}

$woredaHeader = 'All';
if (!empty($woredaIds)) {
    if (count($woredaIds) === 1) {
        foreach ($woredas as $w) {
            if ((string)$w['id'] === (string)$woredaIds[0]) {
                $woredaHeader = $w['name'];
                break;
            }
        }
    } else {
        $woredaHeader = 'Multiple';
    }
}

// report type label
$reportTypeHeader = $filters['period_type']
    ? ucfirst($filters['period_type']) . ' report'
    : 'All';

// reporting month label
if ($filters['month_from'] !== '' && $filters['month_to'] !== '' && $filters['month_from'] == $filters['month_to']) {
    $reportingMonthHeader = date('F', mktime(0,0,0,(int)$filters['month_from'],1));
} elseif ($filters['month_from'] !== '' || $filters['month_to'] !== '') {
    $mfName = $filters['month_from'] !== '' ? date('F', mktime(0,0,0,(int)$filters['month_from'],1)) : 'Any';
    $mtName = $filters['month_to']   !== '' ? date('F', mktime(0,0,0,(int)$filters['month_to'],1))   : 'Any';
    $reportingMonthHeader = $mfName . ' – ' . $mtName;
} else {
    $reportingMonthHeader = 'All';
}

// date range header
$dateFromHeader = '__________';
$dateToHeader   = '__________';
if (!empty($dateStartList)) {
    sort($dateStartList);
    $dateFromHeader = $dateStartList[0];
}
if (!empty($dateEndList)) {
    sort($dateEndList);
    $dateToHeader = $dateEndList[count($dateEndList)-1];
}

// quick range label
$quickRangeHeader = 'All';
if ($filters['quick_range'] !== '') {
    switch ($filters['quick_range']) {
        case 'past_1w':  $quickRangeHeader = 'Past 1 week';   break;
        case 'past_2w':  $quickRangeHeader = 'Past 2 weeks';  break;
        case 'past_3w':  $quickRangeHeader = 'Past 3 weeks';  break;
        case 'past_4w':  $quickRangeHeader = 'Past 4 weeks';  break;
        case 'past_2m':  $quickRangeHeader = 'Past 2 months'; break;
        case 'past_3m':  $quickRangeHeader = 'Past 3 months'; break;
        case 'past_4m':  $quickRangeHeader = 'Past 4 months'; break;
        case 'past_5m':  $quickRangeHeader = 'Past 5 months'; break;
        case 'past_6m':  $quickRangeHeader = 'Past 6 months'; break;
        case 'past_7m':  $quickRangeHeader = 'Past 7 months'; break;
        case 'past_8m':  $quickRangeHeader = 'Past 8 months'; break;
        case 'past_9m':  $quickRangeHeader = 'Past 9 months'; break;
        case 'past_10m': $quickRangeHeader = 'Past 10 months'; break;
        case 'past_11m': $quickRangeHeader = 'Past 11 months'; break;
        case 'past_24m': $quickRangeHeader = 'Past 24 months'; break;
        case 'past_36m': $quickRangeHeader = 'Past 36 months'; break;
        case 'past_48m': $quickRangeHeader = 'Past 48 months'; break;
        case 'custom_weeks': 
            $customValue = $filters['custom_period_value'] ?? '';
            $quickRangeHeader = $customValue ? "Past {$customValue} weeks" : 'Custom weeks'; 
            break;
        case 'custom_months': 
            $customValue = $filters['custom_period_value'] ?? '';
            $quickRangeHeader = $customValue ? "Past {$customValue} months" : 'Custom months'; 
            break;
        default:         $quickRangeHeader = 'Custom';        break;
    }
}

/* ---------------------- export handlers (CSV / Word / Excel / PDF) ---------------------- */

if (!empty($_GET['export']) && in_array($_GET['export'], ['csv','word','excel','pdf'], true)) {
    $exportType = $_GET['export'];

    // Build flat rows with derived totals
    $flat = [];
    $sn   = 1;

    foreach ($indicatorAgg as $row) {
        $boys_u5     = (int)$row['boys_u5'];
        $girls_u5    = (int)$row['girls_u5'];
        $boys_5_17   = (int)$row['boys_5_17'];
        $girls_5_17  = (int)$row['girls_5_17'];
        $men_18_59   = (int)$row['men_18_59'];
        $women_18_59 = (int)$row['women_18_59'];
        $men_60p     = (int)$row['men_60p'];
        $women_60p   = (int)$row['women_60p'];
        $pwd_count   = (int)$row['pwd_count'];
        $non_ben     = (int)$row['non_beneficiary'];

        $boysTotal   = $boys_u5 + $boys_5_17;
        $girlsTotal  = $girls_u5 + $girls_5_17;
        $menTotal    = $men_18_59 + $men_60p;
        $womenTotal  = $women_18_59 + $women_60p;
        $totalPerson = $boysTotal + $girlsTotal + $menTotal + $womenTotal;

        $targetValue = (float)$row['target_value'] ?: 0;

        $indId = (int)$row['indicator_id'];
        $planPeriod = $targetValue;
        $cumPlan    = $targetValue;

        if (isset($planMap[$indId])) {
            $planPeriod = $planMap[$indId]['plan_period'];
            $cumPlan    = $planMap[$indId]['plan_cumulative'];
        }

        $progressPct = $planPeriod > 0 ? safe_percentage($totalPerson, $planPeriod) : '0';

        $flat[] = [
            'SN'               => $sn++,
            'indicator_code'   => $row['indicator_code'],
            'indicator_name'   => $row['indicator_name'],
            'beneficiary_type' => $row['beneficiary_type'],
            'unit'             => $row['unit_type'],
            'target_value'     => $targetValue,
            'plan_period'      => $planPeriod,
            'achieved_value'   => $totalPerson,
            'progress_pct'     => $progressPct,
            'boys_u5'          => $boys_u5,
            'girls_u5'         => $girls_u5,
            'boys_5_17'        => $boys_5_17,
            'girls_5_17'       => $girls_5_17,
            'men_18_59'        => $men_18_59,
            'women_18_59'      => $women_18_59,
            'men_60p'          => $men_60p,
            'women_60p'        => $women_60p,
            'pwd_count'        => $pwd_count,
            'non_beneficiary'  => $non_ben,
            'boys_total'       => $boysTotal,
            'girls_total'      => $girlsTotal,
            'men_total'        => $menTotal,
            'women_total'      => $womenTotal,
            'total_persons'    => $totalPerson
        ];
    }

    if (function_exists('ob_get_length') && ob_get_length()) {
        @ob_clean();
    }

    if ($exportType === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="nexus_custom_report_sadd.csv"');

        $out = fopen('php://output', 'w');

        // Nexus header lines
        fputcsv($out, ["Nexus Ethiopia Project Performance Report – custom view per project"]);
        fputcsv($out, ["Project Title:", $projectHeaderTitle]);
        if ($projectCode !== '')        fputcsv($out, ["Project Code:", $projectCode]);
        if ($projectDonor !== '')       fputcsv($out, ["Donor:", $projectDonor]);
        if ($projectDescription !== '') fputcsv($out, ["Project Description:", $projectDescription]);
        if ($projectMainSectors !== '') fputcsv($out, ["Main Programme Sectors:", $projectMainSectors]);
        if ($projectSpecificAreas !== '') fputcsv($out, ["Project Specific Areas:", $projectSpecificAreas]);
        if ($projectLocation !== '')    fputcsv($out, ["Project Locations:", $projectLocation]);
        fputcsv($out, ["Region:", $regionHeader, "Zone:", $zoneHeader, "Name of Woreda:", $woredaHeader]);
        fputcsv($out, ["Report type:", $reportTypeHeader, "Reporting Month:", $reportingMonthHeader]);
        fputcsv($out, ["Quick range:", $quickRangeHeader]);
        fputcsv($out, ["Date: From", $dateFromHeader, "to", $dateToHeader]);
        fputcsv($out, []); // empty line

        $headerCols = array_keys($flat[0] ?? [
            'SN','indicator_code','indicator_name','beneficiary_type','unit','target_value',
            'plan_period','achieved_value','progress_pct',
            'boys_u5','girls_u5','boys_5_17','girls_5_17','men_18_59','women_18_59',
            'men_60p','women_60p','pwd_count','non_beneficiary',
            'boys_total','girls_total','men_total','women_total','total_persons'
        ]);
        fputcsv($out, $headerCols);

        foreach ($flat as $r) {
            $row = [];
            foreach ($headerCols as $c) {
                $row[] = $r[$c] ?? '';
            }
            fputcsv($out, $row);
        }
        fclose($out);

        if (function_exists('ob_end_flush')) {
            @ob_end_flush();
        }
        exit;
    }

    if ($exportType === 'word') {
        header('Content-Type: application/msword; charset=utf-8');
        header('Content-Disposition: attachment; filename="nexus_custom_report_sadd.doc"');

        echo "<html><body>";
        echo "<h2>Nexus Ethiopia Project Performance Report – custom view per project</h2>";
        echo "<p><strong>Project Title:</strong> " . h($projectHeaderTitle) . "</p>";
        if ($projectCode !== '') {
            echo "<p><strong>Project Code:</strong> " . h($projectCode) . "</p>";
        }
        if ($projectDonor !== '') {
            echo "<p><strong>Donor:</strong> " . h($projectDonor) . "</p>";
        }
        if ($projectDescription !== '') {
            echo "<p><strong>Project Description:</strong> " . h($projectDescription) . "</p>";
        }
        if ($projectMainSectors !== '') {
            echo "<p><strong>Main Programme Sectors:</strong> " . h($projectMainSectors) . "</p>";
        }
        if ($projectSpecificAreas !== '') {
            echo "<p><strong>Project Specific Areas:</strong> " . h($projectSpecificAreas) . "</p>";
        }
        if ($projectLocation !== '') {
            echo "<p><strong>Project Locations:</strong> " . h($projectLocation) . "</p>";
        }

        echo "<p><strong>Region:</strong> " . h($regionHeader) .
             " &nbsp;&nbsp; <strong>Zone:</strong> " . h($zoneHeader) .
             " &nbsp;&nbsp; <strong>Name of Woreda:</strong> " . h($woredaHeader) . "</p>";
        echo "<p><strong>Report type:</strong> " . h($reportTypeHeader) .
             " &nbsp;&nbsp; <strong>Reporting Month:</strong> " . h($reportingMonthHeader) . "</p>";
        echo "<p><strong>Quick range:</strong> " . h($quickRangeHeader) . "</p>";
        echo "<p><strong>Date:</strong> From " . h($dateFromHeader) . " to " . h($dateToHeader) . "</p>";

        echo "<h3>Indicator SADD View (Aggregated)</h3>";
        if (!empty($flat)) {
            $headerCols = array_keys($flat[0]);
            echo "<table border='1' cellpadding='4' cellspacing='0'>";
            echo "<tr>";
            foreach ($headerCols as $hcol) {
                echo "<th>" . h($hcol) . "</th>";
            }
            echo "</tr>";
            foreach ($flat as $r) {
                echo "<tr>";
                foreach ($headerCols as $c) {
                    echo "<td>" . h((string)($r[$c] ?? '')) . "</td>";
                }
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p>No indicator data for the selected filters.</p>";
        }
        echo "</body></html>";

        if (function_exists('ob_end_flush')) {
            @ob_end_flush();
        }
        exit;
    }
    
    if ($exportType === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="nexus_custom_report_' . date('Y-m-d') . '.xls"');
        
        echo '<html><head><meta charset="UTF-8"><style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .header { background: #4361ee; color: white; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
            .header h1 { margin: 0 0 10px 0; }
            table { border-collapse: collapse; width: 100%; margin: 20px 0; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background-color: #4361ee; color: white; font-weight: bold; }
            tr:nth-child(even) { background-color: #f9fafb; }
            @media print {
                body { margin: 10mm; }
            }
        </style></head><body>';
        
        echo '<div class="header">';
        echo '<h1>🚀 Health Reporting System</h1>';
        echo '<h2>Nexus Ethiopia Project Performance Report</h2>';
        echo '<p><strong>Project Title:</strong> ' . h($projectHeaderTitle) . '</p>';
        if ($projectCode !== '') echo '<p><strong>Project Code:</strong> ' . h($projectCode) . '</p>';
        if ($projectDonor !== '') echo '<p><strong>Donor:</strong> ' . h($projectDonor) . '</p>';
        if ($projectDescription !== '') echo '<p><strong>Description:</strong> ' . h($projectDescription) . '</p>';
        echo '<p><strong>Region:</strong> ' . h($regionHeader) . ' | <strong>Zone:</strong> ' . h($zoneHeader) . ' | <strong>Woreda:</strong> ' . h($woredaHeader) . '</p>';
        echo '<p><strong>Export Date:</strong> ' . date('Y-m-d H:i:s') . '</p>';
        echo '</div>';
        
        if (!empty($flat)) {
            $headerCols = array_keys($flat[0]);
            echo '<table>';
            echo '<tr>';
            foreach ($headerCols as $hcol) {
                echo '<th>' . h($hcol) . '</th>';
            }
            echo '</tr>';
            foreach ($flat as $r) {
                echo '<tr>';
                foreach ($headerCols as $c) {
                    echo '<td>' . h((string)($r[$c] ?? '')) . '</td>';
                }
                echo '</tr>';
            }
            echo '</table>';
        } else {
            echo '<p>No indicator data for the selected filters.</p>';
        }
        echo '</body></html>';
        
        if (function_exists('ob_end_flush')) {
            @ob_end_flush();
        }
        exit;
    }
    
    if ($exportType === 'pdf') {
        // Build printable HTML and convert it to a REAL PDF (server-side) if Dompdf is installed.
        // If Dompdf is missing, helpers.php will gracefully fall back to a print-friendly HTML download.

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
            @page { margin: 20mm; size: A4 landscape; }
            body { font-family: Arial, sans-serif; font-size: 9pt; }
            .header { background: #4361ee; color: white; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
            .header h1 { margin: 0 0 5px 0; font-size: 16pt; }
            .header h2 { margin: 0 0 10px 0; font-size: 12pt; }
            .header p { margin: 3px 0; font-size: 9pt; }
            table { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 8pt; }
            th, td { border: 1px solid #333; padding: 4px; text-align: left; }
            th { background: #4361ee; color: white; font-weight: bold; }
            tr:nth-child(even) { background-color: #f9fafb; }
            @media print {
                body { margin: 0; padding: 0; }
                .no-print, nav, header, .site-header, .app-header, .btn, button { display: none !important; }
            }
        </style></head><body>';

        $html .= '<div class="header">';
        $html .= '<h1>🚀 Health Reporting System</h1>';
        $html .= '<h2>Nexus Ethiopia Project Performance Report</h2>';
        $html .= '<p><strong>Project Title:</strong> ' . h($projectHeaderTitle) . '</p>';
        if ($projectCode !== '') $html .= '<p><strong>Project Code:</strong> ' . h($projectCode) . '</p>';
        if ($projectDonor !== '') $html .= '<p><strong>Donor:</strong> ' . h($projectDonor) . '</p>';
        $html .= '<p><strong>Region:</strong> ' . h($regionHeader) . ' | <strong>Zone:</strong> ' . h($zoneHeader) . ' | <strong>Woreda:</strong> ' . h($woredaHeader) . '</p>';
        $html .= '<p><strong>Export Date:</strong> ' . date('Y-m-d H:i:s') . '</p>';
        $html .= '</div>';
        
        if (!empty($flat)) {
            $headerCols = array_keys($flat[0]);
            $html .= '<table>';
            $html .= '<tr>';
            foreach ($headerCols as $hcol) {
                $html .= '<th>' . h($hcol) . '</th>';
            }
            $html .= '</tr>';
            foreach ($flat as $r) {
                $html .= '<tr>';
                foreach ($headerCols as $c) {
                    $html .= '<td>' . h((string)($r[$c] ?? '')) . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</table>';
        } else {
            $html .= '<p>No indicator data for the selected filters.</p>';
        }

        $html .= '</body></html>';

        $pdfName = 'nexus_custom_report_' . date('Y-m-d') . '.pdf';
        hrs_export_pdf_from_html($html, $pdfName, 'A4', 'landscape');
    }
}

/* ---------------------- AJAX handler for project geography and zones/woredas ---------------------- */

if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    // Handle zones request
    if ($action === 'get_zones' && isset($_GET['region_id'])) {
        header('Content-Type: application/json');
        $regionId = (int)$_GET['region_id'];
        $zones = get_zones_by_region($regionId);
        echo json_encode($zones);
        exit;
    }
    
    // Handle woredas request
    if ($action === 'get_woredas' && isset($_GET['zone_id'])) {
        header('Content-Type: application/json');
        $zoneId = (int)$_GET['zone_id'];
        $woredas = get_woredas_by_zone($zoneId);
        echo json_encode($woredas);
        exit;
    }
    
    // Handle project indicators request
    if ($action === 'get_project_indicators') {
        header('Content-Type: application/json');
        $pid = isset($_GET['project_id']) && $_GET['project_id'] !== '' && $_GET['project_id'] !== 'all' 
            ? (int)$_GET['project_id'] 
            : null;
        
        $result = ['success' => false, 'indicators' => []];
        
        try {
            // If pid is null or 0, get all indicators (for "All" projects)
            $indicators = get_indicators($pid);
            $result['indicators'] = $indicators;
            $result['success'] = true;
        } catch (Exception $e) {
            error_log("Error getting project indicators: " . $e->getMessage());
        }
        
        echo json_encode($result);
        exit;
    }
    
    // Handle project geography request
    if ($action === 'get_project_geography') {
        header('Content-Type: application/json');
        $pid = (int)($_GET['project_id'] ?? 0);
        
        $result = ['success' => false, 'region_id' => '', 'zone_id' => '', 'woreda_ids' => []];
        
        if ($pid > 0) {
            try {
                $stmt = $pdo->prepare("SELECT region_id, zone_id FROM projects WHERE id = ?");
                $stmt->execute([$pid]);
                $project = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($project) {
                    $result['region_id'] = $project['region_id'] ?? '';
                    $result['zone_id'] = $project['zone_id'] ?? '';
                    
                    // Get from project_locations if exists
                    try {
                        $stmtLoc = $pdo->prepare("SELECT woreda_id FROM project_locations WHERE project_id = ?");
                        $stmtLoc->execute([$pid]);
                        $locations = $stmtLoc->fetchAll(PDO::FETCH_COLUMN);
                        $result['woreda_ids'] = array_map('intval', $locations);
                    } catch (Exception $e) {
                        // Table may not exist
                    }
                    
                    $result['success'] = true;
                }
            } catch (Exception $e) {
                error_log("Error getting project geography: " . $e->getMessage());
            }
        }
        
        echo json_encode($result);
        exit;
    }
}

/* ---------------------- render page ---------------------- */

require_once __DIR__ . '/../header.php';

// share links
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$currentUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

$shareSubject = rawurlencode('Nexus Project Custom Report View');
$shareBody    = rawurlencode("Nexus report view\r\n" . $currentUrl);
$shareUrlEnc  = rawurlencode($currentUrl);
?>
<style>
.analytics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}
.kpi-card {
    background: white;
    padding: 15px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.08);
    text-align: center;
}
.kpi-value {
    font-size: 24px;
    font-weight: bold;
    color: #2c3e50;
}
.kpi-label {
    font-size: 14px;
    color: #7f8c8d;
    margin-top: 5px;
}
.chart-container {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.08);
    margin-bottom: 20px;
}
.progress-bar {
    height: 20px;
    background: #ecf0f1;
    border-radius: 10px;
    overflow: hidden;
    margin: 5px 0;
}
.progress-fill {
    height: 100%;
    background: #3498db;
    transition: width 0.3s ease;
}
.progress-danger { background: #e74c3c; }
.progress-warning { background: #f39c12; }
.progress-success { background: #27ae60; }
.summary-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 10px;
    margin: 15px 0;
}
.stat-item {
    text-align: center;
    padding: 10px;
    background: #f8f9fa;
    border-radius: 5px;
}
.alert {
    padding: 10px;
    margin: 10px 0;
    border-radius: 4px;
}
.alert-error {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}
.alert-info {
    background: #d1ecf1;
    color: #0c5460;
    border: 1px solid #bee5eb;
}
.form-row {
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    margin-bottom:8px;
}
.form-row label {
    font-size:0.85rem;
}
.form-row select {
    min-width:150px;
}
.table {
    width:100%;
    border-collapse:collapse;
    font-size:0.85rem;
}
.table th,.table td {
    border:1px solid #e5e5e5;
    padding:4px 6px;
}
.table th {
    background:#f1f3f5;
}
.btn-sm {
    display:inline-block;
    padding:4px 10px;
    font-size:0.8rem;
    border-radius:999px;
    border:1px solid #ccc;
    background:#f8f9fa;
    text-decoration:none;
    color:#333;
    cursor:pointer;
    margin-left:4px;
}
.btn-sm:hover {
    background:#e9ecef;
}
.print-header-vr {
    display:none;
    margin-bottom:10px;
    padding-bottom:10px;
    border-bottom:1px solid #000;
}
.print-header-vr h1 {
    margin:0 0 4px 0;
    font-size:1.2rem;
}
.print-header-vr p {
    margin:0;
    font-size:0.85rem;
}
@media print {
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
        display:none !important;
    }
    .print-header-vr {
        display:block;
    }
}
</style>

<div class="card">

    <!-- PRINT / EXPORT HEADER ONLY -->
    <div class="print-header-vr">
        <h1>Nexus Ethiopia Project Performance Report – custom view per project</h1>
        <p><strong>Project Title:</strong> <?php echo h($projectHeaderTitle); ?></p>
        <?php if ($projectCode !== ''): ?>
            <p><strong>Project Code:</strong> <?php echo h($projectCode); ?></p>
        <?php endif; ?>
        <?php if ($projectDonor !== ''): ?>
            <p><strong>Donor:</strong> <?php echo h($projectDonor); ?></p>
        <?php endif; ?>
        <?php if ($projectDescription !== ''): ?>
            <p><strong>Project Description:</strong> <?php echo h($projectDescription); ?></p>
        <?php endif; ?>
        <?php if ($projectMainSectors !== ''): ?>
            <p><strong>Main Programme Sectors:</strong> <?php echo h($projectMainSectors); ?></p>
        <?php endif; ?>
        <?php if ($projectSpecificAreas !== ''): ?>
            <p><strong>Project Specific Areas:</strong> <?php echo h($projectSpecificAreas); ?></p>
        <?php endif; ?>
        <?php if ($projectLocation !== ''): ?>
            <p><strong>Project Locations:</strong> <?php echo h($projectLocation); ?></p>
        <?php endif; ?>
        <p>
            <strong>Region:</strong> <?php echo h($regionHeader); ?>
            &nbsp;&nbsp; <strong>Zone:</strong> <?php echo h($zoneHeader); ?>
            &nbsp;&nbsp; <strong>Name of Woreda:</strong> <?php echo h($woredaHeader); ?>
        </p>
        <p>
            <strong>Report type:</strong> <?php echo h($reportTypeHeader); ?>
            &nbsp;&nbsp; <strong>Reporting Month:</strong> <?php echo h($reportingMonthHeader); ?>
        </p>
        <p><strong>Quick range:</strong> <?php echo h($quickRangeHeader); ?></p>
        <p><strong>Date:</strong> From <?php echo h($dateFromHeader); ?> to <?php echo h($dateToHeader); ?></p>
    </div>

    <h1>📊 Advanced Custom Reports Dashboard (Project-specific)</h1>
    <p>
        Use the filters below (project, locations, period, quick range, indicators, beneficiary types)
        and click <strong>Apply filters</strong> to generate your custom SADD report.<br>
        The full Nexus header + project details appear in <strong>Print / PDF</strong> and <strong>Downloads (CSV/Word)</strong>.
    </p>

    <?php if (empty($projects)): ?>
        <div class="alert alert-error">
            <strong>Warning:</strong> No projects found in the database. Please check your database setup.
        </div>
    <?php endif; ?>

    <?php if (empty($regions)): ?>
        <div class="alert alert-info">
            <strong>Info:</strong> No regions found. Geography filters will be disabled.
        </div>
    <?php endif; ?>

    <form method="get" style="margin-bottom:10px;">
        <div class="form-row">
            <label>Project
                <br>
                <select name="project_id" id="project_select" onchange="onProjectChange()">
                    <option value="">-- Select project --</option>
                    <option value="all" <?php echo ($filters['project_id'] === 'all' ? 'selected' : ''); ?>>All Projects</option>
                    <?php foreach ($projects as $p): ?>
                        <option value="<?php echo $p['id']; ?>" 
                                data-region-id="<?php echo h($p['region_id'] ?? ''); ?>"
                                data-zone-id="<?php echo h($p['zone_id'] ?? ''); ?>"
                                <?php echo ($filters['project_id'] == $p['id'] ? 'selected' : ''); ?>>
                            <?php echo h($p['title'] ?? ($p['name'] ?? 'Project ' . $p['id'])); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Region
                <br>
                <select name="region_id" id="filter_region_id" class="geo-region region-select" data-placeholder="All">
                    <option value="">All</option>
                    <?php foreach ($regions as $r): ?>
                        <option value="<?php echo $r['id']; ?>" <?php echo ($filters['region_id'] == $r['id'] ? 'selected' : ''); ?>>
                            <?php echo h($r['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Zone
                <br>
                <select name="zone_id" id="filter_zone_id" class="geo-zone zone-select" data-placeholder="All">
                    <option value="">All</option>
                    <?php foreach ($zones as $z): ?>
                        <option value="<?php echo $z['id']; ?>" data-region-id="<?php echo $z['region_id'] ?? ''; ?>" <?php echo ($filters['zone_id'] == $z['id'] ? 'selected' : ''); ?>>
                            <?php echo h($z['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Woreda
                <br>
                <select name="woreda_id[]" id="filter_woreda_id" class="geo-woreda woreda-select" multiple size="4" data-placeholder="All">
                    <option value="">All</option>
                    <?php foreach ($woredas as $w): ?>
                        <option value="<?php echo $w['id']; ?>" data-zone-id="<?php echo $w['zone_id'] ?? ''; ?>" <?php echo in_array($w['id'], $woredaIds, true) ? 'selected' : ''; ?>>
                            <?php echo h($w['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <div class="form-row">
            <label>Year from
                <br>
                <select name="year_from">
                    <option value="">Any</option>
                    <?php for ($y = 2020; $y <= 2075; $y++): ?>
                        <option value="<?php echo $y; ?>" <?php echo ($filters['year_from'] == $y ? 'selected' : ''); ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </label>
            <label>Year to
                <br>
                <select name="year_to">
                    <option value="">Any</option>
                    <?php for ($y = 2020; $y <= 2075; $y++): ?>
                        <option value="<?php echo $y; ?>" <?php echo ($filters['year_to'] == $y ? 'selected' : ''); ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </label>
            <label>Month from
                <br>
                <select name="month_from" id="month_from_select">
                    <option value="">Any</option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo ($filters['month_from'] == $m ? 'selected' : ''); ?>>
                            <?php echo date('F', mktime(0,0,0,$m,1)); ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </label>
            <label>Month to
                <br>
                <select name="month_to" id="month_to_select">
                    <option value="">Any</option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo ($filters['month_to'] == $m ? 'selected' : ''); ?>>
                            <?php echo date('F', mktime(0,0,0,$m,1)); ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </label>
            <label id="week_from_label" style="display: none;">Week from
                <br>
                <select name="week_from" id="week_from_select">
                    <option value="">Any</option>
                    <?php for ($w = 1; $w <= 52; $w++): ?>
                        <option value="<?php echo $w; ?>" <?php echo (isset($filters['week_from']) && $filters['week_from'] == $w ? 'selected' : ''); ?>>
                            Week <?php echo $w; ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </label>
            <label id="week_to_label" style="display: none;">Week to
                <br>
                <select name="week_to" id="week_to_select">
                    <option value="">Any</option>
                    <?php for ($w = 1; $w <= 52; $w++): ?>
                        <option value="<?php echo $w; ?>" <?php echo (isset($filters['week_to']) && $filters['week_to'] == $w ? 'selected' : ''); ?>>
                            Week <?php echo $w; ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </label>
        </div>

        <div class="form-row">
            <label>Period type
                <br>
                <select name="period_type" id="period_type_select" onchange="handlePeriodTypeChange()">
                    <option value="">All</option>
                    <option value="weekly"    <?php echo ($filters['period_type'] == 'weekly'    ? 'selected' : ''); ?>>Weekly</option>
                    <option value="monthly"   <?php echo ($filters['period_type'] == 'monthly'   ? 'selected' : ''); ?>>Monthly</option>
                    <option value="quarterly" <?php echo ($filters['period_type'] == 'quarterly' ? 'selected' : ''); ?>>Quarterly</option>
                    <option value="annual"    <?php echo ($filters['period_type'] == 'annual'    ? 'selected' : ''); ?>>Annual</option>
                </select>
            </label>
            <label>Quick range
                <br>
                <select name="quick_range" id="quick_range_select" onchange="handleQuickRangeChange()">
                    <option value="">None</option>
                    <option value="past_1w"  <?php echo ($filters['quick_range'] == 'past_1w'  ? 'selected' : ''); ?>>Past 1 week</option>
                    <option value="past_2w"  <?php echo ($filters['quick_range'] == 'past_2w'  ? 'selected' : ''); ?>>Past 2 weeks</option>
                    <option value="past_3w"  <?php echo ($filters['quick_range'] == 'past_3w'  ? 'selected' : ''); ?>>Past 3 weeks</option>
                    <option value="past_4w"  <?php echo ($filters['quick_range'] == 'past_4w'  ? 'selected' : ''); ?>>Past 4 weeks</option>
                    <option value="past_2m"  <?php echo ($filters['quick_range'] == 'past_2m'  ? 'selected' : ''); ?>>Past 2 months</option>
                    <option value="past_3m"  <?php echo ($filters['quick_range'] == 'past_3m'  ? 'selected' : ''); ?>>Past 3 months</option>
                    <option value="past_4m"  <?php echo ($filters['quick_range'] == 'past_4m'  ? 'selected' : ''); ?>>Past 4 months</option>
                    <option value="past_5m"  <?php echo ($filters['quick_range'] == 'past_5m'  ? 'selected' : ''); ?>>Past 5 months</option>
                    <option value="past_6m"  <?php echo ($filters['quick_range'] == 'past_6m'  ? 'selected' : ''); ?>>Past 6 months</option>
                    <option value="past_7m"  <?php echo ($filters['quick_range'] == 'past_7m'  ? 'selected' : ''); ?>>Past 7 months</option>
                    <option value="past_8m"  <?php echo ($filters['quick_range'] == 'past_8m'  ? 'selected' : ''); ?>>Past 8 months</option>
                    <option value="past_9m"  <?php echo ($filters['quick_range'] == 'past_9m'  ? 'selected' : ''); ?>>Past 9 months</option>
                    <option value="past_10m" <?php echo ($filters['quick_range'] == 'past_10m' ? 'selected' : ''); ?>>Past 10 months</option>
                    <option value="past_11m" <?php echo ($filters['quick_range'] == 'past_11m' ? 'selected' : ''); ?>>Past 11 months</option>
                    <option value="past_12m" <?php echo ($filters['quick_range'] == 'past_12m' ? 'selected' : ''); ?>>Past 12 months</option>
                    <option value="past_24m" <?php echo ($filters['quick_range'] == 'past_24m' ? 'selected' : ''); ?>>Past 24 months</option>
                    <option value="past_36m" <?php echo ($filters['quick_range'] == 'past_36m' ? 'selected' : ''); ?>>Past 36 months</option>
                    <option value="past_48m" <?php echo ($filters['quick_range'] == 'past_48m' ? 'selected' : ''); ?>>Past 48 months</option>
                    <option value="custom_weeks" <?php echo ($filters['quick_range'] == 'custom_weeks' ? 'selected' : ''); ?>>Other (weeks)</option>
                    <option value="custom_months" <?php echo ($filters['quick_range'] == 'custom_months' ? 'selected' : ''); ?>>Other (months)</option>
                </select>
                <div id="custom_period_input" style="display: <?php echo ($filters['quick_range'] === 'custom_weeks' || $filters['quick_range'] === 'custom_months') ? 'block' : 'none'; ?>; margin-top: 5px;">
                    <input type="number" name="custom_period_value" id="custom_period_value" placeholder="Enter number" min="1" value="<?php echo h($filters['custom_period_value']); ?>" style="width: 100px; padding: 4px;">
                    <span id="custom_period_label"><?php echo ($filters['quick_range'] === 'custom_weeks') ? 'weeks' : (($filters['quick_range'] === 'custom_months') ? 'months' : ''); ?></span>
                </div>
            </label>
            <label>Indicator (SADD view)<br><small>(Hold Ctrl / Cmd for multiple)</small>
                <br>
                <select name="indicator_id[]" id="indicator_select" multiple size="4">
                    <option value="">All</option>
                    <?php foreach ($indicatorsAll as $ind): ?>
                        <option value="<?php echo $ind['id']; ?>"
                            <?php echo in_array($ind['id'], $indicatorIds, true) ? 'selected' : ''; ?>>
                            <?php echo h($ind['code'] . ' – ' . $ind['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Beneficiary type<br><small>(Hold Ctrl / Cmd for multiple)</small>
                <br>
                <select name="benef_type[]" multiple size="4">
                    <option value="">All</option>
                    <option value="host"       <?php echo in_array('host', $benefTypes, true)       ? 'selected' : ''; ?>>Host community</option>
                    <option value="idp"        <?php echo in_array('idp', $benefTypes, true)        ? 'selected' : ''; ?>>IDP</option>
                    <option value="returnee"   <?php echo in_array('returnee', $benefTypes, true)   ? 'selected' : ''; ?>>Returnee</option>
                    <option value="refugee"    <?php echo in_array('refugee', $benefTypes, true)    ? 'selected' : ''; ?>>Refugee</option>
                    <option value="pwd"        <?php echo in_array('pwd', $benefTypes, true)        ? 'selected' : ''; ?>>People with disability</option>
                    <option value="total"      <?php echo in_array('total', $benefTypes, true)      ? 'selected' : ''; ?>>Total</option>
                    <option value="non_person" <?php echo in_array('non_person', $benefTypes, true) ? 'selected' : ''; ?>>Non person</option>
                </select>
            </label>
            <label>View as
                <br>
                <select name="display_mode">
                    <option value="table"     <?php echo ($filters['display_mode'] == 'table'     ? 'selected' : ''); ?>>Table</option>
                    <option value="chart"     <?php echo ($filters['display_mode'] == 'chart'     ? 'selected' : ''); ?>>Chart</option>
                    <option value="analytics" <?php echo ($filters['display_mode'] == 'analytics' ? 'selected' : ''); ?>>Analytics Dashboard</option>
                </select>
            </label>
            <label>&nbsp;<br>
                <button type="submit" class="btn-sm">Apply filters</button>
                <button type="button" class="btn-sm" onclick="printReport()">🖨️ Print</button>
                <button type="button" class="btn-sm" onclick="exportReport('pdf')">📄 PDF</button>
                <button type="button" class="btn-sm" onclick="exportReport('excel')">📊 Excel</button>
                <button type="button" class="btn-sm" onclick="exportReport('word')">📝 Word</button>
                <button type="button" class="btn-sm" onclick="exportReport('csv')">📋 CSV</button>
                <button type="button" class="btn-sm" onclick="shareReport('email')">📧 Email</button>
                <button type="button" class="btn-sm" onclick="shareReport('whatsapp')">💬 WhatsApp</button>
                <button type="button" class="btn-sm" onclick="shareReport('telegram')">✈️ Telegram</button>
            </label>
        </div>
    </form>
</div>

<!-- ================== ANALYTICS DASHBOARD ================== -->
<?php if ($projectSelected && $filters['display_mode'] === 'analytics'): ?>
<div class="card">
    <h2>📈 Analytics Dashboard</h2>
    
    <!-- KPI Cards -->
    <div class="analytics-grid">
        <div class="kpi-card">
            <div class="kpi-value"><?php echo format_number($analyticsData['total_reports']); ?></div>
            <div class="kpi-label">Total Reports (enter_data)</div>
        </div>
        
        <div class="kpi-card">
            <div class="kpi-value"><?php echo format_number(count($indicatorAgg)); ?></div>
            <div class="kpi-label">Indicator SADD Records</div>
        </div>
        
        <div class="kpi-card">
            <div class="kpi-value">
                <?php
                $totalBeneficiaries = 0;
                foreach ($indicatorAgg as $row) {
                    $boys  = (int)$row['boys_u5'] + (int)$row['boys_5_17'];
                    $girls = (int)$row['girls_u5'] + (int)$row['girls_5_17'];
                    $men   = (int)$row['men_18_59'] + (int)$row['men_60p'];
                    $women = (int)$row['women_18_59'] + (int)$row['women_60p'];
                    $totalBeneficiaries += $boys + $girls + $men + $women;
                }
                echo format_number($totalBeneficiaries);
                ?>
            </div>
            <div class="kpi-label">Total Beneficiaries</div>
        </div>
        
        <div class="kpi-card">
            <div class="kpi-value">
                <?php
                $uniqueIndicators = [];
                foreach ($indicatorAgg as $row) {
                    $uniqueIndicators[$row['indicator_id']] = true;
                }
                echo count($uniqueIndicators);
                ?>
            </div>
            <div class="kpi-label">Unique Indicators</div>
        </div>
    </div>

    <!-- Charts Section -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
        <!-- Reports by Period Type -->
        <div class="chart-container">
            <h3>Reports by Period Type</h3>
            <canvas id="periodTypeChart" style="height: 300px;"></canvas>
        </div>
        
        <!-- Reports by Region -->
        <div class="chart-container">
            <h3>Reports by Region</h3>
            <canvas id="regionChart" style="height: 300px;"></canvas>
        </div>
    </div>

    <!-- Auto Calculations Summary -->
    <div class="chart-container">
        <h3>📊 Performance Summary (Planning + SADD)</h3>
        <div class="summary-stats">
            <?php
            $indicatorsWithTargets = 0;
            $indicatorsOnTrack = 0;
            $totalProgress = 0;
            
            foreach ($indicatorAgg as $row) {
                $boys  = (int)$row['boys_u5'] + (int)$row['boys_5_17'];
                $girls = (int)$row['girls_u5'] + (int)$row['girls_5_17'];
                $men   = (int)$row['men_18_59'] + (int)$row['men_60p'];
                $women = (int)$row['women_18_59'] + (int)$row['women_60p'];
                $achieved = $boys + $girls + $men + $women;

                $indId = (int)$row['indicator_id'];
                $target = (float)$row['target_value'] ?: 0;

                // prefer planMap if available
                if (isset($planMap[$indId]) && $planMap[$indId]['plan_period'] > 0) {
                    $target = $planMap[$indId]['plan_period'];
                }
                
                if ($target > 0) {
                    $indicatorsWithTargets++;
                    $progress = ($achieved / $target) * 100;
                    $totalProgress += $progress;
                    if ($progress >= 80) $indicatorsOnTrack++;
                }
            }
            
            $avgProgress = $indicatorsWithTargets > 0 ? $totalProgress / $indicatorsWithTargets : 0;
            ?>
            
            <div class="stat-item">
                <strong><?php echo $indicatorsWithTargets; ?></strong>
                <div>Indicators with Targets/Plans</div>
            </div>
            <div class="stat-item">
                <strong><?php echo $indicatorsOnTrack; ?></strong>
                <div>On Track (≥80%)</div>
            </div>
            <div class="stat-item">
                <strong><?php echo $indicatorsWithTargets > 0 ? safe_percentage($indicatorsOnTrack, $indicatorsWithTargets) : '0'; ?>%</strong>
                <div>Success Rate</div>
            </div>
            <div class="stat-item">
                <strong><?php echo number_format($avgProgress, 1); ?>%</strong>
                <div>Average Progress</div>
            </div>
        </div>
        
        <!-- Progress Bars for Key Indicators -->
        <h4>Top Indicators Progress</h4>
        <?php
        $indicatorProgress = [];
        foreach ($indicatorAgg as $row) {
            $boys  = (int)$row['boys_u5'] + (int)$row['boys_5_17'];
            $girls = (int)$row['girls_u5'] + (int)$row['girls_5_17'];
            $men   = (int)$row['men_18_59'] + (int)$row['men_60p'];
            $women = (int)$row['women_18_59'] + (int)$row['women_60p'];
            $achieved = $boys + $girls + $men + $women;

            $indId = (int)$row['indicator_id'];
            $target = (float)$row['target_value'] ?: 0;
            if (isset($planMap[$indId]) && $planMap[$indId]['plan_period'] > 0) {
                $target = $planMap[$indId]['plan_period'];
            }
            
            if ($target > 0 && $achieved > 0) {
                $progress = ($achieved / $target) * 100;
                $indicatorProgress[] = [
                    'name' => $row['indicator_code'] . ' - ' . $row['indicator_name'],
                    'progress' => $progress,
                    'achieved' => $achieved,
                    'target' => $target
                ];
            }
        }
        
        usort($indicatorProgress, function($a, $b) {
            return $b['progress'] <=> $a['progress'];
        });
        $topIndicators = array_slice($indicatorProgress, 0, 5);
        
        if (!empty($topIndicators)):
            foreach ($topIndicators as $ind): 
                $progressClass = $ind['progress'] >= 100 ? 'progress-success' : 
                                ($ind['progress'] >= 80 ? 'progress-warning' : 'progress-danger');
        ?>
            <div style="margin: 10px 0;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                    <span><?php echo h($ind['name']); ?></span>
                    <span><?php echo number_format($ind['progress'], 1); ?>% 
                          (<?php echo format_number($ind['achieved']); ?>/<?php echo format_number($ind['target']); ?>)
                    </span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill <?php echo $progressClass; ?>" 
                         style="width: <?php echo min($ind['progress'], 100); ?>%"></div>
                </div>
            </div>
        <?php 
            endforeach;
        else:
        ?>
            <p>No indicator data with valid plan/target available for progress tracking.</p>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Analytics Charts
document.addEventListener('DOMContentLoaded', function() {
    // Period Type Chart
    const periodCtx = document.getElementById('periodTypeChart');
    if (periodCtx) {
        new Chart(periodCtx.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_keys($analyticsData['reports_by_period_type'])); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_values($analyticsData['reports_by_period_type'])); ?>
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    }

    // Region Chart
    const regionCtx = document.getElementById('regionChart');
    if (regionCtx) {
        new Chart(regionCtx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_keys($analyticsData['reports_by_region'])); ?>,
                datasets: [{
                    label: 'Reports',
                    data: <?php echo json_encode(array_values($analyticsData['reports_by_region'])); ?>
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });
    }
});
</script>
<?php endif; ?>

<!-- ================== BASIC REPORT LIST ================== -->
<div class="card">
    <h2>1. Report list (metadata – linked to enter_data.php)</h2>
    <?php if (!$projectSelected): ?>
        <p><em>Please select a project above and apply filters to view submitted reports.</em></p>
    <?php endif; ?>
    <table class="table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Project</th>
                <th>Region</th>
                <th>Zone</th>
                <th>Woreda</th>
                <th>Period type</th>
                <th>Year</th>
                <th>Month</th>
                <th>Week</th>
                <th>Start date</th>
                <th>End date</th>
                <th>Status</th>
                <th>Created at</th>
                <th>Edit</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($projectSelected): ?>
            <?php if (!empty($reportRows)): ?>
                <?php foreach ($reportRows as $r): ?>
                    <tr>
                        <td><?php echo (int)$r['id']; ?></td>
                        <td><?php echo h($projectHeaderTitle); ?></td>
                        <td><?php echo $hasRegionCol ? h($r['region_name'] ?? '') : ''; ?></td>
                        <td><?php echo $hasZoneCol   ? h($r['zone_name']   ?? '') : ''; ?></td>
                        <td><?php echo $hasWoredaCol ? h($r['woreda_name'] ?? '') : ''; ?></td>
                        <td><?php echo h($r['period_type'] ?? ''); ?></td>
                        <td><?php echo h($r['year'] ?? ''); ?></td>
                        <td><?php echo h($r['month'] ?? ''); ?></td>
                        <td><?php echo h($r['week'] ?? ''); ?></td>
                        <td><?php echo h($r['start_date'] ?? ''); ?></td>
                        <td><?php echo h($r['end_date'] ?? ''); ?></td>
                        <td><span class="badge badge-success"><?php echo h($r['status'] ?? ''); ?></span></td>
                        <td><?php echo h($r['created_at'] ?? ''); ?></td>
                        <td>
                            <a class="btn-sm" href="enter_data.php?edit_id=<?php echo (int)$r['id']; ?>&project_id=<?php echo (int)$r['project_id']; ?>">
                                ✏️ Edit
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="14">No reports have been submitted yet for this project and filters.</td></tr>
            <?php endif; ?>
        <?php else: ?>
            <tr><td colspan="14"><em>No data – project not selected.</em></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- ================== INDICATOR PERFORMANCE (TWO + SADD TABLES) ================== -->

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;">
        <h2>2. Indicator performance (SADD view, Planning + Budget)</h2>
        <div>
            <?php
                $queryBase = $_GET;
                $queryBase['export'] = 'csv';
            ?>
            <button type="button" class="btn-sm" onclick="exportReport('csv')">📋 CSV</button>
            <button type="button" class="btn-sm" onclick="exportReport('excel')">📊 Excel</button>
            <button type="button" class="btn-sm" onclick="exportReport('word')">📝 Word</button>
            <button type="button" class="btn-sm" onclick="exportReport('pdf')">📄 PDF</button>
            <button type="button" class="btn-sm" onclick="printReport()">🖨️ Print</button>
        </div>
    </div>
    <p>
        Aggregated <strong>indicator SADD data</strong> for the selected project and filters,
        joined with <strong>planning.php</strong> (indicator plans) and <strong>budget.php</strong> (indicator budgets)
        when those tables are available. PWD are <strong>not</strong> included in SADD totals.
    </p>

    <?php if ($filters['display_mode'] === 'table'): ?>

        <?php if ($projectSelected && !empty($indicatorAgg)): ?>
            <?php
            $rowsForDisplay = [];
            $sn = 1;
            foreach ($indicatorAgg as $row) {
                $boys_u5     = (int)$row['boys_u5'];
                $girls_u5    = (int)$row['girls_u5'];
                $boys_5_17   = (int)$row['boys_5_17'];
                $girls_5_17  = (int)$row['girls_5_17'];
                $men_18_59   = (int)$row['men_18_59'];
                $women_18_59 = (int)$row['women_18_59'];
                $men_60p     = (int)$row['men_60p'];
                $women_60p   = (int)$row['women_60p'];
                $pwd_count   = (int)$row['pwd_count'];
                $non_ben     = (int)$row['non_beneficiary'];

                $boysTotal   = $boys_u5 + $boys_5_17;
                $girlsTotal  = $girls_u5 + $girls_5_17;
                $menTotal    = $men_18_59 + $men_60p;
                $womenTotal  = $women_18_59 + $women_60p;
                $totalPerson = $boysTotal + $girlsTotal + $menTotal + $womenTotal;

                // plan & target
                $indId         = (int)$row['indicator_id'];
                $targetValue   = (float)$row['target_value'] ?: null;
                $planPeriod    = $targetValue;
                $cumPlan       = $targetValue;

                if (isset($planMap[$indId])) {
                    $planPeriod = $planMap[$indId]['plan_period'];
                    $cumPlan    = $planMap[$indId]['plan_cumulative'];
                }

                $achievPeriod      = $totalPerson;
                $progressPeriodPct = ($planPeriod && $planPeriod > 0) ? safe_percentage($achievPeriod, $planPeriod) : null;
                $cumAchiev         = $achievPeriod;
                $cumProgPct        = $progressPeriodPct;

                // very simple timeliness/completeness placeholders
                $timelinessPct   = 95;
                $completenessPct = 98;

                // budget from budget.php table
                $budgetPlanned = null;
                $budgetUsed    = null;
                $budgetUtilPct = null;
                if (isset($budgetMap[$indId])) {
                    $budgetPlanned = $budgetMap[$indId]['planned'];
                    $budgetUsed    = $budgetMap[$indId]['used'];
                    if ($budgetPlanned > 0 && $budgetUsed !== null) {
                        $budgetUtilPct = safe_percentage($budgetUsed, $budgetPlanned);
                    }
                }

                // Smart feedback logic
                if ($achievPeriod === 0) {
                    $feedback = '🔴 No progress reported – follow up with activity owner.';
                } else {
                    $p = $progressPeriodPct !== null ? (float)$progressPeriodPct : 0;
                    $c = $cumProgPct       !== null ? (float)$cumProgPct       : 0;
                    $b = $budgetUtilPct    !== null ? (float)$budgetUtilPct    : 0;
                    $t = (float)$timelinessPct;

                    if ($p < 70 || $c < 70 || ($b !== 0 && $b < 60) || ($t !== 0 && $t < 70)) {
                        $feedback = '🔴 Critical – immediate management action required. Review implementation, targeting and data quality.';
                    } elseif ($p < 80 || $c < 80 || ($b !== 0 && $b < 70) || ($t !== 0 && $t < 80)) {
                        $feedback = '🟡 Under-achieved – adjust implementation pace, address delays and check budget absorption.';
                    } elseif (
                        ($p >= 80 && $p <= 110) &&
                        ($c >= 80 && $c <= 110) &&
                        (($b === 0) || ($b >= 70 && $b <= 120)) &&
                        ($t >= 80)
                    ) {
                        $feedback = '🟢 On track – progress, timeliness and budget use are within acceptable range.';
                    } else {
                        $feedback = '🔵 Over-achieved – verify data, targets and budget coding; document learning/good practice.';
                    }
                }

                $benefKey   = $row['beneficiary_type'];
                $benefLabel = $benefLabels[$benefKey] ?? ucfirst($benefKey);

                $rowsForDisplay[] = [
                    'sn'               => $sn++,
                    'indicator_code'   => $row['indicator_code'],
                    'indicator_name'   => $row['indicator_name'],
                    'benef_label'      => $benefLabel,
                    'unit'             => $row['unit_type'],
                    'target_value'     => $targetValue,
                    'plan_period'      => $planPeriod,
                    'achiev_period'    => $achievPeriod,
                    'progress_pct'     => $progressPeriodPct,
                    'cum_plan'         => $cumPlan,
                    'cum_achiev'       => $cumAchiev,
                    'cum_progress_pct' => $cumProgPct,
                    'timeliness_pct'   => $timelinessPct,
                    'completeness_pct' => $completenessPct,
                    'budget_planned'   => $budgetPlanned,
                    'budget_used'      => $budgetUsed,
                    'budget_util_pct'  => $budgetUtilPct,
                    'boys_u5'          => $boys_u5,
                    'girls_u5'         => $girls_u5,
                    'boys_5_17'        => $boys_5_17,
                    'girls_5_17'       => $girls_5_17,
                    'men_18_59'        => $men_18_59,
                    'women_18_59'      => $women_18_59,
                    'men_60p'          => $men_60p,
                    'women_60p'        => $women_60p,
                    'pwd_count'        => $pwd_count,
                    'non_beneficiary'  => $non_ben,
                    'boys_total'       => $boysTotal,
                    'girls_total'      => $girlsTotal,
                    'men_total'        => $menTotal,
                    'women_total'      => $womenTotal,
                    'total_persons'    => $totalPerson,
                    'feedback'         => $feedback
                ];
            }
            ?>

            <!-- Table 2.1: Results & Progress -->
            <h3>2.1 Results & Progress (Targets / Plans / Achievements)</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th>SN</th>
                        <th>Indicator</th>
                        <th>Beneficiary description<br>(Non person, Host, IDP, Returnee, Refugee, PWD, Total)</th>
                        <th>Unit of measurement</th>
                        <th>Target (indicator)</th>
                        <th>Plan – reporting period<br>(planning.php)</th>
                        <th>Achievement – reporting period</th>
                        <th>Progress % – reporting period</th>
                        <th>Cumulative plan</th>
                        <th>Cumulative achievement</th>
                        <th>% Cumulative progress vs plan</th>
                        <th>% Timeliness</th>
                        <th>Completeness %</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rowsForDisplay as $r): ?>
                        <tr>
                            <td><?php echo $r['sn']; ?></td>
                            <td>
                                <strong><?php echo h($r['indicator_code']); ?></strong><br>
                                <?php echo h($r['indicator_name']); ?>
                            </td>
                            <td><?php echo h($r['benef_label']); ?></td>
                            <td><?php echo h($r['unit']); ?></td>
                            <td><?php echo $r['target_value'] === null ? 'N/A' : format_number($r['target_value']); ?></td>
                            <td><?php echo $r['plan_period'] === null ? 'N/A' : format_number($r['plan_period']); ?></td>
                            <td><strong><?php echo format_number($r['achiev_period']); ?></strong></td>
                            <td>
                                <?php if ($r['progress_pct'] !== null): ?>
                                    <div class="progress-bar" style="height: 15px; margin: 2px 0;">
                                        <?php
                                            $pp = (float)$r['progress_pct'];
                                            $pClass = $pp >= 100 ? 'progress-success' :
                                                      ($pp >= 80 ? 'progress-warning' : 'progress-danger');
                                        ?>
                                        <div class="progress-fill <?php echo $pClass; ?>" 
                                             style="width: <?php echo min($pp, 100); ?>%"></div>
                                    </div>
                                    <?php echo h($r['progress_pct'].'%'); ?>
                                <?php else: ?>
                                    N/A
                                <?php endif; ?>
                            </td>
                            <td><?php echo $r['cum_plan'] === null ? 'N/A' : format_number($r['cum_plan']); ?></td>
                            <td><strong><?php echo format_number($r['cum_achiev']); ?></strong></td>
                            <td><?php echo $r['cum_progress_pct'] === null ? 'N/A' : h($r['cum_progress_pct'].'%'); ?></td>
                            <td><?php echo h($r['timeliness_pct'].'%'); ?></td>
                            <td><?php echo h($r['completeness_pct'].'%'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Table 2.2: Budget & SADD totals -->
            <h3 style="margin-top:20px;">2.2 Budget & SADD totals (Auto totals, PWD excluded)</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th rowspan="2">SN</th>
                        <th rowspan="2">Indicator</th>
                        <th rowspan="2">Beneficiary description<br>(Non person, Host, IDP, Returnee, Refugee, PWD, Total)</th>
                        <th rowspan="2">Unit of measurement</th>
                        <th rowspan="2">Budget planned – reporting period<br>(budget.php)</th>
                        <th rowspan="2">Budget utilized – reporting period</th>
                        <th rowspan="2">% Budget utilization – reporting period</th>
                        <th colspan="5">Auto totals (SADD, PWD excluded)</th>
                        <th rowspan="2">Feedback (auto)</th>
                    </tr>
                    <tr>
                        <th>Boys total</th>
                        <th>Girls total</th>
                        <th>Men total</th>
                        <th>Women total</th>
                        <th>Total persons</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rowsForDisplay as $r): ?>
                        <tr>
                            <td><?php echo $r['sn']; ?></td>
                            <td>
                                <strong><?php echo h($r['indicator_code']); ?></strong><br>
                                <?php echo h($r['indicator_name']); ?>
                            </td>
                            <td><?php echo h($r['benef_label']); ?></td>
                            <td><?php echo h($r['unit']); ?></td>
                            <td><?php echo $r['budget_planned'] === null ? 'N/A' : '$' . format_number($r['budget_planned']); ?></td>
                            <td><?php echo $r['budget_used'] === null ? 'N/A' : '$' . format_number($r['budget_used']); ?></td>
                            <td><?php echo $r['budget_util_pct'] === null ? 'N/A' : h($r['budget_util_pct'].'%'); ?></td>
                            <td><?php echo format_number($r['boys_total']); ?></td>
                            <td><?php echo format_number($r['girls_total']); ?></td>
                            <td><?php echo format_number($r['men_total']); ?></td>
                            <td><?php echo format_number($r['women_total']); ?></td>
                            <td><strong><?php echo format_number($r['total_persons']); ?></strong></td>
                            <td style="max-width: 220px; font-size: 12px;"><?php echo h($r['feedback']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Table 2.3: Detailed SADD breakdown -->
            <h3 style="margin-top:20px;">2.3 Detailed SADD breakdown (age / sex / disability)</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th>SN</th>
                        <th>Indicator</th>
                        <th>Beneficiary description<br>(Non person, Host, IDP, Returnee, Refugee, PWD, Total)</th>
                        <th>Unit of measurement</th>
                        <th>Boys U5</th>
                        <th>Girls U5</th>
                        <th>Boys 5–17</th>
                        <th>Girls 5–17</th>
                        <th>Men 18–59</th>
                        <th>Women 18–59</th>
                        <th>Men 60+</th>
                        <th>Women 60+</th>
                        <th>PWD (all)</th>
                        <th>Non person</th>
                        <th>Boys total</th>
                        <th>Girls total</th>
                        <th>Men total</th>
                        <th>Women total</th>
                        <th>Total persons (SADD, excl. PWD)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rowsForDisplay as $r): ?>
                        <tr>
                            <td><?php echo $r['sn']; ?></td>
                            <td>
                                <strong><?php echo h($r['indicator_code']); ?></strong><br>
                                <?php echo h($r['indicator_name']); ?>
                            </td>
                            <td><?php echo h($r['benef_label']); ?></td>
                            <td><?php echo h($r['unit']); ?></td>
                            <td><?php echo format_number($r['boys_u5']); ?></td>
                            <td><?php echo format_number($r['girls_u5']); ?></td>
                            <td><?php echo format_number($r['boys_5_17']); ?></td>
                            <td><?php echo format_number($r['girls_5_17']); ?></td>
                            <td><?php echo format_number($r['men_18_59']); ?></td>
                            <td><?php echo format_number($r['women_18_59']); ?></td>
                            <td><?php echo format_number($r['men_60p']); ?></td>
                            <td><?php echo format_number($r['women_60p']); ?></td>
                            <td><?php echo format_number($r['pwd_count']); ?></td>
                            <td><?php echo format_number($r['non_beneficiary']); ?></td>
                            <td><strong><?php echo format_number($r['boys_total']); ?></strong></td>
                            <td><strong><?php echo format_number($r['girls_total']); ?></strong></td>
                            <td><strong><?php echo format_number($r['men_total']); ?></strong></td>
                            <td><strong><?php echo format_number($r['women_total']); ?></strong></td>
                            <td><strong><?php echo format_number($r['total_persons']); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php else: ?>
            <?php if (!$projectSelected): ?>
                <p><em>Please select a project and apply filters to view indicator performance.</em></p>
            <?php elseif ($projectSelected && empty($reportRows)): ?>
                <p>No reports have been submitted yet for this project and filters.</p>
            <?php else: ?>
                <p>No indicator data found for this project and filters.</p>
            <?php endif; ?>
        <?php endif; ?>

    <?php elseif ($filters['display_mode'] === 'chart'): ?>
        <?php if (!$projectSelected): ?>
            <p><em>Please select a project to enable chart view.</em></p>
        <?php else: ?>
            <p><strong>Chart view:</strong> total persons (all beneficiary types) per indicator.</p>
            <canvas id="indicatorChart" style="max-width:100%;height:380px;"></canvas>
        <?php endif; ?>
    <?php endif; ?>

    <div style="margin-top:10px; padding: 15px; background: #f8f9fa; border-radius: 5px;">
        <strong>📤 Share & Export Options:</strong>
        <div style="margin-top: 10px; display: flex; gap: 10px; flex-wrap: wrap;">
            <button type="button" class="btn-sm" onclick="printReport()">🖨️ Print</button>
            <button type="button" class="btn-sm" onclick="exportReport('pdf')">📄 PDF</button>
            <button type="button" class="btn-sm" onclick="exportReport('excel')">📊 Excel</button>
            <button type="button" class="btn-sm" onclick="exportReport('word')">📝 Word</button>
            <button type="button" class="btn-sm" onclick="exportReport('csv')">📋 CSV</button>
            <button type="button" class="btn-sm" onclick="shareReport('email')">📧 Email</button>
            <button type="button" class="btn-sm" onclick="shareReport('whatsapp')">💬 WhatsApp</button>
            <button type="button" class="btn-sm" onclick="shareReport('telegram')">✈️ Telegram</button>
        </div>
    </div>
    
    <!-- AI Guidance Panel -->
    <div id="aiGuidancePanel" style="margin-top: 20px; padding: 15px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 8px; display: none;">
        <h3 style="margin-top: 0; display: flex; align-items: center; gap: 10px;">
            <span>🤖</span> AI-Powered Real-Time Guidance
        </h3>
        <div id="aiGuidanceContent" style="margin-top: 10px;">
            <p>Select a project to get AI-powered insights and recommendations.</p>
        </div>
    </div>
</div>

<?php if ($filters['display_mode'] === 'chart' && $projectSelected): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function () {
    var ctx = document.getElementById('indicatorChart');
    if (!ctx) return;
    var labels = <?php echo json_encode(array_keys($chartAgg)); ?>;
    var values = <?php echo json_encode(array_values($chartAgg)); ?>;

    if (!labels.length) {
        ctx.insertAdjacentHTML('beforebegin', '<p>No data available for chart.</p>');
        return;
    }

    new Chart(ctx.getContext('2d'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Total persons (all beneficiary types)',
                data: values
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: true },
                title: {
                    display: true,
                    text: 'Indicator performance – total persons'
                }
            },
            scales: {
                x: { 
                    ticks: { 
                        autoSkip: false, 
                        maxRotation: 60, 
                        minRotation: 30,
                        font: { size: 10 }
                    } 
                },
                y: { 
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return value.toLocaleString();
                        }
                    }
                }
            }
        }
    });
})();
</script>
<?php endif; ?>

<script>
// ==================== GEOGRAPHY CASCADING DROPDOWNS ====================
// Ensure geography dropdowns work properly with auto-population

// Initialize geography on page load
document.addEventListener('DOMContentLoaded', function() {
    // Wait for header.php to load initGeographyDropdowns
    setTimeout(function() {
        if (typeof initGeographyDropdowns === 'function') {
            initGeographyDropdowns();
            console.log('Geography dropdowns initialized');
        } else {
            console.warn('initGeographyDropdowns not found, initializing manually...');
            initGeographyManually();
        }
    }, 500);
    
    // Auto-generate insights if project is selected
    const projectId = document.getElementById('project_select')?.value;
    if (projectId) {
        setTimeout(generateAIInsights, 1500);
    }
    
    // Initialize period type handler
    handlePeriodTypeChange();
    
    // Initialize quick range handler
    handleQuickRangeChange();
    
    // Monitor period type changes
    const periodTypeSelect = document.getElementById('period_type_select');
    if (periodTypeSelect) {
        periodTypeSelect.addEventListener('change', handlePeriodTypeChange);
    }
    
    // Monitor month selections to disable quick range
    const monthFrom = document.querySelector('select[name="month_from"]');
    const monthTo = document.querySelector('select[name="month_to"]');
    if (monthFrom && monthTo) {
        monthFrom.addEventListener('change', handleQuickRangeChange);
        monthTo.addEventListener('change', handleQuickRangeChange);
    }
});

// Manual geography initialization if header.php function not available
function initGeographyManually() {
    const regionSelect = document.getElementById('filter_region_id');
    const zoneSelect = document.getElementById('filter_zone_id');
    const woredaSelect = document.getElementById('filter_woreda_id');
    
    if (!regionSelect || !zoneSelect || !woredaSelect) return;
    
    // Region change handler
    regionSelect.addEventListener('change', async function() {
        const regionId = this.value;
        
        // Clear zones and woredas
        zoneSelect.innerHTML = '<option value="">All</option>';
        woredaSelect.innerHTML = '<option value="">All</option>';
        
        if (!regionId) return;
        
        // Load zones for selected region
        try {
            const baseUrl = window.APP_BASE_URL || '/health_reporting_system';
            const response = await fetch(baseUrl + '/api/geography.php?action=get_zones&region_id=' + encodeURIComponent(regionId));
            if (response.ok) {
                const zones = await response.json();
                zones.forEach(zone => {
                    const option = document.createElement('option');
                    option.value = zone.id;
                    option.textContent = zone.name;
                    zoneSelect.appendChild(option);
                });
            } else {
                // Fallback: try direct database query via PHP endpoint
                const fallbackResponse = await fetch('?action=get_zones&region_id=' + encodeURIComponent(regionId));
                if (fallbackResponse.ok) {
                    const zones = await fallbackResponse.json();
                    if (Array.isArray(zones)) {
                        zones.forEach(zone => {
                            const option = document.createElement('option');
                            option.value = zone.id;
                            option.textContent = zone.name;
                            zoneSelect.appendChild(option);
                        });
                    }
                }
            }
        } catch (error) {
            console.error('Error loading zones:', error);
        }
    });
    
    // Zone change handler
    zoneSelect.addEventListener('change', async function() {
        const zoneId = this.value;
        
        // Clear woredas
        woredaSelect.innerHTML = '<option value="">All</option>';
        
        if (!zoneId) return;
        
        // Load woredas for selected zone
        try {
            const baseUrl = window.APP_BASE_URL || '/health_reporting_system';
            const response = await fetch(baseUrl + '/api/geography.php?action=get_woredas&zone_id=' + encodeURIComponent(zoneId));
            if (response.ok) {
                const woredas = await response.json();
                woredas.forEach(woreda => {
                    const option = document.createElement('option');
                    option.value = woreda.id;
                    option.textContent = woreda.name;
                    woredaSelect.appendChild(option);
                });
            } else {
                // Fallback: try direct database query via PHP endpoint
                const fallbackResponse = await fetch('?action=get_woredas&zone_id=' + encodeURIComponent(zoneId));
                if (fallbackResponse.ok) {
                    const woredas = await fallbackResponse.json();
                    if (Array.isArray(woredas)) {
                        woredas.forEach(woreda => {
                            const option = document.createElement('option');
                            option.value = woreda.id;
                            option.textContent = woreda.name;
                            woredaSelect.appendChild(option);
                        });
                    }
                }
            }
        } catch (error) {
            console.error('Error loading woredas:', error);
        }
    });
    
    // Trigger change if region/zone already selected
    if (regionSelect.value) {
        regionSelect.dispatchEvent(new Event('change'));
    }
    if (zoneSelect.value) {
        setTimeout(() => zoneSelect.dispatchEvent(new Event('change')), 300);
    }
}

// ==================== AUTO-DISPLAY ON PROJECT SELECTION ====================
function onProjectChange() {
    const projectSelect = document.getElementById('project_select');
    const projectId = projectSelect.value;
    
    if (projectId) {
        // Show loading indicator
        showAIGuidance('🔄 Loading project data, auto-populating geography and indicators...', 'info');
        
        // If "all" is selected, load all indicators
        if (projectId === 'all') {
            loadProjectIndicators('all');
            // Don't auto-populate geography for "all"
            setTimeout(() => {
                const form = projectSelect.closest('form');
                if (form) {
                    form.submit();
                }
            }, 500);
        } else {
            // Load project geography and indicators via AJAX
            loadProjectGeography(projectId);
            loadProjectIndicators(projectId);
            
            // Auto-submit form after geography is loaded
            setTimeout(() => {
                const form = projectSelect.closest('form');
                if (form) {
                    form.submit();
                }
            }, 800);
        }
    } else {
        hideAIGuidance();
        // Clear indicators
        const indicatorSelect = document.getElementById('indicator_select');
        if (indicatorSelect) {
            indicatorSelect.innerHTML = '<option value="">All</option>';
        }
    }
}

// Load project indicators and auto-select them
function loadProjectIndicators(projectId) {
    const url = projectId === 'all' 
        ? '?action=get_project_indicators&project_id=all'
        : '?action=get_project_indicators&project_id=' + projectId;
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            const indicatorSelect = document.getElementById('indicator_select');
            if (indicatorSelect) {
                // Clear existing options
                indicatorSelect.innerHTML = '<option value="">All</option>';
                
                if (data.success && data.indicators && data.indicators.length > 0) {
                    // Add project indicators and auto-select them
                    data.indicators.forEach(ind => {
                        const option = document.createElement('option');
                        option.value = ind.id;
                        option.textContent = ind.code + ' – ' + ind.name;
                        option.selected = true;
                        indicatorSelect.appendChild(option);
                    });
                }
            }
        })
        .catch(error => {
            console.error('Error loading project indicators:', error);
        });
}

// Load project geography and auto-populate filters
function loadProjectGeography(projectId) {
    fetch('?action=get_project_geography&project_id=' + projectId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Auto-populate geography dropdowns
                if (data.region_id) {
                    const regionSelect = document.getElementById('filter_region_id');
                    if (regionSelect) {
                        regionSelect.value = data.region_id;
                        // Trigger zone update
                        regionSelect.dispatchEvent(new Event('change'));
                    }
                }
                if (data.zone_id) {
                    setTimeout(() => {
                        const zoneSelect = document.getElementById('filter_zone_id');
                        if (zoneSelect) {
                            zoneSelect.value = data.zone_id;
                            // Trigger woreda update
                            zoneSelect.dispatchEvent(new Event('change'));
                        }
                    }, 500);
                }
            }
        })
        .catch(error => {
            console.error('Error loading project geography:', error);
        });
}

// Handle period type changes - show/hide month/week fields
function handlePeriodTypeChange() {
    const periodTypeSelect = document.getElementById('period_type_select');
    if (!periodTypeSelect) return;
    
    // Find labels by finding the parent label of the select elements
    const monthFromSelect = document.getElementById('month_from_select');
    const monthToSelect = document.getElementById('month_to_select');
    const weekFromSelect = document.getElementById('week_from_select');
    const weekToSelect = document.getElementById('week_to_select');
    const weekFromLabel = document.getElementById('week_from_label');
    const weekToLabel = document.getElementById('week_to_label');
    
    const monthFromLabel = monthFromSelect ? monthFromSelect.closest('label') : null;
    const monthToLabel = monthToSelect ? monthToSelect.closest('label') : null;
    
    const periodType = periodTypeSelect.value;
    
    // Show/hide month fields based on period type
    if (periodType === 'monthly' || periodType === 'quarterly' || periodType === 'annual' || periodType === '') {
        // Show month fields
        if (monthFromLabel) monthFromLabel.style.display = '';
        if (monthToLabel) monthToLabel.style.display = '';
        if (monthFromSelect) monthFromSelect.style.display = '';
        if (monthToSelect) monthToSelect.style.display = '';
        // Hide week fields
        if (weekFromLabel) weekFromLabel.style.display = 'none';
        if (weekToLabel) weekToLabel.style.display = 'none';
        if (weekFromSelect) weekFromSelect.style.display = 'none';
        if (weekToSelect) weekToSelect.style.display = 'none';
    } else if (periodType === 'weekly') {
        // Hide month fields
        if (monthFromLabel) monthFromLabel.style.display = 'none';
        if (monthToLabel) monthToLabel.style.display = 'none';
        if (monthFromSelect) monthFromSelect.style.display = 'none';
        if (monthToSelect) monthToSelect.style.display = 'none';
        // Show week fields
        if (weekFromLabel) weekFromLabel.style.display = '';
        if (weekToLabel) weekToLabel.style.display = '';
        if (weekFromSelect) weekFromSelect.style.display = '';
        if (weekToSelect) weekToSelect.style.display = '';
    } else {
        // Show all fields for "All" or unknown
        if (monthFromLabel) monthFromLabel.style.display = '';
        if (monthToLabel) monthToLabel.style.display = '';
        if (monthFromSelect) monthFromSelect.style.display = '';
        if (monthToSelect) monthToSelect.style.display = '';
        if (weekFromLabel) weekFromLabel.style.display = '';
        if (weekToLabel) weekToLabel.style.display = '';
        if (weekFromSelect) weekFromSelect.style.display = '';
        if (weekToSelect) weekToSelect.style.display = '';
    }
}

// Handle quick range changes
function handleQuickRangeChange() {
    const quickRangeSelect = document.getElementById('quick_range_select');
    const customInput = document.getElementById('custom_period_input');
    const customLabel = document.getElementById('custom_period_label');
    
    if (!quickRangeSelect || !customInput || !customLabel) return;
    
    const value = quickRangeSelect.value;
    
    if (value === 'custom_weeks') {
        customInput.style.display = 'block';
        customLabel.textContent = 'weeks';
    } else if (value === 'custom_months') {
        customInput.style.display = 'block';
        customLabel.textContent = 'months';
    } else {
        customInput.style.display = 'none';
    }
    
    // Disable quick range if specific month is selected
    const monthFrom = document.querySelector('select[name="month_from"]')?.value;
    const monthTo = document.querySelector('select[name="month_to"]')?.value;
    
    if (monthFrom && monthTo && monthFrom === monthTo) {
        quickRangeSelect.value = '';
        customInput.style.display = 'none';
    }
}

// ==================== EXPORT FUNCTIONS ====================
function exportReport(format) {
    const url = new URL(window.location.href);
    url.searchParams.set('export', format);
    window.location.href = url.toString();
}

// ==================== PRINT FUNCTION ====================
function printReport() {
    const style = document.createElement('style');
    style.innerHTML = `
        @media print {
            .no-print, .btn-sm, button, .form-row, nav, header, .site-header, .app-header, .card:first-child { display: none !important; }
            body { background: white; margin: 0; padding: 10mm; }
            .card { box-shadow: none; border: 1px solid #ddd; page-break-inside: avoid; }
            .print-header-vr { display: block !important; }
            table { page-break-inside: auto; }
            tr { page-break-inside: avoid; page-break-after: auto; }
        }
    `;
    document.head.appendChild(style);
    window.print();
    setTimeout(() => {
        if (document.head.contains(style)) {
            document.head.removeChild(style);
        }
    }, 1000);
}

// ==================== SHARE FUNCTIONS ====================
function shareReport(platform) {
    const url = window.location.href;
    const subject = 'Nexus Project Custom Report';
    const body = encodeURIComponent('View this custom report:\n' + url);
    
    let shareUrl = '';
    switch(platform) {
        case 'email':
            shareUrl = 'mailto:?subject=' + encodeURIComponent(subject) + '&body=' + body;
            break;
        case 'whatsapp':
            shareUrl = 'https://wa.me/?text=' + body;
            break;
        case 'telegram':
            shareUrl = 'https://t.me/share/url?url=' + encodeURIComponent(url) + '&text=' + body;
            break;
    }
    
    if (shareUrl) {
        window.open(shareUrl, '_blank');
    }
}

// ==================== AI GUIDANCE FUNCTIONS ====================
function showAIGuidance(message, type = 'info') {
    const panel = document.getElementById('aiGuidancePanel');
    const content = document.getElementById('aiGuidanceContent');
    
    if (panel && content) {
        content.innerHTML = '<p>' + message + '</p>';
        panel.style.display = 'block';
        
        // Update color based on type
        if (type === 'success') {
            panel.style.background = 'linear-gradient(135deg, #11998e 0%, #38ef7d 100%)';
        } else if (type === 'warning') {
            panel.style.background = 'linear-gradient(135deg, #f093fb 0%, #f5576c 100%)';
        } else if (type === 'error') {
            panel.style.background = 'linear-gradient(135deg, #fa709a 0%, #fee140 100%)';
        } else {
            panel.style.background = 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)';
        }
    }
}

function hideAIGuidance() {
    const panel = document.getElementById('aiGuidancePanel');
    if (panel) {
        panel.style.display = 'none';
    }
}

// Generate AI insights based on report data
function generateAIInsights() {
    const projectId = document.getElementById('project_select')?.value;
    if (!projectId) {
        return;
    }
    
    // Check if we have data
    const hasData = <?php echo json_encode(!empty($indicatorAgg)); ?>;
    const totalReports = <?php echo json_encode(count($reportRows)); ?>;
    const totalIndicators = <?php echo json_encode(count($indicatorAgg)); ?>;
    
    let insights = [];
    
    if (!hasData) {
        insights.push('⚠️ No data found for selected filters. Consider adjusting date ranges or geography filters.');
        insights.push('💡 Tip: Ensure reports have been submitted in enter_data.php for this project.');
    } else {
        insights.push('✅ Found ' + totalReports + ' reports and ' + totalIndicators + ' indicator records.');
        
        if (totalReports < 5) {
            insights.push('📊 Low report count detected. Consider encouraging more frequent reporting.');
        }
        
        if (totalIndicators > 0) {
            insights.push('📈 Review indicator performance in the tables below for detailed insights.');
        }
    }
    
    if (insights.length > 0) {
        showAIGuidance(insights.join('<br>'), hasData ? 'success' : 'warning');
    }
}
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>
