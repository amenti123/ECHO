<?php
require_once __DIR__ . '/../header.php';
require_login();
$pdo = getPDO();

/**
 * PROGRESS TRACKING DASHBOARD
 * - Activity progress (planned vs achieved placeholder + budget utilization)
 * - Indicator progress (from report_values SADD totals)
 * - Overall project progress summary (activities, indicators, budget)
 * - Per-project and per-location filters, charts, exports, and smart insight messages
 */

/* ---------------- Shared helper: safe column detection --------------------- */
if (!function_exists('table_has_column')) {
    function table_has_column(PDO $pdo, string $table, string $column): bool {
        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
            $stmt->execute([$column]);
            return (bool)$stmt->fetch();
        } catch (Exception $e) {
            error_log("table_has_column error in progress.php: " . $e->getMessage());
            return false;
        }
    }
}

/* -------------------------- Helper: project label -------------------------- */
function project_label_for_activity(array $row): string {
    $projectId = $row['project_id'] ?? null;
    $label     = $projectId ? ('Project #' . $projectId) : 'Project – N/A';

    if (!empty($row['title'])) {
        $label = $row['title'];          // if projects table has `title`
    } elseif (!empty($row['project_name'])) {
        $label = $row['project_name'];   // if projects table has `name` or similar
    } elseif (!empty($row['name'])) {
        $label = $row['name'];
    }

    return $label;
}

/* ----------------------- Detect key project columns ------------------------ */
$projHasRegionCol  = table_has_column($pdo, 'projects', 'region');
$projHasZoneCol    = table_has_column($pdo, 'projects', 'zone');
$projHasWoredaCol  = table_has_column($pdo, 'projects', 'woreda');

// Detect which column holds donor info in projects
$projectDonorColumn = null;
foreach (['donor', 'donor_name', 'funder', 'funding_source'] as $dc) {
    if (table_has_column($pdo, 'projects', $dc)) {
        $projectDonorColumn = $dc;
        break;
    }
}

/* ------------------------- Optional project filter ------------------------- */

// Cross-app shared helper if available
$projects   = function_exists('get_projects') ? get_projects() : [];
$projectMap = [];
foreach ($projects as $p) {
    $projectMap[$p['id']] = $p['title'] ?? ($p['name'] ?? ('Project #' . $p['id']));
}
$filterProjectId = isset($_GET['project_id']) && $_GET['project_id'] !== ''
    ? (int)$_GET['project_id']
    : null;

// Load the current project row for header info
$currentProject = null;
if ($filterProjectId) {
    $stmtProj = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
    $stmtProj->execute([$filterProjectId]);
    $currentProject = $stmtProj->fetch();
}

/* ----------------------- Ethiopia-Specific Donors List --------------------- */
$ethiopiaDonors = [
    // UN Agencies
    'EHF/UNOCHA',
    'UNDP',
    'UNICEF',
    'WFP',
    'WHO',
    'UNHCR',
    'FAO',
    'UNESCO',
    'UNFPA',
    'IOM',
    'UN-Habitat',
    'UN Women',
    'UNIDO',
    'UNEP',

    // International Financial Institutions
    'World Bank',
    'IMF',
    'African Development Bank',
    'Islamic Development Bank',

    // Bilateral Donors
    'USAID',
    'UK Foreign, Commonwealth & Development Office (FCDO)',
    'Global Affairs Canada',
    'SIDA (Swedish International Development Cooperation Agency)',
    'GIZ (German Society for International Cooperation)',
    'JICA (Japan International Cooperation Agency)',
    'KOICA (Korea International Cooperation Agency)',
    'DFAT (Australian Department of Foreign Affairs and Trade)',
    'NORAD (Norwegian Agency for Development Cooperation)',
    'DANIDA (Danish International Development Agency)',
    'SDC (Swiss Agency for Development and Cooperation)',
    'Ministry of Foreign Affairs of the Netherlands',
    'AFD (French Development Agency)',
    'Italian Agency for Development Cooperation',
    'Spanish Agency for International Development Cooperation',
    'European Union',

    // International NGOs operating in Ethiopia
    'CARE International',
    'Oxfam',
    'Save the Children',
    'World Vision',
    'Mercy Corps',
    'International Rescue Committee',
    'Norwegian Refugee Council',
    'Danish Refugee Council',
    'Plan International',
    'Catholic Relief Services',
    'Concern Worldwide',
    'Action Against Hunger',
    'Medecins Sans Frontieres (Doctors Without Borders)',
    'International Medical Corps',
    'World Jewish Relief',
    'Lutheran World Federation',
    'Christian Aid',
    'Tearfund',
    'World Renew',
    'Food for the Hungry',
    'Samaritan\'s Purse',
    'ADRA (Adventist Development and Relief Agency)',
    'BRAC',
    'Handicap International - Humanity & Inclusion',
    'People in Need',
    'Solidarités International',
    'ACTED',
    'Welthungerhilfe',
    'HelpAge International',

    // Foundations
    'Bill & Melinda Gates Foundation',
    'The Rockefeller Foundation',
    'Mastercard Foundation',
    'Open Society Foundations',

    'Other'
];

/* ----------------------- Ethiopian Regions List ---------------------------- */
$ethiopianRegions = [
    'Addis Ababa',
    'Afar',
    'Amhara',
    'Benishangul-Gumuz',
    'Dire Dawa',
    'Gambela',
    'Harari',
    'Oromia',
    'Sidama',
    'Somali',
    'Southern Nations, Nationalities, and Peoples\' Region (SNNPR)',
    'Tigray',
    'South West Ethiopia Peoples\' Region',
    'Other'
];

/* ----------------------- Location Dropdown Data (optional) ----------------- */
$zones   = [];
$woredas = [];

try {
    // Get all unique zones from existing projects
    $stmtZones = $pdo->query("SELECT DISTINCT zone FROM projects WHERE zone IS NOT NULL AND zone != '' ORDER BY zone");
    $zones     = $stmtZones->fetchAll(PDO::FETCH_COLUMN);

    // Get all unique woredas from existing projects
    $stmtWoredas = $pdo->query("SELECT DISTINCT woreda FROM projects WHERE woreda IS NOT NULL AND woreda != '' ORDER BY woreda");
    $woredas     = $stmtWoredas->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    // Silent – used only to enrich dropdowns if possible
}

/* -------------------------- Location / Donor filters ----------------------- */

$filterDonor = '';
if (isset($_GET['donor']) && $_GET['donor'] !== '') {
    $filterDonor = trim($_GET['donor']);
    if ($filterDonor === 'Other') {
        $filterDonor = trim($_GET['donor_manual'] ?? '');
    }
}

$filterRegion = '';
if (isset($_GET['region']) && $_GET['region'] !== '') {
    $filterRegion = trim($_GET['region']);
    if ($filterRegion === 'Other') {
        $filterRegion = trim($_GET['region_manual'] ?? '');
    }
}

$filterZone = isset($_GET['zone']) ? trim($_GET['zone']) : '';
$filterWoreda = isset($_GET['woreda']) ? trim($_GET['woreda']) : '';

