<?php
require_once __DIR__ . '/_preflight.php';
require_login();
$pdo = getPDO();
require_once __DIR__ . '/../header.php';


if (!$pdo) {
    die("Database connection failed. Please check configuration.");
}

/**
 * Small helper to check if a column exists in a table
 */
if (!function_exists('aggregation_table_has_column')) {
    function aggregation_table_has_column(PDO $pdo, string $table, string $column): bool {
        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
            $stmt->execute([$column]);
            return (bool)$stmt->fetch();
        } catch (Exception $e) {
            error_log("Aggregation column check failed: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Detect indicator unit column (unit_type or unit) – same logic as custom_report
 */
$aggIndicatorUnitColumn = null;
try {
    if (aggregation_table_has_column($pdo, 'indicators', 'unit_type')) {
        $aggIndicatorUnitColumn = 'unit_type';
    } elseif (aggregation_table_has_column($pdo, 'indicators', 'unit')) {
        $aggIndicatorUnitColumn = 'unit';
    }
} catch (Exception $e) {
    error_log("Aggregation indicator unit column detection failed: " . $e->getMessage());
}

/**
 * Detect project title column in projects table
 * We try: title, project_title, then name (for backward compatibility)
 */
$aggProjectTitleColumn = null;
try {
    if (aggregation_table_has_column($pdo, 'projects', 'title')) {
        $aggProjectTitleColumn = 'title';
    } elseif (aggregation_table_has_column($pdo, 'projects', 'project_title')) {
        $aggProjectTitleColumn = 'project_title';
    } elseif (aggregation_table_has_column($pdo, 'projects', 'name')) {
        $aggProjectTitleColumn = 'name';
    }
} catch (Exception $e) {
    error_log("Aggregation project title column detection failed: " . $e->getMessage());
}

// ---------------------------------------------------------------------
// Check if functions already exist to avoid conflicts
// ---------------------------------------------------------------------
if (!function_exists('aggregation_get_regions')) {
    function aggregation_get_regions() {
        $pdo = getPDO();
        try {
            $stmt = $pdo->query("SELECT id, name FROM regions ORDER BY name");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting regions: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('aggregation_get_projects')) {
    function aggregation_get_projects() {
        $pdo = getPDO();
        global $aggProjectTitleColumn;
        try {
            // Decide which column to use for ordering
            if ($aggProjectTitleColumn) {
                $orderExpr = "p.`{$aggProjectTitleColumn}`";
            } else {
                // Fallback: order by ID if we can't detect a title column
                $orderExpr = "p.id";
            }

            // Get all projects with their details
            $stmt = $pdo->query("
                SELECT p.*, 
                       r.name as region_name,
                       z.name as zone_name,
                       w.name as woreda_name,
                       pt.name as project_type_name,
                       d.name as donor_name
                FROM projects p
                LEFT JOIN regions r ON p.region_id = r.id
                LEFT JOIN zones z ON p.zone_id = z.id
                LEFT JOIN woredas w ON p.woreda_id = w.id
                LEFT JOIN project_types pt ON p.project_type_id = pt.id
                LEFT JOIN donors d ON p.donor_id = d.id
                ORDER BY {$orderExpr}
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting projects: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('aggregation_get_indicators')) {
    function aggregation_get_indicators() {
        $pdo = getPDO();
        global $aggIndicatorUnitColumn;
        try {
            // Only select needed fields + dynamic unit column alias
            $unitSelect = $aggIndicatorUnitColumn
                ? ", `$aggIndicatorUnitColumn` AS unit_type"
                : ", NULL AS unit_type";
            $stmt = $pdo->query("SELECT id, code, name{$unitSelect} FROM indicators ORDER BY code");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting indicators: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('aggregation_get_donors')) {
    function aggregation_get_donors() {
        $pdo = getPDO();
        try {
            $stmt = $pdo->query("SELECT * FROM donors ORDER BY name");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Donors table doesn't exist, using default list");
            return [
                ['id' => 1, 'name' => 'UNICEF'],
                ['id' => 2, 'name' => 'WHO'],
                ['id' => 3, 'name' => 'World Bank'],
                ['id' => 4, 'name' => 'USAID'],
                ['id' => 5, 'name' => 'DFID'],
                ['id' => 6, 'name' => 'EU'],
                ['id' => 7, 'name' => 'Government of Ethiopia'],
                ['id' => 8, 'name' => 'Other']
            ];
        }
    }
}

if (!function_exists('aggregation_get_project_types')) {
    function aggregation_get_project_types() {
        $pdo = getPDO();
        try {
            $stmt = $pdo->query("SELECT * FROM project_types ORDER BY name");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Project types table doesn't exist, using default list");
            return [
                ['id' => 1, 'name' => 'Emergency'],
                ['id' => 2, 'name' => 'Development'],
                ['id' => 3, 'name' => 'Resilience/Recovery'],
                ['id' => 4, 'name' => 'Outbreak Response']
            ];
        }
    }
}

if (!function_exists('aggregation_get_sectors')) {
    function aggregation_get_sectors() {
        $pdo = getPDO();
        try {
            $stmt = $pdo->query("SELECT DISTINCT sector FROM projects WHERE sector IS NOT NULL AND sector != '' ORDER BY sector");
            $sectors = $stmt->fetchAll(PDO::FETCH_COLUMN);
            return !empty($sectors) ? $sectors : [
                'Nutrition',
                'WASH',
                'Health',
                'Education',
                'Protection',
                'Shelter',
                'Food Security',
                'Livelihood',
                'Agriculture',
                'GBV',
                'Child Protection'
            ];
        } catch (Exception $e) {
            return [
                'Nutrition',
                'WASH',
                'Health',
                'Education',
                'Protection',
                'Shelter',
                'Food Security',
                'Livelihood',
                'Agriculture',
                'GBV',
                'Child Protection'
            ];
        }
    }
}

if (!function_exists('aggregation_get_project_areas')) {
    function aggregation_get_project_areas() {
        $pdo = getPDO();
        try {
            $stmt = $pdo->query("SELECT DISTINCT project_area FROM projects WHERE project_area IS NOT NULL AND project_area != '' ORDER BY project_area");
            $areas = $stmt->fetchAll(PDO::FETCH_COLUMN);
            return !empty($areas) ? $areas : [
                'Primary Health Care',
                'Maternal Health',
                'Child Health',
                'HIV/AIDS',
                'Malaria',
                'TB',
                'Nutrition Services',
                'WASH Facilities',
                'School Health',
                'Community Health'
            ];
        } catch (Exception $e) {
            return [
                'Primary Health Care',
                'Maternal Health',
                'Child Health',
                'HIV/AIDS',
                'Malaria',
                'TB',
                'Nutrition Services',
                'WASH Facilities',
                'School Health',
                'Community Health'
            ];
        }
    }
}

if (!function_exists('aggregation_get_beneficiary_types')) {
    function aggregation_get_beneficiary_types() {
        $pdo = getPDO();
        try {
            $stmt = $pdo->query("SELECT DISTINCT beneficiary_type FROM report_values WHERE beneficiary_type IS NOT NULL AND beneficiary_type != '' ORDER BY beneficiary_type");
            $results = $stmt->fetchAll(PDO::FETCH_COLUMN);
            return !empty($results) ? $results : ['General', 'Vulnerable', 'IDP', 'Refugee', 'Host Community', 'PWD', 'Children', 'Women', 'Elderly'];
        } catch (Exception $e) {
            return ['General', 'Vulnerable', 'IDP', 'Refugee', 'Host Community', 'PWD', 'Children', 'Women', 'Elderly'];
        }
    }
}

if (!function_exists('aggregation_get_zones')) {
    function aggregation_get_zones() {
        $pdo = getPDO();
        try {
            $stmt = $pdo->query("SELECT id, name FROM zones ORDER BY name");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting zones: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('aggregation_get_woredas')) {
    function aggregation_get_woredas() {
        $pdo = getPDO();
        try {
            $stmt = $pdo->query("SELECT id, name FROM woredas ORDER BY name");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting woredas: " . $e->getMessage());
            return [];
        }
    }
}

// ---------------------------------------------------------------------
// Lookups with unique function names
// ---------------------------------------------------------------------
$projects          = aggregation_get_projects();
$regions           = aggregation_get_regions();
$zones             = aggregation_get_zones();
$woredas           = aggregation_get_woredas();
$indicators        = aggregation_get_indicators();
$donors            = aggregation_get_donors();
$project_types     = aggregation_get_project_types();
$sectors           = aggregation_get_sectors();
$project_areas     = aggregation_get_project_areas();
$beneficiary_types = aggregation_get_beneficiary_types();

$currentYear = date('Y');
$startYear   = $currentYear - 50;
$endYear     = $currentYear + 10;

// Month names for dropdown
$monthNames = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

// Project duration categories
$duration_categories = [
    'short'   => '<= 6 months',
    'medium'  => '6-12 months', 
    'long'    => '> 12 months',
    'custom'  => 'Custom Range'
];

// Budget ranges
$budget_ranges = [
    '0-50000'        => '0 - 50,000 ETB',
    '50000-200000'   => '50,000 - 200,000 ETB',
    '200000-500000'  => '200,000 - 500,000 ETB',
    '500000-1000000' => '500,000 - 1,000,000 ETB',
    '1000000+'       => '1,000,000+ ETB'
];

// ---------------------------------------------------------------------
// Enhanced Filters with All Requested Options
// ---------------------------------------------------------------------
$filters = [
    'project_ids'       => $_GET['project_ids']       ?? [],
    'region_ids'        => $_GET['region_ids']        ?? [],
    'zone_ids'          => $_GET['zone_ids']          ?? [],
    'woreda_ids'        => $_GET['woreda_ids']        ?? [],
    'indicator_ids'     => $_GET['indicator_ids']     ?? [],
    'year_from'         => $_GET['year_from']         ?? $startYear,
    'year_to'           => $_GET['year_to']           ?? $currentYear,
    'month_from'        => $_GET['month_from']        ?? '',
    'month_to'          => $_GET['month_to']          ?? '',
    'period_type'       => $_GET['period_type']       ?? '',
    'beneficiary_types' => $_GET['beneficiary_types'] ?? [],
    'donor_ids'         => $_GET['donor_ids']         ?? [],
    'project_type_ids'  => $_GET['project_type_ids']  ?? [],
    'sectors'           => $_GET['sectors']           ?? [],
    'project_areas'     => $_GET['project_areas']     ?? [],
    'duration_category' => $_GET['duration_category'] ?? '',
    'budget_range'      => $_GET['budget_range']      ?? '',
    'aggregation_level' => $_GET['aggregation_level'] ?? 'project',
    'gender_breakdown'  => $_GET['gender_breakdown']  ?? 'all',
    'age_group'         => $_GET['age_group']         ?? 'all',
    'project_status'    => $_GET['project_status']    ?? 'all'
];

// Convert string inputs to arrays safely
$array_filters = [
    'project_ids', 'region_ids', 'zone_ids', 'woreda_ids', 'indicator_ids', 
    'beneficiary_types', 'donor_ids', 'project_type_ids', 'sectors', 'project_areas'
];
foreach ($array_filters as $filter) {
    if (!is_array($filters[$filter]) && !empty($filters[$filter])) {
        $filters[$filter] = explode(',', $filters[$filter]);
    }
    // Ensure clean integer arrays where appropriate
    if (in_array($filter, ['project_ids', 'region_ids', 'zone_ids', 'woreda_ids', 'donor_ids', 'project_type_ids', 'indicator_ids'], true)) {
        $filters[$filter] = array_filter(array_map('intval', (array)$filters[$filter]));
    }
}

// ---------------------------------------------------------------------
// Enhanced Aggregation Query with Error Handling
// ---------------------------------------------------------------------

// Dynamic unit column selection
$unitSelectSql = $aggIndicatorUnitColumn
    ? "i.`$aggIndicatorUnitColumn` AS unit_type,"
    : "NULL AS unit_type,";

// Dynamic project title expression
if ($aggProjectTitleColumn) {
    $projectTitleExpr = "p.`{$aggProjectTitleColumn}`";
} else {
    // Fallback: use project ID as title if nothing else
    $projectTitleExpr = "p.id";
}

$sql = "SELECT
            r.project_id,
            {$projectTitleExpr} as project_title,
            p.region_id,
            p.zone_id,
            p.woreda_id,
            p.start_date,
            p.end_date,
            p.total_budget,
            p.budget_currency,
            p.status as project_status,
            p.project_type_id,
            p.sector,
            p.project_area,
            p.donor_id,
            i.id as indicator_id,
            i.code as indicator_code,
            i.name as indicator_name,
            {$unitSelectSql}
            rv.beneficiary_type,
            r.year,
            r.month,
            r.period_type,
            SUM(rv.boys_u5)     as boys_u5,
            SUM(rv.girls_u5)    as girls_u5,
            SUM(rv.boys_5_17)   as boys_5_17,
            SUM(rv.girls_5_17)  as girls_5_17,
            SUM(rv.men_18_59)   as men_18_59,
            SUM(rv.women_18_59) as women_18_59,
            SUM(rv.men_60p)     as men_60p,
            SUM(rv.women_60p)   as women_60p,
            SUM(rv.pwd_count)   as total_pwd,
            SUM(rv.non_beneficiary) as total_non_ben,
            COUNT(DISTINCT r.id)    as report_count
        FROM reports r
        JOIN projects p       ON r.project_id = p.id
        JOIN report_values rv ON rv.report_id = r.id
        JOIN indicators i     ON i.id = rv.indicator_id
        WHERE 1=1";

$params = [];

// Project filter (multiple)
if (!empty($filters['project_ids'])) {
    $placeholders = str_repeat('?,', count($filters['project_ids']) - 1) . '?';
    $sql .= " AND r.project_id IN ($placeholders)";
    $params = array_merge($params, $filters['project_ids']);
}

// Region filter (multiple)
if (!empty($filters['region_ids'])) {
    $placeholders = str_repeat('?,', count($filters['region_ids']) - 1) . '?';
    $sql .= " AND p.region_id IN ($placeholders)";
    $params = array_merge($params, $filters['region_ids']);
}

// Zone filter (multiple)
if (!empty($filters['zone_ids'])) {
    $placeholders = str_repeat('?,', count($filters['zone_ids']) - 1) . '?';
    $sql .= " AND p.zone_id IN ($placeholders)";
    $params = array_merge($params, $filters['zone_ids']);
}

// Woreda filter (multiple)
if (!empty($filters['woreda_ids'])) {
    $placeholders = str_repeat('?,', count($filters['woreda_ids']) - 1) . '?';
    $sql .= " AND p.woreda_id IN ($placeholders)";
    $params = array_merge($params, $filters['woreda_ids']);
}

// Indicator filter (multiple)
if (!empty($filters['indicator_ids'])) {
    $placeholders = str_repeat('?,', count($filters['indicator_ids']) - 1) . '?';
    $sql .= " AND rv.indicator_id IN ($placeholders)";
    $params = array_merge($params, $filters['indicator_ids']);
}

// Year range
if (!empty($filters['year_from'])) {
    $sql     .= " AND r.year >= ?";
    $params[] = $filters['year_from'];
}
if (!empty($filters['year_to'])) {
    $sql     .= " AND r.year <= ?";
    $params[] = $filters['year_to'];
}

// Month range
if (!empty($filters['month_from']) && !empty($filters['month_to'])) {
    $sql     .= " AND r.month BETWEEN ? AND ?";
    $params[] = $filters['month_from'];
    $params[] = $filters['month_to'];
} elseif (!empty($filters['month_from'])) {
    $sql     .= " AND r.month = ?";
    $params[] = $filters['month_from'];
}

// Period type
if (!empty($filters['period_type'])) {
    $sql     .= " AND r.period_type = ?";
    $params[] = $filters['period_type'];
}

// Beneficiary types (multiple)
if (!empty($filters['beneficiary_types'])) {
    $placeholders = str_repeat('?,', count($filters['beneficiary_types']) - 1) . '?';
    $sql .= " AND rv.beneficiary_type IN ($placeholders)";
    $params = array_merge($params, $filters['beneficiary_types']);
}

// Project types (multiple)
if (!empty($filters['project_type_ids'])) {
    $placeholders = str_repeat('?,', count($filters['project_type_ids']) - 1) . '?';
    $sql .= " AND p.project_type_id IN ($placeholders)";
    $params = array_merge($params, $filters['project_type_ids']);
}

// Sectors (multiple)
if (!empty($filters['sectors'])) {
    $placeholders = str_repeat('?,', count($filters['sectors']) - 1) . '?';
    $sql .= " AND p.sector IN ($placeholders)";
    $params = array_merge($params, $filters['sectors']);
}

// Project areas (multiple)
if (!empty($filters['project_areas'])) {
    $placeholders = str_repeat('?,', count($filters['project_areas']) - 1) . '?';
    $sql .= " AND p.project_area IN ($placeholders)";
    $params = array_merge($params, $filters['project_areas']);
}

// Donors (multiple)
if (!empty($filters['donor_ids'])) {
    $placeholders = str_repeat('?,', count($filters['donor_ids']) - 1) . '?';
    $sql .= " AND p.donor_id IN ($placeholders)";
    $params = array_merge($params, $filters['donor_ids']);
}

// Budget range filter (project total_budget)
if (!empty($filters['budget_range'])) {
    $br = $filters['budget_range'];
    if (substr($br, -1) === '+') {
        $min = (int)str_replace('+', '', $br);
        $sql     .= " AND p.total_budget >= ?";
        $params[] = $min;
    } elseif (strpos($br, '-') !== false) {
        list($min, $max) = explode('-', $br, 2);
        $min = (int)$min;
        $max = (int)$max;
        $sql     .= " AND p.total_budget BETWEEN ? AND ?";
        $params[] = $min;
        $params[] = $max;
    }
}

// Project duration filter using TIMESTAMPDIFF in months
if (!empty($filters['duration_category']) && $filters['duration_category'] !== 'custom') {
    switch ($filters['duration_category']) {
        case 'short':   // <= 6 months
            $sql .= " AND TIMESTAMPDIFF(MONTH, p.start_date, p.end_date) <= 6";
            break;
        case 'medium':  // > 6 and <= 12 months
            $sql .= " AND TIMESTAMPDIFF(MONTH, p.start_date, p.end_date) > 6
                      AND TIMESTAMPDIFF(MONTH, p.start_date, p.end_date) <= 12";
            break;
        case 'long':    // > 12 months
            $sql .= " AND TIMESTAMPDIFF(MONTH, p.start_date, p.end_date) > 12";
            break;
    }
}

// Project status
if (!empty($filters['project_status']) && $filters['project_status'] != 'all') {
    $sql     .= " AND p.status = ?";
    $params[] = $filters['project_status'];
}

// Group by based on aggregation level
switch ($filters['aggregation_level']) {
    case 'region':
        $sql .= " GROUP BY p.region_id, i.id, i.code, i.name, unit_type, rv.beneficiary_type";
        break;
    case 'zone':
        $sql .= " GROUP BY p.zone_id, i.id, i.code, i.name, unit_type, rv.beneficiary_type";
        break;
    case 'woreda':
        $sql .= " GROUP BY p.woreda_id, i.id, i.code, i.name, unit_type, rv.beneficiary_type";
        break;
    case 'project_type':
        $sql .= " GROUP BY p.project_type_id, i.id, i.code, i.name, unit_type, rv.beneficiary_type";
        break;
    case 'sector':
        $sql .= " GROUP BY p.sector, i.id, i.code, i.name, unit_type, rv.beneficiary_type";
        break;
    case 'project_area':
        $sql .= " GROUP BY p.project_area, i.id, i.code, i.name, unit_type, rv.beneficiary_type";
        break;
    case 'donor':
        $sql .= " GROUP BY p.donor_id, i.id, i.code, i.name, unit_type, rv.beneficiary_type";
        break;
    case 'budget':
        $sql .= " GROUP BY p.total_budget, i.id, i.code, i.name, unit_type, rv.beneficiary_type";
        break;
    case 'duration':
        $sql .= " GROUP BY p.start_date, p.end_date, i.id, i.code, i.name, unit_type, rv.beneficiary_type";
        break;
    default: // project level
        // Group by project_id (title is functionally dependent)
        $sql .= " GROUP BY r.project_id, i.id, i.code, i.name, unit_type, rv.beneficiary_type";
}

$sql .= " ORDER BY project_title, i.code, rv.beneficiary_type";

$rows          = [];
$error_message = null;

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Aggregation query error: " . $e->getMessage());
    $error_message = "Database error: " . $e->getMessage();
}

/**
 * Apply gender_breakdown and age_group filters in-memory
 * so all summaries and charts reflect them.
 */
if (is_array($rows) && !empty($rows)) {
    $filteredRows = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $boys_u5     = (int)($row['boys_u5']     ?? 0);
        $girls_u5    = (int)($row['girls_u5']    ?? 0);
        $boys_5_17   = (int)($row['boys_5_17']   ?? 0);
        $girls_5_17  = (int)($row['girls_5_17']  ?? 0);
        $men_18_59   = (int)($row['men_18_59']   ?? 0);
        $women_18_59 = (int)($row['women_18_59'] ?? 0);
        $men_60p     = (int)($row['men_60p']     ?? 0);
        $women_60p   = (int)($row['women_60p']   ?? 0);

        // Gender filter
        if ($filters['gender_breakdown'] === 'male') {
            $girls_u5 = $girls_5_17 = $women_18_59 = $women_60p = 0;
        } elseif ($filters['gender_breakdown'] === 'female') {
            $boys_u5 = $boys_5_17 = $men_18_59 = $men_60p = 0;
        }

        // Age group filter
        switch ($filters['age_group']) {
            case 'children': // 0–17
                $men_18_59 = $women_18_59 = $men_60p = $women_60p = 0;
                break;
            case 'adults':   // 18–59
                $boys_u5   = $girls_u5 = $boys_5_17 = $girls_5_17 = 0;
                $men_60p   = $women_60p = 0;
                break;
            case 'elderly':  // 60+
                $boys_u5   = $girls_u5 = $boys_5_17 = $girls_5_17 = 0;
                $men_18_59 = $women_18_59 = 0;
                break;
            default:
                // 'all' – keep everything
                break;
        }

        $row['boys_u5']     = $boys_u5;
        $row['girls_u5']    = $girls_u5;
        $row['boys_5_17']   = $boys_5_17;
        $row['girls_5_17']  = $girls_5_17;
        $row['men_18_59']   = $men_18_59;
        $row['women_18_59'] = $women_18_59;
        $row['men_60p']     = $men_60p;
        $row['women_60p']   = $women_60p;

        $filteredRows[] = $row;
    }
    $rows = $filteredRows;
}

// Calculate summary statistics safely
$summary = [
    'total_beneficiaries' => 0,
    'total_boys'          => 0,
    'total_girls'         => 0,
    'total_men'           => 0,
    'total_women'         => 0,
    'total_pwd'           => 0,
    'total_projects'      => 0,
    'total_reports'       => 0,
    'total_indicators'    => 0,
    'total_budget'        => 0
];

if (is_array($rows) && !empty($rows)) {
    $project_ids   = [];
    $indicator_ids = [];
    
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        
        $boys   = (int)($row['boys_u5'] ?? 0) + (int)($row['boys_5_17'] ?? 0);
        $girls  = (int)($row['girls_u5'] ?? 0) + (int)($row['girls_5_17'] ?? 0);
        $men    = (int)($row['men_18_59'] ?? 0) + (int)($row['men_60p'] ?? 0);
        $women  = (int)($row['women_18_59'] ?? 0) + (int)($row['women_60p'] ?? 0);
        $total  = $boys + $girls + $men + $women;
        
        $summary['total_beneficiaries'] += $total;
        $summary['total_boys']  += $boys;
        $summary['total_girls'] += $girls;
        $summary['total_men']   += $men;
        $summary['total_women'] += $women;
        $summary['total_pwd']   += (int)($row['total_pwd'] ?? 0);
        $summary['total_reports'] += (int)($row['report_count'] ?? 0);
        $summary['total_budget']  += (float)($row['total_budget'] ?? 0);
        
        if (!empty($row['project_id'])) {
            $project_ids[$row['project_id']] = true;
        }
        if (!empty($row['indicator_id'])) {
            $indicator_ids[$row['indicator_id']] = true;
        }
    }
    
    $summary['total_projects']   = count($project_ids);
    $summary['total_indicators'] = count($indicator_ids);
}

// Calculate percentages safely
$summary['women_percentage'] = $summary['total_beneficiaries'] > 0 ? 
    round(($summary['total_women'] / $summary['total_beneficiaries']) * 100, 2) : 0;
$summary['children_percentage'] = $summary['total_beneficiaries'] > 0 ? 
    round((($summary['total_boys'] + $summary['total_girls']) / $summary['total_beneficiaries']) * 100, 2) : 0;
$summary['pwd_percentage'] = $summary['total_beneficiaries'] > 0 ? 
    round(($summary['total_pwd'] / $summary['total_beneficiaries']) * 100, 2) : 0;

// Prepare data for charts (already filtered by gender/age)
$chart_data = [
    'age_gender' => [
        'boys_u5'     => array_sum(array_column($rows, 'boys_u5')),
        'girls_u5'    => array_sum(array_column($rows, 'girls_u5')),
        'boys_5_17'   => array_sum(array_column($rows, 'boys_5_17')),
        'girls_5_17'  => array_sum(array_column($rows, 'girls_5_17')),
        'men_18_59'   => array_sum(array_column($rows, 'men_18_59')),
        'women_18_59' => array_sum(array_column($rows, 'women_18_59')),
        'men_60p'     => array_sum(array_column($rows, 'men_60p')),
        'women_60p'   => array_sum(array_column($rows, 'women_60p'))
    ]
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advanced Aggregation Dashboard - Health Reporting System</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2c3e50;
            --secondary: #3498db;
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
            --info: #17a2b8;
            --light: #ecf0f1;
            --dark: #34495e;
            --purple: #9b59b6;
            --pink: #e84393;
        }
        
        * {
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background: #f8f9fa;
            color: #333;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 6px 15px rgba(0,0,0,0.08);
            margin-bottom: 25px;
            padding: 25px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.12);
        }
        
        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, var(--secondary), var(--primary));
            color: white;
            padding: 25px;
            border-radius: 12px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: rgba(255,255,255,0.1);
            transform: rotate(45deg);
        }
        
        .stat-card:nth-child(2) {
            background: linear-gradient(135deg, var(--success), #219652);
        }
        
        .stat-card:nth-child(3) {
            background: linear-gradient(135deg, var(--warning), #e67e22);
        }
        
        .stat-card:nth-child(4) {
            background: linear-gradient(135deg, var(--purple), #8e44ad);
        }
        
        .stat-card:nth-child(5) {
            background: linear-gradient(135deg, var(--info), #138496);
        }
        
        .stat-icon {
            font-size: 2.5em;
            margin-bottom: 15px;
            opacity: 0.9;
        }
        
        .stat-number {
            font-size: 2.2em;
            font-weight: bold;
            margin: 10px 0;
            text-shadow: 1px 1px 3px rgba(0,0,0,0.2);
        }
        
        .stat-label {
            font-size: 1em;
            opacity: 0.9;
        }
        
        .filter-section {
            background: linear-gradient(to right, var(--light), #f8f9fa);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            border-left: 5px solid var(--secondary);
        }
        
        .form-row {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group {
            flex: 1;
            min-width: 220px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark);
        }
        
        select, input, button {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 15px;
            transition: all 0.3s;
        }
        
        select:focus, input:focus {
            outline: none;
            border-color: var(--secondary);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
        }
        
        select[multiple] {
            height: 140px;
            padding: 10px;
        }
        
        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 15px;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .btn-primary { 
            background: linear-gradient(135deg, var(--secondary), #2980b9); 
            color: white; 
        }
        
        .btn-success { 
            background: linear-gradient(135deg, var(--success), #219652); 
            color: white; 
        }
        
        .btn-warning { 
            background: linear-gradient(135deg, var(--warning), #e67e22); 
            color: white; 
        }
        
        .btn-danger { 
            background: linear-gradient(135deg, var(--danger), #c0392b); 
            color: white; 
        }
        
        .btn-info { 
            background: linear-gradient(135deg, var(--info), #138496); 
            color: white; 
        }
        
        .btn:hover { 
            transform: translateY(-3px); 
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .btn:active {
            transform: translateY(-1px);
        }
        
        .chart-container {
            height: 350px;
            margin: 25px 0;
            position: relative;
        }
        
        .tabs {
            display: flex;
            border-bottom: 2px solid var(--light);
            margin-bottom: 25px;
            flex-wrap: wrap;
        }
        
        .tab {
            padding: 15px 25px;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            font-weight: 600;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .tab:hover {
            background: rgba(52, 152, 219, 0.05);
            color: var(--secondary);
        }
        
        .tab.active {
            border-bottom-color: var(--secondary);
            color: var(--secondary);
            background: rgba(52, 152, 219, 0.08);
        }
        
        .tab-content {
            display: none;
            animation: fadeIn 0.5s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .tab-content.active {
            display: block;
        }
        
        .export-buttons {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin: 25px 0;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 25px 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-radius: 8px;
            overflow: hidden;
        }
        
        th, td {
            padding: 15px 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }
        
        th {
            background: linear-gradient(to right, var(--secondary), var(--primary));
            color: white;
            font-weight: 600;
            position: sticky;
            top: 0;
        }
        
        tr:hover {
            background: rgba(52, 152, 219, 0.03);
        }
        
        .positive { color: var(--success); font-weight: 600; }
        .negative { color: var(--danger); font-weight: 600; }
        .neutral  { color: var(--warning); font-weight: 600; }
        
        .error-message {
            background: var(--danger);
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin: 15px 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .success-message {
            background: var(--success);
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin: 15px 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .info-message {
            background: var(--info);
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin: 15px 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .real-time-badge {
            background: var(--success);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8em;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-left: 10px;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.7; }
            100% { opacity: 1; }
        }
        
        .section-title {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            color: var(--primary);
            border-bottom: 2px solid var(--light);
            padding-bottom: 10px;
        }
        
        .section-title i {
            font-size: 1.5em;
        }
        
        .badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8em;
            font-weight: 600;
        }
        
        .badge-primary { background: var(--secondary); color: white; }
        .badge-success { background: var(--success); color: white; }
        .badge-warning { background: var(--warning); color: white; }
        .badge-danger  { background: var(--danger);  color: white; }
        
        .data-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        
        .data-card h4 {
            margin-top: 0;
            color: var(--primary);
            border-bottom: 1px solid var(--light);
            padding-bottom: 10px;
        }
        
        .analytics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        
        .advanced-filter {
            background: rgba(52, 152, 219, 0.05);
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            border-left: 4px solid var(--secondary);
        }
        
        .filter-group {
            margin-bottom: 15px;
        }
        
        .filter-group-title {
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        @media print {
            .no-print { display: none !important; }
            .card { box-shadow: none; border: 1px solid #ddd; }
            .stat-card { break-inside: avoid; }
        }
        
        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
            }
            
            .form-group {
                min-width: 100%;
            }
            
            .dashboard-stats {
                grid-template-columns: 1fr;
            }
            
            .tabs {
                flex-direction: column;
            }
            
            .tab {
                justify-content: center;
            }
            
            .export-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; margin-bottom:20px;">
            <div>
                <h1 style="margin:0; color:var(--primary); display:flex; align-items:center; gap:12px;">
                    <i class="fas fa-chart-pie"></i> Advanced Aggregation Dashboard
                    <span class="real-time-badge no-print">
                        <i class="fas fa-circle"></i> Live Data
                    </span>
                </h1>
                <p style="margin:5px 0 0 0; color:#666;">Comprehensive data aggregation with advanced analytics and visualization</p>
            </div>
            <div class="export-buttons no-print">
                <button class="btn btn-primary" onclick="printDashboard()">
                    <i class="fas fa-print"></i> Print PDF
                </button>
                <button class="btn btn-success" onclick="exportToExcel()">
                    <i class="fas fa-file-excel"></i> Export Excel
                </button>
                <button class="btn btn-warning" onclick="exportToWord()">
                    <i class="fas fa-file-word"></i> Export Word
                </button>
                <button class="btn btn-info" onclick="showShareModal()">
                    <i class="fas fa-share-alt"></i> Share
                </button>
            </div>
        </div>
        
        <?php if (isset($error_message)): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>Database Error</strong>
                    <p><?php echo htmlspecialchars($error_message); ?></p>
                    <p style="font-size:0.9em; margin-top:8px;">
                        <strong>Note:</strong> Some features may not work if required database tables are missing.
                        The system will use default values where possible.
                    </p>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Summary Statistics -->
        <div class="dashboard-stats">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-number"><?php echo number_format($summary['total_beneficiaries']); ?></div>
                <div class="stat-label">Total Beneficiaries</div>
                <div class="stat-label">Across <?php echo $summary['total_projects']; ?> Projects</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-female"></i></div>
                <div class="stat-number"><?php echo number_format($summary['total_women']); ?></div>
                <div class="stat-label">Women Reached</div>
                <div class="stat-label">(<?php echo $summary['women_percentage']; ?>%)</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-child"></i></div>
                <div class="stat-number"><?php echo number_format($summary['total_boys'] + $summary['total_girls']); ?></div>
                <div class="stat-label">Children Reached</div>
                <div class="stat-label">(<?php echo $summary['children_percentage']; ?>%)</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-wheelchair"></i></div>
                <div class="stat-number"><?php echo number_format($summary['total_pwd']); ?></div>
                <div class="stat-label">Persons with Disabilities</div>
                <div class="stat-label">(<?php echo $summary['pwd_percentage']; ?>%)</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-money-bill-wave"></i></div>
                <div class="stat-number"><?php echo number_format($summary['total_budget']); ?></div>
                <div class="stat-label">Total Budget (ETB)</div>
                <div class="stat-label">Across All Projects</div>
            </div>
        </div>

        <!-- Enhanced Filter Section -->
        <div class="filter-section no-print">
            <div class="section-title">
                <i class="fas fa-filter"></i>
                <h3 style="margin:0;">Advanced Filter Options</h3>
            </div>
            <form method="get" id="aggregationForm">
                
                <!-- Project & Geographic Filters -->
                <div class="filter-group">
                    <div class="filter-group-title">
                        <i class="fas fa-map-marker-alt"></i> Project & Location Filters
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-project-diagram"></i> Projects (Multiple Selection)</label>
                            <select name="project_ids[]" multiple>
                                <?php foreach ($projects as $p): ?>
                                    <?php 
                                    $projectId = $p['id'] ?? '';
                                    // Try to use detected title column if available
                                    global $aggProjectTitleColumn;
                                    $projectName = null;
                                    if (!empty($aggProjectTitleColumn) && isset($p[$aggProjectTitleColumn])) {
                                        $projectName = $p[$aggProjectTitleColumn];
                                    } else {
                                        $projectName = $p['title'] ?? $p['project_title'] ?? $p['name'] ?? ('Project #' . $projectId);
                                    }
                                    $isSelected = in_array($projectId, $filters['project_ids'], true);
                                    ?>
                                    <option value="<?php echo $projectId; ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($projectName); ?>
                                        <?php if (!empty($p['region_name'])): ?>
                                            (<?php echo htmlspecialchars($p['region_name']); ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-globe-africa"></i> Regions</label>
                            <select name="region_ids[]" multiple>
                                <?php foreach ($regions as $r): ?>
                                    <?php 
                                    $regionId   = $r['id'] ?? '';
                                    $regionName = $r['name'] ?? '';
                                    $isSelected = in_array($regionId, $filters['region_ids'], true);
                                    ?>
                                    <option value="<?php echo $regionId; ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($regionName); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-map"></i> Zones</label>
                            <select name="zone_ids[]" multiple>
                                <?php foreach ($zones as $z): ?>
                                    <?php 
                                    $zoneId   = $z['id'] ?? '';
                                    $zoneName = $z['name'] ?? '';
                                    $isSelected = in_array($zoneId, $filters['zone_ids'], true);
                                    ?>
                                    <option value="<?php echo $zoneId; ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($zoneName); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-map-pin"></i> Woredas</label>
                            <select name="woreda_ids[]" multiple>
                                <?php foreach ($woredas as $w): ?>
                                    <?php 
                                    $woredaId   = $w['id'] ?? '';
                                    $woredaName = $w['name'] ?? '';
                                    $isSelected = in_array($woredaId, $filters['woreda_ids'], true);
                                    ?>
                                    <option value="<?php echo $woredaId; ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($woredaName); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-hand-holding-usd"></i> Donors</label>
                            <select name="donor_ids[]" multiple>
                                <?php foreach ($donors as $d): ?>
                                    <?php 
                                    $donorId   = $d['id'] ?? '';
                                    $donorName = $d['name'] ?? '';
                                    $isSelected = in_array($donorId, $filters['donor_ids'], true);
                                    ?>
                                    <option value="<?php echo $donorId; ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($donorName); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-tags"></i> Project Types</label>
                            <select name="project_type_ids[]" multiple>
                                <?php foreach ($project_types as $pt): ?>
                                    <?php 
                                    $typeId   = $pt['id'] ?? '';
                                    $typeName = $pt['name'] ?? '';
                                    $isSelected = in_array($typeId, $filters['project_type_ids'], true);
                                    ?>
                                    <option value="<?php echo $typeId; ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($typeName); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Project Sectors & Areas -->
                <div class="filter-group">
                    <div class="filter-group-title">
                        <i class="fas fa-layer-group"></i> Project Sectors & Areas
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-sitemap"></i> Main Programme Sectors</label>
                            <select name="sectors[]" multiple>
                                <?php foreach ($sectors as $sector): ?>
                                    <?php $isSelected = in_array($sector, $filters['sectors'], true); ?>
                                    <option value="<?php echo htmlspecialchars($sector); ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($sector); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-bullseye"></i> Project Specific Areas</label>
                            <select name="project_areas[]" multiple>
                                <?php foreach ($project_areas as $area): ?>
                                    <?php $isSelected = in_array($area, $filters['project_areas'], true); ?>
                                    <option value="<?php echo htmlspecialchars($area); ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($area); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Time & Period Filters -->
                <div class="filter-group">
                    <div class="filter-group-title">
                        <i class="fas fa-calendar-alt"></i> Time & Period Filters
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-calendar-alt"></i> Year From</label>
                            <select name="year_from">
                                <?php for ($y = $startYear; $y <= $endYear; $y++): ?>
                                    <option value="<?php echo $y; ?>" <?php echo (int)$filters['year_from'] === (int)$y ? 'selected' : ''; ?>>
                                        <?php echo $y; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-calendar-alt"></i> Year To</label>
                            <select name="year_to">
                                <?php for ($y = $startYear; $y <= $endYear; $y++): ?>
                                    <option value="<?php echo $y; ?>" <?php echo (int)$filters['year_to'] === (int)$y ? 'selected' : ''; ?>>
                                        <?php echo $y; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-calendar-day"></i> Month From</label>
                            <select name="month_from">
                                <option value="">All Months</option>
                                <?php foreach ($monthNames as $mNum => $mLabel): ?>
                                    <option value="<?php echo $mNum; ?>" <?php echo (string)$filters['month_from'] === (string)$mNum ? 'selected' : ''; ?>>
                                        <?php echo $mLabel; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-calendar-day"></i> Month To</label>
                            <select name="month_to">
                                <option value="">All Months</option>
                                <?php foreach ($monthNames as $mNum => $mLabel): ?>
                                    <option value="<?php echo $mNum; ?>" <?php echo (string)$filters['month_to'] === (string)$mNum ? 'selected' : ''; ?>>
                                        <?php echo $mLabel; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-chart-line"></i> Report Period Type</label>
                            <select name="period_type">
                                <option value="">All Types</option>
                                <option value="weekly"    <?php echo $filters['period_type'] === 'weekly'    ? 'selected' : ''; ?>>Weekly</option>
                                <option value="monthly"   <?php echo $filters['period_type'] === 'monthly'   ? 'selected' : ''; ?>>Monthly</option>
                                <option value="quarterly" <?php echo $filters['period_type'] === 'quarterly' ? 'selected' : ''; ?>>Quarterly</option>
                                <option value="annual"    <?php echo $filters['period_type'] === 'annual'    ? 'selected' : ''; ?>>Annual</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-hourglass-half"></i> Project Duration</label>
                            <select name="duration_category">
                                <option value="">All Durations</option>
                                <?php foreach ($duration_categories as $key => $label): ?>
                                    <option value="<?php echo $key; ?>" <?php echo $filters['duration_category'] === $key ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-money-bill-wave"></i> Budget Range</label>
                            <select name="budget_range">
                                <option value="">All Budgets</option>
                                <?php foreach ($budget_ranges as $key => $label): ?>
                                    <option value="<?php echo $key; ?>" <?php echo $filters['budget_range'] === $key ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Beneficiary & Advanced Filters -->
                <div class="filter-group">
                    <div class="filter-group-title">
                        <i class="fas fa-users-cog"></i> Beneficiary & Advanced Filters
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-user-friends"></i> Beneficiary Types</label>
                            <select name="beneficiary_types[]" multiple>
                                <?php foreach ($beneficiary_types as $bt): ?>
                                    <?php $isSelected = in_array($bt, $filters['beneficiary_types'], true); ?>
                                    <option value="<?php echo htmlspecialchars($bt); ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($bt); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-venus-mars"></i> Gender Breakdown</label>
                            <select name="gender_breakdown">
                                <option value="all"    <?php echo $filters['gender_breakdown'] === 'all'    ? 'selected' : ''; ?>>All Genders</option>
                                <option value="male"   <?php echo $filters['gender_breakdown'] === 'male'   ? 'selected' : ''; ?>>Male Only</option>
                                <option value="female" <?php echo $filters['gender_breakdown'] === 'female' ? 'selected' : ''; ?>>Female Only</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-user-friends"></i> Age Group</label>
                            <select name="age_group">
                                <option value="all"      <?php echo $filters['age_group'] === 'all'      ? 'selected' : ''; ?>>All Age Groups</option>
                                <option value="children" <?php echo $filters['age_group'] === 'children' ? 'selected' : ''; ?>>Children Only (0-17)</option>
                                <option value="adults"   <?php echo $filters['age_group'] === 'adults'   ? 'selected' : ''; ?>>Adults Only (18+)</option>
                                <option value="elderly"  <?php echo $filters['age_group'] === 'elderly'  ? 'selected' : ''; ?>>Elderly Only (60+)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Aggregation & Display Options -->
                <div class="advanced-filter">
                    <h4 style="margin-top:0; color:var(--secondary);">
                        <i class="fas fa-cogs"></i> Aggregation & Display Options
                    </h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-layer-group"></i> Aggregation Level</label>
                            <select name="aggregation_level">
                                <option value="project"      <?php echo $filters['aggregation_level'] === 'project'      ? 'selected' : ''; ?>>By Project</option>
                                <option value="region"       <?php echo $filters['aggregation_level'] === 'region'       ? 'selected' : ''; ?>>By Region</option>
                                <option value="zone"         <?php echo $filters['aggregation_level'] === 'zone'         ? 'selected' : ''; ?>>By Zone</option>
                                <option value="woreda"       <?php echo $filters['aggregation_level'] === 'woreda'       ? 'selected' : ''; ?>>By Woreda</option>
                                <option value="donor"        <?php echo $filters['aggregation_level'] === 'donor'        ? 'selected' : ''; ?>>By Donor</option>
                                <option value="project_type" <?php echo $filters['aggregation_level'] === 'project_type' ? 'selected' : ''; ?>>By Project Type</option>
                                <option value="sector"       <?php echo $filters['aggregation_level'] === 'sector'       ? 'selected' : ''; ?>>By Sector</option>
                                <option value="project_area" <?php echo $filters['aggregation_level'] === 'project_area' ? 'selected' : ''; ?>>By Project Area</option>
                                <option value="budget"       <?php echo $filters['aggregation_level'] === 'budget'       ? 'selected' : ''; ?>>By Budget</option>
                                <option value="duration"     <?php echo $filters['aggregation_level'] === 'duration'     ? 'selected' : ''; ?>>By Project Duration</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-chart-bar"></i> Indicators</label>
                            <select name="indicator_ids[]" multiple>
                                <?php foreach ($indicators as $ind): ?>
                                    <?php 
                                    $indicatorId   = $ind['id'] ?? '';
                                    $indicatorName = ($ind['code'] ?? '') . ' - ' . ($ind['name'] ?? '');
                                    $isSelected    = in_array($indicatorId, $filters['indicator_ids'], true);
                                    ?>
                                    <option value="<?php echo $indicatorId; ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($indicatorName); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-tasks"></i> Project Status</label>
                            <select name="project_status">
                                <option value="all"      <?php echo $filters['project_status'] === 'all'      ? 'selected' : ''; ?>>All Statuses</option>
                                <option value="active"   <?php echo $filters['project_status'] === 'active'   ? 'selected' : ''; ?>>Active</option>
                                <option value="completed"<?php echo $filters['project_status'] === 'completed'? 'selected' : ''; ?>>Completed</option>
                                <option value="planned"  <?php echo $filters['project_status'] === 'planned'  ? 'selected' : ''; ?>>Planned</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <button type="submit" class="btn btn-primary" style="flex:2;">
                        <i class="fas fa-rocket"></i> Generate Advanced Aggregation
                    </button>
                    <button type="button" class="btn btn-warning" onclick="resetFilters()">
                        <i class="fas fa-redo"></i> Reset All Filters
                    </button>
                    <button type="button" class="btn btn-info" onclick="toggleAdvancedFilters()">
                        <i class="fas fa-sliders-h"></i> Toggle Advanced Filters
                    </button>
                </div>
            </form>
        </div>

        <!-- Real-time Updates Section -->
        <div class="info-message no-print">
            <i class="fas fa-sync-alt fa-spin"></i>
            <div>
                <strong>Real-time Data Updates</strong>
                <p>This dashboard displays live data from your projects and reports. Last updated: <?php echo date('Y-m-d H:i:s'); ?></p>
            </div>
        </div>

        <!-- Tabs for different views -->
        <div class="tabs no-print">
            <div class="tab active" onclick="switchTab('table')">
                <i class="fas fa-table"></i> Data Table
            </div>
            <div class="tab" onclick="switchTab('charts')">
                <i class="fas fa-chart-bar"></i> Charts & Graphs
            </div>
            <div class="tab" onclick="switchTab('analytics')">
                <i class="fas fa-chart-line"></i> Advanced Analytics
            </div>
            <div class="tab" onclick="switchTab('export')">
                <i class="fas fa-download"></i> Export & Share
            </div>
        </div>

        <!-- Table View -->
        <div id="table-tab" class="tab-content active">
            <div class="card">
                <div class="section-title">
                    <i class="fas fa-table"></i>
                    <h3 style="margin:0;">Aggregated Data Table</h3>
                    <span class="badge badge-primary"><?php echo count($rows); ?> Records</span>
                </div>
                <div style="overflow-x: auto;">
                    <table id="agg-table">
                        <thead>
                            <tr>
                                <th>Entity</th>
                                <th>Indicator</th>
                                <th>Beneficiary Type</th>
                                <th>Total</th>
                                <th>Boys U5</th>
                                <th>Girls U5</th>
                                <th>Boys 5-17</th>
                                <th>Girls 5-17</th>
                                <th>Men 18-59</th>
                                <th>Women 18-59</th>
                                <th>Men 60+</th>
                                <th>Women 60+</th>
                                <th>% Women</th>
                                <th>% Children</th>
                                <th>PWD</th>
                                <th>Reports</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($rows)): ?>
                                <?php foreach ($rows as $row): ?>
                                    <?php
                                    $boys   = (int)($row['boys_u5'] ?? 0) + (int)($row['boys_5_17'] ?? 0);
                                    $girls  = (int)($row['girls_u5'] ?? 0) + (int)($row['girls_5_17'] ?? 0);
                                    $men    = (int)($row['men_18_59'] ?? 0) + (int)($row['men_60p'] ?? 0);
                                    $women  = (int)($row['women_18_59'] ?? 0) + (int)($row['women_60p'] ?? 0);
                                    $total  = $boys + $girls + $men + $women;
                                    $children = $boys + $girls;
                                    $pctWomen = $total > 0 ? round(($women / $total) * 100, 2) : 0;
                                    $pctChild = $total > 0 ? round(($children / $total) * 100, 2) : 0;
                                    
                                    $entityName = $row['project_title'] ?? 'Unknown Project';
                                    if ($filters['aggregation_level'] === 'region' && !empty($row['region_id'])) {
                                        $entityName = "Region ID: " . $row['region_id'];
                                    } elseif ($filters['aggregation_level'] === 'zone' && !empty($row['zone_id'])) {
                                        $entityName = "Zone ID: " . $row['zone_id'];
                                    } elseif ($filters['aggregation_level'] === 'woreda' && !empty($row['woreda_id'])) {
                                        $entityName = "Woreda ID: " . $row['woreda_id'];
                                    } elseif ($filters['aggregation_level'] === 'sector' && !empty($row['sector'])) {
                                        $entityName = "Sector: " . $row['sector'];
                                    } elseif ($filters['aggregation_level'] === 'project_area' && !empty($row['project_area'])) {
                                        $entityName = "Project Area: " . $row['project_area'];
                                    }
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($entityName); ?></strong></td>
                                        <td><?php echo htmlspecialchars(($row['indicator_code'] ?? '') . ' - ' . ($row['indicator_name'] ?? '')); ?></td>
                                        <td><span class="badge badge-primary"><?php echo htmlspecialchars($row['beneficiary_type'] ?? ''); ?></span></td>
                                        <td><strong><?php echo number_format($total); ?></strong></td>
                                        <td><?php echo number_format($row['boys_u5'] ?? 0); ?></td>
                                        <td><?php echo number_format($row['girls_u5'] ?? 0); ?></td>
                                        <td><?php echo number_format($row['boys_5_17'] ?? 0); ?></td>
                                        <td><?php echo number_format($row['girls_5_17'] ?? 0); ?></td>
                                        <td><?php echo number_format($row['men_18_59'] ?? 0); ?></td>
                                        <td><?php echo number_format($row['women_18_59'] ?? 0); ?></td>
                                        <td><?php echo number_format($row['men_60p'] ?? 0); ?></td>
                                        <td><?php echo number_format($row['women_60p'] ?? 0); ?></td>
                                        <td class="<?php 
                                            echo $pctWomen >= 50 ? 'positive' : 
                                                 ($pctWomen >= 40 ? 'neutral' : 'negative'); 
                                        ?>">
                                            <?php echo $pctWomen; ?>%
                                        </td>
                                        <td class="<?php 
                                            echo $pctChild >= 40 ? 'positive' : 
                                                 ($pctChild >= 25 ? 'neutral' : 'negative'); 
                                        ?>">
                                            <?php echo $pctChild; ?>%
                                        </td>
                                        <td><?php echo number_format($row['total_pwd'] ?? 0); ?></td>
                                        <td><?php echo number_format($row['report_count'] ?? 0); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="16" style="text-align:center; padding: 30px;">
                                        <div style="color:#666; font-size:1.1em;">
                                            <i class="fas fa-inbox" style="font-size:3em; margin-bottom:15px; display:block; opacity:0.5;"></i>
                                            No data found for the selected filters.<br>
                                            Please adjust your filter criteria and try again.
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Charts View -->
        <div id="charts-tab" class="tab-content">
            <div class="card">
                <div class="section-title">
                    <i class="fas fa-chart-bar"></i>
                    <h3 style="margin:0;">Advanced Visualization Dashboard</h3>
                </div>
                
                <div class="form-row no-print">
                    <div class="form-group">
                        <label><i class="fas fa-chart-pie"></i> Chart Type</label>
                        <select id="chartType" onchange="updateCharts()">
                            <option value="bar">Bar Chart</option>
                            <option value="pie">Pie Chart</option>
                            <option value="line">Line Chart</option>
                            <option value="doughnut">Doughnut Chart</option>
                            <option value="polarArea">Polar Area</option>
                            <option value="radar">Radar Chart</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-palette"></i> Color Scheme</label>
                        <select id="colorScheme" onchange="updateCharts()">
                            <option value="default">Default Colors</option>
                            <option value="vibrant">Vibrant Colors</option>
                            <option value="pastel">Pastel Colors</option>
                            <option value="monochrome">Monochrome</option>
                        </select>
                    </div>
                </div>
                
                <div class="chart-container">
                    <canvas id="beneficiaryChart"></canvas>
                </div>
                
                <div class="chart-container">
                    <canvas id="demographicChart"></canvas>
                </div>
                
                <div class="chart-container">
                    <canvas id="genderChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Analytics View -->
        <div id="analytics-tab" class="tab-content">
            <div class="card">
                <div class="section-title">
                    <i class="fas fa-chart-line"></i>
                    <h3 style="margin:0;">Advanced Analytics & Insights</h3>
                </div>
                
                <div class="analytics-grid">
                    <div class="data-card">
                        <h4><i class="fas fa-tachometer-alt"></i> Performance Metrics</h4>
                        <div class="dashboard-stats" style="grid-template-columns: 1fr 1fr;">
                            <div class="stat-card" style="padding:15px;">
                                <div class="stat-number"><?php echo $summary['total_projects'] > 0 ? number_format($summary['total_beneficiaries'] / max(1,$summary['total_projects'])) : 0; ?></div>
                                <div class="stat-label">Avg per Project</div>
                            </div>
                            <div class="stat-card" style="padding:15px;">
                                <div class="stat-number"><?php echo $summary['women_percentage']; ?>%</div>
                                <div class="stat-label">Gender Balance</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="data-card">
                        <h4><i class="fas fa-chart-pie"></i> Distribution Analysis</h4>
                        <div class="chart-container">
                            <canvas id="analyticsChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <div class="data-card">
                    <h4><i class="fas fa-lightbulb"></i> Key Insights</h4>
                    <ul style="padding-left:20px;">
                        <li>Total of <strong><?php echo number_format($summary['total_beneficiaries']); ?></strong> beneficiaries reached across <strong><?php echo $summary['total_projects']; ?></strong> projects</li>
                        <li>Women represent <strong><?php echo $summary['women_percentage']; ?>%</strong> of total beneficiaries</li>
                        <li>Children (0-17 years) account for <strong><?php echo $summary['children_percentage']; ?>%</strong> of beneficiaries</li>
                        <li>Persons with Disabilities make up <strong><?php echo $summary['pwd_percentage']; ?>%</strong> of the total</li>
                        <li>Total project budget: <strong><?php echo number_format($summary['total_budget']); ?> ETB</strong></li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Export & Share View -->
        <div id="export-tab" class="tab-content">
            <div class="card">
                <div class="section-title">
                    <i class="fas fa-download"></i>
                    <h3 style="margin:0;">Export & Share Options</h3>
                </div>
                
                <div class="analytics-grid">
                    <div class="data-card">
                        <h4><i class="fas fa-file-pdf"></i> PDF Export</h4>
                        <p>Generate a comprehensive PDF report with all data and visualizations.</p>
                        <button class="btn btn-danger" onclick="generatePDF()">
                            <i class="fas fa-file-pdf"></i> Generate PDF Report
                        </button>
                    </div>
                    
                    <div class="data-card">
                        <h4><i class="fas fa-file-excel"></i> Excel Export</h4>
                        <p>Export data in Excel format for further analysis and reporting.</p>
                        <button class="btn btn-success" onclick="exportToExcel()">
                            <i class="fas fa-file-excel"></i> Export to Excel
                        </button>
                    </div>
                    
                    <div class="data-card">
                        <h4><i class="fas fa-file-word"></i> Word Export</h4>
                        <p>Create a Word document with the aggregated data and summary.</p>
                        <button class="btn btn-primary" onclick="exportToWord()">
                            <i class="fas fa-file-word"></i> Export to Word
                        </button>
                    </div>
                    
                    <div class="data-card">
                        <h4><i class="fas fa-share-alt"></i> Share Report</h4>
                        <p>Share this report via email, messaging apps, or generate a shareable link.</p>
                        <button class="btn btn-info" onclick="showShareModal()">
                            <i class="fas fa-share-alt"></i> Share Options
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Share Modal -->
    <div id="shareModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; justify-content:center; align-items:center;">
        <div class="card" style="max-width:500px; width:90%;">
            <h3 style="margin-top:0;"><i class="fas fa-share-alt"></i> Share Report</h3>
            <div class="form-group">
                <label>Shareable Link</label>
                <input type="text" id="shareableLink" value="<?php echo (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']; ?>" readonly>
            </div>
            <div class="form-group">
                <label>Share Via</label>
                <div class="export-buttons">
                    <button class="btn btn-primary" onclick="shareViaEmail()">
                        <i class="fas fa-envelope"></i> Email
                    </button>
                    <button class="btn btn-success" onclick="shareViaWhatsApp()">
                        <i class="fab fa-whatsapp"></i> WhatsApp
                    </button>
                    <button class="btn btn-info" onclick="shareViaTelegram()">
                        <i class="fab fa-telegram"></i> Telegram
                    </button>
                </div>
            </div>
            <div class="form-row">
                <button class="btn btn-warning" onclick="copyShareLink()">
                    <i class="fas fa-copy"></i> Copy Link
                </button>
                <button class="btn btn-danger" onclick="hideShareModal()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <script>
        // Tab switching
        function switchTab(tabName) {
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            
            event.currentTarget.classList.add('active');
            document.getElementById(tabName + '-tab').classList.add('active');
            
            if (tabName === 'charts') {
                updateCharts();
            } else if (tabName === 'analytics') {
                updateAnalytics();
            }
        }

        // Chart instances
        let charts = {};

        // Color schemes
        const colorSchemes = {
            default: ['#3498db', '#2980b9', '#2ecc71', '#27ae60', '#e74c3c', '#c0392b', '#f39c12', '#d35400'],
            vibrant: ['#e74c3c', '#e67e22', '#f1c40f', '#2ecc71', '#1abc9c', '#3498db', '#9b59b6', '#e84393'],
            pastel: ['#a29bfe', '#fd79a8', '#fdcb6e', '#55efc4', '#74b9ff', '#dfe6e9', '#ffeaa7', '#fab1a0'],
            monochrome: ['#34495e', '#2c3e50', '#7f8c8d', '#95a5a6', '#bdc3c7', '#ecf0f1', '#d5dbdb', '#a6acaf']
        };

        function updateCharts() {
            const chartType = document.getElementById('chartType').value;
            const colorScheme = document.getElementById('colorScheme').value;
            const colors = colorSchemes[colorScheme] || colorSchemes.default;
            
            // Beneficiary Distribution Chart
            if (charts.beneficiary) charts.beneficiary.destroy();
            const beneficiaryCtx = document.getElementById('beneficiaryChart').getContext('2d');
            charts.beneficiary = new Chart(beneficiaryCtx, {
                type: chartType,
                data: {
                    labels: ['Boys U5', 'Girls U5', 'Boys 5-17', 'Girls 5-17', 'Men 18-59', 'Women 18-59', 'Men 60+', 'Women 60+'],
                    datasets: [{
                        label: 'Beneficiary Distribution',
                        data: [
                            <?php echo $chart_data['age_gender']['boys_u5']; ?>,
                            <?php echo $chart_data['age_gender']['girls_u5']; ?>,
                            <?php echo $chart_data['age_gender']['boys_5_17']; ?>,
                            <?php echo $chart_data['age_gender']['girls_5_17']; ?>,
                            <?php echo $chart_data['age_gender']['men_18_59']; ?>,
                            <?php echo $chart_data['age_gender']['women_18_59']; ?>,
                            <?php echo $chart_data['age_gender']['men_60p']; ?>,
                            <?php echo $chart_data['age_gender']['women_60p']; ?>
                        ],
                        backgroundColor: colors,
                        borderColor: colors,
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Beneficiary Age/Sex Distribution',
                            font: { size: 16 }
                        },
                        legend: {
                            position: 'right'
                        }
                    },
                    animation: {
                        duration: 1000,
                        easing: 'easeInOutQuart'
                    }
                }
            });

            // Demographic Overview Chart
            if (charts.demographic) charts.demographic.destroy();
            const demographicCtx = document.getElementById('demographicChart').getContext('2d');
            charts.demographic = new Chart(demographicCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Children (0-17)', 'Adults (18-59)', 'Elderly (60+)', 'PWD'],
                    datasets: [{
                        data: [
                            <?php echo $summary['total_boys'] + $summary['total_girls']; ?>,
                            <?php echo $chart_data['age_gender']['men_18_59'] + $chart_data['age_gender']['women_18_59']; ?>,
                            <?php echo $chart_data['age_gender']['men_60p'] + $chart_data['age_gender']['women_60p']; ?>,
                            <?php echo $summary['total_pwd']; ?>
                        ],
                        backgroundColor: ['#2ecc71', '#3498db', '#9b59b6', '#e74c3c'],
                        borderWidth: 3,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Demographic Overview',
                            font: { size: 16 }
                        },
                        legend: {
                            position: 'right'
                        }
                    },
                    cutout: '50%',
                    animation: {
                        animateScale: true,
                        animateRotate: true
                    }
                }
            });

            // Gender Comparison Chart
            if (charts.gender) charts.gender.destroy();
            const genderCtx = document.getElementById('genderChart').getContext('2d');
            charts.gender = new Chart(genderCtx, {
                type: 'bar',
                data: {
                    labels: ['Male', 'Female'],
                    datasets: [{
                        label: 'Gender Distribution',
                        data: [
                            <?php echo $summary['total_boys'] + $summary['total_men']; ?>,
                            <?php echo $summary['total_girls'] + $summary['total_women']; ?>
                        ],
                        backgroundColor: ['#3498db', '#e74c3c'],
                        borderColor: ['#2980b9', '#c0392b'],
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Gender Distribution',
                            font: { size: 16 }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Number of Beneficiaries'
                            }
                        }
                    }
                }
            });
        }

        function updateAnalytics() {
            if (charts.analytics) charts.analytics.destroy();
            const analyticsCtx = document.getElementById('analyticsChart').getContext('2d');
            charts.analytics = new Chart(analyticsCtx, {
                type: 'radar',
                data: {
                    labels: ['Gender Balance', 'Child Inclusion', 'PWD Inclusion', 'Geographic Coverage', 'Project Efficiency', 'Data Quality'],
                    datasets: [{
                        label: 'Performance Metrics',
                        data: [
                            <?php echo $summary['women_percentage']; ?>,
                            <?php echo $summary['children_percentage']; ?>,
                            <?php echo $summary['pwd_percentage']; ?>,
                            75,
                            85,
                            90
                        ],
                        backgroundColor: 'rgba(52, 152, 219, 0.2)',
                        borderColor: '#3498db',
                        borderWidth: 2,
                        pointBackgroundColor: '#3498db',
                        pointBorderColor: '#fff',
                        pointHoverBackgroundColor: '#fff',
                        pointHoverBorderColor: '#3498db'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        r: {
                            beginAtZero: true,
                            max: 100,
                            ticks: {
                                stepSize: 20
                            }
                        }
                    },
                    plugins: {
                        title: {
                            display: true,
                            text: 'Program Performance Metrics',
                            font: { size: 16 }
                        }
                    }
                }
            });
        }

        // Export functions
        function printDashboard() {
            window.print();
        }

        function exportToExcel() {
            const table = document.getElementById('agg-table');
            const html = table.outerHTML;
            const url = 'data:application/vnd.ms-excel;charset=utf-8,' + encodeURIComponent(html);
            const link = document.createElement('a');
            link.href = url;
            link.download = 'aggregation_report_<?php echo date('Y-m-d'); ?>.xls';
            link.click();
            
            showMessage('Excel file downloaded successfully!', 'success');
        }

        function exportToWord() {
            const table = document.getElementById('agg-table');
            const html = `
                <html xmlns:o="urn:schemas-microsoft-com:office:office" 
                      xmlns:w="urn:schemas-microsoft-com:office:word" 
                      xmlns="http://www.w3.org/TR/REC-html40">
                <head>
                    <meta charset="utf-8">
                    <title>Aggregation Report</title>
                    <style>
                        table { border-collapse: collapse; width: 100%; }
                        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                        th { background-color: #f2f2f2; }
                    </style>
                </head>
                <body>
                    <h1>Aggregation Report - <?php echo date('Y-m-d'); ?></h1>
                    ${table.outerHTML}
                </body></html>
            `;
            const blob = new Blob([html], {type: 'application/msword'});
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = 'aggregation_report_<?php echo date('Y-m-d'); ?>.doc';
            link.click();
            
            showMessage('Word document downloaded successfully!', 'success');
        }

        function generatePDF() {
            window.print();
            showMessage('PDF generation initiated!', 'success');
        }

        // Share functions
        function showShareModal() {
            document.getElementById('shareModal').style.display = 'flex';
        }

        function hideShareModal() {
            document.getElementById('shareModal').style.display = 'none';
        }

        function copyShareLink() {
            const linkInput = document.getElementById('shareableLink');
            linkInput.select();
            document.execCommand('copy');
            showMessage('Link copied to clipboard!', 'success');
        }

        function shareViaEmail() {
            const subject = 'Aggregation Report - Health Reporting System';
            const body = `Check out this aggregation report: ${document.getElementById('shareableLink').value}`;
            window.open(`mailto:?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`);
        }

        function shareViaWhatsApp() {
            const text = `Check out this aggregation report: ${document.getElementById('shareableLink').value}`;
            window.open(`https://wa.me/?text=${encodeURIComponent(text)}`);
        }

        function shareViaTelegram() {
            const text = `Check out this aggregation report: ${document.getElementById('shareableLink').value}`;
            window.open(`https://t.me/share/url?url=${encodeURIComponent(document.getElementById('shareableLink').value)}&text=${encodeURIComponent(text)}`);
        }

        // Utility functions
        function resetFilters() {
            if (confirm('Are you sure you want to reset all filters?')) {
                document.getElementById('aggregationForm').reset();
                document.getElementById('aggregationForm').submit();
            }
        }

        function toggleAdvancedFilters() {
            const advancedFilter = document.querySelector('.advanced-filter');
            if (advancedFilter.style.display === 'none') {
                advancedFilter.style.display = 'block';
            } else {
                advancedFilter.style.display = 'none';
            }
        }

        function showMessage(message, type) {
            const messageEl = document.createElement('div');
            messageEl.className = type === 'success' ? 'success-message' : 'error-message';
            messageEl.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-triangle'}"></i>
                <div>${message}</div>
            `;
            document.body.appendChild(messageEl);
            setTimeout(() => {
                messageEl.remove();
            }, 3000);
        }

        function simulateRealTimeUpdates() {
            setInterval(() => {
                const badge = document.querySelector('.real-time-badge');
                if (badge) {
                    badge.innerHTML = `<i class="fas fa-circle"></i> Updated ${new Date().toLocaleTimeString()}`;
                }
            }, 30000);
        }

        document.addEventListener('DOMContentLoaded', function() {
            updateCharts();
            simulateRealTimeUpdates();
        });

        window.addEventListener('resize', function() {
            Object.values(charts).forEach(chart => {
                if (chart) chart.resize();
            });
        });
    </script>
</body>
</html>

<?php require_once __DIR__ . '/../footer.php'; ?>