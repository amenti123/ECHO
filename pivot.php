<?php
require_once __DIR__ . '/../header.php';
require_login();
$pdo = getPDO();

// -----------------------------------------------------
// Small helper – check if column exists in a table
// -----------------------------------------------------
if (!function_exists('table_has_column')) {
    function table_has_column(PDO $pdo, string $table, string $column): bool {
        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
            $stmt->execute([$column]);
            return (bool)$stmt->fetch();
        } catch (Exception $e) {
            error_log("table_has_column error: " . $e->getMessage());
            return false;
        }
    }
}

// Normalize multi-select GET params (array or comma-separated string)
if (!function_exists('normalize_multi')) {
    function normalize_multi($value): array {
        if (!is_array($value) && $value !== '' && $value !== null) {
            $value = explode(',', $value);
        }
        $value = (array)$value;
        $out = [];
        foreach ($value as $v) {
            $v = trim((string)$v);
            if ($v !== '') {
                $out[] = $v;
            }
        }
        return $out;
    }
}

/**
 * Detect preferred project title column so pivot, projects.php and enter_data.php
 * all speak the same “project title”.
 */
$pivotProjectTitleColumn = null;
try {
    if (table_has_column($pdo, 'projects', 'title')) {
        $pivotProjectTitleColumn = 'title';
    } elseif (table_has_column($pdo, 'projects', 'project_title')) {
        $pivotProjectTitleColumn = 'project_title';
    } elseif (table_has_column($pdo, 'projects', 'name')) {
        $pivotProjectTitleColumn = 'name';
    }
} catch (Exception $e) {
    error_log("Pivot project title column detection failed: " . $e->getMessage());
}

// Detect preferred project code column
$pivotProjectCodeColumn = null;
try {
    if (table_has_column($pdo, 'projects', 'project_code')) {
        $pivotProjectCodeColumn = 'project_code';
    } elseif (table_has_column($pdo, 'projects', 'code')) {
        $pivotProjectCodeColumn = 'code';
    }
} catch (Exception $e) {
    error_log("Pivot project code column detection failed: " . $e->getMessage());
}

// Other project/report structure
$hasYearCol             = table_has_column($pdo, 'reports',  'year');
$hasMonthCol            = table_has_column($pdo, 'reports',  'month');
$hasStartDate           = table_has_column($pdo, 'reports',  'start_date');
$reportsHasPeriodType   = table_has_column($pdo, 'reports',  'period_type');
$projHasRegion          = table_has_column($pdo, 'projects', 'region_id');
$projHasZone            = table_has_column($pdo, 'projects', 'zone_id');
$projHasWoreda          = table_has_column($pdo, 'projects', 'woreda_id');
$projHasTitle           = $pivotProjectTitleColumn !== null;
$projHasProjectTypeId   = table_has_column($pdo, 'projects', 'project_type_id');
$projHasSectorCol       = table_has_column($pdo, 'projects', 'sector');
$projHasProjectAreaCol  = table_has_column($pdo, 'projects', 'project_area');
$projHasDonorIdCol      = table_has_column($pdo, 'projects', 'donor_id');
$projHasStatusCol       = table_has_column($pdo, 'projects', 'status');
$rvHasBeneficiaryTypeCol = table_has_column($pdo, 'report_values', 'beneficiary_type');

// -----------------------------------------------------
// Cross-app data helpers (shared with projects/planning/enter_data)
// -----------------------------------------------------

// Projects list – used by filters
if (!function_exists('get_projects')) {
    function get_projects() {
        $pdo = getPDO();
        global $pivotProjectTitleColumn, $pivotProjectCodeColumn;

        try {
            if ($pivotProjectTitleColumn) {
                $orderExpr = "p.`{$pivotProjectTitleColumn}`";
            } elseif ($pivotProjectCodeColumn) {
                $orderExpr = "p.`{$pivotProjectCodeColumn}`";
            } else {
                $orderExpr = "p.id";
            }

            $sql = "
                SELECT 
                    p.*,
                    r.name AS region_name,
                    z.name AS zone_name,
                    w.name AS woreda_name
                FROM projects p
                LEFT JOIN regions r ON r.id = p.region_id
                LEFT JOIN zones   z ON z.id = p.zone_id
                LEFT JOIN woredas w ON w.id = p.woreda_id
                ORDER BY {$orderExpr}
            ";
            $stmt = $pdo->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('get_projects() failed in pivot.php: ' . $e->getMessage());
            return [];
        }
    }
}

// Regions list – used by filters and geo pivot
if (!function_exists('get_regions')) {
    function get_regions() {
        $pdo = getPDO();
        try {
            $stmt = $pdo->query("SELECT id, name FROM regions ORDER BY name");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('get_regions() failed in pivot.php: ' . $e->getMessage());
            return [];
        }
    }
}

// Indicators list – keep consistent with enter_data.php
if (!function_exists('get_indicators')) {
    function get_indicators() {
        $pdo = getPDO();
        try {
            $stmt = $pdo->query("SELECT id, code, name, unit_type FROM indicators ORDER BY code");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('get_indicators() failed in pivot.php: ' . $e->getMessage());
            return [];
        }
    }
}

// Donors list – from donors table, else default global/Ethiopia list
if (!function_exists('get_donors')) {
    function get_donors() {
        $pdo = getPDO();
        try {
            $stmt = $pdo->query("SELECT id, name FROM donors ORDER BY name");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Donors table not found, using default donors in pivot.php: " . $e->getMessage());
            return [
                ['id' => 1, 'name' => 'UNICEF'],
                ['id' => 2, 'name' => 'WHO'],
                ['id' => 3, 'name' => 'World Bank'],
                ['id' => 4, 'name' => 'USAID'],
                ['id' => 5, 'name' => 'DFID'],
                ['id' => 6, 'name' => 'EU'],
                ['id' => 7, 'name' => 'Government of Ethiopia'],
                ['id' => 8, 'name' => 'GIZ'],
                ['id' => 9, 'name' => 'Other'],
            ];
        }
    }
}