/* -------------------- 1. ACTIVITY PROGRESS (with budget) ------------------- */

$sqlAct = "
    SELECT
        a.id      AS activity_id,
        a.project_id,
        a.output_id,
        a.code    AS activity_code,
        a.name    AS activity_name,
        a.unit    AS activity_unit,
        a.target  AS activity_target,
        p.*       -- all project fields (id, title, name, region, zone, woreda, donor, etc.)
    FROM activities a
    LEFT JOIN projects p ON a.project_id = p.id
    WHERE 1=1
";
$paramsAct = [];

if ($filterProjectId) {
    $sqlAct      .= " AND a.project_id = ? ";
    $paramsAct[] = $filterProjectId;
}
if ($filterRegion !== '' && $projHasRegionCol) {
    $sqlAct      .= " AND p.region = ? ";
    $paramsAct[] = $filterRegion;
}
if ($filterZone !== '' && $projHasZoneCol) {
    $sqlAct      .= " AND p.zone = ? ";
    $paramsAct[] = $filterZone;
}
if ($filterWoreda !== '' && $projHasWoredaCol) {
    $sqlAct      .= " AND p.woreda = ? ";
    $paramsAct[] = $filterWoreda;
}
if ($filterDonor !== '' && $projectDonorColumn) {
    $sqlAct      .= " AND p.`$projectDonorColumn` = ? ";
    $paramsAct[] = $filterDonor;
}

$sqlAct .= " ORDER BY a.project_id, a.code";

$stmtAct    = $pdo->prepare($sqlAct);
$stmtAct->execute($paramsAct);
$activities = $stmtAct->fetchAll();

// Budget totals per activity (use total_cost – we are NOT using total_etb)
$budgetTotals = [];

$sqlBud = "
    SELECT
        b.activity_id,
        SUM(b.total_cost) AS allocated_etb,
        SUM(b.total_cost) AS allocated_donor
    FROM activity_budget b
    JOIN activities a ON b.activity_id = a.id
    LEFT JOIN projects p ON a.project_id = p.id
    WHERE 1=1
";
$paramsBud = [];

if ($filterProjectId) {
    $sqlBud      .= " AND a.project_id = ? ";
    $paramsBud[] = $filterProjectId;
}
if ($filterRegion !== '' && $projHasRegionCol) {
    $sqlBud      .= " AND p.region = ? ";
    $paramsBud[] = $filterRegion;
}
if ($filterZone !== '' && $projHasZoneCol) {
    $sqlBud      .= " AND p.zone = ? ";
    $paramsBud[] = $filterZone;
}
if ($filterWoreda !== '' && $projHasWoredaCol) {
    $sqlBud      .= " AND p.woreda = ? ";
    $paramsBud[] = $filterWoreda;
}
if ($filterDonor !== '' && $projectDonorColumn) {
    $sqlBud      .= " AND p.`$projectDonorColumn` = ? ";
    $paramsBud[] = $filterDonor;
}

$sqlBud .= " GROUP BY b.activity_id";

$stmtBud    = $pdo->prepare($sqlBud);
$stmtBud->execute($paramsBud);
$budgetRows = $stmtBud->fetchAll();

foreach ($budgetRows as $b) {
    $budgetTotals[$b['activity_id']] = $b;
}

// Status counters for overall summary
$activityStatusCounts = [
    'Completed'   => 0,
    'On progress' => 0,
    'Not started' => 0,
];

/* ------------------- 2. INDICATOR PROGRESS (from reports) ------------------ */
/**
 * Filter by project + location + donor if selected (through projects table).
 */
$sqlInd = "
    SELECT
        i.id,
        i.code,
        i.name,
        i.unit_type,
        COALESCE(SUM(
            rv.boys_u5 + rv.girls_u5 +
            rv.boys_5_17 + rv.girls_5_17 +
            rv.men_18_59 + rv.women_18_59 +
            rv.men_60p + rv.women_60p
        ), 0) AS total_sadd,
        COALESCE(SUM(rv.boys_u5 + rv.boys_5_17), 0)   AS total_boys,
        COALESCE(SUM(rv.girls_u5 + rv.girls_5_17), 0) AS total_girls,
        COALESCE(SUM(rv.men_18_59 + rv.men_60p), 0)   AS total_men,
        COALESCE(SUM(rv.women_18_59 + rv.women_60p), 0) AS total_women
    FROM indicators i
    LEFT JOIN report_values rv ON rv.indicator_id = i.id
    LEFT JOIN reports r ON rv.report_id = r.id
    LEFT JOIN projects p ON p.id = r.project_id
";

$paramsInd    = [];
$conditionsInd = [];

if ($filterProjectId) {
    $conditionsInd[] = "r.project_id = ?";
    $paramsInd[]     = $filterProjectId;
}
if ($filterRegion !== '' && $projHasRegionCol) {
    $conditionsInd[] = "p.region = ?";
    $paramsInd[]     = $filterRegion;
}
if ($filterZone !== '' && $projHasZoneCol) {
    $conditionsInd[] = "p.zone = ?";
    $paramsInd[]     = $filterZone;
}
if ($filterWoreda !== '' && $projHasWoredaCol) {
    $conditionsInd[] = "p.woreda = ?";
    $paramsInd[]     = $filterWoreda;
}
if ($filterDonor !== '' && $projectDonorColumn) {
    $conditionsInd[] = "p.`$projectDonorColumn` = ?";
    $paramsInd[]     = $filterDonor;
}

if ($conditionsInd) {
    $sqlInd .= " WHERE " . implode(' AND ', $conditionsInd);
}

$sqlInd .= "
    GROUP BY i.id, i.code, i.name, i.unit_type
    ORDER BY i.code
";

$stmtInd = $pdo->prepare($sqlInd);
$stmtInd->execute($paramsInd);
$indicatorProgress = $stmtInd->fetchAll();

// Indicator status counters
$indicatorStatusCounts = [
    'Completed'   => 0,
    'On progress' => 0,
    'Not started' => 0,
];

/* --------------------- 3. BUDGET "STATUS" FOR SUMMARY ---------------------- */

$budgetStatusCounts = [
    'Completed'   => 0,
    'On progress' => 0,
    'Not started' => 0,
];

$paramsBudSummary = [];
$hasBudgetFilters = $filterProjectId
    || $filterRegion !== ''
    || $filterZone !== ''
    || $filterWoreda !== ''
    || ($filterDonor !== '' && $projectDonorColumn);

