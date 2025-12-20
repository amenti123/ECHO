<?php
// view_reports.php – Advanced Nexus Ethiopia Performance Viewer (linked to enter_data.php)

require_once __DIR__ . '/../helpers.php';
require_login();
$pdo = getPDO();

$error = '';

/* ------------------------------------------------------------------
   1. Ensure indicator_reports table & columns exist
      (same structure as in enter_data.php so both apps talk to
       exactly the same data store)
------------------------------------------------------------------ */
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
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

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
        "ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
    ];
    foreach ($alterStmts as $stmt) {
        try {
            $pdo->exec("ALTER TABLE indicator_reports {$stmt}");
        } catch (Exception $e) {
            // ignore duplicate-column errors
        }
    }
} catch (Exception $e) {
    $error = 'Database error: ' . $e->getMessage();
}

/* ------------------------------------------------------------------
   2. Small helpers
------------------------------------------------------------------ */

// Safe HTML escape
if (!function_exists('h')) {
    function h(string $v): string {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}

/* ------------------------------------------------------------------
   3. Reference data (projects, geography, indicators)
------------------------------------------------------------------ */

$projects = get_projects();

$projectMap = [];
foreach ($projects as $p) {
    $projectMap[(int)$p['id']] = $p['title'] ?? ($p['name'] ?? '');
}

// Regions
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

// Selected geography from filters (for dynamic zone/woreda lists)
$selected_region_id = $_GET['region_id'] ?? '';

$zoneFilterRaw = $_GET['zone_id'] ?? [];
if (!is_array($zoneFilterRaw)) {
    $zoneFilterRaw = [$zoneFilterRaw];
}
$zoneIds = array_values(array_filter(array_map('intval', $zoneFilterRaw), function($v) {
    return $v > 0;
}));
$primaryZoneId = $zoneIds[0] ?? '';

$woredaFilterRaw = $_GET['woreda_id'] ?? [];
if (!is_array($woredaFilterRaw)) {
    $woredaFilterRaw = [$woredaFilterRaw];
}
$woredaIds = array_values(array_filter(array_map('intval', $woredaFilterRaw), function($v) {
    return $v > 0;
}));

$zones   = [];
$woredas = [];

try {
    if ($selected_region_id) {
        if (function_exists('get_zones_by_region')) {
            $zones = get_zones_by_region($selected_region_id);
        } else {
            $stmt = $pdo->prepare("SELECT id, name FROM zones WHERE region_id = ? ORDER BY name");
            $stmt->execute([$selected_region_id]);
            $zones = $stmt->fetchAll();
        }
    }
    if ($primaryZoneId) {
        if (function_exists('get_woredas_by_zone')) {
            $woredas = get_woredas_by_zone($primaryZoneId);
        } else {
            $stmt = $pdo->prepare("SELECT id, name FROM woredas WHERE zone_id = ? ORDER BY name");
            $stmt->execute([$primaryZoneId]);
            $woredas = $stmt->fetchAll();
        }
    }
} catch (Throwable $e) {
    $zones   = [];
    $woredas = [];
}

// Indicator list for filter (union of impact/outcome/output)
$indicatorFilterOptions = [];
try {
    // Impact
    $stmt = $pdo->query("
        SELECT 'impact' AS level, ii.id, ii.indicator_code, ii.indicator_name,
               p.title AS project_title
        FROM impact_indicators ii
        LEFT JOIN projects p ON p.id = ii.project_id
        ORDER BY p.title, ii.indicator_code, ii.id
    ");
    $indicatorFilterOptions = array_merge($indicatorFilterOptions, $stmt->fetchAll());

    // Outcome
    $stmt = $pdo->query("
        SELECT 'outcome' AS level, oi.id, oi.indicator_code, oi.indicator_name,
               p.title AS project_title
        FROM outcome_indicators oi
        LEFT JOIN projects p ON p.id = oi.project_id
        ORDER BY p.title, oi.indicator_code, oi.id
    ");
    $indicatorFilterOptions = array_merge($indicatorFilterOptions, $stmt->fetchAll());

    // Output
    $stmt = $pdo->query("
        SELECT 'output' AS level, oi.id, oi.indicator_code, oi.indicator_name,
               p.title AS project_title
        FROM output_indicators oi
        LEFT JOIN projects p ON p.id = oi.project_id
        ORDER BY p.title, oi.indicator_code, oi.id
    ");
    $indicatorFilterOptions = array_merge($indicatorFilterOptions, $stmt->fetchAll());
} catch (Throwable $e) {
    $indicatorFilterOptions = [];
}

/* ------------------------------------------------------------------
   4. Read filters (multi-project, geography, period, indicator, etc.)
------------------------------------------------------------------ */

// Multi-project filter (project_ids[])
$projectFilterRaw = $_GET['project_ids'] ?? [];
if (!is_array($projectFilterRaw)) {
    $projectFilterRaw = [$projectFilterRaw];
}
$projectIds = array_values(array_filter(array_map('intval', $projectFilterRaw), function($v) {
    return $v > 0;
}));

// Backwards compatibility: old ?project_id=1
if (!$projectIds && isset($_GET['project_id']) && $_GET['project_id'] !== '') {
    $projectIds = [(int)$_GET['project_id']];
}

// Indicator multi-filter
$indicatorFilterRaw = $_GET['indicator_key'] ?? [];
if (!is_array($indicatorFilterRaw)) {
    $indicatorFilterRaw = [$indicatorFilterRaw];
}
$indicatorKeys = array_values(array_filter(array_map('strval', $indicatorFilterRaw), function($v) {
    return trim($v) !== '';
}));

$indicatorFilterPairs = [];
foreach ($indicatorKeys as $k) {
    if (strpos($k, ':') !== false) {
        [$lvl, $id] = explode(':', $k, 2);
        $lvl = trim((string)$lvl);
        $id  = (int)$id;
        if ($lvl !== '' && $id > 0) {
            $indicatorFilterPairs[] = [$lvl, $id];
        }
    }
}

// Beneficiary multi-filter
$benefFilterRaw = $_GET['benef_type'] ?? [];
if (!is_array($benefFilterRaw)) {
    $benefFilterRaw = [$benefFilterRaw];
}
$benefTypes = array_values(array_filter($benefFilterRaw, function($v) {
    return trim((string)$v) !== '';
}));

$filters = [
    'project_ids'   => $projectIds,
    'region_id'     => $selected_region_id,
    'zone_ids'      => $zoneIds,
    'woreda_ids'    => $woredaIds,
    'period_type'   => $_GET['period_type']   ?? '',
    'year_from'     => $_GET['year_from']     ?? '',
    'year_to'       => $_GET['year_to']       ?? '',
    'month_from'    => $_GET['month_from']    ?? '',
    'month_to'      => $_GET['month_to']      ?? '',
    'quick_range'   => $_GET['quick_range']   ?? '',
    'indicator_keys'=> $indicatorKeys,
    'benef_types'   => $benefTypes,
    'display_mode'  => $_GET['display_mode']  ?? 'table'
];

/* ------------------------------------------------------------------
   5. Build common WHERE clauses for indicator_reports (metadata + agg)
------------------------------------------------------------------ */

$whereParts = ["1=1"];
$params     = [];

// Projects (multi select)
if (!empty($projectIds)) {
    $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
    $whereParts[] = "ir.project_id IN ($placeholders)";
    $params       = array_merge($params, $projectIds);
}

// Geography
if ($filters['region_id'] !== '') {
    $whereParts[] = "ir.region_id = ?";
    $params[]     = (int)$filters['region_id'];
}
if (!empty($filters['zone_ids'])) {
    $placeholders = implode(',', array_fill(0, count($filters['zone_ids']), '?'));
    $whereParts[] = "ir.zone_id IN ($placeholders)";
    $params       = array_merge($params, $filters['zone_ids']);
}
if (!empty($filters['woreda_ids'])) {
    $placeholders = implode(',', array_fill(0, count($filters['woreda_ids']), '?'));
    $whereParts[] = "ir.woreda_id IN ($placeholders)";
    $params       = array_merge($params, $filters['woreda_ids']);
}

// Year range
if ($filters['year_from'] !== '') {
    $whereParts[] = "ir.year >= ?";
    $params[]     = (int)$filters['year_from'];
}
if ($filters['year_to'] !== '') {
    $whereParts[] = "ir.year <= ?";
    $params[]     = (int)$filters['year_to'];
}

// Month range
if ($filters['month_from'] !== '') {
    $whereParts[] = "(ir.month IS NOT NULL AND ir.month >= ?)";
    $params[]     = (int)$filters['month_from'];
}
if ($filters['month_to'] !== '') {
    $whereParts[] = "(ir.month IS NOT NULL AND ir.month <= ?)";
    $params[]     = (int)$filters['month_to'];
}

// Period type
if ($filters['period_type'] !== '') {
    $whereParts[] = "ir.period_type = ?";
    $params[]     = $filters['period_type'];
}

// Quick ranges (applied on start_date)
if ($filters['quick_range'] !== '') {
    $today    = new DateTime();
    $fromDate = null;

    switch ($filters['quick_range']) {
        case 'past_2m':  $fromDate = (clone $today)->modify('-2 months'); break;
        case 'past_3m':  $fromDate = (clone $today)->modify('-3 months'); break;
        case 'past_6m':  $fromDate = (clone $today)->modify('-6 months'); break;
        case 'past_9m':  $fromDate = (clone $today)->modify('-9 months'); break;
        case 'past_11m': $fromDate = (clone $today)->modify('-11 months'); break;
        case 'past_1w':  $fromDate = (clone $today)->modify('-1 week'); break;
        case 'past_2w':  $fromDate = (clone $today)->modify('-2 weeks'); break;
        case 'past_3w':  $fromDate = (clone $today)->modify('-3 weeks'); break;
        case 'past_4w':  $fromDate = (clone $today)->modify('-4 weeks'); break;
    }
    if ($fromDate) {
        $whereParts[] = "ir.start_date >= ?";
        $params[]     = $fromDate->format('Y-m-d');
    }
}

// Indicator multi-filter
if (!empty($indicatorFilterPairs)) {
    $condParts = [];
    foreach ($indicatorFilterPairs as $pair) {
        $condParts[] = "(ir.indicator_level = ? AND ir.indicator_id = ?)";
        $params[]    = $pair[0];
        $params[]    = $pair[1];
    }
    $whereParts[] = '(' . implode(' OR ', $condParts) . ')';
}

$whereSql = implode(' AND ', $whereParts);

/* ------------------------------------------------------------------
   6. Fetch raw report rows (for section 1)
------------------------------------------------------------------ */

$reportRows = [];
try {
    $sqlRows = "
        SELECT
            ir.*,
            p.title AS project_title,
            rg.name AS region_name,
            z.name  AS zone_name,
            w.name  AS woreda_name,
            CASE
                WHEN ir.indicator_level = 'impact'  THEN ii.indicator_code
                WHEN ir.indicator_level = 'outcome' THEN oc.indicator_code
                WHEN ir.indicator_level = 'output'  THEN oo.indicator_code
                ELSE ''
            END AS indicator_code,
            CASE
                WHEN ir.indicator_level = 'impact'  THEN ii.indicator_name
                WHEN ir.indicator_level = 'outcome' THEN oc.indicator_name
                WHEN ir.indicator_level = 'output'  THEN oo.indicator_name
                ELSE CONCAT('Indicator #', ir.indicator_id)
            END AS indicator_name,
            CASE
                WHEN ir.indicator_level = 'impact'  THEN ii.unit_type
                WHEN ir.indicator_level = 'outcome' THEN oc.unit_type
                WHEN ir.indicator_level = 'output'  THEN oo.unit_type
                ELSE ''
            END AS unit_type,
            CASE
                WHEN ir.indicator_level = 'impact'  THEN ii.beneficiary_type
                WHEN ir.indicator_level = 'outcome' THEN oc.beneficiary_type
                WHEN ir.indicator_level = 'output'  THEN oo.beneficiary_type
                ELSE ''
            END AS beneficiary_type
        FROM indicator_reports ir
        LEFT JOIN projects p           ON ir.project_id = p.id
        LEFT JOIN regions rg           ON ir.region_id = rg.id
        LEFT JOIN zones z              ON ir.zone_id   = z.id
        LEFT JOIN woredas w            ON ir.woreda_id = w.id
        LEFT JOIN impact_indicators  ii ON ir.indicator_level = 'impact'  AND ir.indicator_id = ii.id
        LEFT JOIN outcome_indicators oc ON ir.indicator_level = 'outcome' AND ir.indicator_id = oc.id
        LEFT JOIN output_indicators  oo ON ir.indicator_level = 'output'  AND ir.indicator_id = oo.id
        WHERE {$whereSql}
        ORDER BY ir.year DESC, ir.month DESC, ir.week DESC, ir.created_at DESC
        LIMIT 500
    ";

    $stmt = $pdo->prepare($sqlRows);
    $stmt->execute($params);
    $reportRows = $stmt->fetchAll();
} catch (Exception $e) {
    $error = 'Error loading reports: ' . $e->getMessage();
}

/* ------------------------------------------------------------------
   7. Aggregated indicator SADD data (for section 2 + exports + charts)
------------------------------------------------------------------ */

$indicatorAgg = [];
$chartAgg     = [];   // label => total persons
$totPersons   = 0;
$totPWD       = 0;
$totNonPerson = 0;
$totBoys      = 0;
$totGirls     = 0;
$totMen       = 0;
$totWomen     = 0;

// 7.1 Load budget planning data (indicator_budget) – safe if table missing
$budgetMap = []; // key: level:id => ['planned'=>..,'used'=>..]

try {
    $budgetWhere  = ["1=1"];
    $budgetParams = [];

    if (!empty($projectIds)) {
        $ph = implode(',', array_fill(0, count($projectIds), '?'));
        $budgetWhere[] = "project_id IN ($ph)";
        $budgetParams  = array_merge($budgetParams, $projectIds);
    }
    if ($filters['period_type'] !== '') {
        $budgetWhere[] = "period_type = ?";
        $budgetParams[] = $filters['period_type'];
    }
    if ($filters['year_from'] !== '') {
        $budgetWhere[] = "year >= ?";
        $budgetParams[] = (int)$filters['year_from'];
    }
    if ($filters['year_to'] !== '') {
        $budgetWhere[] = "year <= ?";
        $budgetParams[] = (int)$filters['year_to'];
    }
    if ($filters['month_from'] !== '') {
        $budgetWhere[] = "(month IS NOT NULL AND month >= ?)";
        $budgetParams[] = (int)$filters['month_from'];
    }
    if ($filters['month_to'] !== '') {
        $budgetWhere[] = "(month IS NOT NULL AND month <= ?)";
        $budgetParams[] = (int)$filters['month_to'];
    }

    $budgetWhereSql = implode(' AND ', $budgetWhere);

    $sqlBudget = "
        SELECT
            indicator_level,
            indicator_id,
            SUM(budget_planned) AS budget_planned,
            SUM(budget_used)    AS budget_used
        FROM indicator_budget
        WHERE {$budgetWhereSql}
        GROUP BY indicator_level, indicator_id
    ";

    $stmtB = $pdo->prepare($sqlBudget);
    $stmtB->execute($budgetParams);
    $budgetRows = $stmtB->fetchAll();

    foreach ($budgetRows as $b) {
        $key = $b['indicator_level'] . ':' . $b['indicator_id'];
        $budgetMap[$key] = [
            'planned' => (float)$b['budget_planned'],
            'used'    => (float)$b['budget_used']
        ];
    }
} catch (Throwable $e) {
    $budgetMap = []; // if table missing or columns mismatch, just ignore and show N/A
}

try {
    $sqlAgg = "
        SELECT
            ir.indicator_level,
            ir.indicator_id,
            CASE
                WHEN ir.indicator_level = 'impact'  THEN ii.indicator_code
                WHEN ir.indicator_level = 'outcome' THEN oc.indicator_code
                WHEN ir.indicator_level = 'output'  THEN oo.indicator_code
                ELSE ''
            END AS indicator_code,
            CASE
                WHEN ir.indicator_level = 'impact'  THEN ii.indicator_name
                WHEN ir.indicator_level = 'outcome' THEN oc.indicator_name
                WHEN ir.indicator_level = 'output'  THEN oo.indicator_name
                ELSE CONCAT('Indicator #', ir.indicator_id)
            END AS indicator_name,
            CASE
                WHEN ir.indicator_level = 'impact'  THEN ii.unit_type
                WHEN ir.indicator_level = 'outcome' THEN oc.unit_type
                WHEN ir.indicator_level = 'output'  THEN oo.unit_type
                ELSE ''
            END AS unit_type,
            CASE
                WHEN ir.indicator_level = 'impact'  THEN ii.beneficiary_type
                WHEN ir.indicator_level = 'outcome' THEN oc.beneficiary_type
                WHEN ir.indicator_level = 'output'  THEN oo.beneficiary_type
                ELSE ''
            END AS beneficiary_type,
            COALESCE(SUM(ir.boys_u5),0)      AS boys_u5,
            COALESCE(SUM(ir.girls_u5),0)     AS girls_u5,
            COALESCE(SUM(ir.boys_5_17),0)    AS boys_5_17,
            COALESCE(SUM(ir.girls_5_17),0)   AS girls_5_17,
            COALESCE(SUM(ir.men_18_59),0)    AS men_18_59,
            COALESCE(SUM(ir.women_18_59),0)  AS women_18_59,
            COALESCE(SUM(ir.men_60p),0)      AS men_60p,
            COALESCE(SUM(ir.women_60p),0)    AS women_60p,
            COALESCE(SUM(ir.pwd_count),0)    AS pwd_count,
            COALESCE(SUM(ir.non_beneficiary),0) AS non_beneficiary,
            COALESCE(SUM(ir.non_person_value),0) AS non_person_value
        FROM indicator_reports ir
        LEFT JOIN impact_indicators  ii ON ir.indicator_level = 'impact'  AND ir.indicator_id = ii.id
        LEFT JOIN outcome_indicators oc ON ir.indicator_level = 'outcome' AND ir.indicator_id = oc.id
        LEFT JOIN output_indicators  oo ON ir.indicator_level = 'output'  AND ir.indicator_id = oo.id
        WHERE {$whereSql}
        GROUP BY
            ir.indicator_level,
            ir.indicator_id,
            indicator_code,
            indicator_name,
            unit_type,
            beneficiary_type
        ORDER BY
            FIELD(ir.indicator_level, 'impact','outcome','output'),
            indicator_code,
            indicator_name,
            beneficiary_type
    ";

    $stmtAgg = $pdo->prepare($sqlAgg);
    $stmtAgg->execute($params);
    $rowsAggRaw = $stmtAgg->fetchAll();

    $indicatorAggProcessed = [];

    foreach ($rowsAggRaw as $row) {
        // Apply beneficiary multi-filter at PHP level
        if (!empty($benefTypes) && !in_array($row['beneficiary_type'], $benefTypes, true)) {
            continue;
        }

        $boys_u5     = (int)$row['boys_u5'];
        $girls_u5    = (int)$row['girls_u5'];
        $boys_5_17   = (int)$row['boys_5_17'];
        $girls_5_17  = (int)$row['girls_5_17'];
        $men_18_59   = (int)$row['men_18_59'];
        $women_18_59 = (int)$row['women_18_59'];
        $men_60p     = (int)$row['men_60p'];
        $women_60p   = (int)$row['women_60p'];
        $pwd_count   = (int)$row['pwd_count'];
        $np_value    = (float)$row['non_person_value'];

        $boysTotal   = $boys_u5 + $boys_5_17;
        $girlsTotal  = $girls_u5 + $girls_5_17;
        $menTotal    = $men_18_59 + $men_60p;
        $womenTotal  = $women_18_59 + $women_60p;
        $totalPerson = $boysTotal + $girlsTotal + $menTotal + $womenTotal;

        $label = ucfirst($row['indicator_level']) . ' – ' . $row['indicator_code'] . ' ' . $row['indicator_name'];
        if (!isset($chartAgg[$label])) {
            $chartAgg[$label] = 0;
        }
        $chartAgg[$label] += $totalPerson;

        $totPersons   += $totalPerson;
        $totPWD       += $pwd_count;
        $totNonPerson += $np_value;

        $totBoys   += $boysTotal;
        $totGirls  += $girlsTotal;
        $totMen    += $menTotal;
        $totWomen  += $womenTotal;

        $indicatorAggProcessed[] = $row;
    }

    $indicatorAgg = $indicatorAggProcessed;
} catch (Exception $e) {
    $error = 'Error aggregating indicator data: ' . $e->getMessage();
}

/* ------------------------------------------------------------------
   7b. Derived header labels (for print + export)
------------------------------------------------------------------ */

// Beneficiary labels
$benefLabels = [
    'host'       => 'Host community',
    'idp'        => 'IDP',
    'returnee'   => 'Returnee',
    'refugee'    => 'Refugee',
    'pwd'        => 'People with disability',
    'total'      => 'Total',
    'non_person' => 'Non person'
];

// KPIs
$childrenTotal = $totBoys + $totGirls;
$womenGirls    = $totGirls + $totWomen;

$childrenShare = $totPersons > 0 ? round(($childrenTotal / $totPersons) * 100, 1) : 0;
$wgShare       = $totPersons > 0 ? round(($womenGirls / $totPersons) * 100, 1) : 0;
$pwdShare      = ($totPersons + $totPWD) > 0 ? round(($totPWD / ($totPersons + $totPWD)) * 100, 1) : 0;

// Project label for header
if (count($projectIds) === 0) {
    $projectLabel = 'All projects';
} elseif (count($projectIds) === 1) {
    $pid = $projectIds[0];
    $projectLabel = $projectMap[$pid] ?? ('Project #' . $pid);
} else {
    $projectLabel = 'Multiple projects (' . count($projectIds) . ')';
}

// Region name
$regionNameHeader = 'All';
if ($selected_region_id) {
    foreach ($regions as $r) {
        if ((int)$r['id'] === (int)$selected_region_id) {
            $regionNameHeader = $r['name'];
            break;
        }
    }
}

// Zone header
$zoneNameHeader = 'All';
if (!empty($zoneIds)) {
    if (count($zoneIds) === 1) {
        foreach ($zones as $z) {
            if ((int)$z['id'] === (int)$zoneIds[0]) {
                $zoneNameHeader = $z['name'];
                break;
            }
        }
    } else {
        $zoneNameHeader = 'Multiple';
    }
}

// Woreda header
$woredaNameHeader = 'All';
if (!empty($woredaIds)) {
    if (count($woredaIds) === 1) {
        foreach ($woredas as $w) {
            if ((int)$w['id'] === (int)$woredaIds[0]) {
                $woredaNameHeader = $w['name'];
                break;
            }
        }
    } else {
        $woredaNameHeader = 'Multiple';
    }
}

// Report type & reporting month
$reportType = $filters['period_type'] ? ucfirst($filters['period_type']) . ' report' : 'All';

$reportingMonth = '';
if ($filters['month_from'] !== '' && $filters['month_to'] !== '' && $filters['month_from'] == $filters['month_to']) {
    $reportingMonth = date('F', mktime(0,0,0,(int)$filters['month_from'],1));
} elseif ($filters['month_from'] !== '' || $filters['month_to'] !== '') {
    $mfName = $filters['month_from'] !== '' ? date('F', mktime(0,0,0,(int)$filters['month_from'],1)) : 'Any';
    $mtName = $filters['month_to']   !== '' ? date('F', mktime(0,0,0,(int)$filters['month_to'],1))   : 'Any';
    $reportingMonth = $mfName . ' – ' . $mtName;
} else {
    $reportingMonth = 'All';
}

// Date range from data (start_date / end_date)
$dateFromLabel = '__________';
$dateToLabel   = '__________';

$startDates = [];
$endDates   = [];

foreach ($reportRows as $r) {
    if (!empty($r['start_date'])) {
        $startDates[] = $r['start_date'];
    }
    if (!empty($r['end_date'])) {
        $endDates[] = $r['end_date'];
    }
}
if (!empty($startDates)) {
    sort($startDates);
    $dateFromLabel = $startDates[0];
}
if (!empty($endDates)) {
    sort($endDates);
    $dateToLabel = $endDates[count($endDates)-1];
}

/* ------------------------------------------------------------------
   8. Export handlers (CSV / Word) – based on aggregated data
------------------------------------------------------------------ */

if (!empty($_GET['export']) && in_array($_GET['export'], ['csv', 'word'], true)) {
    $exportType = $_GET['export'];

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
        $np_value    = (float)$row['non_person_value'];

        $boysTotal   = $boys_u5 + $boys_5_17;
        $girlsTotal  = $girls_u5 + $girls_5_17;
        $menTotal    = $men_18_59 + $men_60p;
        $womenTotal  = $women_18_59 + $women_60p;
        $totalPerson = $boysTotal + $girlsTotal + $menTotal + $womenTotal;

        $flat[] = [
            'SN'               => $sn++,
            'indicator_level'  => $row['indicator_level'],
            'indicator_code'   => $row['indicator_code'],
            'indicator_name'   => $row['indicator_name'],
            'beneficiary_type' => $row['beneficiary_type'],
            'unit'             => $row['unit_type'],
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
            'non_person_value' => $np_value,
            'boys_total'       => $boysTotal,
            'girls_total'      => $girlsTotal,
            'men_total'        => $menTotal,
            'women_total'      => $womenTotal,
            'total_persons'    => $totalPerson
        ];
    }

    $columns = [
        'SN','indicator_level','indicator_code','indicator_name','beneficiary_type','unit',
        'boys_u5','girls_u5','boys_5_17','girls_5_17','men_18_59','women_18_59',
        'men_60p','women_60p','pwd_count','non_beneficiary','non_person_value',
        'boys_total','girls_total','men_total','women_total','total_persons'
    ];

    if ($exportType === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="nexus_view_reports_sadd.csv"');

        $out = fopen('php://output', 'w');

        // Header lines (as comments for CSV; Excel will still open)
        fputcsv($out, ["Nexus Ethiopia Project Performance Report"]);
        fputcsv($out, ["Project Title:", $projectLabel]);
        fputcsv($out, ["Region:", $regionNameHeader, "Zone:", $zoneNameHeader, "Name of Woreda:", $woredaNameHeader]);
        fputcsv($out, ["Report type:", $reportType, "Reporting Month:", $reportingMonth]);
        fputcsv($out, ["Date: From", $dateFromLabel, "to", $dateToLabel]);
        fputcsv($out, []); // empty line

        fputcsv($out, $columns);

        foreach ($flat as $r) {
            $row = [];
            foreach ($columns as $c) {
                $row[] = $r[$c] ?? '';
            }
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    if ($exportType === 'word') {
        header('Content-Type: application/msword; charset=utf-8');
        header('Content-Disposition: attachment; filename="nexus_view_reports_sadd.doc"');

        echo "<html><body>";
        echo "<h2>Nexus Ethiopia Project Performance Report</h2>";
        echo "<p><strong>Project Title:</strong> " . h($projectLabel) . "</p>";
        echo "<p><strong>Region:</strong> " . h($regionNameHeader) .
             " &nbsp;&nbsp; <strong>Zone:</strong> " . h($zoneNameHeader) .
             " &nbsp;&nbsp; <strong>Name of Woreda:</strong> " . h($woredaNameHeader) . "</p>";
        echo "<p><strong>Report type:</strong> " . h($reportType) .
             " &nbsp;&nbsp; <strong>Reporting Month:</strong> " . h($reportingMonth) . "</p>";
        echo "<p><strong>Date:</strong> From " . h($dateFromLabel) . " to " . h($dateToLabel) . "</p>";

        echo "<h3>Indicator SADD View (Aggregated)</h3>";
        echo "<table border='1' cellpadding='4' cellspacing='0'>";
        echo "<tr>";
        foreach ($columns as $hcol) {
            echo "<th>" . h($hcol) . "</th>";
        }
        echo "</tr>";
        foreach ($flat as $r) {
            echo "<tr>";
            foreach ($columns as $c) {
                echo "<td>" . h((string)($r[$c] ?? '')) . "</td>";
            }
            echo "</tr>";
        }
        echo "</table></body></html>";
        exit;
    }
}

/* ------------------------------------------------------------------
   9. Page header / layout
------------------------------------------------------------------ */

require_once __DIR__ . '/../header.php';

// For share links
$scheme     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$currentUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
?>
<style>
/* Simple page padding */
.view-page-container {
    max-width: 1200px;
    margin: 20px auto 40px;
    padding: 0 10px;
}
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit,minmax(220px,1fr));
    gap: 12px;
    margin: 10px 0 20px;
}
.kpi-card {
    border-radius: 10px;
    border: 1px solid #e1e5ff;
    background: #f8f9ff;
    padding: 10px 12px;
}
.kpi-label {
    font-size: 0.9rem;
    color: #444;
}
.kpi-value {
    margin-top: 4px;
    font-weight: 700;
    font-size: 1.1rem;
}
.kpi-sub {
    font-size: 0.8rem;
    color: #666;
}

/* simple buttons */
.btn-sm {
    display: inline-block;
    padding: 4px 10px;
    font-size: 0.8rem;
    border-radius: 999px;
    border: 1px solid #ccc;
    background: #f8f9fa;
    text-decoration: none;
    color: #333;
    cursor: pointer;
    margin-left: 4px;
}
.btn-sm:hover {
    background: #e9ecef;
}

/* form rows */
.form-row {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 8px;
}
.form-row label {
    font-size: 0.85rem;
}
.form-row select {
    min-width: 150px;
}

/* tables */
.table-wrapper {
    width: 100%;
    overflow-x: auto;
}
.table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
    min-width: 800px;
}
.table th, .table td {
    border: 1px solid #e5e5e5;
    padding: 4px 6px;
}
.table th {
    background: #f1f3f5;
}

/* print header */
.print-header-vr {
    display: none;
    margin-bottom: 10px;
    padding-bottom: 10px;
    border-bottom: 1px solid #000;
}
.print-header-vr h1 {
    margin: 0 0 4px 0;
    font-size: 1.2rem;
}
.print-header-vr p {
    margin: 0;
    font-size: 0.85rem;
}

/* icons in section headings */
.section-title {
    display:flex;
    align-items:center;
    gap:6px;
}

/* PRINT RULES – hide site header/nav, show print header */
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
        display: none !important;
    }
    .view-page-container {
        margin: 0;
        max-width: 100%;
    }
    .print-header-vr {
        display: block;
    }
}
</style>