// Project types – from table, else default 4 types
if (!function_exists('get_project_types')) {
    function get_project_types() {
        $pdo = getPDO();
        try {
            $stmt = $pdo->query("SELECT id, name FROM project_types ORDER BY name");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Project types table not found, using default types in pivot.php: " . $e->getMessage());
            return [
                ['id' => 1, 'name' => 'Emergency'],
                ['id' => 2, 'name' => 'Development'],
                ['id' => 3, 'name' => 'Resilience/Recovery'],
                ['id' => 4, 'name' => 'Outbreak Response'],
            ];
        }
    }
}

// Main programme sectors – distinct from projects, else default cluster list
if (!function_exists('get_sectors')) {
    function get_sectors() {
        $pdo = getPDO();
        try {
            $stmt = $pdo->query("SELECT DISTINCT sector FROM projects WHERE sector IS NOT NULL AND sector != '' ORDER BY sector");
            $sectors = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($sectors)) return $sectors;
        } catch (Exception $e) {
            error_log("get_sectors() fallback in pivot.php: " . $e->getMessage());
        }

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
            'Child Protection',
            'MHPSS',
            'SRH / Family Planning',
            'CVA',
            'DRR / Resilience',
            'Social Protection',
            'HIV/AIDS, TB, Malaria',
            'Peacebuilding / Social Cohesion',
            'Multi-sector',
            'Other'
        ];
    }
}

// Project specific areas – distinct from projects, else default list
if (!function_exists('get_project_areas')) {
    function get_project_areas() {
        $pdo = getPDO();
        try {
            $stmt = $pdo->query("SELECT DISTINCT project_area FROM projects WHERE project_area IS NOT NULL AND project_area != '' ORDER BY project_area");
            $areas = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($areas)) return $areas;
        } catch (Exception $e) {
            error_log("get_project_areas() fallback in pivot.php: " . $e->getMessage());
        }

        return [
            'Mobile Health and Nutrition Team (MHNT)',
            'Primary Health Care (PHC)',
            'Cholera / Outbreak Response',
            'SAM Treatment (OTP / SC)',
            'IYCF-E',
            'SRH / Family Planning',
            'Hygiene Promotion',
            'Water Supply & Treatment',
            'Sanitation Infrastructure',
            'Shelter Kits / ES-NFI',
            'Protection Monitoring',
            'GBV Response & Risk Mitigation',
            'Child Protection in Emergencies',
            'Community-based MHPSS',
            'Peacebuilding Dialogues',
            'Livelihoods / IGAs',
            'Cash Transfers',
            'Education in Emergencies',
            'Capacity Building / Training',
            'Systems Strengthening',
            'Other'
        ];
    }
}

// Beneficiary types – distinct from report_values, else default list
if (!function_exists('get_beneficiary_types')) {
    function get_beneficiary_types() {
        $pdo = getPDO();
        try {
            $stmt = $pdo->query("SELECT DISTINCT beneficiary_type FROM report_values WHERE beneficiary_type IS NOT NULL AND beneficiary_type != '' ORDER BY beneficiary_type");
            $types = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($types)) return $types;
        } catch (Exception $e) {
            error_log("get_beneficiary_types() fallback in pivot.php: " . $e->getMessage());
        }

        return [
            'General',
            'Vulnerable',
            'IDP',
            'Refugee',
            'Host Community',
            'PWD',
            'Children',
            'Women',
            'Elderly'
        ];
    }
}

// -----------------------------------------------------
// Real-time messaging system
// -----------------------------------------------------
function showLiveMessage($type, $message) {
    $icons = [
        'success' => '✓',
        'info' => 'ⓘ',
        'warning' => '⚠',
        'error' => '✗'
    ];
    $icon = $icons[$type] ?? 'ⓘ';
    return "<div class='live-message live-{$type}' data-type='{$type}'>
                <span class='live-icon'>{$icon}</span>
                <span class='live-text'>{$message}</span>
                <span class='live-time'>" . date('H:i:s') . "</span>
            </div>";
}

// -----------------------------------------------------
// Year / month expressions (from reports table)
// -----------------------------------------------------
if ($hasYearCol) {
    $yearExpr = 'r.year';
} elseif ($hasStartDate) {
    $yearExpr = 'YEAR(r.start_date)';
} else {
    $yearExpr = '0';
}

if ($hasMonthCol) {
    $monthExpr = 'r.month';
} elseif ($hasStartDate) {
    $monthExpr = 'MONTH(r.start_date)';
} else {
    $monthExpr = '0';
}

// -----------------------------------------------------
// Filters + pivot configuration from GET
// -----------------------------------------------------
$projects          = get_projects();
$regions           = get_regions();
$indicators        = get_indicators();
$donors            = get_donors();
$projectTypes      = get_project_types();
$sectors           = get_sectors();
$projectAreas      = get_project_areas();
$beneficiaryTypes  = get_beneficiary_types();

$rowDimInput  = $_GET['row_dim']    ?? 'woreda';
$colDim       = $_GET['col_dim']    ?? 'year_month';
$valueType    = $_GET['value_type'] ?? 'sadd';
$chartType    = $_GET['chart_type'] ?? 'bar';
$theme        = $_GET['theme']      ?? 'light';

$filterProject        = $_GET['project_id']        ?? '';
$filterRegion         = $_GET['region_id']        ?? '';
$filterYear           = $_GET['year']             ?? '';
$filterIndicator      = $_GET['indicator_id']     ?? '';
$filterFrequency      = $_GET['frequency']        ?? '';
$filterDonorIds       = normalize_multi($_GET['donor_ids']       ?? []);
$filterProjectTypeIds = normalize_multi($_GET['project_type_ids'] ?? []);
$filterSectors        = normalize_multi($_GET['sectors']         ?? []);
$filterProjectAreas   = normalize_multi($_GET['project_areas']    ?? []);
$filterBenTypes       = normalize_multi($_GET['beneficiary_types'] ?? []);

// Cast numeric filters
$filterDonorIds       = array_map('intval', $filterDonorIds);
$filterProjectTypeIds = array_map('intval', $filterProjectTypeIds);

// If user asks for geo-based rows but projects table lacks those columns, fallback
$rowDim = $rowDimInput;
if ($rowDim === 'woreda' && !$projHasWoreda) {
    $rowDim = $projHasZone ? 'zone' : ($projHasRegion ? 'region' : 'all');
}
if ($rowDim === 'zone' && !$projHasZone) {
    $rowDim = $projHasRegion ? 'region' : 'all';
}
if ($rowDim === 'region' && !$projHasRegion) {
    $rowDim = 'all';
}