if ($hasBudgetFilters) {
    $sqlBudSummary = "
        SELECT b.total_cost AS total_etb
        FROM activity_budget b
        JOIN activities a ON b.activity_id = a.id
        LEFT JOIN projects p ON a.project_id = p.id
        WHERE 1=1
    ";
    if ($filterProjectId) {
        $sqlBudSummary     .= " AND a.project_id = ? ";
        $paramsBudSummary[] = $filterProjectId;
    }
    if ($filterRegion !== '' && $projHasRegionCol) {
        $sqlBudSummary     .= " AND p.region = ? ";
        $paramsBudSummary[] = $filterRegion;
    }
    if ($filterZone !== '' && $projHasZoneCol) {
        $sqlBudSummary     .= " AND p.zone = ? ";
        $paramsBudSummary[] = $filterZone;
    }
    if ($filterWoreda !== '' && $projHasWoredaCol) {
        $sqlBudSummary     .= " AND p.woreda = ? ";
        $paramsBudSummary[] = $filterWoreda;
    }
    if ($filterDonor !== '' && $projectDonorColumn) {
        $sqlBudSummary     .= " AND p.`$projectDonorColumn` = ? ";
        $paramsBudSummary[] = $filterDonor;
    }
} else {
    $sqlBudSummary = "SELECT total_cost AS total_etb FROM activity_budget";
}

$stmtBudSummary = $pdo->prepare($sqlBudSummary);
$stmtBudSummary->execute($paramsBudSummary);
$budgetLinesForSummary = $stmtBudSummary->fetchAll();

if ($budgetLinesForSummary) {
    foreach ($budgetLinesForSummary as $b) {
        $etb = (float)$b['total_etb'];
        if ($etb > 0) {
            $budgetStatusCounts['On progress']++;
        } else {
            $budgetStatusCounts['Not started']++;
        }
    }
}

/* ----------------- 4. PRE-CALCULATE ACTIVITY ROWS & STATUS ----------------- */

$activityRows = [];

foreach ($activities as $a) {
    $projLabel = project_label_for_activity($a);

    $target   = (float)($a['activity_target'] ?? 0);
    // TODO: wire $achieved to reporting data when activity -> indicator mapping is available
    $achieved = 0.0;
    $percent  = ($target > 0) ? round(($achieved / $target) * 100, 2) : 0.0;

    // Beneficiaries – placeholders (wire to data later if needed)
    $benMale   = 0;
    $benFemale = 0;
    $benTotal  = $benMale + $benFemale;

    // Location & donor labels from project row
    $regionLabel = $projHasRegionCol ? ($a['region'] ?? '') : '';
    $zoneLabel   = $projHasZoneCol ? ($a['zone'] ?? '') : '';
    $woredaLabel = $projHasWoredaCol ? ($a['woreda'] ?? '') : '';
    $donorLabel  = $projectDonorColumn && isset($a[$projectDonorColumn]) ? ($a[$projectDonorColumn] ?? '') : '';

    // Budget info
    $activityId    = $a['activity_id'];
    $allocated_etb = 0.0;
    $budget_used   = 0.0;
    $util_rate     = 0.0;

    if (isset($budgetTotals[$activityId])) {
        $allocated_etb = (float)$budgetTotals[$activityId]['allocated_etb'];
        // budget_used could later come from an expenditure table
        $budget_used   = 0.0;
        $util_rate     = ($allocated_etb > 0)
                         ? round(($budget_used / $allocated_etb) * 100, 2)
                         : 0.0;
    }

    // Status logic
    if ($percent >= 100 && $target > 0) {
        $status = 'Completed';
    } elseif ($percent > 0 && $percent < 100) {
        $status = 'On progress';
    } else {
        $status = 'Not started';
    }
    if (!isset($activityStatusCounts[$status])) {
        $activityStatusCounts[$status] = 0;
    }
    $activityStatusCounts[$status]++;

    $statusNote = ($status === 'Completed')
        ? 'Target fully achieved or exceeded.'
        : (($status === 'On progress')
            ? 'Achievements are progressing but below target.'
            : 'No achievement recorded yet.');

    $activityRows[] = [
        'proj_label'    => $projLabel,
        'region'        => $regionLabel,
        'zone'          => $zoneLabel,
        'woreda'        => $woredaLabel,
        'donor'         => $donorLabel,
        'activity_code' => $a['activity_code'],
        'activity_name' => $a['activity_name'],
        'unit'          => $a['activity_unit'],
        'target'        => $target,
        'achieved'      => $achieved,
        'percent'       => $percent,
        'ben_male'      => $benMale,
        'ben_female'    => $benFemale,
        'ben_total'     => $benTotal,
        'allocated_etb' => $allocated_etb,
        'budget_used'   => $budget_used,
        'util_rate'     => $util_rate,
        'status'        => $status,
        'status_note'   => $statusNote,
    ];
}

/* ----------------- 5. FILL INDICATOR STATUS COUNTS (SUMMARY) --------------- */

foreach ($indicatorProgress as $ip) {
    $achieved   = (float)$ip['total_sadd'];
    $targetInd  = 0.0; // no indicator target column yet
    $percentInd = ($targetInd > 0)
                  ? round(($achieved / $targetInd) * 100, 2)
                  : 0.0;

    if ($achieved > 0 && $targetInd > 0 && $percentInd >= 100) {
        $indStatus = 'Completed';
    } elseif ($achieved > 0) {
        $indStatus = 'On progress';
    } else {
        $indStatus = 'Not started';
    }

    if (!isset($indicatorStatusCounts[$indStatus])) {
        $indicatorStatusCounts[$indStatus] = 0;
    }
    $indicatorStatusCounts[$indStatus]++;
}

/* ------------------ 6. Overall counts & percentage conversion -------------- */

$totalActivities   = count($activities);
$totalIndicators   = count($indicatorProgress);
$totalBudgetLines  = count($budgetLinesForSummary);

$statusOrder        = ['Completed', 'On progress', 'Not started'];
$statusPercentages  = [];
foreach ($statusOrder as $status) {
    $actPct = $totalActivities
        ? round(($activityStatusCounts[$status] ?? 0) * 100 / $totalActivities, 2)
        : 0.0;
    $indPct = $totalIndicators
        ? round(($indicatorStatusCounts[$status] ?? 0) * 100 / $totalIndicators, 2)
        : 0.0;
    $budPct = $totalBudgetLines
        ? round(($budgetStatusCounts[$status] ?? 0) * 100 / $totalBudgetLines, 2)
        : 0.0;

    $avg = round(($actPct + $indPct + $budPct) / 3, 2);
    $statusPercentages[$status] = [
        'act' => $actPct,
        'ind' => $indPct,
        'bud' => $budPct,
        'avg' => $avg,
    ];
}

/* ---------------------- 7. AI-STYLE SMART INSIGHTS ------------------------- */

$aiInsights   = [];
$contextLabel = $filterProjectId ? 'this project' : 'all projects (current filters)';