<div class="view-page-container">

    <!-- PRINT HEADER -->
    <div class="print-header-vr">
        <h1>Nexus Ethiopia Project Performance Report</h1>
        <p><strong>Project Title:</strong> <?php echo h($projectLabel); ?></p>
        <p>
            <strong>Region:</strong> <?php echo h($regionNameHeader); ?>
            &nbsp;&nbsp; <strong>Zone:</strong> <?php echo h($zoneNameHeader); ?>
            &nbsp;&nbsp; <strong>Name of Woreda:</strong> <?php echo h($woredaNameHeader); ?>
        </p>
        <p>
            <strong>Report type:</strong> <?php echo h($reportType); ?>
            &nbsp;&nbsp; <strong>Reporting Month:</strong> <?php echo h($reportingMonth); ?>
        </p>
        <p><strong>Date:</strong> From <?php echo h($dateFromLabel); ?> to <?php echo h($dateToLabel); ?></p>
    </div>

    <!-- VIEW HEADER (ON SCREEN) -->
    <div class="card" style="margin-bottom:10px;padding:10px 12px;border:1px solid #e1e5ff;border-radius:10px;background:#fdfdff;">
        <div class="section-title">
            <h1>📊 View &amp; Analyze Indicator Reports</h1>
        </div>
        <p>
            <strong>Nexus Ethiopia Project Performance Report</strong><br>
            <strong>Project Title:</strong> <?php echo h($projectLabel); ?><br>
            <strong>Region:</strong> <?php echo h($regionNameHeader); ?>
            &nbsp;&nbsp; <strong>Zone:</strong> <?php echo h($zoneNameHeader); ?>
            &nbsp;&nbsp; <strong>Name of Woreda:</strong> <?php echo h($woredaNameHeader); ?><br>
            <strong>Report type:</strong> <?php echo h($reportType); ?>
            &nbsp;&nbsp; <strong>Reporting Month:</strong> <?php echo h($reportingMonth); ?><br>
            <strong>Date:</strong> From <?php echo h($dateFromLabel); ?> to <?php echo h($dateToLabel); ?>
        </p>
        <p style="margin-top:6px;">
            This viewer is fully linked to <strong>Enter Data</strong> (indicator_reports),
            your <strong>Projects</strong> and planning modules. Filter by project(s),
            time period and geography, then view:
            <strong>raw records</strong>, <strong>SADD aggregation</strong>,
            and <strong>charts</strong> – all from the same data.
        </p>

        <?php if ($error): ?>
            <p style="color:#b00020;font-weight:bold;"><?php echo h($error); ?></p>
        <?php endif; ?>

        <form method="get" style="margin-bottom:10px;">
            <div class="form-row">
                <label>📁 Projects (single or multi)
                    <br>
                    <select name="project_ids[]" multiple size="3">
                        <?php foreach ($projects as $p): ?>
                            <?php $pid = (int)$p['id']; ?>
                            <option value="<?php echo $pid; ?>"
                                <?php echo in_array($pid, $projectIds, true) ? 'selected' : ''; ?>>
                                <?php echo h($p['title'] ?? ($p['name'] ?? '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <br><small>Hold Ctrl (or long-press) to select more than one.</small>
                </label>

                <label>🌍 Region
                    <br>
                    <select name="region_id">
                        <option value="">All</option>
                        <?php foreach ($regions as $r): ?>
                            <option value="<?php echo (int)$r['id']; ?>"
                                <?php echo ((string)$selected_region_id === (string)$r['id'] ? 'selected' : ''); ?>>
                                <?php echo h($r['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>🧭 Zone (single / multi)
                    <br>
                    <select name="zone_id[]" multiple size="3">
                        <option value="">All</option>
                        <?php foreach ($zones as $z): ?>
                            <option value="<?php echo (int)$z['id']; ?>"
                                <?php echo in_array((int)$z['id'], $zoneIds, true) ? 'selected' : ''; ?>>
                                <?php echo h($z['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <br><small>Hold Ctrl (or long-press) for multi.</small>
                </label>

                <label>🏘️ Woreda (single / multi)
                    <br>
                    <select name="woreda_id[]" multiple size="3">
                        <option value="">All</option>
                        <?php foreach ($woredas as $w): ?>
                            <option value="<?php echo (int)$w['id']; ?>"
                                <?php echo in_array((int)$w['id'], $woredaIds, true) ? 'selected' : ''; ?>>
                                <?php echo h($w['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <br><small>Linked to first selected zone.</small>
                </label>
            </div>

            <div class="form-row">
                <label>📅 Year from
                    <br>
                    <select name="year_from">
                        <option value="">Any</option>
                        <?php for ($y = 2020; $y <= 2050; $y++): ?>
                            <option value="<?php echo $y; ?>"
                                <?php echo ($filters['year_from'] == $y ? 'selected' : ''); ?>>
                                <?php echo $y; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </label>

                <label>📅 Year to
                    <br>
                    <select name="year_to">
                        <option value="">Any</option>
                        <?php for ($y = 2020; $y <= 2050; $y++): ?>
                            <option value="<?php echo $y; ?>"
                                <?php echo ($filters['year_to'] == $y ? 'selected' : ''); ?>>
                                <?php echo $y; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </label>

                <label>🗓️ Month from
                    <br>
                    <select name="month_from">
                        <option value="">Any</option>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>"
                                <?php echo ($filters['month_from'] == $m ? 'selected' : ''); ?>>
                                <?php echo date('F', mktime(0,0,0,$m,1)); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </label>

                <label>🗓️ Month to
                    <br>
                    <select name="month_to">
                        <option value="">Any</option>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>"
                                <?php echo ($filters['month_to'] == $m ? 'selected' : ''); ?>>
                                <?php echo date('F', mktime(0,0,0,$m,1)); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </label>

                <label>⏱️ Period type
                    <br>
                    <select name="period_type">
                        <option value="">All</option>
                        <option value="weekly"    <?php echo ($filters['period_type'] == 'weekly'    ? 'selected' : ''); ?>>Weekly</option>
                        <option value="monthly"   <?php echo ($filters['period_type'] == 'monthly'   ? 'selected' : ''); ?>>Monthly</option>
                        <option value="quarterly" <?php echo ($filters['period_type'] == 'quarterly' ? 'selected' : ''); ?>>Quarterly</option>
                        <option value="annual"    <?php echo ($filters['period_type'] == 'annual'    ? 'selected' : ''); ?>>Annual</option>
                    </select>
                </label>

                <label>⚡ Quick range
                    <br>
                    <select name="quick_range">
                        <option value="">None</option>
                        <option value="past_2m"  <?php echo ($filters['quick_range'] == 'past_2m'  ? 'selected' : ''); ?>>Past 2 months</option>
                        <option value="past_3m"  <?php echo ($filters['quick_range'] == 'past_3m'  ? 'selected' : ''); ?>>Past 3 months</option>
                        <option value="past_6m"  <?php echo ($filters['quick_range'] == 'past_6m'  ? 'selected' : ''); ?>>Past 6 months</option>
                        <option value="past_9m"  <?php echo ($filters['quick_range'] == 'past_9m'  ? 'selected' : ''); ?>>Past 9 months</option>
                        <option value="past_11m" <?php echo ($filters['quick_range'] == 'past_11m' ? 'selected' : ''); ?>>Past 11 months</option>
                        <option value="past_1w"  <?php echo ($filters['quick_range'] == 'past_1w'  ? 'selected' : ''); ?>>Past 1 week</option>
                        <option value="past_2w"  <?php echo ($filters['quick_range'] == 'past_2w'  ? 'selected' : ''); ?>>Past 2 weeks</option>
                        <option value="past_3w"  <?php echo ($filters['quick_range'] == 'past_3w'  ? 'selected' : ''); ?>>Past 3 weeks</option>
                        <option value="past_4w"  <?php echo ($filters['quick_range'] == 'past_4w'  ? 'selected' : ''); ?>>Past 4 weeks</option>
                    </select>
                </label>
            </div>

            <div class="form-row">
                <label>🎯 Indicator (all projects) – multi
                    <br>
                    <select name="indicator_key[]" multiple size="3">
                        <option value="">All indicators</option>
                        <?php foreach ($indicatorFilterOptions as $ind): ?>
                            <?php
                                $key   = $ind['level'] . ':' . $ind['id'];
                                $label = strtoupper($ind['level']) . ' | ';
                                if (!empty($ind['project_title'])) {
                                    $label .= '[' . $ind['project_title'] . '] ';
                                }
                                if (!empty($ind['indicator_code'])) {
                                    $label .= $ind['indicator_code'] . ' – ';
                                }
                                $label .= $ind['indicator_name'];
                            ?>
                            <option value="<?php echo h($key); ?>"
                                <?php echo in_array($key, $filters['indicator_keys'], true) ? 'selected' : ''; ?>>
                                <?php echo h($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <br><small>Leave empty to include all.</small>
                </label>

                <label>👥 Beneficiary type (from indicator) – multi
                    <br>
                    <select name="benef_type[]" multiple size="3">
                        <option value="">All</option>
                        <option value="host"       <?php echo in_array('host', $filters['benef_types'], true)       ? 'selected' : ''; ?>>Host community</option>
                        <option value="idp"        <?php echo in_array('idp', $filters['benef_types'], true)        ? 'selected' : ''; ?>>IDP</option>
                        <option value="returnee"   <?php echo in_array('returnee', $filters['benef_types'], true)   ? 'selected' : ''; ?>>Returnee</option>
                        <option value="refugee"    <?php echo in_array('refugee', $filters['benef_types'], true)    ? 'selected' : ''; ?>>Refugee</option>
                        <option value="pwd"        <?php echo in_array('pwd', $filters['benef_types'], true)        ? 'selected' : ''; ?>>People with disability</option>
                        <option value="total"      <?php echo in_array('total', $filters['benef_types'], true)      ? 'selected' : ''; ?>>Total</option>
                        <option value="non_person" <?php echo in_array('non_person', $filters['benef_types'], true) ? 'selected' : ''; ?>>Non person</option>
                    </select>
                    <br><small>Filter aggregation by beneficiary type in indicator metadata.</small>
                </label>

                <label>👀 View as
                    <br>
                    <select name="display_mode">
                        <option value="table" <?php echo ($filters['display_mode'] == 'table' ? 'selected' : ''); ?>>Table + analytics</option>
                        <option value="chart" <?php echo ($filters['display_mode'] == 'chart' ? 'selected' : ''); ?>>Charts only</option>
                    </select>
                </label>

                <label>&nbsp;<br>
                    <button type="submit" class="btn-sm">Apply filters</button>
                    <a href="view_reports.php" class="btn-sm">Reset</a>
                    <button type="button" class="btn-sm" onclick="window.print();">Print / PDF (with header)</button>
                </label>
            </div>
        </form>

        <!-- KPI SUMMARY CARDS -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-label">👥 Total persons reached (SADD)</div>
                <div class="kpi-value"><?php echo number_format($totPersons); ?></div>
                <div class="kpi-sub">Across all selected projects, periods &amp; filters</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">♿ Persons with disability (PWD)</div>
                <div class="kpi-value"><?php echo number_format($totPWD); ?></div>
                <div class="kpi-sub">Share of total (incl. PWD): <?php echo $pwdShare; ?>%</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">👧👩 Women &amp; girls share</div>
                <div class="kpi-value"><?php echo $wgShare; ?>%</div>
                <div class="kpi-sub">Women + girls divided by all persons</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">🧒 Children (&lt;18) share</div>
                <div class="kpi-value"><?php echo $childrenShare; ?>%</div>
                <div class="kpi-sub">Under 5 + 5–17 out of all persons</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">📦 Total non-person value</div>
                <div class="kpi-value"><?php echo number_format($totNonPerson, 2); ?></div>
                <div class="kpi-sub">Facilities, sessions, kits, etc. (non-person)</div>
            </div>
        </div>
    </div>

    <!-- ================== 1. RAW REPORT LIST ================== -->
    <div class="card">
        <div class="section-title">
            <h2>1. 📋 Raw report records (from Enter Data)</h2>
        </div>
        <p style="font-size:0.9rem;">
            Each row = one saved indicator report from <strong>enter_data.php</strong>,
            including the full <strong>report entry, beneficiary breakdown and analytics</strong>
            SADD structure – similar to the <strong>“Saved reports &amp; aggregation”</strong> view.
        </p>

        <div class="table-wrapper">
            <table class="table">
                <thead>
                <tr>
                    <th rowspan="2">SN</th>
                    <th rowspan="2">Project</th>
                    <th rowspan="2">Indicator level</th>
                    <th rowspan="2">Indicator code</th>
                    <th rowspan="2">Indicator name</th>
                    <th rowspan="2">Beneficiary type</th>
                    <th rowspan="2">Region</th>
                    <th rowspan="2">Zone</th>
                    <th rowspan="2">Woreda</th>
                    <th rowspan="2">Period</th>
                    <th rowspan="2">Start date</th>
                    <th rowspan="2">End date</th>
                    <th colspan="8">Persons reached (sex &amp; age)</th>
                    <th rowspan="2">PWD total</th>
                    <th rowspan="2">Non-beneficiaries</th>
                    <th rowspan="2">Non-person value</th>
                    <th rowspan="2">Total persons</th>
                    <th rowspan="2">Created</th>
                    <th rowspan="2">Action</th>
                </tr>
                <tr>
                    <th>Boys &lt;5</th>
                    <th>Girls &lt;5</th>
                    <th>Boys 5–17</th>
                    <th>Girls 5–17</th>
                    <th>Men 18–59</th>
                    <th>Women 18–59</th>
                    <th>Men 60+</th>
                    <th>Women 60+</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($reportRows): ?>
                    <?php $sn = 1; ?>
                    <?php foreach ($reportRows as $r): ?>
                        <?php
                            $boys_u5     = (int)$r['boys_u5'];
                            $girls_u5    = (int)$r['girls_u5'];
                            $boys_5_17   = (int)$r['boys_5_17'];
                            $girls_5_17  = (int)$r['girls_5_17'];
                            $men_18_59   = (int)$r['men_18_59'];
                            $women_18_59 = (int)$r['women_18_59'];
                            $men_60p     = (int)$r['men_60p'];
                            $women_60p   = (int)$r['women_60p'];

                            $boysTotal   = $boys_u5 + $boys_5_17;
                            $girlsTotal  = $girls_u5 + $girls_5_17;
                            $menTotal    = $men_18_59 + $men_60p;
                            $womenTotal  = $women_18_59 + $women_60p;
                            $totalPerson = $boysTotal + $girlsTotal + $menTotal + $womenTotal;

                            $periodText = strtoupper($r['period_type']) . ' ' . $r['year'];
                            if (!empty($r['month'])) {
                                $periodText .= ' / M' . (int)$r['month'];
                            }
                            if (!empty($r['week'])) {
                                $periodText .= ' / W' . (int)$r['week'];
                            }

                            $benefKey   = $r['beneficiary_type'];
                            $benefLabel = $benefLabels[$benefKey] ?? $benefKey;

                            $pwdTotal   = (int)$r['pwd_count'];
                            $nonBen     = (int)$r['non_beneficiary'];
                            $nonPersonV = (float)$r['non_person_value'];
                        ?>
                        <tr>
                            <td><?php echo $sn++; ?></td>
                            <td><?php echo h($r['project_title'] ?? ($projectMap[$r['project_id']] ?? '')); ?></td>
                            <td><?php echo h(ucfirst($r['indicator_level'])); ?></td>
                            <td><?php echo h($r['indicator_code']); ?></td>
                            <td><?php echo h($r['indicator_name']); ?></td>
                            <td><?php echo h($benefLabel); ?></td>
                            <td><?php echo h($r['region_name'] ?? ''); ?></td>
                            <td><?php echo h($r['zone_name']   ?? ''); ?></td>
                            <td><?php echo h($r['woreda_name'] ?? ''); ?></td>
                            <td><?php echo h($periodText); ?></td>
                            <td><?php echo h($r['start_date'] ?? ''); ?></td>
                            <td><?php echo h($r['end_date']   ?? ''); ?></td>

                            <td><?php echo $boys_u5; ?></td>
                            <td><?php echo $girls_u5; ?></td>
                            <td><?php echo $boys_5_17; ?></td>
                            <td><?php echo $girls_5_17; ?></td>
                            <td><?php echo $men_18_59; ?></td>
                            <td><?php echo $women_18_59; ?></td>
                            <td><?php echo $men_60p; ?></td>
                            <td><?php echo $women_60p; ?></td>

                            <td><?php echo $pwdTotal; ?></td>
                            <td><?php echo $nonBen; ?></td>
                            <td><?php echo number_format($nonPersonV, 2); ?></td>
                            <td><?php echo number_format($totalPerson); ?></td>
                            <td><?php echo h($r['created_at'] ?? ''); ?></td>
                            <td>
                                <a class="btn-sm"
                                   href="enter_data.php?edit_id=<?php echo (int)$r['id']; ?>&project_id=<?php echo (int)$r['project_id']; ?>">
                                    ✏️ Edit
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="26">No reports match the selected filters.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ================== 2. INDICATOR PERFORMANCE ================== -->

    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;">
            <div class="section-title">
                <h2>2. 📈 Indicator performance (SADD aggregation)</h2>
            </div>
            <div>
                <?php
                    $queryBase = $_GET;
                    $queryBase['export'] = 'csv';
                ?>
                <a class="btn-sm" href="?<?php echo h(http_build_query($queryBase)); ?>">Download CSV</a>
                <?php
                    $queryBase['export'] = 'word';
                ?>
                <a class="btn-sm" href="?<?php echo h(http_build_query($queryBase)); ?>">Download Word</a>
                <button type="button" class="btn-sm" onclick="window.print();">Print / PDF</button>
            </div>
        </div>
        <p style="font-size:0.9rem;">
            This section aggregates <strong>indicator_reports</strong> by
            <strong>indicator</strong> and <strong>beneficiary type</strong>, using
            the same Nexus SADD logic as in the Enter Data aggregation.
            To keep the view and printout manageable, performance is split into
            two linked tables: <strong>2A. Indicator progress overview</strong> and
            <strong>2B. Budget &amp; SADD performance</strong>.
        </p>

        <?php if ($filters['display_mode'] === 'table'): ?>

            <!-- 2A. Progress overview per indicator -->
            <h3>2A. Indicator progress overview</h3>
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                    <tr>
                        <th>SN</th>
                        <th>Indicator level</th>
                        <th>Indicator</th>
                        <th>Beneficiary type</th>
                        <th>Unit</th>
                        <th>Total persons</th>
                        <th>PWD total</th>
                        <th>Share of total reach (%)</th>
                        <th>Share of PWD (%)</th>
                        <th>Progress notes (auto)</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($indicatorAgg): ?>
                        <?php $sn = 1; ?>
                        <?php foreach ($indicatorAgg as $row): ?>
                            <?php
                                $boys_u5     = (int)$row['boys_u5'];
                                $girls_u5    = (int)$row['girls_u5'];
                                $boys_5_17   = (int)$row['boys_5_17'];
                                $girls_5_17  = (int)$row['girls_5_17'];
                                $men_18_59   = (int)$row['men_18_59'];
                                $women_18_59 = (int)$row['women_18_59'];
                                $men_60p     = (int)$row['men_60p'];
                                $women_60p   = (int)$row['women_60p'];
                                $pwd_count   = (int)$row['pwd_count'];

                                $boysTotal   = $boys_u5 + $boys_5_17;
                                $girlsTotal  = $girls_u5 + $girls_5_17;
                                $menTotal    = $men_18_59 + $men_60p;
                                $womenTotal  = $women_18_59 + $women_60p;
                                $totalPerson = $boysTotal + $girlsTotal + $menTotal + $womenTotal;

                                $portfolioShare = $totPersons > 0
                                    ? round(($totalPerson / $totPersons) * 100, 1)
                                    : 0;

                                $pwdShareRow = ($totalPerson + $pwd_count) > 0
                                    ? round(($pwd_count / ($totalPerson + $pwd_count)) * 100, 1)
                                    : 0;

                                $benefKey   = $row['beneficiary_type'];
                                $benefLabel = $benefLabels[$benefKey] ?? $benefKey;

                                if ($totalPerson === 0) {
                                    $progressNote = 'No reach reported for this indicator in the selected filters.';
                                } else {
                                    $noteParts = [];
                                    if ($portfolioShare < 5) {
                                        $noteParts[] = "Low contribution ({$portfolioShare}% of total reach).";
                                    } elseif ($portfolioShare <= 20) {
                                        $noteParts[] = "Moderate contribution ({$portfolioShare}% of total reach).";
                                    } else {
                                        $noteParts[] = "Key contributor ({$portfolioShare}% of total reach).";
                                    }

                                    if ($pwdShareRow > 0 && $pwdShareRow < 4) {
                                        $noteParts[] = "Very low PWD share ({$pwdShareRow}%).";
                                    } elseif ($pwdShareRow > 15) {
                                        $noteParts[] = "High PWD share ({$pwdShareRow}%) – document good practice.";
                                    }

                                    if (!$noteParts) {
                                        $noteParts[] = "On track overall for the selected period.";
                                    }
                                    $progressNote = implode(' ', $noteParts);
                                }
                            ?>
                            <tr>
                                <td><?php echo $sn++; ?></td>
                                <td><?php echo h(ucfirst($row['indicator_level'])); ?></td>
                                <td><?php echo h($row['indicator_code'] . ' – ' . $row['indicator_name']); ?></td>
                                <td><?php echo h($benefLabel); ?></td>
                                <td><?php echo h($row['unit_type']); ?></td>
                                <td><?php echo number_format($totalPerson); ?></td>
                                <td><?php echo number_format($pwd_count); ?></td>
                                <td><?php echo $portfolioShare; ?></td>
                                <td><?php echo $pwdShareRow; ?></td>
                                <td><?php echo h($progressNote); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="10">No indicator data for the selected filters.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- 2B. Budget & SADD performance -->
            <h3 style="margin-top:18px;">2B. Budget &amp; SADD performance</h3>
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                    <tr>
                        <th rowspan="2">SN</th>
                        <th rowspan="2">Indicator level</th>
                        <th rowspan="2">Indicator</th>
                        <th rowspan="2">Beneficiary type</th>
                        <th rowspan="2">Unit</th>
                        <th rowspan="2">Budget planned – period</th>
                        <th rowspan="2">Budget used – period</th>
                        <th rowspan="2">% Budget utilization</th>
                        <th colspan="5">Auto totals (SADD; PWD excluded)</th>
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
                    <?php if ($indicatorAgg): ?>
                        <?php $sn = 1; ?>
                        <?php foreach ($indicatorAgg as $row): ?>
                            <?php
                                $boys_u5     = (int)$row['boys_u5'];
                                $girls_u5    = (int)$row['girls_u5'];
                                $boys_5_17   = (int)$row['boys_5_17'];
                                $girls_5_17  = (int)$row['girls_5_17'];
                                $men_18_59   = (int)$row['men_18_59'];
                                $women_18_59 = (int)$row['women_18_59'];
                                $men_60p     = (int)$row['men_60p'];
                                $women_60p   = (int)$row['women_60p'];

                                $boysTotal   = $boys_u5 + $boys_5_17;
                                $girlsTotal  = $girls_u5 + $girls_5_17;
                                $menTotal    = $men_18_59 + $men_60p;
                                $womenTotal  = $women_18_59 + $women_60p;
                                $totalPerson = $boysTotal + $girlsTotal + $menTotal + $womenTotal;

                                $pwd_count      = (int)$row['pwd_count'];
                                $benefKey       = $row['beneficiary_type'];
                                $benefLabel     = $benefLabels[$benefKey] ?? $benefKey;

                                // Budget from planning table (indicator_budget)
                                $bKey          = $row['indicator_level'] . ':' . $row['indicator_id'];
                                $budgetPlanned = null;
                                $budgetUsed    = null;
                                $budgetUtilPct = null;

                                if (isset($budgetMap[$bKey])) {
                                    $budgetPlanned = $budgetMap[$bKey]['planned'];
                                    $budgetUsed    = $budgetMap[$bKey]['used'];
                                    if ($budgetPlanned > 0 && $budgetUsed !== null) {
                                        $budgetUtilPct = round(($budgetUsed / $budgetPlanned) * 100, 1);
                                    }
                                }

                                // Row-level analytics for AI-powered feedback
                                if ($totalPerson === 0) {
                                    $feedback = 'No progress reported for this indicator in the selected period.';
                                } else {
                                    $childrenRow   = $boys_u5 + $girls_u5 + $boys_5_17 + $girls_5_17;
                                    $womenGirlsRow = $girlsTotal + $womenTotal;
                                    $wgShareRow    = $totalPerson > 0 ? round(($womenGirlsRow / $totalPerson) * 100, 1) : 0;
                                    $childShareRow = $totalPerson > 0 ? round(($childrenRow / $totalPerson) * 100, 1) : 0;
                                    $pwdShareRow   = ($totalPerson + $pwd_count) > 0
                                                     ? round(($pwd_count / ($totalPerson + $pwd_count)) * 100, 1)
                                                     : 0;

                                    // Contribution of this indicator to all selected reach
                                    $portfolioShareRow = $totPersons > 0
                                        ? round(($totalPerson / $totPersons) * 100, 1)
                                        : 0;

                                    $fbParts = [];

                                    // Portfolio progress (how much this indicator contributes)
                                    if ($portfolioShareRow < 5) {
                                        $fbParts[] = "Low contribution to overall progress ({$portfolioShareRow}% of all persons reached).";
                                    } elseif ($portfolioShareRow <= 20) {
                                        $fbParts[] = "Moderate contribution ({$portfolioShareRow}% of all persons reached).";
                                    } else {
                                        $fbParts[] = "Key contributor to project results ({$portfolioShareRow}% of all persons reached).";
                                    }

                                    // Gender balance
                                    if ($wgShareRow < 45) {
                                        $fbParts[] = "Gender gap: women & girls are only {$wgShareRow}% of people reached.";
                                    } elseif ($wgShareRow > 60) {
                                        $fbParts[] = "High participation of women & girls ({$wgShareRow}%) – check that men & boys are not unintentionally excluded.";
                                    }

                                    // Children focus
                                    if ($childShareRow > 60) {
                                        $fbParts[] = "Majority of reach are children (&lt;18) ({$childShareRow}%) – confirm this matches the indicator target group.";
                                    }

                                    // PWD inclusion
                                    if ($pwd_count > 0 && $pwdShareRow < 4) {
                                        $fbParts[] = "Very low inclusion of PWD ({$pwdShareRow}% of total incl. PWD) – consider targeted inclusion strategies.";
                                    } elseif ($pwdShareRow > 15) {
                                        $fbParts[] = "High proportion of PWD reached ({$pwdShareRow}%) – document good practice and verify data quality.";
                                    }

                                    // Budget comments (when available)
                                    if ($budgetUtilPct !== null) {
                                        if ($budgetUtilPct < 70) {
                                            $fbParts[] = "Low budget absorption ({$budgetUtilPct}%) – check activity phasing and procurement delays.";
                                        } elseif ($budgetUtilPct > 120) {
                                            $fbParts[] = "Budget use &gt;120% – verify coding and reallocations.";
                                        }
                                    }

                                    if (!$fbParts) {
                                        $fbParts[] = "On track overall – this indicator’s progress and SADD distribution look broadly balanced for the selected period.";
                                    }

                                    $feedback = implode(' ', $fbParts);
                                }
                            ?>
                            <tr>
                                <td><?php echo $sn++; ?></td>
                                <td><?php echo h(ucfirst($row['indicator_level'])); ?></td>
                                <td><?php echo h($row['indicator_code'] . ' – ' . $row['indicator_name']); ?></td>
                                <td><?php echo h($benefLabel); ?></td>
                                <td><?php echo h($row['unit_type']); ?></td>

                                <td><?php echo $budgetPlanned === null ? 'N/A' : number_format($budgetPlanned, 2); ?></td>
                                <td><?php echo $budgetUsed    === null ? 'N/A' : number_format($budgetUsed, 2); ?></td>
                                <td><?php echo $budgetUtilPct === null ? 'N/A' : h($budgetUtilPct . '%'); ?></td>

                                <td><?php echo h($boysTotal); ?></td>
                                <td><?php echo h($girlsTotal); ?></td>
                                <td><?php echo h($menTotal); ?></td>
                                <td><?php echo h($womenTotal); ?></td>
                                <td><?php echo h($totalPerson); ?></td>
                                <td><?php echo h($feedback); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="14">No indicator data for the selected filters.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>
            <p style="font-size:0.9rem;">
                <strong>Chart view:</strong> total persons (all beneficiary types) per indicator,
                based on your filters.
            </p>
            <canvas id="indicatorChart" style="max-width:100%;height:380px;"></canvas>
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
                                    ticks: { autoSkip: false, maxRotation: 60, minRotation: 30 }
                                },
                                y: {
                                    beginAtZero: true
                                }
                            }
                        }
                    });
                })();
            </script>
        <?php endif; ?>

        <div style="margin-top:10px;">
            <strong>🔗 Share this view:</strong>
            <?php
                $shareText = urlencode("Nexus Ethiopia report view – " . $currentUrl);
                $shareUrl  = urlencode($currentUrl);
            ?>
            <a class="btn-sm" href="mailto:?subject=Nexus Project Report&body=<?php echo $shareText; ?>">Email</a>
            <a class="btn-sm" href="https://wa.me/?text=<?php echo $shareText; ?>" target="_blank">WhatsApp</a>
            <a class="btn-sm" href="https://t.me/share/url?url=<?php echo $shareUrl; ?>&text=<?php echo $shareText; ?>" target="_blank">Telegram</a>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/../footer.php'; ?>