// -----------------------------------------------------
// Map row & column dimensions to SQL expressions
// -----------------------------------------------------
$rowIcon  = '🌐';
$rowLabel = 'All';
$rowExpr  = "'All Nexus'";

switch ($rowDim) {
    case 'region':
        if ($projHasRegion) {
            $rowExpr  = 'rg.name';
            $rowLabel = 'Region';
            $rowIcon  = '🏢';
        }
        break;
    case 'zone':
        if ($projHasZone) {
            $rowExpr  = 'z.name';
            $rowLabel = 'Zone';
            $rowIcon  = '🗺️';
        }
        break;
    case 'woreda':
        if ($projHasWoreda) {
            $rowExpr  = 'w.name';
            $rowLabel = 'Woreda';
            $rowIcon  = '📍';
        }
        break;
    case 'indicator':
        $rowExpr  = 'i.code';
        $rowLabel = 'Indicator';
        $rowIcon  = '📊';
        break;
    case 'project':
        if ($projHasTitle && $pivotProjectTitleColumn) {
            if ($pivotProjectCodeColumn) {
                $rowExpr = "CONCAT(COALESCE(p.`{$pivotProjectCodeColumn}`, ''), ' - ', p.`{$pivotProjectTitleColumn}`)";
            } else {
                $rowExpr = "p.`{$pivotProjectTitleColumn}`";
            }
        } else {
            $rowExpr = "CONCAT('Project #', p.id)";
        }
        $rowLabel = 'Project';
        $rowIcon  = '🚀';
        break;
    case 'project_code':
        if ($pivotProjectCodeColumn) {
            $rowExpr  = "p.`{$pivotProjectCodeColumn}`";
            $rowLabel = 'Project Code';
            $rowIcon  = '🧾';
        }
        break;
    case 'project_type':
        if ($projHasProjectTypeId) {
            // We’ll map ID -> name in PHP
            $rowExpr  = "p.project_type_id";
            $rowLabel = 'Project Type';
            $rowIcon  = '📂';
        }
        break;
    case 'status':
        if ($projHasStatusCol) {
            $rowExpr  = "p.status";
            $rowLabel = 'Project Status';
            $rowIcon  = '🔖';
        }
        break;
    case 'donor':
        if ($projHasDonorIdCol) {
            // We’ll map ID -> donor name in PHP
            $rowExpr  = "p.donor_id";
            $rowLabel = 'Donor';
            $rowIcon  = '💰';
        }
        break;
    case 'sector':
        if ($projHasSectorCol) {
            $rowExpr  = "p.sector";
            $rowLabel = 'Programme Sector';
            $rowIcon  = '🧩';
        }
        break;
    case 'project_area':
        if ($projHasProjectAreaCol) {
            $rowExpr  = "p.project_area";
            $rowLabel = 'Project Specific Area';
            $rowIcon  = '🎯';
        }
        break;
    case 'beneficiary_type':
        if ($rvHasBeneficiaryTypeCol) {
            $rowExpr  = "rv.beneficiary_type";
            $rowLabel = 'Beneficiary Type';
            $rowIcon  = '🧍‍♀️';
        }
        break;
    case 'frequency':
        if ($reportsHasPeriodTypeCol) {
            $rowExpr  = "r.period_type";
            $rowLabel = 'Reporting Frequency';
            $rowIcon  = '⏱️';
        }
        break;
    case 'all':
    default:
        $rowExpr  = "'All Nexus'";
        $rowLabel = 'All';
        $rowIcon  = '🌐';
        break;
}

// Column dimension (time)
switch ($colDim) {
    case 'year':
        $colExpr  = $yearExpr;
        $colLabel = 'Year';
        $colIcon  = '📅';
        break;
    case 'month':
        $colExpr  = $monthExpr;
        $colLabel = 'Month';
        $colIcon  = '📆';
        break;
    case 'year_month':
    default:
        $colExpr  = "CONCAT($yearExpr, '-', LPAD($monthExpr, 2, '0'))";
        $colLabel = 'Year-Month';
        $colIcon  = '🗓️';
        break;
}

// value expression
$valueExprSadd   = "SUM(rv.boys_u5 + rv.girls_u5 + rv.boys_5_17 + rv.girls_5_17 + rv.men_18_59 + rv.women_18_59 + rv.men_60p + rv.women_60p)";
$valueExprPwd    = "SUM(rv.pwd_count)";
$valueExprNonBen = "SUM(rv.non_beneficiary)";

switch ($valueType) {
    case 'pwd':
        $valueName = 'Total PWD';
        $valueIcon = '♿';
        break;
    case 'non_ben':
        $valueName = 'Total Non-beneficiaries';
        $valueIcon = '👥';
        break;
    case 'sadd':
    default:
        $valueName = 'Total SADD (excl. PWD)';
        $valueIcon = '👨‍👩‍👧‍👦';
        break;
}

// Build donor / project type maps for labels
$donorMap = [];
foreach ($donors as $d) {
    if (isset($d['id'])) {
        $donorMap[(string)$d['id']] = $d['name'] ?? ('Donor #' . $d['id']);
    }
}
$projectTypeMap = [];
foreach ($projectTypes as $pt) {
    if (isset($pt['id'])) {
        $projectTypeMap[(string)$pt['id']] = $pt['name'] ?? ('Type #' . $pt['id']);
    }
}

// -----------------------------------------------------
// Build main SQL for pivot (cross-communicating with enter_data.php data)
// -----------------------------------------------------
$sql = "
    SELECT
        $rowExpr AS row_key,
        $colExpr AS col_key,
        $valueExprSadd   AS value_sadd,
        $valueExprPwd    AS value_pwd,
        $valueExprNonBen AS value_non_ben
    FROM reports r
    JOIN report_values rv ON rv.report_id = r.id
    JOIN indicators i     ON i.id = rv.indicator_id
    LEFT JOIN projects p  ON p.id = r.project_id
";