if ($totalActivities || $totalIndicators || $totalBudgetLines) {
    $completedActPct = $statusPercentages['Completed']['act'] ?? 0.0;
    $completedIndPct = $statusPercentages['Completed']['ind'] ?? 0.0;
    $completedBudPct = $statusPercentages['Completed']['bud'] ?? 0.0;
    $overallAvgDone  = $statusPercentages['Completed']['avg'] ?? 0.0;

    if ($overallAvgDone < 30) {
        $aiInsights[] =
            "Overall implementation for $contextLabel is still in an early phase (about {$overallAvgDone}% completed). " .
            "Prioritize fast-tracking foundational activities, key procurements and community mobilization.";
    } elseif ($overallAvgDone < 70) {
        $aiInsights[] =
            "Implementation for $contextLabel is progressing (around {$overallAvgDone}% completed). " .
            "Maintain the current pace while focusing on bottleneck activities and under-performing locations.";
    } else {
        $aiInsights[] =
            "Implementation for $contextLabel is in an advanced stage (around {$overallAvgDone}% completed). " .
            "Focus on quality, documentation, and sustainability measures as the project approaches closure.";
    }

    // Compare budget vs activity / indicator progress for burn rate
    $maxProg = max($completedActPct, $completedIndPct);
    if ($completedBudPct > $maxProg + 15) {
        $aiInsights[] =
            "Budget absorption (≈{$completedBudPct}%) is ahead of activity and indicator completion. " .
            "Review spending plans carefully to ensure resources are aligned with tangible outputs and outcomes.";
    } elseif ($maxProg > $completedBudPct + 15) {
        $aiInsights[] =
            "Activities/indicators (≈{$maxProg}%) are ahead of budget absorption (≈{$completedBudPct}%). " .
            "Accelerate eligible expenditures (e.g. per diems, transport, supplies) to avoid large unspent balances.";
    }

    // Many activities not started
    $notStartedActPct = $statusPercentages['Not started']['act'] ?? 0.0;
    if ($notStartedActPct >= 40) {
        $aiInsights[] =
            "A considerable share of activities (≈{$notStartedActPct}%) are not yet started. " .
            "Review the Detailed Implementation Plan, clarify responsibilities and adjust timelines where necessary.";
    }

    // Identify the weakest dimension
    $dimMap = [
        'Activities' => $completedActPct,
        'Indicators' => $completedIndPct,
        'Budget'     => $completedBudPct,
    ];
    $minDim = array_keys($dimMap, min($dimMap))[0] ?? null;
    if ($minDim && max($dimMap) - min($dimMap) >= 10) {
        $aiInsights[] =
            "$minDim completion is lagging behind the other dimensions. " .
            "Agree targeted corrective actions (coaching, additional field support, or re-prioritization) for this area.";
    }
}

/* ------------------------- 8. TREND DATA (MONTHLY) ------------------------- */
/**
 * Trend analysis by month and location (woreda or equivalent) for the selected
 * project and/or filters. Schema-aware like other apps.
 */

$trendChartData = [
    'labels'   => [],
    'datasets' => [],
];

// Detect which columns exist in reports table
$reportYearCol  = null;
$reportMonthCol = null;
$reportDateCol  = null;

// 1) Prefer explicit year/month integer columns if available
if (table_has_column($pdo, 'reports', 'report_year') &&
    table_has_column($pdo, 'reports', 'report_month')) {
    $reportYearCol  = 'report_year';
    $reportMonthCol = 'report_month';
} elseif (table_has_column($pdo, 'reports', 'reporting_year') &&
          table_has_column($pdo, 'reports', 'reporting_month')) {
    $reportYearCol  = 'reporting_year';
    $reportMonthCol = 'reporting_month';
} elseif (table_has_column($pdo, 'reports', 'year') &&
          table_has_column($pdo, 'reports', 'month')) {
    $reportYearCol  = 'year';
    $reportMonthCol = 'month';
}

// 2) If no separate year/month columns, look for a single date column
if (!$reportYearCol) {
    if (table_has_column($pdo, 'reports', 'report_date')) {
        $reportDateCol = 'report_date';
    } elseif (table_has_column($pdo, 'reports', 'reporting_date')) {
        $reportDateCol = 'reporting_date';
    } elseif (table_has_column($pdo, 'reports', 'submitted_at')) {
        $reportDateCol = 'submitted_at';
    } elseif (table_has_column($pdo, 'reports', 'created_at')) {
        $reportDateCol = 'created_at';
    }
}

if ($reportYearCol || $reportDateCol) {
    // Build SQL expressions for year and month
    if ($reportYearCol && $reportMonthCol) {
        $yearExpr  = 'r.`' . $reportYearCol . '`';
        $monthExpr = 'r.`' . $reportMonthCol . '`';
    } else {
        // Fallback: derive from date column
        $yearExpr  = 'YEAR(r.`' . $reportDateCol . '`)';
        $monthExpr = 'MONTH(r.`' . $reportDateCol . '`)';
    }

    // Detect a location column (woreda / woreda_name / location), otherwise fallback
    if (table_has_column($pdo, 'reports', 'woreda')) {
        $locationExpr = 'r.`woreda`';
    } elseif (table_has_column($pdo, 'reports', 'woreda_name')) {
        $locationExpr = 'r.`woreda_name`';
    } elseif (table_has_column($pdo, 'reports', 'location')) {
        $locationExpr = 'r.`location`';
    } else {
        // No location field in reports – group everything as "All locations"
        $locationExpr = "'All locations'";
    }

    $sqlTrend = "
        SELECT
            $yearExpr AS report_year,
            $monthExpr AS report_month,
            $locationExpr AS location_label,
            COALESCE(SUM(
                rv.boys_u5 + rv.girls_u5 +
                rv.boys_5_17 + rv.girls_5_17 +
                rv.men_18_59 + rv.women_18_59 +
                rv.men_60p + rv.women_60p
            ), 0) AS total_sadd
        FROM reports r
        JOIN report_values rv ON rv.report_id = r.id
        LEFT JOIN projects p ON p.id = r.project_id
    ";

    $paramsTrend    = [];
    $conditionsTrend = [];

    if ($filterProjectId) {
        $conditionsTrend[] = "r.project_id = ?";
        $paramsTrend[]     = $filterProjectId;
    }
    if ($filterRegion !== '' && $projHasRegionCol) {
        $conditionsTrend[] = "p.region = ?";
        $paramsTrend[]     = $filterRegion;
    }
    if ($filterZone !== '' && $projHasZoneCol) {
        $conditionsTrend[] = "p.zone = ?";
        $paramsTrend[]     = $filterZone;
    }
    if ($filterWoreda !== '' && $projHasWoredaCol) {
        $conditionsTrend[] = "p.woreda = ?";
        $paramsTrend[]     = $filterWoreda;
    }
    if ($filterDonor !== '' && $projectDonorColumn) {
        $conditionsTrend[] = "p.`$projectDonorColumn` = ?";
        $paramsTrend[]     = $filterDonor;
    }

    if ($conditionsTrend) {
        $sqlTrend .= " WHERE " . implode(' AND ', $conditionsTrend);
    }

    $sqlTrend .= "
        GROUP BY report_year, report_month, location_label
        ORDER BY report_year, report_month
    ";

    $stmtTrend = $pdo->prepare($sqlTrend);
    $stmtTrend->execute($paramsTrend);
    $trendRows = $stmtTrend->fetchAll();

    $trendLabels       = []; // months like 2025-01
    $trendDataByWoreda = [];

    foreach ($trendRows as $row) {
        $year   = (int)($row['report_year'] ?? 0);
        $month  = (int)($row['report_month'] ?? 0);
        $woreda = trim($row['location_label'] ?? '') ?: 'Unspecified';
        if (!$year || !$month) {
            continue;
        }
        $label = sprintf('%04d-%02d', $year, $month);
        if (!in_array($label, $trendLabels, true)) {
            $trendLabels[] = $label;
        }
        if (!isset($trendDataByWoreda[$woreda])) {
            $trendDataByWoreda[$woreda] = [];
        }
        $trendDataByWoreda[$woreda][$label] = (float)$row['total_sadd'];
    }
    sort($trendLabels);

    $trendDatasets = [];
    foreach ($trendDataByWoreda as $woreda => $perMonth) {
        $dataPoints = [];
        foreach ($trendLabels as $lbl) {
            $dataPoints[] = isset($perMonth[$lbl]) ? (float)$perMonth[$lbl] : 0.0;
        }
        $trendDatasets[] = [
            'label' => $woreda,
            'data'  => $dataPoints,
            'fill'  => false,
        ];
    }

    $trendChartData = [
        'labels'   => $trendLabels,
        'datasets' => $trendDatasets,
    ];
}

/* ---------------------------- 9. CHART DATA SETS --------------------------- */

$activityChartData = [
    'labels' => $statusOrder,
    'data'   => [
        (int)($activityStatusCounts['Completed']   ?? 0),
        (int)($activityStatusCounts['On progress'] ?? 0),
        (int)($activityStatusCounts['Not started'] ?? 0),
    ],
];

$indicatorChartData = [
    'labels' => $statusOrder,
    'data'   => [
        (int)($indicatorStatusCounts['Completed']   ?? 0),
        (int)($indicatorStatusCounts['On progress'] ?? 0),
        (int)($indicatorStatusCounts['Not started'] ?? 0),
    ],
];

$budgetChartData = [
    'labels' => $statusOrder,
    'data'   => [
        (int)($budgetStatusCounts['Completed']   ?? 0),
        (int)($budgetStatusCounts['On progress'] ?? 0),
        (int)($budgetStatusCounts['Not started'] ?? 0),
    ],
];
?>
<style>
    .progress-summary-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        margin-top: 0.75rem;
    }
    .progress-summary-card {
        flex: 1 1 220px;
        background: #f9fafb;
        border-radius: 0.75rem;
        padding: 0.75rem 1rem;
        box-shadow: 0 1px 2px rgba(15,23,42,0.06);
    }
    .progress-summary-card h3 {
        margin: 0 0 0.25rem;
        font-size: 0.9rem;
        display: flex;
        align-items: center;
        gap: 0.35rem;
    }
    .progress-summary-main {
        font-size: 1.25rem;
        font-weight: 600;
        margin: 0.2rem 0;
    }
    .progress-summary-sub {
        font-size: 0.8rem;
        color: #4b5563;
        margin: 0;
    }
    .chart-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 1.5rem;
        margin-top: 1rem;
    }
    .chart-container {
        flex: 1 1 260px;
        min-width: 240px;
    }
    .chart-container label {
        font-size: 0.8rem;
        color: #4b5563;
    }
    .ai-insights {
        margin-top: 1rem;
        padding: 0.8rem 1rem;
        border-radius: 0.75rem;
        background: #f5f3ff;
        border: 1px solid #e0e7ff;
    }
    .ai-insights h3 {
        margin: 0 0 0.25rem;
        font-size: 0.9rem;
        display: flex;
        align-items: center;
        gap: 0.4rem;
    }
    .ai-insights ul {
        margin: 0.3rem 0 0;
        padding-left: 1.15rem;
        font-size: 0.8rem;
    }
    .export-buttons {
        margin-bottom: 0.5rem;
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        align-items: center;
        font-size: 0.8rem;
    }
    .location-filters {
        background: #f8f9fa;
        padding: 1rem;
        border-radius: 0.5rem;
        margin: 1rem 0;
        border: 1px solid #e9ecef;
    }
    .location-filters h3 {
        margin: 0 0 0.75rem 0;
        font-size: 1rem;
        color: #495057;
    }
    .filter-row {
        display: flex;
        flex-wrap: wrap;
        gap: 1rem;
        align-items: end;
    }
    .filter-group {
        flex: 1;
        min-width: 200px;
    }
    .filter-group label {
        display: block;
        font-size: 0.8rem;
        font-weight: 600;
        margin-bottom: 0.25rem;
        color: #495057;
    }
    .filter-group select, .filter-group input {
        width: 100%;
        padding: 0.5rem;
        border: 1px solid #ced4da;
        border-radius: 0.25rem;
        font-size: 0.9rem;
    }
    .manual-input {
        margin-top: 0.5rem;
        display: none;
    }
    .manual-input input {
        width: 100%;
        padding: 0.5rem;
        border: 1px solid #ced4da;
        border-radius: 0.25rem;
        font-size: 0.9rem;
    }
</style>