if ($projHasRegion) {
    $sql .= "\n    LEFT JOIN regions rg ON rg.id = p.region_id";
}
if ($projHasZone) {
    $sql .= "\n    LEFT JOIN zones z ON z.id = p.zone_id";
}
if ($projHasWoreda) {
    $sql .= "\n    LEFT JOIN woredas w ON w.id = p.woreda_id";
}

$sql .= "\n    WHERE 1=1";
$params = [];

// Basic filters
if ($filterProject !== '') {
    $sql      .= " AND r.project_id = ?";
    $params[] = $filterProject;
}
if ($filterRegion !== '' && $projHasRegion) {
    $sql      .= " AND p.region_id = ?";
    $params[] = $filterRegion;
}
if ($filterIndicator !== '') {
    $sql      .= " AND rv.indicator_id = ?";
    $params[] = $filterIndicator;
}
if ($filterYear !== '') {
    if ($hasYearCol) {
        $sql      .= " AND r.year = ?";
        $params[] = $filterYear;
    } elseif ($hasStartDate) {
        $sql      .= " AND YEAR(r.start_date) = ?";
        $params[] = $filterYear;
    }
}

// Frequency (period_type from reports)
if ($filterFrequency !== '' && $reportsHasPeriodTypeCol) {
    $sql      .= " AND r.period_type = ?";
    $params[] = $filterFrequency;
}

// Donors (multi-select)
if (!empty($filterDonorIds) && $projHasDonorIdCol) {
    $placeholders = implode(',', array_fill(0, count($filterDonorIds), '?'));
    $sql         .= " AND p.donor_id IN ($placeholders)";
    $params       = array_merge($params, $filterDonorIds);
}

// Project types (multi-select)
if (!empty($filterProjectTypeIds) && $projHasProjectTypeId) {
    $placeholders = implode(',', array_fill(0, count($filterProjectTypeIds), '?'));
    $sql         .= " AND p.project_type_id IN ($placeholders)";
    $params       = array_merge($params, $filterProjectTypeIds);
}

// Sectors (multi-select)
if (!empty($filterSectors) && $projHasSectorCol) {
    $placeholders = implode(',', array_fill(0, count($filterSectors), '?'));
    $sql         .= " AND p.sector IN ($placeholders)";
    $params       = array_merge($params, $filterSectors);
}

// Project specific areas (multi-select)
if (!empty($filterProjectAreas) && $projHasProjectAreaCol) {
    $placeholders = implode(',', array_fill(0, count($filterProjectAreas), '?'));
    $sql         .= " AND p.project_area IN ($placeholders)";
    $params       = array_merge($params, $filterProjectAreas);
}

// Beneficiary types (multi-select)
if (!empty($filterBenTypes) && $rvHasBeneficiaryTypeCol) {
    $placeholders = implode(',', array_fill(0, count($filterBenTypes), '?'));
    $sql         .= " AND rv.beneficiary_type IN ($placeholders)";
    $params       = array_merge($params, $filterBenTypes);
}

$sql .= "
    GROUP BY row_key, col_key
    ORDER BY row_key, col_key
    LIMIT 5000
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// -----------------------------------------------------
// Build matrix (rows x cols)
// -----------------------------------------------------
$matrix    = [];
$rowLabels = [];
$colLabels = [];
$totals    = ['sadd' => 0, 'pwd' => 0, 'non_ben' => 0];

foreach ($data as $r) {
    $rKey = $r['row_key'] ?? 'All Nexus';
    $cKey = $r['col_key'] ?? 'Unknown';

    $rowLabels[$rKey] = true;
    $colLabels[$cKey] = true;

    if (!isset($matrix[$rKey])) {
        $matrix[$rKey] = [];
    }
    if (!isset($matrix[$rKey][$cKey])) {
        $matrix[$rKey][$cKey] = [
            'sadd'    => 0,
            'pwd'     => 0,
            'non_ben' => 0,
        ];
    }

    $matrix[$rKey][$cKey]['sadd']    += (int)$r['value_sadd'];
    $matrix[$rKey][$cKey]['pwd']     += (int)$r['value_pwd'];
    $matrix[$rKey][$cKey]['non_ben'] += (int)$r['value_non_ben'];

    $totals['sadd']    += (int)$r['value_sadd'];
    $totals['pwd']     += (int)$r['value_pwd'];
    $totals['non_ben'] += (int)$r['value_non_ben'];
}

ksort($rowLabels);
ksort($colLabels);

// -----------------------------------------------------
// Prepare data for charts
// -----------------------------------------------------
$chartData   = [];
$chartLabels = array_keys($colLabels);

foreach ($rowLabels as $rowKey => $_) {
    $rowData = [];
    foreach ($colLabels as $colKey => $_2) {
        $cell = $matrix[$rowKey][$colKey] ?? ['sadd' => 0, 'pwd' => 0, 'non_ben' => 0];
        switch ($valueType) {
            case 'pwd':
                $rowData[] = $cell['pwd'];
                break;
            case 'non_ben':
                $rowData[] = $cell['non_ben'];
                break;
            case 'sadd':
            default:
                $rowData[] = $cell['sadd'];
                break;
        }
    }
    $chartData[$rowKey] = $rowData;
}

// -----------------------------------------------------
// CSV export
// -----------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $rows = [];

    // Header row
    $header = [$rowLabel . ' \\ ' . $colLabel];
    foreach ($colLabels as $c => $_) {
        $header[] = $c;
    }
    $rows[] = $header;

    // Data rows
    foreach ($rowLabels as $rKey => $_) {
        $row = [$rKey];
        foreach ($colLabels as $cKey => $_2) {
            $cell = $matrix[$rKey][$cKey] ?? ['sadd' => 0, 'pwd' => 0, 'non_ben' => 0];
            switch ($valueType) {
                case 'pwd':
                    $val = $cell['pwd'];
                    break;
                case 'non_ben':
                    $val = $cell['non_ben'];
                    break;
                case 'sadd':
                default:
                    $val = $cell['sadd'];
                    break;
            }
            $row[] = $val;
        }
        $rows[] = $row;
    }

    // Nexus total row
    if ($rowLabels && $colLabels) {
        $totalRow = ['All Nexus'];
        foreach ($colLabels as $cKey => $_2) {
            $sum = 0;
            foreach ($rowLabels as $rKey => $_3) {
                $cell = $matrix[$rKey][$cKey] ?? ['sadd' => 0, 'pwd' => 0, 'non_ben' => 0];
                switch ($valueType) {
                    case 'pwd':
                        $sum += $cell['pwd'];
                        break;
                    case 'non_ben':
                        $sum += $cell['non_ben'];
                        break;
                    case 'sadd':
                    default:
                        $sum += $cell['sadd'];
                        break;
                }
            }
            $totalRow[] = $sum;
        }
        $rows[] = $totalRow;
    }

    array_to_csv_download($rows, 'pivot_export.csv');
    // exits in helper
}