<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
        <div>
            <h1 style="margin:0;">📊 Progress Tracking Dashboard</h1>
            <p style="margin:4px 0 0; font-size:13px; color:#4b5563;">
                Real-time view of project activities, indicators and budget utilization,
                auto-pulled from Planning, Budget and Reporting modules.
            </p>
            <?php if ($currentProject): ?>
                <p style="margin:4px 0 0; font-size:12px; color:#111827;">
                    <strong>Project focus:</strong>
                    <?php echo h($currentProject['title'] ?? $currentProject['name'] ?? ('Project #' . $currentProject['id'])); ?>
                    <?php
                        $locParts = [];
                        if (!empty($currentProject['region']))  $locParts[] = $currentProject['region'];
                        if (!empty($currentProject['zone']))    $locParts[] = $currentProject['zone'];
                        if (!empty($currentProject['woreda']))  $locParts[] = $currentProject['woreda'];
                        if ($locParts) {
                            echo ' – ' . h(implode(' / ', $locParts));
                        }
                    ?>
                    <?php if (!empty($currentProject['start_date']) && !empty($currentProject['end_date'])): ?>
                        | <strong>Duration:</strong>
                        <?php echo h($currentProject['start_date']); ?> – <?php echo h($currentProject['end_date']); ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
        <div>
            <form method="get" style="display:inline-block; margin-right:8px;">
                <label style="font-size:13px; color:#111827;">
                    Project:
                    <select name="project_id" style="font-size:13px; padding:2px 4px;">
                        <option value="">All projects</option>
                        <?php foreach ($projects as $p): ?>
                            <?php $pid = (int)$p['id']; ?>
                            <option value="<?php echo $pid; ?>"
                                <?php echo ($filterProjectId === $pid ? 'selected' : ''); ?>>
                                <?php echo h($projectMap[$pid] ?? ('Project #' . $pid)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="btn-sm">Apply</button>
            </form>
            <button type="button" class="btn-sm" onclick="window.print();">
                🖨️ Print / Save as PDF
            </button>
        </div>
    </div>

    <!-- Location Filters Section -->
    <div class="location-filters">
        <h3>📍 Location & Donor Filters</h3>
        <form method="get" class="filter-row">
            <?php if ($filterProjectId): ?>
                <input type="hidden" name="project_id" value="<?php echo $filterProjectId; ?>">
            <?php endif; ?>

            <!-- Donor Filter -->
            <div class="filter-group">
                <label for="donor_filter">Donor/Organization</label>
                <select id="donor_filter" name="donor">
                    <option value="">-- All Donors --</option>
                    <?php foreach ($ethiopiaDonors as $donor): ?>
                        <option value="<?php echo h($donor); ?>"
                            <?php echo (isset($_GET['donor']) && $_GET['donor'] === $donor) ? 'selected' : ''; ?>>
                            <?php echo h($donor); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div id="donor_manual_input" class="manual-input">
                    <input type="text" name="donor_manual" placeholder="Enter donor/organization name manually"
                           value="<?php echo isset($_GET['donor_manual']) ? h($_GET['donor_manual']) : ''; ?>">
                </div>
            </div>

            <!-- Region Filter -->
            <div class="filter-group">
                <label for="region_filter">Region</label>
                <select id="region_filter" name="region">
                    <option value="">-- All Regions --</option>
                    <?php foreach ($ethiopianRegions as $region): ?>
                        <option value="<?php echo h($region); ?>"
                            <?php echo (isset($_GET['region']) && $_GET['region'] === $region) ? 'selected' : ''; ?>>
                            <?php echo h($region); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div id="region_manual_input" class="manual-input">
                    <input type="text" name="region_manual" placeholder="Specify region"
                           value="<?php echo isset($_GET['region_manual']) ? h($_GET['region_manual']) : ''; ?>">
                </div>
            </div>

            <!-- Zone Filter -->
            <div class="filter-group">
                <label for="zone_filter">Zone</label>
                <input type="text" id="zone_filter" name="zone" placeholder="Enter zone"
                       value="<?php echo isset($_GET['zone']) ? h($_GET['zone']) : ''; ?>">
            </div>

            <!-- Woreda Filter -->
            <div class="filter-group">
                <label for="woreda_filter">Woreda</label>
                <input type="text" id="woreda_filter" name="woreda" placeholder="Enter woreda"
                       value="<?php echo isset($_GET['woreda']) ? h($_GET['woreda']) : ''; ?>">
            </div>

            <div class="filter-group">
                <button type="submit" class="btn-sm">Apply Filters</button>
                <?php if (isset($_GET['region']) || isset($_GET['zone']) || isset($_GET['woreda']) || isset($_GET['donor']) || isset($_GET['region_manual']) || isset($_GET['donor_manual'])): ?>
                    <a href="?<?php echo $filterProjectId ? "project_id=$filterProjectId" : ''; ?>" class="btn-sm" style="margin-left: 0.5rem;">
                        Clear Filters
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="progress-summary-grid">
        <div class="progress-summary-card">
            <h3>🚀 Activities snapshot</h3>
            <p class="progress-summary-main">
                <?php echo (int)$totalActivities; ?> activities
            </p>
            <p class="progress-summary-sub">
                Completed: <?php echo (int)($activityStatusCounts['Completed'] ?? 0); ?>
                (<?php echo $statusPercentages['Completed']['act'] ?? 0; ?>%) |
                On progress: <?php echo (int)($activityStatusCounts['On progress'] ?? 0); ?>
            </p>
        </div>
        <div class="progress-summary-card">
            <h3>📈 Indicator snapshot</h3>
            <p class="progress-summary-main">
                <?php echo (int)$totalIndicators; ?> indicators
            </p>
            <p class="progress-summary-sub">
                With data: <?php echo (int)($indicatorStatusCounts['On progress'] ?? 0); ?> |
                No reports yet: <?php echo (int)($indicatorStatusCounts['Not started'] ?? 0); ?>
            </p>
        </div>
        <div class="progress-summary-card">
            <h3>💰 Budget snapshot</h3>
            <p class="progress-summary-main">
                <?php echo (int)$totalBudgetLines; ?> budget lines
            </p>
            <p class="progress-summary-sub">
                Lines with allocation: <?php echo (int)($budgetStatusCounts['On progress'] ?? 0); ?> |
                Not started: <?php echo (int)($budgetStatusCounts['Not started'] ?? 0); ?>
            </p>
        </div>
    </div>
</div>

<!-- ======================= ACTIVITY PROGRESS SECTION ======================= -->
<div class="card">
    <h2>1. Project Activity Progress Tracking</h2>
    <p>
        Structure aligned with your Excel activity progress template
        (Target vs Achieved, beneficiaries, budget utilization, status + location and donor).
    </p>

    <div class="export-buttons">
        <span>Export this table:</span>
        <button type="button" class="btn-sm" onclick="exportTable('activity-progress-table','excel')">Excel</button>
        <button type="button" class="btn-sm" onclick="exportTable('activity-progress-table','word')">Word</button>
        <button type="button" class="btn-sm" onclick="exportTable('activity-progress-table','csv')">CSV</button>
    </div>

    <table id="activity-progress-table" class="table">
        <thead>
            <tr>
                <th>#</th>
                <th>Project</th>
                <th>Region</th>
                <th>Zone</th>
                <th>Woreda</th>
                <th>Donor</th>
                <th>Activity code</th>
                <th>Activity</th>
                <th>Unit of measurement</th>
                <th>Target (planned)</th>
                <th>Achieved</th>
                <th>% (Achieved / Target)</th>
                <th>Beneficiaries – Male</th>
                <th>Beneficiaries – Female</th>
                <th>Beneficiaries – Total</th>
                <th>Allocated budget</th>
                <th>Budget used</th>
                <th>Utilization rate (%)</th>
                <th>Status of the Activity</th>
                <th>Status notification</th>
            </tr>
        </thead>
        <tbody>
        <?php $rowIndex = 0; ?>
        <?php foreach ($activityRows as $row): ?>
            <tr>
                <td><?php echo ++$rowIndex; ?></td>
                <td><?php echo h($row['proj_label']); ?></td>
                <td><?php echo h($row['region']); ?></td>
                <td><?php echo h($row['zone']); ?></td>
                <td><?php echo h($row['woreda']); ?></td>
                <td><?php echo h($row['donor']); ?></td>
                <td><?php echo h($row['activity_code']); ?></td>
                <td><?php echo h($row['activity_name']); ?></td>
                <td><?php echo h($row['unit']); ?></td>
                <td><?php echo h($row['target']); ?></td>
                <td><?php echo h($row['achieved']); ?></td>
                <td><?php echo h($row['percent']); ?>%</td>
                <td><?php echo h($row['ben_male']); ?></td>
                <td><?php echo h($row['ben_female']); ?></td>
                <td><?php echo h($row['ben_total']); ?></td>
                <td><?php echo h($row['allocated_etb']); ?></td>
                <td><?php echo h($row['budget_used']); ?></td>
                <td><?php echo h($row['util_rate']); ?>%</td>
                <td><?php echo h($row['status']); ?></td>
                <td><?php echo h($row['status_note']); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$activityRows): ?>
            <tr><td colspan="20">No activities found. Please define activities in Planning (or check the selected project/location/donor filters).</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- ====================== INDICATOR PROGRESS SECTION ======================= -->
<div class="card">
    <h2>2. Indicator Progress Tracking</h2>
    <p>
        Uses SADD totals from <code>report_values</code> filtered by the selected project,
        location and donor. Targets for indicators can be added later to enable exact % progress.
    </p>

    <div class="export-buttons">
        <span>Export this table:</span>
        <button type="button" class="btn-sm" onclick="exportTable('indicator-progress-table','excel')">Excel</button>
        <button type="button" class="btn-sm" onclick="exportTable('indicator-progress-table','word')">Word</button>
        <button type="button" class="btn-sm" onclick="exportTable('indicator-progress-table','csv')">CSV</button>
    </div>

    <table id="indicator-progress-table" class="table">
        <thead>
            <tr>
                <th>#</th>
                <th>Indicator code</th>
                <th>Indicator</th>
                <th>Unit of measurement</th>
                <th>Target (planned)</th>
                <th>Achieved (SADD total)</th>
                <th>% (Achieved / Target)</th>
                <th>Boys</th>
                <th>Girls</th>
                <th>Men</th>
                <th>Women</th>
                <th>Total</th>
                <th>Status of the Indicator</th>
                <th>Status notification</th>
            </tr>
        </thead>
        <tbody>
        <?php $indIndex = 0; ?>
        <?php foreach ($indicatorProgress as $ip): ?>
            <?php
                $targetInd  = 0.0; // no indicator target column yet
                $achieved   = (float)$ip['total_sadd'];
                $percentInd = ($targetInd > 0)
                              ? round(($achieved / $targetInd) * 100, 2)
                              : 0.0;

                if ($achieved > 0 && $targetInd > 0 && $percentInd >= 100) {
                    $indStatus = 'Completed';
                } elseif ($achieved > 0) {
                    $indStatus = 'On progress';
                } else {
                    $indStatus = 'Not started';
                }

                $note = ($indStatus === 'Completed')
                    ? 'Indicator target fully achieved or exceeded.'
                    : (($indStatus === 'On progress')
                        ? 'Reported data available but target not fully reached.'
                        : 'No reports yet for this indicator (for current filters).');
            ?>
            <tr>
                <td><?php echo ++$indIndex; ?></td>
                <td><?php echo h($ip['code']); ?></td>
                <td><?php echo h($ip['name']); ?></td>
                <td><?php echo h($ip['unit_type']); ?></td>
                <td><?php echo h($targetInd ? $targetInd : 'N/A'); ?></td>
                <td><?php echo h($achieved); ?></td>
                <td><?php echo $targetInd ? h($percentInd) . '%' : 'N/A'; ?></td>
                <td><?php echo h($ip['total_boys']); ?></td>
                <td><?php echo h($ip['total_girls']); ?></td>
                <td><?php echo h($ip['total_men']); ?></td>
                <td><?php echo h($ip['total_women']); ?></td>
                <td><?php echo h($ip['total_sadd']); ?></td>
                <td><?php echo h($indStatus); ?></td>
                <td><?php echo h($note); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$indicatorProgress): ?>
            <tr><td colspan="14">No indicators or no report data yet for the current filters.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <h3 style="margin-top:1.25rem;">2.1 Monthly trend analysis by location (woreda)</h3>
    <p style="font-size:12px; color:#4b5563;">
        Line/bar chart of total reported beneficiaries (SADD sum) per month and location
        for <?php echo $filterProjectId ? 'this project' : 'all projects'; ?>, using the same filters above.
    </p>
    <div class="chart-container" style="max-width:100%;">
        <label for="trendChartType">
            Chart type:
            <select id="trendChartType" style="font-size:12px; padding:2px 4px; margin-left:4px;">
                <option value="line">Line (trend)</option>
                <option value="bar">Bar</option>
            </select>
        </label>
        <canvas id="trendChart" height="180"></canvas>
    </div>
</div>