?>
<!DOCTYPE html>
<html lang="en" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advanced Pivot Analysis - Nexus</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2ecc71;
            --accent-color: #e74c3c;
            --bg-color: #f8f9fa;
            --card-bg: #ffffff;
            --text-color: #2c3e50;
            --border-color: #dee2e6;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
        }

        [data-theme="dark"] {
            --bg-color: #1a1a1a;
            --card-bg: #2d2d2d;
            --text-color: #e0e0e0;
            --border-color: #444;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            margin: 0;
            padding: 0;
            transition: all 0.3s ease;
        }

        .card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 20px;
            margin: 15px 0;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
        }

        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }

        .stat-card {
            background: linear-gradient(135deg, var(--primary-color), #2980b9);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }

        .stat-card h3 {
            margin: 0 0 10px 0;
            font-size: 14px;
            opacity: 0.9;
        }

        .stat-card .value {
            font-size: 24px;
            font-weight: bold;
            margin: 5px 0;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 15px;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        label {
            font-weight: 600;
            margin-bottom: 5px;
            font-size: 14px;
        }

        select, input, button {
            padding: 8px 10px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 13px;
        }

        select[multiple] {
            min-height: 80px;
        }

        .btn {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn:hover {
            opacity: 0.9;
            transform: translateY(-2px);
        }

        .btn-success {
            background: var(--success-color);
        }

        .btn-warning {
            background: var(--warning-color);
        }

        .btn-danger {
            background: var(--danger-color);
        }

        .tab-container {
            margin: 20px 0;
        }

        .tabs {
            display: flex;
            border-bottom: 2px solid var(--border-color);
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .tab {
            padding: 8px 18px;
            cursor: pointer;
            border: none;
            background: none;
            color: var(--text-color);
            font-weight: 600;
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
            font-size: 14px;
        }

        .tab.active {
            border-bottom-color: var(--primary-color);
            color: var(--primary-color);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .table-container {
            overflow-x: auto;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            background: var(--card-bg);
        }

        .table th, .table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
            font-size: 13px;
        }

        .table th {
            background: var(--primary-color);
            color: white;
            font-weight: 600;
            position: sticky;
            top: 0;
        }

        .table tr:hover {
            background: rgba(52, 152, 219, 0.1);
        }

        .chart-container {
            position: relative;
            height: 450px;
            margin: 20px 0;
        }

        .live-messages {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            max-width: 400px;
        }

        .live-message {
            padding: 12px;
            margin: 10px 0;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.5s ease;
            font-size: 13px;
        }

        .live-success { background: var(--success-color); color: white; }
        .live-info { background: var(--primary-color); color: white; }
        .live-warning { background: var(--warning-color); color: white; }
        .live-error { background: var(--danger-color); color: white; }

        .live-icon { font-weight: bold; }
        .live-time { font-size: 11px; opacity: 0.8; margin-left: auto; }

        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        .toolbar {
            display: flex;
            gap: 10px;
            margin: 15px 0;
            flex-wrap: wrap;
        }

        .theme-toggle {
            background: none;
            border: 2px solid var(--border-color);
            padding: 6px 10px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 18px;
        }

        .export-options {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .dimension-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: var(--primary-color);
            color: white;
            padding: 4px 9px;
            border-radius: 20px;
            font-size: 11px;
            margin: 0 5px;
        }

        .loading {
            display: inline-block;
            width: 18px;
            height: 18px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .stats-cards {
                grid-template-columns: 1fr;
            }
            
            .dashboard-header {
                flex-direction: column;
            }
        }

        @media print {
            .toolbar,
            .tabs,
            .live-messages,
            form {
                display: none !important;
            }
            body {
                background: #fff;
            }
            .card {
                box-shadow: none;
                border: 1px solid #ccc;
            }
        }
    </style>
</head>
<body>
    <div class="live-messages" id="liveMessages">
        <?php
        echo showLiveMessage('info', 'Pivot analysis loaded successfully');
        if (count($data) > 0) {
            echo showLiveMessage('success', 'Found ' . count($data) . ' records');
        } else {
            echo showLiveMessage('warning', 'No data found for current filters');
        }
        ?>
    </div>

    <div class="card">
        <div class="dashboard-header">
            <h1>📊 Advanced Pivot Analysis</h1>
            <div class="toolbar">
                <button class="theme-toggle" onclick="toggleTheme()">🌙</button>
                <div class="export-options">
                    <button class="btn btn-success" onclick="exportChart()">
                        📸 Export Chart (PNG)
                    </button>
                    <button class="btn btn-warning" onclick="exportData()">
                        📊 Export CSV
                    </button>
                    <button class="btn btn-warning" onclick="exportExcel()">
                        📊 Export Excel
                    </button>
                    <button class="btn btn-danger" onclick="window.print()">
                        🖨 Print
                    </button>
                </div>
            </div>
        </div>

        <div class="stats-cards">
            <div class="stat-card">
                <h3>Total SADD</h3>
                <div class="value"><?php echo number_format($totals['sadd']); ?></div>
                <div>👨‍👩‍👧‍👦 Beneficiaries</div>
            </div>
            <div class="stat-card">
                <h3>Total PWD</h3>
                <div class="value"><?php echo number_format($totals['pwd']); ?></div>
                <div>♿ Persons with Disabilities</div>
            </div>
            <div class="stat-card">
                <h3>Total Non-Beneficiaries</h3>
                <div class="value"><?php echo number_format($totals['non_ben']); ?></div>
                <div>👥 Other Individuals</div>
            </div>
            <div class="stat-card">
                <h3>Data Points</h3>
                <div class="value"><?php echo count($data); ?></div>
                <div>📈 Records Processed</div>
            </div>
        </div>

        <form method="get" class="form-row">
            <div class="form-group">
                <label>Row Dimension</label>
                <select name="row_dim">
                    <option value="all" <?php echo $rowDim === 'all' ? 'selected' : ''; ?>>🌐 All Nexus</option>
                    <option value="region" <?php echo $rowDim === 'region' ? 'selected' : ''; ?>>🏢 Region</option>
                    <option value="zone" <?php echo $rowDim === 'zone' ? 'selected' : ''; ?>>🗺️ Zone</option>
                    <option value="woreda" <?php echo $rowDim === 'woreda' ? 'selected' : ''; ?>>📍 Woreda</option>
                    <option value="indicator" <?php echo $rowDim === 'indicator' ? 'selected' : ''; ?>>📊 Indicator</option>
                    <option value="project" <?php echo $rowDim === 'project' ? 'selected' : ''; ?>>🚀 Project (Code + Title)</option>
                    <option value="project_code" <?php echo $rowDim === 'project_code' ? 'selected' : ''; ?>>🧾 Project Code Only</option>
                    <option value="project_type" <?php echo $rowDim === 'project_type' ? 'selected' : ''; ?>>📂 Project Type</option>
                    <option value="status" <?php echo $rowDim === 'status' ? 'selected' : ''; ?>>🔖 Project Status</option>
                    <option value="donor" <?php echo $rowDim === 'donor' ? 'selected' : ''; ?>>💰 Donor</option>
                    <option value="sector" <?php echo $rowDim === 'sector' ? 'selected' : ''; ?>>🧩 Programme Sector</option>
                    <option value="project_area" <?php echo $rowDim === 'project_area' ? 'selected' : ''; ?>>🎯 Project Specific Area</option>
                    <option value="beneficiary_type" <?php echo $rowDim === 'beneficiary_type' ? 'selected' : ''; ?>>🧍‍♀️ Beneficiary Type</option>
                    <option value="frequency" <?php echo $rowDim === 'frequency' ? 'selected' : ''; ?>>⏱️ Reporting Frequency</option>
                </select>
            </div>

            <div class="form-group">
                <label>Column Dimension</label>
                <select name="col_dim">
                    <option value="year" <?php echo $colDim === 'year' ? 'selected' : ''; ?>>📅 Year</option>
                    <option value="month" <?php echo $colDim === 'month' ? 'selected' : ''; ?>>📆 Month</option>
                    <option value="year_month" <?php echo $colDim === 'year_month' ? 'selected' : ''; ?>>🗓️ Year-Month</option>
                </select>
            </div>

            <div class="form-group">
                <label>Value Type</label>
                <select name="value_type">
                    <option value="sadd" <?php echo $valueType === 'sadd' ? 'selected' : ''; ?>>👨‍👩‍👧‍👦 Total SADD</option>
                    <option value="pwd" <?php echo $valueType === 'pwd' ? 'selected' : ''; ?>>♿ Total PWD</option>
                    <option value="non_ben" <?php echo $valueType === 'non_ben' ? 'selected' : ''; ?>>👥 Total Non-beneficiaries</option>
                </select>
            </div>

            <div class="form-group">
                <label>Chart Type</label>
                <select name="chart_type">
                    <option value="bar" <?php echo $chartType === 'bar' ? 'selected' : ''; ?>>📊 Bar Chart</option>
                    <option value="line" <?php echo $chartType === 'line' ? 'selected' : ''; ?>>📈 Line Chart</option>
                    <option value="pie" <?php echo $chartType === 'pie' ? 'selected' : ''; ?>>🥧 Pie Chart</option>
                    <option value="stacked" <?php echo $chartType === 'stacked' ? 'selected' : ''; ?>>📚 Stacked Bar</option>
                </select>
            </div>

            <div class="form-group">
                <label>Project</label>
                <select name="project_id">
                    <option value="">All Projects</option>
                    <?php foreach ($projects as $p): ?>
                        <?php
                        $pid = $p['id'];
                        if ($pivotProjectTitleColumn && isset($p[$pivotProjectTitleColumn])) {
                            $title = $p[$pivotProjectTitleColumn];
                        } else {
                            $title = $p['title'] ?? 'Project #' . $pid;
                        }
                        if ($pivotProjectCodeColumn && !empty($p[$pivotProjectCodeColumn])) {
                            $label = $p[$pivotProjectCodeColumn] . ' - ' . $title;
                        } else {
                            $label = $title;
                        }
                        ?>
                        <option value="<?php echo $pid; ?>" <?php echo ($filterProject == $pid ? 'selected' : ''); ?>>
                            <?php echo h($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Region</label>
                <select name="region_id">
                    <option value="">All Regions</option>
                    <?php foreach ($regions as $r): ?>
                        <option value="<?php echo $r['id']; ?>" <?php echo ($filterRegion == $r['id'] ? 'selected' : ''); ?>>
                            <?php echo h($r['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Indicator</label>
                <select name="indicator_id">
                    <option value="">All Indicators</option>
                    <?php foreach ($indicators as $ind): ?>
                        <option value="<?php echo $ind['id']; ?>" <?php echo ($filterIndicator == $ind['id'] ? 'selected' : ''); ?>>
                            <?php echo h(($ind['code'] ?? '') . ' - ' . $ind['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Year</label>
                <select name="year">
                    <option value="">All Years</option>
                    <?php for ($y = 2020; $y <= 2035; $y++): ?>
                        <option value="<?php echo $y; ?>" <?php echo ($filterYear == $y ? 'selected' : ''); ?>>
                            <?php echo $y; ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Reporting Frequency</label>
                <select name="frequency">
                    <option value="">All Frequencies</option>
                    <option value="weekly"   <?php echo $filterFrequency === 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                    <option value="monthly"  <?php echo $filterFrequency === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                    <option value="quarterly"<?php echo $filterFrequency === 'quarterly' ? 'selected' : ''; ?>>Quarterly</option>
                    <option value="annual"   <?php echo $filterFrequency === 'annual' ? 'selected' : ''; ?>>Annual</option>
                </select>
            </div>

            <div class="form-group">
                <label>Donor (multi-select)</label>
                <select name="donor_ids[]" multiple>
                    <?php foreach ($donors as $d): ?>
                        <option value="<?php echo $d['id']; ?>" <?php echo in_array((string)$d['id'], array_map('strval', $filterDonorIds), true) ? 'selected' : ''; ?>>
                            <?php echo h($d['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Project Type (multi-select)</label>
                <select name="project_type_ids[]" multiple>
                    <?php foreach ($projectTypes as $pt): ?>
                        <option value="<?php echo $pt['id']; ?>" <?php echo in_array((string)$pt['id'], array_map('strval', $filterProjectTypeIds), true) ? 'selected' : ''; ?>>
                            <?php echo h($pt['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Main Programme Sectors (multi-select)</label>
                <select name="sectors[]" multiple>
                    <?php foreach ($sectors as $sec): ?>
                        <option value="<?php echo h($sec); ?>" <?php echo in_array($sec, $filterSectors, true) ? 'selected' : ''; ?>>
                            <?php echo h($sec); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Project Specific Areas (multi-select)</label>
                <select name="project_areas[]" multiple>
                    <?php foreach ($projectAreas as $area): ?>
                        <option value="<?php echo h($area); ?>" <?php echo in_array($area, $filterProjectAreas, true) ? 'selected' : ''; ?>>
                            <?php echo h($area); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Beneficiary Types (multi-select)</label>
                <select name="beneficiary_types[]" multiple>
                    <?php foreach ($beneficiaryTypes as $bt): ?>
                        <option value="<?php echo h($bt); ?>" <?php echo in_array($bt, $filterBenTypes, true) ? 'selected' : ''; ?>>
                            <?php echo h($bt); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <button type="submit" class="btn">
                    <span id="generateText">🚀 Generate Analysis</span>
                    <span id="generateLoading" class="loading" style="display: none;"></span>
                </button>
            </div>
        </form>
    </div>

    <div class="tab-container">
        <div class="tabs">
            <button class="tab active" onclick="switchTab('table')">📋 Data Table</button>
            <button class="tab" onclick="switchTab('chart')">📊 Visual Charts</button>
            <button class="tab" onclick="switchTab('summary')">📈 Summary Stats</button>
        </div>

        <div id="table" class="tab-content active">
            <div class="card">
                <h2>
                    <?php echo $rowIcon . ' ' . h($rowLabel); ?> × 
                    <?php echo $colIcon . ' ' . h($colLabel); ?> 
                    <span class="dimension-badge">
                        <?php echo $valueIcon . ' ' . h($valueName); ?>
                    </span>
                </h2>
                
                <div class="table-container">
                    <table class="table" id="pivot-table">
                        <thead>
                            <tr>
                                <th><?php echo h($rowLabel . ' \\ ' . $colLabel); ?></th>
                                <?php foreach ($colLabels as $c => $_): ?>
                                    <th><?php echo h($c); ?></th>
                                <?php endforeach; ?>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rowLabels as $rKey => $_): ?>
                                <?php
                                // Human-readable row label for donor / project type
                                $displayRowKey = $rKey;
                                if ($rowDim === 'donor') {
                                    $displayRowKey = $donorMap[(string)$rKey] ?? ('Donor #' . $rKey);
                                } elseif ($rowDim === 'project_type') {
                                    $displayRowKey = $projectTypeMap[(string)$rKey] ?? ('Type #' . $rKey);
                                }
                                ?>
                                <tr>
                                    <td><strong><?php echo h($displayRowKey); ?></strong></td>
                                    <?php 
                                    $rowTotal = 0;
                                    foreach ($colLabels as $cKey => $_2): 
                                        $cell = $matrix[$rKey][$cKey] ?? ['sadd' => 0, 'pwd' => 0, 'non_ben' => 0];
                                        switch ($valueType) {
                                            case 'pwd':
                                                $val = $cell['pwd'];
                                                break;
                                            case 'non_ben':
                                                $val = $cell['non_ben'];
                                                break;
                                            case 'sadd':
                                            default:
                                                $val = $cell['sadd'];
                                                break;
                                        }
                                        $rowTotal += $val;
                                    ?>
                                        <td><?php echo number_format($val); ?></td>
                                    <?php endforeach; ?>
                                    <th><?php echo number_format($rowTotal); ?></th>
                                </tr>
                            <?php endforeach; ?>

                            <?php if ($rowLabels && $colLabels): ?>
                                <tr style="background: rgba(52, 152, 219, 0.1);">
                                    <th>🌐 All Nexus (Total)</th>
                                    <?php 
                                    $grandTotal = 0;
                                    foreach ($colLabels as $cKey => $_2): 
                                        $sum = 0;
                                        foreach ($rowLabels as $rKey => $_3) {
                                            $cell = $matrix[$rKey][$cKey] ?? ['sadd' => 0, 'pwd' => 0, 'non_ben' => 0];
                                            switch ($valueType) {
                                                case 'pwd':
                                                    $sum += $cell['pwd'];
                                                    break;
                                                case 'non_ben':
                                                    $sum += $cell['non_ben'];
                                                    break;
                                                case 'sadd':
                                                default:
                                                    $sum += $cell['sadd'];
                                                    break;
                                            }
                                        }
                                        $grandTotal += $sum;
                                    ?>
                                        <th><?php echo number_format($sum); ?></th>
                                    <?php endforeach; ?>
                                    <th><?php echo number_format($grandTotal); ?></th>
                                </tr>
                            <?php endif; ?>

                            <?php if (!$rowLabels): ?>
                                <tr><td colspan="99">📭 No data available for the selected filters.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="chart" class="tab-content">
            <div class="card">
                <h2>📊 Interactive Chart Visualization</h2>
                <div class="chart-container">
                    <canvas id="pivotChart"></canvas>
                </div>
            </div>
        </div>

        <div id="summary" class="tab-content">
            <div class="card">
                <h2>📈 Statistical Summary</h2>
                <div class="stats-cards">
                    <div class="stat-card" style="background: linear-gradient(135deg, #9b59b6, #8e44ad);">
                        <h3>Data Coverage</h3>
                        <div class="value"><?php echo count($rowLabels); ?> Rows</div>
                        <div><?php echo count($colLabels); ?> Columns</div>
                    </div>
                    <div class="stat-card" style="background: linear-gradient(135deg, #e67e22, #d35400);">
                        <h3>Time Range</h3>
                        <div class="value">
                            <?php 
                            if ($colLabels) {
                                $keys = array_keys($colLabels);
                                $first = reset($keys);
                                $last  = end($keys);
                                echo $first . ' to ' . $last;
                            } else {
                                echo 'N/A';
                            }
                            ?>
                        </div>
                        <div>Period Coverage</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Tab switching
        function switchTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            document.getElementById(tabName).classList.add('active');
            document.querySelector(`.tab[onclick="switchTab('${tabName}')"]`).classList.add('active');

            if (tabName === 'chart') {
                renderChart();
            }
        }

        // Theme toggle
        function toggleTheme() {
            const currentTheme = document.documentElement.getAttribute('data-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', newTheme);
            
            const themeBtn = document.querySelector('.theme-toggle');
            themeBtn.textContent = newTheme === 'dark' ? '☀️' : '🌙';
            
            showMessage('Theme switched to ' + newTheme + ' mode', 'info');
        }

        // Live messaging system
        function showMessage(message, type = 'info') {
            const messagesContainer = document.getElementById('liveMessages');
            const messageDiv = document.createElement('div');
            messageDiv.className = `live-message live-${type}`;
            
            const icons = {
                'success': '✓',
                'info': 'ⓘ',
                'warning': '⚠',
                'error': '✗'
            };
            
            messageDiv.innerHTML = `
                <span class="live-icon">${icons[type]}</span>
                <span class="live-text">${message}</span>
                <span class="live-time">${new Date().toLocaleTimeString()}</span>
            `;
            
            messagesContainer.appendChild(messageDiv);
            
            setTimeout(() => {
                messageDiv.remove();
            }, 5000);
        }

        // Chart rendering
        function renderChart() {
            const ctx = document.getElementById('pivotChart').getContext('2d');
            const chartType = '<?php echo $chartType; ?>';
            const chartLabels = <?php echo json_encode(array_keys($colLabels)); ?>;
            const chartData = <?php echo json_encode($chartData); ?>;
            const rowDim = '<?php echo $rowDim; ?>';
            const donorMap = <?php echo json_encode($donorMap); ?>;
            const projectTypeMap = <?php echo json_encode($projectTypeMap); ?>;

            const colors = [
                '#3498db', '#2ecc71', '#e74c3c', '#f39c12', '#9b59b6',
                '#1abc9c', '#34495e', '#d35400', '#c0392b', '#16a085'
            ];

            const datasets = [];
            let rowIndex = 0;
            
            for (const [rowKey, rowValues] of Object.entries(chartData)) {
                let label = rowKey;
                if (rowDim === 'donor' && donorMap[rowKey]) {
                    label = donorMap[rowKey];
                } else if (rowDim === 'project_type' && projectTypeMap[rowKey]) {
                    label = projectTypeMap[rowKey];
                }

                datasets.push({
                    label: label,
                    data: rowValues,
                    backgroundColor: colors[rowIndex % colors.length],
                    borderColor: colors[rowIndex % colors.length],
                    borderWidth: 2,
                    fill: false
                });
                rowIndex++;
            }

            if (window.pivotChart) {
                window.pivotChart.destroy();
            }

            const config = {
                type: chartType === 'pie' ? 'pie' : chartType === 'line' ? 'line' : 'bar',
                data: {
                    labels: chartLabels,
                    datasets: datasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: '<?php echo $rowLabel . " × " . $colLabel . " (" . $valueName . ")"; ?>',
                            font: { size: 16 }
                        },
                        legend: {
                            position: 'bottom'
                        }
                    },
                    scales: chartType !== 'pie' ? {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: '<?php echo $valueName; ?>'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: '<?php echo $colLabel; ?>'
                            }
                        }
                    } : {}
                }
            };

            if (chartType === 'stacked') {
                config.type = 'bar';
                config.options.scales.x.stacked = true;
                config.options.scales.y.stacked = true;
            }

            window.pivotChart = new Chart(ctx, config);
            showMessage('Chart rendered successfully', 'success');
        }

        // Export chart PNG
        function exportChart() {
            const chartCanvas = document.getElementById('pivotChart');
            // If chart tab not opened yet, render first
            if (!chartCanvas || !window.pivotChart) {
                renderChart();
            }
            const canvas = document.getElementById('pivotChart');
            const link = document.createElement('a');
            link.download = 'pivot-chart.png';
            link.href = canvas.toDataURL();
            link.click();
            showMessage('Chart exported as PNG', 'success');
        }

        // Export CSV (server side)
        function exportData() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'csv');
            window.location.href = '?' + params.toString();
        }

        // Export Excel (client side, HTML table → .xls)
        function exportExcel() {
            const table = document.getElementById('pivot-table');
            if (!table) {
                showMessage('No table to export', 'error');
                return;
            }
            const html = table.outerHTML;
            const blob = new Blob(['\ufeff' + html], { type: 'application/vnd.ms-excel' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'pivot_table_<?php echo date('Y-m-d'); ?>.xls';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
            showMessage('Excel file exported', 'success');
        }

        document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('chart').classList.contains('active')) {
                renderChart();
            }
            
            const form = document.querySelector('form');
            const generateText = document.getElementById('generateText');
            const generateLoading = document.getElementById('generateLoading');
            
            form.addEventListener('submit', function() {
                generateText.style.display = 'none';
                generateLoading.style.display = 'inline-block';
                showMessage('Generating pivot analysis...', 'info');
            });
            
            const cd = <?php echo json_encode($chartData); ?>;
            if (Object.keys(cd).length > 0) {
                showMessage('Data loaded successfully. Ready for analysis.', 'success');
            }
        });

        // Keep live message time ticking
        setInterval(() => {
            document.querySelectorAll('.live-time').forEach(el => {
                el.textContent = new Date().toLocaleTimeString();
            });
        }, 1000);
    </script>
</body>
</html>

<?php require_once __DIR__ . '/../footer.php'; ?>