<!-- =================== OVERALL PROJECT PROGRESS SECTION ==================== -->
<div class="card">
    <h2>3. Overall Progress of the Project</h2>
    <p>
        Mirrors your Excel summary: percentage of activities, indicators and
        budget lines that are Completed, On progress or Not started, plus an
        average column. All figures respect the project / location / donor filters.
    </p>

    <div class="export-buttons">
        <span>Export this table:</span>
        <button type="button" class="btn-sm" onclick="exportTable('overall-progress-table','excel')">Excel</button>
        <button type="button" class="btn-sm" onclick="exportTable('overall-progress-table','word')">Word</button>
        <button type="button" class="btn-sm" onclick="exportTable('overall-progress-table','csv')">CSV</button>
    </div>

    <table id="overall-progress-table" class="table">
        <thead>
            <tr>
                <th>Status</th>
                <th>Progress of Project Activity (%)</th>
                <th>Progress of Project Indicator (%)</th>
                <th>Progress of Budget (%)</th>
                <th>Averages (%)</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($statusOrder as $status): ?>
            <tr>
                <td><?php echo h($status); ?></td>
                <td><?php echo h($statusPercentages[$status]['act']); ?></td>
                <td><?php echo h($statusPercentages[$status]['ind']); ?></td>
                <td><?php echo h($statusPercentages[$status]['bud']); ?></td>
                <td><?php echo h($statusPercentages[$status]['avg']); ?></td>
            </tr>
        <?php endforeach; ?>
            <tr>
                <th>Total</th>
                <th>100</th>
                <th>100</th>
                <th>100</th>
                <th>100</th>
            </tr>
        </tbody>
    </table>

    <div class="chart-grid">
        <div class="chart-container">
            <label for="activityStatusChartType">
                Activity status chart:
                <select id="activityStatusChartType" style="font-size:12px; padding:2px 4px; margin-left:4px;">
                    <option value="bar">Bar</option>
                    <option value="pie">Pie</option>
                    <option value="doughnut">Doughnut</option>
                </select>
            </label>
            <canvas id="activityStatusChart" height="150"></canvas>
        </div>
        <div class="chart-container">
            <label for="indicatorStatusChartType">
                Indicator status chart:
                <select id="indicatorStatusChartType" style="font-size:12px; padding:2px 4px; margin-left:4px;">
                    <option value="bar">Bar</option>
                    <option value="pie">Pie</option>
                    <option value="doughnut">Doughnut</option>
                </select>
            </label>
            <canvas id="indicatorStatusChart" height="150"></canvas>
        </div>
        <div class="chart-container">
            <label for="budgetStatusChartType">
                Budget status chart:
                <select id="budgetStatusChartType" style="font-size:12px; padding:2px 4px; margin-left:4px;">
                    <option value="bar">Bar</option>
                    <option value="pie">Pie</option>
                    <option value="doughnut">Doughnut</option>
                </select>
            </label>
            <canvas id="budgetStatusChart" height="150"></canvas>
        </div>
    </div>

    <div class="ai-insights">
        <h3>🤖 Smart AI-style insights</h3>
        <p style="margin:0; font-size:12px; color:#4b5563;">
            Generated in real time from the latest dashboard data for
            <strong><?php echo $filterProjectId ? 'this project only' : 'all active projects (current filters)'; ?></strong>.
        </p>
        <ul>
            <?php if ($aiInsights): ?>
                <?php foreach ($aiInsights as $msg): ?>
                    <li><?php echo h($msg); ?></li>
                <?php endforeach; ?>
            <?php else: ?>
                <li>No progress data available yet. Once activities, reports and budgets are entered,
                    smart recommendations will appear here.</li>
            <?php endif; ?>
        </ul>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // ------------ Simple table export (Excel, Word, CSV) -------------------
    function exportTable(tableId, type) {
        var table = document.getElementById(tableId);
        if (!table) return;

        var html = table.outerHTML;
        var blob, filename, mimeType;

        if (type === 'csv') {
            mimeType = 'text/csv';
            filename = tableId + '.csv';
            var rows = [];
            for (var i = 0; i < table.rows.length; i++) {
                var row = table.rows[i];
                var cells = [];
                for (var j = 0; j < row.cells.length; j++) {
                    var text = row.cells[j].innerText || row.cells[j].textContent || '';
                    text = text.replace(/(\r\n|\n|\r)/gm, ' ').replace(/"/g, '""');
                    cells.push('"' + text + '"');
                }
                rows.push(cells.join(','));
            }
            blob = new Blob([rows.join('\n')], {type: mimeType});
        } else if (type === 'excel') {
            mimeType = 'application/vnd.ms-excel';
            filename = tableId + '.xls';
            blob = new Blob(['\ufeff', html], {type: mimeType});
        } else if (type === 'word') {
            mimeType = 'application/msword';
            filename = tableId + '.doc';
            blob = new Blob(['\ufeff', html], {type: mimeType});
        } else {
            return;
        }

        var url = URL.createObjectURL(blob);
        var a   = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    // ----------------- Dynamic Manual Input Fields -------------------------
    document.addEventListener('DOMContentLoaded', function () {
        // Donor manual input toggle
        var donorSelect = document.getElementById('donor_filter');
        var donorManualInput = document.getElementById('donor_manual_input');

        if (donorSelect && donorManualInput) {
            donorSelect.addEventListener('change', function() {
                if (this.value === 'Other') {
                    donorManualInput.style.display = 'block';
                } else {
                    donorManualInput.style.display = 'none';
                }
            });

            // Initialize on page load
            if (donorSelect.value === 'Other') {
                donorManualInput.style.display = 'block';
            }
        }

        // Region manual input toggle
        var regionSelect = document.getElementById('region_filter');
        var regionManualInput = document.getElementById('region_manual_input');

        if (regionSelect && regionManualInput) {
            regionSelect.addEventListener('change', function() {
                if (this.value === 'Other') {
                    regionManualInput.style.display = 'block';
                } else {
                    regionManualInput.style.display = 'none';
                }
            });

            // Initialize on page load
            if (regionSelect.value === 'Other') {
                regionManualInput.style.display = 'block';
            }
        }
    });

    // ------------------------ Charts (Chart.js) -----------------------------
    document.addEventListener('DOMContentLoaded', function () {
        var activityStatusChartData = <?php echo json_encode($activityChartData); ?>;
        var indicatorStatusChartData = <?php echo json_encode($indicatorChartData); ?>;
        var budgetStatusChartData = <?php echo json_encode($budgetChartData); ?>;
        var trendChartData = <?php echo json_encode($trendChartData); ?>;

        var activityChart = null;
        var indicatorChart = null;
        var budgetChart = null;
        var trendChart = null;

        function createStatusChartInstance(canvasId, chartType, chartData, label) {
            var ctx = document.getElementById(canvasId);
            if (!ctx) return null;

            return new Chart(ctx, {
                type: chartType,
                data: {
                    labels: chartData.labels,
                    datasets: [{
                        label: label,
                        data: chartData.data
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            display: (chartType === 'pie' || chartType === 'doughnut')
                        },
                        tooltip: {
                            enabled: true
                        }
                    },
                    scales: (chartType === 'bar' || chartType === 'line') ? {
                        y: {
                            beginAtZero: true
                        }
                    } : {}
                }
            });
        }

        function createTrendChartInstance(canvasId, chartType, trendData) {
            var ctx = document.getElementById(canvasId);
            if (!ctx) return null;

            return new Chart(ctx, {
                type: chartType,
                data: trendData,
                options: {
                    responsive: true,
                    plugins: {
                        legend: { display: true },
                        tooltip: { enabled: true }
                    },
                    scales: (chartType === 'bar' || chartType === 'line') ? {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Beneficiaries (total SADD)'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Reporting month'
                            }
                        }
                    } : {}
                }
            });
        }

        // Initial charts
        activityChart = createStatusChartInstance(
            'activityStatusChart',
            'bar',
            activityStatusChartData,
            'Activities'
        );
        indicatorChart = createStatusChartInstance(
            'indicatorStatusChart',
            'bar',
            indicatorStatusChartData,
            'Indicators'
        );
        budgetChart = createStatusChartInstance(
            'budgetStatusChart',
            'bar',
            budgetStatusChartData,
            'Budget lines'
        );
        if (trendChartData && trendChartData.labels && trendChartData.labels.length) {
            trendChart = createTrendChartInstance(
                'trendChart',
                'line',
                trendChartData
            );
        }

        // Chart type switchers
        var actType = document.getElementById('activityStatusChartType');
        if (actType) {
            actType.addEventListener('change', function (e) {
                if (activityChart) activityChart.destroy();
                activityChart = createStatusChartInstance(
                    'activityStatusChart',
                    e.target.value,
                    activityStatusChartData,
                    'Activities'
                );
            });
        }

        var indType = document.getElementById('indicatorStatusChartType');
        if (indType) {
            indType.addEventListener('change', function (e) {
                if (indicatorChart) indicatorChart.destroy();
                indicatorChart = createStatusChartInstance(
                    'indicatorStatusChart',
                    e.target.value,
                    indicatorStatusChartData,
                    'Indicators'
                );
            });
        }

        var budType = document.getElementById('budgetStatusChartType');
        if (budType) {
            budType.addEventListener('change', function (e) {
                if (budgetChart) budgetChart.destroy();
                budgetChart = createStatusChartInstance(
                    'budgetStatusChart',
                    e.target.value,
                    budgetStatusChartData,
                    'Budget lines'
                );
            });
        }

        var trendType = document.getElementById('trendChartType');
        if (trendType) {
            trendType.addEventListener('change', function (e) {
                if (trendChart) trendChart.destroy();
                trendChart = createTrendChartInstance(
                    'trendChart',
                    e.target.value,
                    trendChartData
                );
            });
        }
    });
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>
