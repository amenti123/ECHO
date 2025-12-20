<?php
// File: pages/data_quality.php
/**
 * ENHANCED DATA QUALITY MONITORING DASHBOARD (UPGRADED v2.0)
 * 
 * Features:
 * - Multiple selection filters (projects, regions, zones, woredas, months, years)
 * - Enhanced data quality dimensions (8 dimensions total)
 * - Custom date range selection (from-to)
 * - Time range now respects selected months/years
 * - Focus Dimension filter (quality_dimension) wired to summary + radar chart
 * - Word / PDF / Excel / CSV / Image export
 * - Real-time data update simulation
 * - Data integration from Enter Data app, CFM system, Budget module, Aggregation module
 * - FULLY LINKED with Project Activity Reports (project_activity_report.php)
 * - Budget and Aggregate data explicitly linked and displayed
 * - Activity report data integrated into all quality dimension calculations
 */

require_once __DIR__ . '/../header.php';
require_login();

$pdo = getPDO();
$user = current_user();
$isAdmin = ($user['role'] ?? 'user') === 'admin';

// Data quality dimensions with icons and descriptions
$qualityDimensions = [
    'timeliness' => ['icon' => '⏰', 'name' => 'Timeliness', 'description' => 'Data is available within required timeframe'],
    'completeness' => ['icon' => '📊', 'name' => 'Completeness', 'description' => 'All required data fields are populated'],
    'accuracy' => ['icon' => '🎯', 'name' => 'Accuracy', 'description' => 'Data correctly represents reality'],
    'consistency' => ['icon' => '🔄', 'name' => 'Consistency', 'description' => 'Data is consistent across systems'],
    'validity' => ['icon' => '✅', 'name' => 'Validity', 'description' => 'Data conforms to business rules'],
    'uniqueness' => ['icon' => '⭐', 'name' => 'Uniqueness', 'description' => 'No duplicate records exist'],
    'integrity' => ['icon' => '🛡️', 'name' => 'Integrity', 'description' => 'Data relationships are maintained'],
    'relevance' => ['icon' => '🎯', 'name' => 'Relevance', 'description' => 'Data meets current business needs']
];

// Initialize filter variables with multiple selection support
$timeFilter      = $_GET['time_filter']      ?? 'monthly';
$projectFilter   = isset($_GET['project_id']) ? (array)$_GET['project_id'] : ['all'];
$regionFilter    = isset($_GET['region_id'])  ? (array)$_GET['region_id']  : ['all'];
$zoneFilter      = isset($_GET['zone_id'])    ? (array)$_GET['zone_id']    : ['all'];
$woredaFilter    = isset($_GET['woreda_id'])  ? (array)$_GET['woreda_id']  : ['all'];
$yearFilter      = isset($_GET['year'])       ? (array)$_GET['year']       : [date('Y')];
$monthFilter     = isset($_GET['month'])      ? (array)$_GET['month']      : ['all'];
$startDate       = $_GET['start_date']        ?? date('Y-m-01', strtotime('-1 month'));
$endDate         = $_GET['end_date']          ?? date('Y-m-t');
$qualityDimension = $_GET['quality_dimension'] ?? 'all';

// Generate years from 2020 to 2050
$years = range(2020, 2050);
$months = [
    '1'  => 'January', '2'  => 'February', '3'  => 'March',     '4'  => 'April',
    '5'  => 'May',     '6'  => 'June',     '7'  => 'July',      '8'  => 'August',
    '9'  => 'September','10'=> 'October',  '11'=> 'November',   '12'=> 'December'
];

// Initialize activity report tables (ensure they exist before querying)
if (!function_exists('initialize_activity_report_tables')) {
    function initialize_activity_report_tables(PDO $pdo): void {
        try {
            // Main activity reports table
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS project_activity_reports (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    project_id INT NOT NULL,
                    report_title VARCHAR(500) NOT NULL,
                    main_sector VARCHAR(255),
                    specific_sector VARCHAR(255),
                    region_id INT,
                    zone_id INT,
                    woreda_id INT,
                    region_other VARCHAR(255),
                    zone_other VARCHAR(255),
                    woreda_other VARCHAR(255),
                    report_type VARCHAR(100),
                    report_month INT,
                    report_year INT,
                    report_date DATE,
                    report_period_start DATE,
                    report_period_end DATE,
                    reported_by_user_id INT NOT NULL,
                    reported_by_name VARCHAR(255),
                    reported_by_email VARCHAR(255),
                    status ENUM('draft', 'submitted', 'approved', 'rejected') DEFAULT 'draft',
                    total_beneficiaries INT DEFAULT 0,
                    overall_progress DECIMAL(5,2) DEFAULT 0,
                    data_quality_score DECIMAL(5,2) DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_activity_reports_project (project_id),
                    INDEX idx_activity_reports_user (reported_by_user_id),
                    INDEX idx_activity_reports_date (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            // Activity report lines table
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS project_activity_report_lines (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    report_id INT NOT NULL,
                    result_level ENUM('impact', 'outcome', 'output') DEFAULT 'output',
                    project_activity TEXT NOT NULL,
                    unit_of_measurement VARCHAR(100),
                    beneficiary_type VARCHAR(255),
                    target_value DECIMAL(15,2) DEFAULT 0,
                    achievement_value DECIMAL(15,2) DEFAULT 0,
                    achievement_type ENUM('persons', 'non_persons') DEFAULT 'persons',
                    progress_percentage DECIMAL(5,2) DEFAULT 0,
                    boys_under_18 INT DEFAULT 0,
                    girls_under_18 INT DEFAULT 0,
                    men_18_59 INT DEFAULT 0,
                    women_18_59 INT DEFAULT 0,
                    elderly_men_60_plus INT DEFAULT 0,
                    elderly_women_60_plus INT DEFAULT 0,
                    total_beneficiaries INT DEFAULT 0,
                    cumulative_achievement DECIMAL(15,2) DEFAULT 0,
                    cumulative_progress_percentage DECIMAL(5,2) DEFAULT 0,
                    progress_status ENUM('completed', 'ongoing', 'not_started', 'delayed') DEFAULT 'not_started',
                    allocated_budget DECIMAL(15,2) DEFAULT 0,
                    budget_utilized DECIMAL(15,2) DEFAULT 0,
                    budget_burn_rate DECIMAL(5,2) DEFAULT 0,
                    remaining_budget DECIMAL(15,2) DEFAULT 0,
                    activity_feedback TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_activity_lines_report (report_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Exception $e) {
            error_log("Error initializing activity report tables: " . $e->getMessage());
        }
    }
}

// Initialize activity report tables
initialize_activity_report_tables($pdo);

// Get available regions, zones, and woredas
try {
    $regions = $pdo->query("SELECT id, name FROM regions ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $zones   = $pdo->query("SELECT id, name, region_id FROM zones ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $woredas = $pdo->query("SELECT id, name, zone_id FROM woredas ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error in filter data: " . $e->getMessage());
    $regions = $zones = $woredas = [];
}

/**
 * Check if table exists in database
 */
function tableExists($pdo, $tableName) {
    try {
        $pdo->query("SELECT 1 FROM $tableName LIMIT 1");
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Check if a data source module is integrated (tables exist and can be queried)
 */
function isModuleIntegrated($pdo, $moduleName) {
    $tableMap = [
        'reporting' => ['reports'],
        'enter_data' => ['enter_data_entries'],
        'cfm' => ['cfm_feedbacks'],
        'budget' => ['budget_lines', 'budgets'],
        'aggregation' => ['indicator_results'],
        'activity_reports' => ['project_activity_reports', 'project_activity_report_lines']
    ];
    
    if (!isset($tableMap[$moduleName])) {
        return false;
    }
    
    $tables = $tableMap[$moduleName];
    foreach ($tables as $table) {
        if (tableExists($pdo, $table)) {
            return true; // At least one table exists for this module
        }
    }
    
    return false;
}

/**
 * Create demo data for Enter Data app if table doesn't exist
 */
function createEnterDataDemo($pdo) {
    if (!tableExists($pdo, 'enter_data_entries')) {
        try {
            $pdo->exec("
                CREATE TABLE enter_data_entries (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    project_id INT,
                    data_content TEXT,
                    status VARCHAR(50) DEFAULT 'completed',
                    validation_status VARCHAR(50) DEFAULT 'valid',
                    completeness_score DECIMAL(5,2) DEFAULT 85.0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (project_id) REFERENCES projects(id)
                )
            ");
            
            $projects = $pdo->query("SELECT id FROM projects LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($projects as $project) {
                $stmt = $pdo->prepare("
                    INSERT INTO enter_data_entries (project_id, data_content, status, validation_status, completeness_score, created_at)
                    VALUES (?, ?, 'completed', 'valid', ?, NOW() - INTERVAL FLOOR(RAND() * 30) DAY)
                ");
                $score = rand(70, 98);
                $stmt->execute([$project['id'], "Demo data for project {$project['id']}", $score]);
            }
            return true;
        } catch (PDOException $e) {
            error_log("Failed to create enter_data_entries table: " . $e->getMessage());
            return false;
        }
    }
    return true;
}

/**
 * Create demo data for CFM system if table doesn't exist
 */
function createCFMDemo($pdo) {
    if (!tableExists($pdo, 'cfm_feedbacks')) {
        try {
            $pdo->exec("
                CREATE TABLE cfm_feedbacks (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    project_id INT,
                    feedback_text TEXT,
                    status VARCHAR(50) DEFAULT 'resolved',
                    resolution_time_hours INT DEFAULT 24,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (project_id) REFERENCES projects(id)
                )
            ");
            
            $projects = $pdo->query("SELECT id FROM projects LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($projects as $project) {
                $stmt = $pdo->prepare("
                    INSERT INTO cfm_feedbacks (project_id, feedback_text, status, resolution_time_hours, created_at)
                    VALUES (?, ?, ?, ?, NOW() - INTERVAL FLOOR(RAND() * 15) DAY)
                ");
                $status = rand(0, 1) ? 'resolved' : 'pending';
                $resolutionTime = $status === 'resolved' ? rand(1, 72) : NULL;
                $stmt->execute([$project['id'], "Feedback for project {$project['id']}", $status, $resolutionTime]);
            }
            return true;
        } catch (PDOException $e) {
            error_log("Failed to create cfm_feedbacks table: " . $e->getMessage());
            return false;
        }
    }
    return true;
}

// Initialize demo data for integrated systems (only if tables missing)
createEnterDataDemo($pdo);
createCFMDemo($pdo);

/**
 * Pull data from Enter Data app
 */
function getEnterDataAppData($pdo, $projectId, $timeRange) {
    $defaultData = [
        'total_entries'     => 0,
        'completed_entries' => 0,
        'valid_entries'     => 0,
        'avg_completeness'  => 0,
        'last_update'       => null
    ];
    
    try {
        $query = "
            SELECT 
                COUNT(*) as total_entries,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_entries,
                COUNT(CASE WHEN validation_status = 'valid' THEN 1 END) as valid_entries,
                COALESCE(AVG(completeness_score), 0) as avg_completeness,
                MAX(updated_at) as last_update
            FROM enter_data_entries 
            WHERE project_id = ? 
            AND created_at BETWEEN ? AND ?
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$projectId, $timeRange['start'], $timeRange['end']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return array_merge($defaultData, $result ?: []);
    } catch (PDOException $e) {
        error_log("Enter Data App query error: " . $e->getMessage());
        return $defaultData;
    }
}

/**
 * Pull data from CFM system
 */
function getCFMData($pdo, $projectId, $timeRange) {
    $defaultData = [
        'total_feedbacks'    => 0,
        'resolved_feedbacks' => 0,
        'pending_feedbacks'  => 0,
        'avg_resolution_time'=> 0,
        'last_feedback'      => null
    ];
    
    try {
        $query = "
            SELECT 
                COUNT(*) as total_feedbacks,
                COUNT(CASE WHEN status = 'resolved' THEN 1 END) as resolved_feedbacks,
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_feedbacks,
                COALESCE(AVG(resolution_time_hours), 0) as avg_resolution_time,
                MAX(created_at) as last_feedback
            FROM cfm_feedbacks 
            WHERE project_id = ? 
            AND created_at BETWEEN ? AND ?
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$projectId, $timeRange['start'], $timeRange['end']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return array_merge($defaultData, $result ?: []);
    } catch (PDOException $e) {
        error_log("CFM system query error: " . $e->getMessage());
        return $defaultData;
    }
}

/**
 * Pull data from Budget module (safe, optional)
 */
function getBudgetData($pdo, $projectId, $timeRange) {
    $default = [
        'total_budget_records' => 0,
        'total_budget_planned' => 0,
        'total_budget_spent'   => 0,
        'last_budget_update'   => null
    ];

    try {
        if (tableExists($pdo, 'budget_lines')) {
            $query = "
                SELECT 
                    COUNT(*) AS total_budget_records,
                    COALESCE(SUM(planned_amount),0) AS total_budget_planned,
                    COALESCE(SUM(spent_amount),0) AS total_budget_spent,
                    MAX(updated_at) AS last_budget_update
                FROM budget_lines
                WHERE project_id = ?
                AND updated_at BETWEEN ? AND ?
            ";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$projectId, $timeRange['start'], $timeRange['end']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return array_merge($default, $row ?: []);
        } elseif (tableExists($pdo, 'budgets')) {
            $query = "
                SELECT 
                    COUNT(*) AS total_budget_records,
                    COALESCE(SUM(total_budget_usd),0) AS total_budget_planned,
                    COALESCE(SUM(spent_budget_usd),0) AS total_budget_spent,
                    MAX(updated_at) AS last_budget_update
                FROM budgets
                WHERE project_id = ?
                AND updated_at BETWEEN ? AND ?
            ";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$projectId, $timeRange['start'], $timeRange['end']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return array_merge($default, $row ?: []);
        }
    } catch (PDOException $e) {
        error_log("Budget data query error: " . $e->getMessage());
    }

    return $default;
}

/**
 * Pull data from Aggregation / Indicator results module (safe, optional)
 */
function getAggregationData($pdo, $projectId, $timeRange) {
    $default = [
        'total_aggregations' => 0,
        'last_aggregation'   => null
    ];

    try {
        if (tableExists($pdo, 'indicator_results')) {
            $query = "
                SELECT 
                    COUNT(*) AS total_aggregations,
                    MAX(created_at) AS last_aggregation
                FROM indicator_results
                WHERE project_id = ?
                AND created_at BETWEEN ? AND ?
            ";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$projectId, $timeRange['start'], $timeRange['end']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return array_merge($default, $row ?: []);
        }
    } catch (PDOException $e) {
        error_log("Aggregation data query error: " . $e->getMessage());
    }

    return $default;
}

/**
 * Pull data from Project Activity Report module (linked from project_activity_report.php)
 */
function getActivityReportData($pdo, $projectId, $timeRange) {
    $default = [
        'total_reports'          => 0,
        'submitted_reports'       => 0,
        'approved_reports'        => 0,
        'draft_reports'           => 0,
        'total_activities'        => 0,
        'completed_activities'    => 0,
        'ongoing_activities'      => 0,
        'total_beneficiaries'     => 0,
        'avg_progress'            => 0,
        'avg_data_quality_score'  => 0,
        'last_report_date'        => null,
        'first_report_date'       => null,
        'total_budget_allocated'  => 0,
        'total_budget_utilized'   => 0,
        'avg_budget_burn_rate'    => 0
    ];

    try {
        // Ensure tables exist (they should be initialized at page load, but double-check)
        if (!tableExists($pdo, 'project_activity_reports')) {
            // Try to initialize if not exists
            if (function_exists('initialize_activity_report_tables')) {
                initialize_activity_report_tables($pdo);
            }
        }
        
        if (tableExists($pdo, 'project_activity_reports')) {
            // Get main report statistics - improved query with better error handling
            $reportQuery = "
                SELECT 
                    COUNT(*) as total_reports,
                    COUNT(CASE WHEN status = 'submitted' THEN 1 END) as submitted_reports,
                    COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_reports,
                    COUNT(CASE WHEN status = 'draft' THEN 1 END) as draft_reports,
                    COALESCE(SUM(total_beneficiaries), 0) as total_beneficiaries,
                    COALESCE(AVG(overall_progress), 0) as avg_progress,
                    COALESCE(AVG(data_quality_score), 0) as avg_data_quality_score,
                    MAX(created_at) as last_report_date,
                    MIN(created_at) as first_report_date
                FROM project_activity_reports
                WHERE project_id = ?
                AND created_at BETWEEN ? AND ?
            ";
            
            $stmt = $pdo->prepare($reportQuery);
            $stmt->execute([$projectId, $timeRange['start'], $timeRange['end']]);
            $reportData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($reportData) {
                $default['total_reports'] = (int)($reportData['total_reports'] ?? 0);
                $default['submitted_reports'] = (int)($reportData['submitted_reports'] ?? 0);
                $default['approved_reports'] = (int)($reportData['approved_reports'] ?? 0);
                $default['draft_reports'] = (int)($reportData['draft_reports'] ?? 0);
                $default['total_beneficiaries'] = (int)($reportData['total_beneficiaries'] ?? 0);
                $default['avg_progress'] = round((float)($reportData['avg_progress'] ?? 0), 2);
                $default['avg_data_quality_score'] = round((float)($reportData['avg_data_quality_score'] ?? 0), 2);
                $default['last_report_date'] = $reportData['last_report_date'] ?? null;
                $default['first_report_date'] = $reportData['first_report_date'] ?? null;
            }
            
            // Get budget and activity line statistics separately for better performance
            if (tableExists($pdo, 'project_activity_report_lines') && $default['total_reports'] > 0) {
                // Get activity line statistics
                $activityQuery = "
                    SELECT 
                        COUNT(*) as total_activities,
                        COUNT(CASE WHEN progress_status = 'completed' THEN 1 END) as completed_activities,
                        COUNT(CASE WHEN progress_status = 'ongoing' THEN 1 END) as ongoing_activities,
                        COALESCE(SUM(allocated_budget), 0) as total_budget_allocated,
                        COALESCE(SUM(budget_utilized), 0) as total_budget_utilized,
                        COALESCE(AVG(budget_burn_rate), 0) as avg_budget_burn_rate
                    FROM project_activity_report_lines
                    WHERE report_id IN (
                        SELECT id FROM project_activity_reports 
                        WHERE project_id = ? 
                        AND created_at BETWEEN ? AND ?
                    )
                ";
                
                $stmt = $pdo->prepare($activityQuery);
                $stmt->execute([$projectId, $timeRange['start'], $timeRange['end']]);
                $activityData = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($activityData) {
                    $default['total_activities'] = (int)($activityData['total_activities'] ?? 0);
                    $default['completed_activities'] = (int)($activityData['completed_activities'] ?? 0);
                    $default['ongoing_activities'] = (int)($activityData['ongoing_activities'] ?? 0);
                    $default['total_budget_allocated'] = round((float)($activityData['total_budget_allocated'] ?? 0), 2);
                    $default['total_budget_utilized'] = round((float)($activityData['total_budget_utilized'] ?? 0), 2);
                    $default['avg_budget_burn_rate'] = round((float)($activityData['avg_budget_burn_rate'] ?? 0), 2);
                }
            }
        } else {
            error_log("Warning: project_activity_reports table does not exist and could not be created");
        }
    } catch (PDOException $e) {
        error_log("Activity Report data query error for project $projectId: " . $e->getMessage());
    }

    return $default;
}

/**
 * Real-time data update simulation
 */
function updateRealTimeData($pdo) {
    $updates = [];
    
    if (tableExists($pdo, 'enter_data_entries')) {
        try {
            $projects = $pdo->query("SELECT id FROM projects ORDER BY RAND() LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($projects as $project) {
                $stmt = $pdo->prepare("
                    INSERT INTO enter_data_entries (project_id, data_content, status, validation_status, completeness_score, created_at)
                    VALUES (?, ?, 'completed', 'valid', ?, NOW())
                ");
                $score = rand(75, 95);
                $stmt->execute([$project['id'], "Real-time update for project {$project['id']}", $score]);
                $updates[] = "Added Enter Data entry for project {$project['id']}";
            }
        } catch (PDOException $e) {
            error_log("Real-time Enter Data update failed: " . $e->getMessage());
        }
    }
    
    if (tableExists($pdo, 'cfm_feedbacks')) {
        try {
            $projects = $pdo->query("SELECT id FROM projects ORDER BY RAND() LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($projects as $project) {
                $stmt = $pdo->prepare("
                    INSERT INTO cfm_feedbacks (project_id, feedback_text, status, resolution_time_hours, created_at)
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $status = rand(0, 1) ? 'resolved' : 'pending';
                $resolutionTime = $status === 'resolved' ? rand(1, 48) : NULL;
                $stmt->execute([$project['id'], "Real-time feedback for project {$project['id']}", $status, $resolutionTime]);
                $updates[] = "Added CFM feedback for project {$project['id']}";
            }
        } catch (PDOException $e) {
            error_log("Real-time CFM update failed: " . $e->getMessage());
        }
    }
    
    return $updates;
}

// Check if real-time update is requested
if (isset($_GET['real_time_update']) && $_GET['real_time_update'] === 'true') {
    $realTimeUpdates = updateRealTimeData($pdo);
}

/**
 * Calculate timeliness metric (including activity reports)
 */
function calculateTimeliness($pdo, $projectId, $timeRange, $project) {
    $timelinessScores = [];
    
    try {
        $reportingQuery = "
            SELECT COUNT(*) as total_reports,
                   SUM(CASE 
                       WHEN DATEDIFF(created_at, DATE_FORMAT(created_at, '%Y-%m-01')) <= 7 THEN 1 
                       ELSE 0 
                   END) as on_time_reports
            FROM reports 
            WHERE project_id = ? 
            AND created_at BETWEEN ? AND ?
        ";
        
        $stmt = $pdo->prepare($reportingQuery);
        $stmt->execute([$projectId, $timeRange['start'], $timeRange['end']]);
        $reportData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $timelinessScores['reporting'] = ($reportData && $reportData['total_reports'] > 0)
            ? round(($reportData['on_time_reports'] / $reportData['total_reports']) * 100, 2)
            : 0;
    } catch (PDOException $e) {
        $timelinessScores['reporting'] = 0;
    }
    
    // Include activity reports timeliness
    $activityReportData = getActivityReportData($pdo, $projectId, $timeRange);
    if ($activityReportData['total_reports'] > 0) {
        $submittedRate = ($activityReportData['submitted_reports'] / $activityReportData['total_reports']) * 100;
        $approvedRate = ($activityReportData['approved_reports'] / $activityReportData['total_reports']) * 100;
        $timelinessScores['activity_reports'] = round(($submittedRate + $approvedRate) / 2, 2);
    } else {
        $timelinessScores['activity_reports'] = 0;
    }
    
    $enterData = getEnterDataAppData($pdo, $projectId, $timeRange);
    $timelinessScores['enter_data'] = $enterData['total_entries'] > 0
        ? round(($enterData['completed_entries'] / $enterData['total_entries']) * 100, 2)
        : 0;
    
    $cfmData = getCFMData($pdo, $projectId, $timeRange);
    if ($cfmData['total_feedbacks'] > 0) {
        $resolutionRate     = ($cfmData['resolved_feedbacks'] / $cfmData['total_feedbacks']) * 100;
        $resolutionTimeScore= $cfmData['avg_resolution_time'] > 0
            ? max(0, 100 - ($cfmData['avg_resolution_time'] / 24))
            : 100;
        $timelinessScores['cfm'] = round(($resolutionRate + $resolutionTimeScore) / 2, 2);
    } else {
        $timelinessScores['cfm'] = 0;
    }
    
    $totalWeight  = 0;
    $weightedSum  = 0;
    
    if ($timelinessScores['reporting'] > 0) {
        $weightedSum += $timelinessScores['reporting'] * 0.35;
        $totalWeight += 0.35;
    }
    if ($timelinessScores['activity_reports'] > 0) {
        $weightedSum += $timelinessScores['activity_reports'] * 0.25;
        $totalWeight += 0.25;
    }
    if ($timelinessScores['enter_data'] > 0) {
        $weightedSum += $timelinessScores['enter_data'] * 0.25;
        $totalWeight += 0.25;
    }
    if ($timelinessScores['cfm'] > 0) {
        $weightedSum += $timelinessScores['cfm'] * 0.15;
        $totalWeight += 0.15;
    }
    
    return $totalWeight > 0 ? round($weightedSum / $totalWeight, 2) : 0;
}

/**
 * Calculate completeness metric (including activity reports, budget, and aggregate data)
 */
function calculateCompleteness($pdo, $projectId, $timeRange) {
    $completenessScores = [];
    
    try {
        $reportCompletenessQuery = "
            SELECT 
                AVG(completeness_score) as avg_completeness
            FROM (
                SELECT 
                    r.id,
                    (COUNT(CASE WHEN rv.value IS NOT NULL AND rv.value != '' THEN 1 END) * 100.0 / 
                     COUNT(rv.id)) as completeness_score
                FROM reports r
                LEFT JOIN report_values rv ON r.id = rv.report_id
                LEFT JOIN indicators i ON rv.indicator_id = i.id
                WHERE r.project_id = ?
                  AND r.created_at BETWEEN ? AND ?
                  AND i.is_required = 1
                GROUP BY r.id
            ) as report_scores
        ";
        
        $stmt = $pdo->prepare($reportCompletenessQuery);
        $stmt->execute([$projectId, $timeRange['start'], $timeRange['end']]);
        $reportData = $stmt->fetch(PDO::FETCH_ASSOC);
        $completenessScores['reporting'] = $reportData['avg_completeness'] ? round($reportData['avg_completeness'], 2) : 0;
    } catch (PDOException $e) {
        $completenessScores['reporting'] = 0;
    }
    
    // Include activity reports completeness
    $activityReportData = getActivityReportData($pdo, $projectId, $timeRange);
    if ($activityReportData['total_reports'] > 0 && $activityReportData['total_activities'] > 0) {
        $activityCompleteness = ($activityReportData['completed_activities'] / $activityReportData['total_activities']) * 100;
        $completenessScores['activity_reports'] = round($activityCompleteness, 2);
    } else {
        $completenessScores['activity_reports'] = 0;
    }
    
    $enterData = getEnterDataAppData($pdo, $projectId, $timeRange);
    $completenessScores['enter_data'] = $enterData['avg_completeness'] ? round($enterData['avg_completeness'], 2) : 0;
    
    // Include budget data completeness
    $budgetData = getBudgetData($pdo, $projectId, $timeRange);
    if ($budgetData['total_budget_records'] > 0 && $budgetData['total_budget_planned'] > 0) {
        $budgetCompleteness = min(100, ($budgetData['total_budget_spent'] / $budgetData['total_budget_planned']) * 100);
        $completenessScores['budget'] = round($budgetCompleteness, 2);
    } else {
        $completenessScores['budget'] = 0;
    }
    
    // Include aggregate data completeness
    $aggData = getAggregationData($pdo, $projectId, $timeRange);
    $completenessScores['aggregation'] = $aggData['total_aggregations'] > 0 ? 85.0 : 0;
    
    try {
        $projectQuery = "
            SELECT 
                (CASE WHEN title IS NOT NULL AND title != '' THEN 20 ELSE 0 END) +
                (CASE WHEN description IS NOT NULL AND description != '' THEN 20 ELSE 0 END) +
                (CASE WHEN start_date IS NOT NULL THEN 20 ELSE 0 END) +
                (CASE WHEN end_date IS NOT NULL THEN 20 ELSE 0 END) +
                (CASE WHEN total_fund_usd IS NOT NULL THEN 20 ELSE 0 END) as profile_score
            FROM projects 
            WHERE id = ?
        ";
        
        $stmt = $pdo->prepare($projectQuery);
        $stmt->execute([$projectId]);
        $projectData = $stmt->fetch(PDO::FETCH_ASSOC);
        $completenessScores['project_profile'] = $projectData['profile_score'] ?? 0;
    } catch (PDOException $e) {
        $completenessScores['project_profile'] = 0;
    }
    
    $weights = [
        'reporting' => 0.25, 
        'activity_reports' => 0.25, 
        'enter_data' => 0.20, 
        'budget' => 0.15,
        'aggregation' => 0.10,
        'project_profile' => 0.05
    ];
    $totalScore  = 0;
    $totalWeight = 0;
    
    foreach ($completenessScores as $source => $score) {
        if (isset($weights[$source]) && $score > 0) {
            $totalScore  += $score * $weights[$source];
            $totalWeight += $weights[$source];
        }
    }
    
    return $totalWeight > 0 ? round($totalScore / $totalWeight, 2) : 0;
}

/**
 * Calculate accuracy metric (including activity reports)
 */
function calculateAccuracy($pdo, $projectId, $timeRange) {
    $accuracyScores = [];
    
    try {
        $reportQuery = "
            SELECT 
                COUNT(*) as total_reports,
                COUNT(CASE WHEN validation_status = 'valid' THEN 1 END) as valid_reports
            FROM reports 
            WHERE project_id = ? 
              AND created_at BETWEEN ? AND ?
        ";
        $stmt = $pdo->prepare($reportQuery);
        $stmt->execute([$projectId, $timeRange['start'], $timeRange['end']]);
        $reportData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $accuracyScores['reporting'] = ($reportData && $reportData['total_reports'] > 0)
            ? round(($reportData['valid_reports'] / $reportData['total_reports']) * 100, 2)
            : 0;
    } catch (Exception $e) {
        $accuracyScores['reporting'] = 0;
    }
    
    // Include activity reports accuracy (based on data quality score)
    $activityReportData = getActivityReportData($pdo, $projectId, $timeRange);
    if ($activityReportData['total_reports'] > 0 && $activityReportData['avg_data_quality_score'] > 0) {
        $accuracyScores['activity_reports'] = round($activityReportData['avg_data_quality_score'], 2);
    } else {
        $accuracyScores['activity_reports'] = 0;
    }
    
    $enterData = getEnterDataAppData($pdo, $projectId, $timeRange);
    $accuracyScores['enter_data'] = $enterData['total_entries'] > 0
        ? round(($enterData['valid_entries'] / $enterData['total_entries']) * 100, 2)
        : 0;
    
    $reportingAccuracy = $accuracyScores['reporting'] ?? 0;
    $activityReportAccuracy = $accuracyScores['activity_reports'] ?? 0;
    $enterDataAccuracy = $accuracyScores['enter_data'] ?? 0;
    
    // Weighted average: reporting 40%, activity reports 35%, enter data 25%
    $totalWeight = 0;
    $weightedSum = 0;
    
    if ($reportingAccuracy > 0) {
        $weightedSum += $reportingAccuracy * 0.40;
        $totalWeight += 0.40;
    }
    if ($activityReportAccuracy > 0) {
        $weightedSum += $activityReportAccuracy * 0.35;
        $totalWeight += 0.35;
    }
    if ($enterDataAccuracy > 0) {
        $weightedSum += $enterDataAccuracy * 0.25;
        $totalWeight += 0.25;
    }
    
    return $totalWeight > 0 ? round($weightedSum / $totalWeight, 2) : 0;
}

/**
 * Calculate consistency metric
 */
function calculateConsistency($pdo, $projectId, $timeRange) {
    try {
        $consistencyQuery = "
            SELECT 
                COUNT(DISTINCT rv.value) as unique_values,
                COUNT(*) as total_values,
                rv.indicator_id
            FROM reports r
            JOIN report_values rv ON r.id = rv.report_id
            WHERE r.project_id = ? 
              AND r.created_at BETWEEN ? AND ?
              AND rv.value IS NOT NULL
            GROUP BY rv.indicator_id
            HAVING total_values > 1
        ";
        $stmt = $pdo->prepare($consistencyQuery);
        $stmt->execute([$projectId, $timeRange['start'], $timeRange['end']]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($results) > 0) {
            $totalConsistency = 0;
            foreach ($results as $result) {
                $consistency = ($result['total_values'] - $result['unique_values']) / $result['total_values'] * 100;
                $totalConsistency += max($consistency, 0);
            }
            return round($totalConsistency / count($results), 2);
        }
        return 88.0;
    } catch (Exception $e) {
        return 88.0;
    }
}

/**
 * Calculate validity metric
 */
function calculateValidity($pdo, $projectId, $timeRange) {
    try {
        $validityQuery = "
            SELECT 
                COUNT(*) as total_values,
                COUNT(CASE WHEN rv.value IS NOT NULL AND rv.value != '' THEN 1 END) as valid_values
            FROM reports r
            JOIN report_values rv ON r.id = rv.report_id
            WHERE r.project_id = ? 
              AND r.created_at BETWEEN ? AND ?
        ";
        $stmt = $pdo->prepare($validityQuery);
        $stmt->execute([$projectId, $timeRange['start'], $timeRange['end']]);
        $reportData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $reportValidity = ($reportData && $reportData['total_values'] > 0)
            ? round(($reportData['valid_values'] / $reportData['total_values']) * 100, 2)
            : 0;
            
        $enterData = getEnterDataAppData($pdo, $projectId, $timeRange);
        $enterDataValidity = $enterData['total_entries'] > 0
            ? round(($enterData['valid_entries'] / $enterData['total_entries']) * 100, 2)
            : 0;
        
        return round(($reportValidity * 0.7 + $enterDataValidity * 0.3), 2);
    } catch (Exception $e) {
        return 90.0;
    }
}

/**
 * Calculate uniqueness metric
 */
function calculateUniqueness($pdo, $projectId, $timeRange) {
    try {
        $uniquenessQuery = "
            SELECT 
                COUNT(*) as total_reports,
                COUNT(DISTINCT CONCAT(COALESCE(report_date, created_at), '-', project_id)) as unique_reports
            FROM reports 
            WHERE project_id = ? 
              AND created_at BETWEEN ? AND ?
        ";
        $stmt = $pdo->prepare($uniquenessQuery);
        $stmt->execute([$projectId, $timeRange['start'], $timeRange['end']]);
        $reportData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $reportUniqueness = ($reportData && $reportData['total_reports'] > 0)
            ? round(($reportData['unique_reports'] / $reportData['total_reports']) * 100, 2)
            : 100;
            
        return $reportUniqueness;
    } catch (Exception $e) {
        return 95.0;
    }
}

/**
 * Calculate integrity metric
 */
function calculateIntegrity($pdo, $projectId, $timeRange) {
    try {
        $integrityQuery = "
            SELECT 
                COUNT(*) as total_relations,
                SUM(CASE WHEN rv.indicator_id IS NOT NULL THEN 1 ELSE 0 END) as valid_relations
            FROM reports r
            JOIN report_values rv ON r.id = rv.report_id
            WHERE r.project_id = ? 
              AND r.created_at BETWEEN ? AND ?
        ";
        $stmt = $pdo->prepare($integrityQuery);
        $stmt->execute([$projectId, $timeRange['start'], $timeRange['end']]);
        $reportData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($reportData && $reportData['total_relations'] > 0) {
            return round(($reportData['valid_relations'] / $reportData['total_relations']) * 100, 2);
        }
        return 92.0;
    } catch (Exception $e) {
        return 92.0;
    }
}

/**
 * Calculate relevance metric
 */
function calculateRelevance($pdo, $projectId, $timeRange) {
    try {
        $relevanceQuery = "
            SELECT 
                COUNT(*) as total_reports,
                SUM(CASE 
                    WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1
                    ELSE 0 
                END) as recent_reports
            FROM reports 
            WHERE project_id = ? 
              AND created_at BETWEEN ? AND ?
        ";
        $stmt = $pdo->prepare($relevanceQuery);
        $stmt->execute([$projectId, $timeRange['start'], $timeRange['end']]);
        $reportData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $reportRelevance = 0;
        if ($reportData && $reportData['total_reports'] > 0) {
            $reportRelevance = ($reportData['recent_reports'] / $reportData['total_reports']) * 100;
        }
        
        $enterData = getEnterDataAppData($pdo, $projectId, $timeRange);
        $enterDataRelevance = (!empty($enterData['last_update']))
            ? (strtotime($enterData['last_update']) > strtotime('-30 days') ? 100 : 50)
            : 0;
            
        return round(($reportRelevance * 0.6 + $enterDataRelevance * 0.4), 2);
    } catch (Exception $e) {
        return 85.0;
    }
}

/**
 * Get reporting information (including activity reports, budget, and aggregate data)
 */
function getReportingInfo($pdo, $projectId, $timeRange) {
    $info = [
        'total_reports'       => 0,
        'last_report_date'    => null,
        'first_report_date'   => null,
        'enter_data_entries'  => 0,
        'cfm_feedbacks'       => 0,
        'budget_records'      => 0,
        'aggregation_records' => 0,
        'activity_reports'    => 0,
        'activity_report_data' => [],
        'data_sources'        => []
    ];
    
    try {
        $reportQuery = "
            SELECT 
                COUNT(*) as total_reports,
                MAX(created_at) as last_report_date,
                MIN(created_at) as first_report_date
            FROM reports 
            WHERE project_id = ? 
              AND created_at BETWEEN ? AND ?
        ";
        $stmt = $pdo->prepare($reportQuery);
        $stmt->execute([$projectId, $timeRange['start'], $timeRange['end']]);
        $reportData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($reportData) {
            $info['total_reports']     = $reportData['total_reports']     ?? 0;
            $info['last_report_date']  = $reportData['last_report_date']  ?? null;
            $info['first_report_date'] = $reportData['first_report_date'] ?? null;
        }
        
        $enterData = getEnterDataAppData($pdo, $projectId, $timeRange);
        $info['enter_data_entries'] = $enterData['total_entries'] ?? 0;
        
        $cfmData = getCFMData($pdo, $projectId, $timeRange);
        $info['cfm_feedbacks'] = $cfmData['total_feedbacks'] ?? 0;

        $budgetData = getBudgetData($pdo, $projectId, $timeRange);
        $info['budget_records'] = $budgetData['total_budget_records'] ?? 0;

        $aggData = getAggregationData($pdo, $projectId, $timeRange);
        $info['aggregation_records'] = $aggData['total_aggregations'] ?? 0;
        
        // Get activity report data (linked from project_activity_report.php)
        $activityReportData = getActivityReportData($pdo, $projectId, $timeRange);
        $info['activity_reports'] = $activityReportData['total_reports'] ?? 0;
        $info['activity_report_data'] = $activityReportData;
        
        // Update last_report_date if activity report is more recent
        if (!empty($activityReportData['last_report_date'])) {
            if (empty($info['last_report_date']) || 
                strtotime($activityReportData['last_report_date']) > strtotime($info['last_report_date'])) {
                $info['last_report_date'] = $activityReportData['last_report_date'];
            }
        }
        
        if ($info['total_reports'] > 0)       $info['data_sources'][] = 'reporting';
        if ($info['enter_data_entries'] > 0)  $info['data_sources'][] = 'enter_data';
        if ($info['cfm_feedbacks'] > 0)       $info['data_sources'][] = 'cfm';
        if ($info['budget_records'] > 0)      $info['data_sources'][] = 'budget';
        if ($info['aggregation_records'] > 0) $info['data_sources'][] = 'aggregation';
        if ($info['activity_reports'] > 0)    $info['data_sources'][] = 'activity_reports';
        
    } catch (PDOException $e) {
        error_log("Reporting info error: " . $e->getMessage());
    }
    
    return $info;
}

/**
 * Calculate overall score from all dimensions
 */
function calculateOverallScore($dimensions) {
    $total = 0;
    $count = 0;
    
    foreach ($dimensions as $key => $value) {
        if ($key !== 'reporting_info' && is_numeric($value)) {
            $total += $value;
            $count++;
        }
    }
    
    return $count > 0 ? round($total / $count, 2) : 0;
}

/**
 * Enhanced time range calculation with:
 * - Custom date range (when time_filter == 'custom')
 * - OR year/month selection (overrides simple weekly/monthly/quarterly/yearly)
 */
function calculateEnhancedTimeRange($filters) {
    // Custom date range has highest priority (only when time_filter is 'custom')
    if ($filters['time_filter'] === 'custom'
        && !empty($filters['start_date'])
        && !empty($filters['end_date'])) {
        return [
            'start' => $filters['start_date'] . ' 00:00:00',
            'end'   => $filters['end_date']   . ' 23:59:59'
        ];
    }

    // If specific years/months are selected, use them
    $years  = $filters['year']  ?? [];
    $months = $filters['month'] ?? [];

    $selectedYears  = array_values(array_filter($years,  fn($y) => $y !== 'all'));
    $selectedMonths = array_values(array_filter($months, fn($m) => $m !== 'all'));

    if (!empty($selectedYears) || !empty($selectedMonths)) {
        // Default: if no specific year selected, use current year
        if (empty($selectedYears)) {
            $selectedYears = [date('Y')];
        }
        // Default: if no specific month selected, use all months
        if (empty($selectedMonths)) {
            $selectedMonths = range(1, 12);
        }

        sort($selectedYears, SORT_NUMERIC);
        sort($selectedMonths, SORT_NUMERIC);

        $startYear  = (int)$selectedYears[0];
        $startMonth = (int)$selectedMonths[0];
        $endYear    = (int)$selectedYears[count($selectedYears) - 1];
        $endMonth   = (int)$selectedMonths[count($selectedMonths) - 1];

        $startDate = sprintf('%04d-%02d-01', $startYear, $startMonth);
        $endDate   = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $endYear, $endMonth)));

        return [
            'start' => $startDate . ' 00:00:00',
            'end'   => $endDate   . ' 23:59:59'
        ];
    }

    // Otherwise, fall back to relative ranges (weekly/monthly/quarterly/yearly)
    $end = date('Y-m-d H:i:s');
    switch ($filters['time_filter']) {
        case 'weekly':
            $start = date('Y-m-d H:i:s', strtotime('-1 week'));
            break;
        case 'monthly':
            $start = date('Y-m-d H:i:s', strtotime('-1 month'));
            break;
        case 'quarterly':
            $start = date('Y-m-d H:i:s', strtotime('-3 months'));
            break;
        case 'yearly':
            $start = date('Y-m-d H:i:s', strtotime('-1 year'));
            break;
        default:
            $start = date('Y-m-d H:i:s', strtotime('-1 month'));
    }
    
    return ['start' => $start, 'end' => $end];
}

/**
 * Calculate all 8 data quality dimensions
 */
function calculateAllQualityDimensions($pdo, $projectId, $timeRange, $project) {
    $dimensions = [];
    
    $dimensions['timeliness']  = calculateTimeliness($pdo, $projectId, $timeRange, $project);
    $dimensions['completeness']= calculateCompleteness($pdo, $projectId, $timeRange);
    $dimensions['accuracy']    = calculateAccuracy($pdo, $projectId, $timeRange);
    $dimensions['consistency'] = calculateConsistency($pdo, $projectId, $timeRange);
    $dimensions['validity']    = calculateValidity($pdo, $projectId, $timeRange);
    $dimensions['uniqueness']  = calculateUniqueness($pdo, $projectId, $timeRange);
    $dimensions['integrity']   = calculateIntegrity($pdo, $projectId, $timeRange);
    $dimensions['relevance']   = calculateRelevance($pdo, $projectId, $timeRange);
    
    $dimensions['reporting_info'] = getReportingInfo($pdo, $projectId, $timeRange);
    
    return $dimensions;
}

/**
 * Generate enhanced feedback based on quality scores
 */
function generateEnhancedFeedback($dimensions) {
    $messages    = [];
    $overallScore= calculateOverallScore($dimensions);
    
    if ($overallScore >= 90) {
        $messages[] = "🏆 EXCELLENT! Data quality is outstanding across all dimensions!";
    } elseif ($overallScore >= 75) {
        $messages[] = "👍 GOOD! Solid data quality with minor improvement opportunities.";
    } elseif ($overallScore >= 60) {
        $messages[] = "⚠️ FAIR! Several areas need attention for better quality.";
    } else {
        $messages[] = "🚨 CRITICAL! Immediate action required to improve data quality!";
    }
    
    foreach ($dimensions as $dimKey => $score) {
        if ($dimKey === 'reporting_info') continue;
        
        $dimensionName = $GLOBALS['qualityDimensions'][$dimKey]['name'] ?? ucfirst($dimKey);
        
        if ($score < 60) {
            $messages[] = "❌ $dimensionName: Critical issues detected!";
        } elseif ($score < 75) {
            $messages[] = "⚠️ $dimensionName: Needs improvement.";
        }
    }
    
    return implode(" ", $messages);
}

/**
 * Generate enhanced actions based on quality scores
 */
function generateEnhancedActions($dimensions) {
    $actions      = [];
    $overallScore = calculateOverallScore($dimensions);
    
    if ($overallScore < 90) {
        $actions[] = "Implement data quality monitoring dashboard";
    }
    if ($dimensions['timeliness'] < 75) {
        $actions[] = "Automate reporting reminders and deadlines";
    }
    if ($dimensions['completeness'] < 75) {
        $actions[] = "Enforce mandatory field validation";
    }
    if ($dimensions['accuracy'] < 75) {
        $actions[] = "Establish data validation rules";
    }
    if ($dimensions['consistency'] < 75) {
        $actions[] = "Standardize data entry procedures";
    }
    
    if (empty($actions)) {
        return "🎯 Maintain current excellence with continuous monitoring";
    }
    
    return implode(" • ", $actions);
}

/**
 * Projects query with multiple selection support
 */
function getFilteredProjects($pdo, $projectFilter, $regionFilter, $zoneFilter, $woredaFilter) {
    // Check if 'code' column exists in projects table
    $hasCodeColumn = false;
    try {
        $checkStmt = $pdo->query("
            SELECT COUNT(*) 
            FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'projects' 
            AND COLUMN_NAME = 'code'
        ");
        $hasCodeColumn = $checkStmt->fetchColumn() > 0;
    } catch (Exception $e) {
        // If check fails, assume column doesn't exist
        $hasCodeColumn = false;
    }
    
    $codeSelect = $hasCodeColumn ? 'p.code,' : '';
    
    try {
        $query = "
            SELECT p.id, p.title, {$codeSelect} p.total_fund_usd, p.status, 
                   p.expected_reporting_day, p.reporting_frequency, p.reporting_deadline_days,
                   r.name as region_name, z.name as zone_name, w.name as woreda_name,
                   r.id as region_id, z.id as zone_id, w.id as woreda_id
            FROM projects p
            LEFT JOIN regions r ON p.region_id = r.id
            LEFT JOIN zones z   ON p.zone_id   = z.id
            LEFT JOIN woredas w ON p.woreda_id = w.id
            WHERE 1=1
        ";
        
        $params = [];
        
        if (!in_array('all', $projectFilter)) {
            $placeholders = str_repeat('?,', count($projectFilter) - 1) . '?';
            $query .= " AND p.id IN ($placeholders)";
            $params = array_merge($params, $projectFilter);
        }
        
        if (!in_array('all', $regionFilter)) {
            $placeholders = str_repeat('?,', count($regionFilter) - 1) . '?';
            $query .= " AND p.region_id IN ($placeholders)";
            $params = array_merge($params, $regionFilter);
        }
        
        if (!in_array('all', $zoneFilter)) {
            $placeholders = str_repeat('?,', count($zoneFilter) - 1) . '?';
            $query .= " AND p.zone_id IN ($placeholders)";
            $params = array_merge($params, $zoneFilter);
        }
        
        if (!in_array('all', $woredaFilter)) {
            $placeholders = str_repeat('?,', count($woredaFilter) - 1) . '?';
            $query .= " AND p.woreda_id IN ($placeholders)";
            $params = array_merge($params, $woredaFilter);
        }
        
        $query .= " ORDER BY p.title";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Projects query error: " . $e->getMessage());
        $codeSelectFallback = $hasCodeColumn ? 'code,' : '';
        return $pdo->query("SELECT id, title, {$codeSelectFallback} total_fund_usd, status FROM projects ORDER BY title")->fetchAll(PDO::FETCH_ASSOC);
    }
}

/**
 * Metrics calculation with 8 data quality dimensions
 */
function calculateEnhancedDataQualityMetrics($pdo, $filters) {
    $metrics = [
        'projects' => [],
        'summary'  => [
            'total_projects'     => 0,
            'reporting_projects' => 0,
            'dimensions'         => []
        ],
        'by_region'    => [],
        'by_zone'      => [],
        'by_woreda'    => [],
        'by_frequency' => [],
        'by_time'      => [],
        'data_sources' => [
            'reporting'        => 0,
            'enter_data'       => 0,
            'cfm'              => 0,
            'budget'           => 0,
            'aggregation'      => 0,
            'activity_reports' => 0
        ]
    ];

    global $qualityDimensions;
    foreach ($qualityDimensions as $dimKey => $dimInfo) {
        $metrics['summary']['dimensions'][$dimKey] = [
            'total'   => 0,
            'count'   => 0,
            'average' => 0
        ];
    }

    $allProjects = getFilteredProjects(
        $pdo,
        $filters['project_id'],
        $filters['region_id'],
        $filters['zone_id'],
        $filters['woreda_id']
    );
    $metrics['summary']['total_projects'] = count($allProjects);

    foreach ($allProjects as $project) {
        $projectId = $project['id'];
        
        $timeRange       = calculateEnhancedTimeRange($filters);
        $dimensionScores = calculateAllQualityDimensions($pdo, $projectId, $timeRange, $project);
        
        $projectMetrics = [
            'title'        => $project['title'],
            'code'         => $project['code'] ?? '',
            'region'       => $project['region_name']  ?? 'N/A',
            'zone'         => $project['zone_name']    ?? 'N/A',
            'woreda'       => $project['woreda_name']  ?? 'N/A',
            'region_id'    => $project['region_id']    ?? null,
            'zone_id'      => $project['zone_id']      ?? null,
            'woreda_id'    => $project['woreda_id']    ?? null,
            'frequency'    => $project['reporting_frequency'] ?? 'monthly',
            'dimensions'   => $dimensionScores,
            'overall_score'=> calculateOverallScore($dimensionScores),
            'last_report'  => $dimensionScores['reporting_info']['last_report_date'] ?? null,
            'feedback'     => generateEnhancedFeedback($dimensionScores),
            'actions'      => generateEnhancedActions($dimensionScores),
            'data_sources' => $dimensionScores['reporting_info']['data_sources'] ?? []
        ];

        $metrics['projects'][$projectId] = $projectMetrics;

        if ($dimensionScores['reporting_info']['total_reports'] > 0) {
            $metrics['summary']['reporting_projects']++;
        }

        foreach ($dimensionScores as $dimKey => $score) {
            if ($dimKey !== 'reporting_info' && is_numeric($score)) {
                $metrics['summary']['dimensions'][$dimKey]['total'] += $score;
                $metrics['summary']['dimensions'][$dimKey]['count']++;
            }
        }

        foreach ($dimensionScores['reporting_info']['data_sources'] as $source) {
            if (isset($metrics['data_sources'][$source])) {
                $metrics['data_sources'][$source]++;
            }
        }

        // Region group
        $region = $project['region_name'] ?? 'Unknown';
        if (!isset($metrics['by_region'][$region])) {
            $metrics['by_region'][$region] = [
                'count'       => 0,
                'timeliness'  => 0,
                'completeness'=> 0,
                'accuracy'    => 0,
                'consistency' => 0,
                'validity'    => 0,
                'uniqueness'  => 0,
                'integrity'   => 0,
                'relevance'   => 0,
                'overall'     => 0
            ];
        }
        $metrics['by_region'][$region]['count']++;
        foreach ($dimensionScores as $dimKey => $score) {
            if ($dimKey !== 'reporting_info' && is_numeric($score)) {
                $metrics['by_region'][$region][$dimKey] += $score;
            }
        }
        $metrics['by_region'][$region]['overall'] += $projectMetrics['overall_score'];

        // Zone group
        $zone = $project['zone_name'] ?? 'Unknown';
        if (!isset($metrics['by_zone'][$zone])) {
            $metrics['by_zone'][$zone] = [
                'count'       => 0,
                'timeliness'  => 0,
                'completeness'=> 0,
                'accuracy'    => 0,
                'consistency' => 0,
                'validity'    => 0,
                'uniqueness'  => 0,
                'integrity'   => 0,
                'relevance'   => 0,
                'overall'     => 0
            ];
        }
        $metrics['by_zone'][$zone]['count']++;
        foreach ($dimensionScores as $dimKey => $score) {
            if ($dimKey !== 'reporting_info' && is_numeric($score)) {
                $metrics['by_zone'][$zone][$dimKey] += $score;
            }
        }
        $metrics['by_zone'][$zone]['overall'] += $projectMetrics['overall_score'];

        // Woreda group
        $woreda = $project['woreda_name'] ?? 'Unknown';
        if (!isset($metrics['by_woreda'][$woreda])) {
            $metrics['by_woreda'][$woreda] = [
                'count'       => 0,
                'timeliness'  => 0,
                'completeness'=> 0,
                'accuracy'    => 0,
                'consistency' => 0,
                'validity'    => 0,
                'uniqueness'  => 0,
                'integrity'   => 0,
                'relevance'   => 0,
                'overall'     => 0
            ];
        }
        $metrics['by_woreda'][$woreda]['count']++;
        foreach ($dimensionScores as $dimKey => $score) {
            if ($dimKey !== 'reporting_info' && is_numeric($score)) {
                $metrics['by_woreda'][$woreda][$dimKey] += $score;
            }
        }
        $metrics['by_woreda'][$woreda]['overall'] += $projectMetrics['overall_score'];
    }

    // Summary dimension averages
    foreach ($metrics['summary']['dimensions'] as $dimKey => &$data) {
        if ($data['count'] > 0) {
            $data['average'] = round($data['total'] / $data['count'], 2);
        }
    }

    // Regional averages
    foreach ($metrics['by_region'] as $region => &$data) {
        if ($data['count'] > 0) {
            foreach ($qualityDimensions as $dimKey => $dimInfo) {
                $data[$dimKey] = round($data[$dimKey] / $data['count'], 2);
            }
            $data['overall'] = round($data['overall'] / $data['count'], 2);
        }
    }

    // Zone averages
    foreach ($metrics['by_zone'] as $zone => &$data) {
        if ($data['count'] > 0) {
            foreach ($qualityDimensions as $dimKey => $dimInfo) {
                $data[$dimKey] = round($data[$dimKey] / $data['count'], 2);
            }
            $data['overall'] = round($data['overall'] / $data['count'], 2);
        }
    }

    // Woreda averages
    foreach ($metrics['by_woreda'] as $woreda => &$data) {
        if ($data['count'] > 0) {
            foreach ($qualityDimensions as $dimKey => $dimInfo) {
                $data[$dimKey] = round($data[$dimKey] / $data['count'], 2);
            }
            $data['overall'] = round($data['overall'] / $data['count'], 2);
        }
    }

    return $metrics;
}

// Calculate metrics with enhanced filters
$filters = [
    'time_filter'       => $timeFilter,
    'project_id'        => $projectFilter,
    'region_id'         => $regionFilter,
    'zone_id'           => $zoneFilter,
    'woreda_id'         => $woredaFilter,
    'year'              => $yearFilter,
    'month'             => $monthFilter,
    'start_date'        => $startDate,
    'end_date'          => $endDate,
    'quality_dimension' => $qualityDimension
];

$metrics     = calculateEnhancedDataQualityMetrics($pdo, $filters);
$projectList = getFilteredProjects($pdo, ['all'], ['all'], ['all'], ['all']);

// AI-powered messages
$aiMessages = [
    "excellent" => [
        "🚀 AI Insight: Your data quality is reaching new heights! Continuous excellence detected across all dimensions.",
        "🤖 AI Analysis: Data excellence achieved! Your metrics are setting new benchmarks for quality standards.",
        "💫 AI Report: Phenomenal data integrity! Your commitment to quality is transforming insights into impact."
    ],
    "good" => [
        "📊 AI Insight: Solid foundation detected! Your data quality shows strong potential for excellence with minor optimizations.",
        "🎯 AI Analysis: Quality momentum building! Your data practices are creating a reliable foundation for decision-making.",
        "⚡ AI Report: Consistent performance observed! Your data quality is building trust and reliability across systems."
    ],
    "fair" => [
        "🔍 AI Insight: Improvement opportunities identified! Strategic enhancements can elevate your data quality significantly.",
        "🔄 AI Analysis: Foundation established! Your data quality journey has begun with clear pathways to excellence.",
        "🌱 AI Report: Growth potential detected! Your current metrics show promising foundations for quality transformation."
    ],
    "poor" => [
        "🚨 AI Insight: Immediate attention recommended! Data quality optimization can unlock significant value and reliability.",
        "🛠️ AI Analysis: Transformation opportunity! Your data quality journey begins with focused improvements and standardization.",
        "🎯 AI Report: Strategic optimization needed! Building data quality foundations will drive better insights and decisions."
    ]
];

function getAIMessage($score, $aiMessages) {
    if ($score >= 90) return $aiMessages['excellent'][array_rand($aiMessages['excellent'])];
    if ($score >= 75) return $aiMessages['good'][array_rand($aiMessages['good'])];
    if ($score >= 60) return $aiMessages['fair'][array_rand($aiMessages['fair'])];
    return $aiMessages['poor'][array_rand($aiMessages['poor'])];
}

// Helper functions for quality classification
function getQualityClass($value) {
    if ($value >= 90) return 'excellent';
    if ($value >= 75) return 'good';
    if ($value >= 60) return 'fair';
    return 'poor';
}

function getQualityLabel($value) {
    if ($value >= 90) return 'Excellent';
    if ($value >= 75) return 'Good';
    if ($value >= 60) return 'Fair';
    return 'Poor';
}

// PREPARE DATA FOR CHARTS (respect quality_dimension filter for summary & radar)
$dimensionLabels        = [];
$dimensionScoresForChart= [];
foreach ($qualityDimensions as $dimKey => $dimInfo) {
    if ($qualityDimension !== 'all' && $qualityDimension !== $dimKey) {
        continue;
    }
    $dimensionLabels[]        = $dimInfo['name'];
    $dimensionScoresForChart[]= $metrics['summary']['dimensions'][$dimKey]['average'] ?? 0;
}

$regionLabels        = array_keys($metrics['by_region']);
$regionScoresForChart= [];
foreach ($metrics['by_region'] as $region => $data) {
    $regionScoresForChart[] = $data['overall'] ?? 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Quality Excellence Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/css/select2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/js/select2.min.js"></script>
    
    <style>
        :root {
            --primary: #4361ee;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --purple: #8b5cf6;
            --pink: #ec4899;
            --indigo: #6366f1;
        }

        .data-quality-container {
            display: grid;
            gap: 25px;
            margin: 25px 0;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            min-height: 100vh;
            position: relative;
            overflow: hidden;
        }

        .data-quality-container::before,
        .data-quality-container::after {
            content: "";
            position: absolute;
            width: 160%;
            height: 180px;
            left: -30%;
            border-radius: 50%;
            opacity: 0.35;
            z-index: 0;
        }

        .data-quality-container::before {
            top: -120px;
            background: radial-gradient(circle at 20% 20%, var(--primary), transparent 70%);
            animation: waveMove 12s infinite linear;
        }

        .data-quality-container::after {
            bottom: -130px;
            background: radial-gradient(circle at 80% 80%, var(--purple), transparent 70%);
            animation: waveMoveReverse 16s infinite linear;
        }

        @keyframes waveMove {
            0% { transform: translateX(0); }
            50% { transform: translateX(40px); }
            100% { transform: translateX(0); }
        }

        @keyframes waveMoveReverse {
            0% { transform: translateX(0); }
            50% { transform: translateX(-40px); }
            100% { transform: translateX(0); }
        }

        .data-quality-container > * {
            position: relative;
            z-index: 1;
        }

        .ai-message-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 20px;
            text-align: center;
            margin-bottom: 25px;
            animation: slideInDown 1s ease-out;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
        }

        .ai-message-banner::after {
            content: "";
            position: absolute;
            bottom: -60px;
            left: -10%;
            width: 120%;
            height: 120px;
            background: radial-gradient(circle at 50% 0, rgba(255,255,255,0.35), transparent 70%);
            opacity: 0.6;
        }

        .ai-message-text {
            font-size: 1.5em;
            font-weight: 700;
            margin-bottom: 15px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }

        .ai-message-subtext {
            opacity: 0.95;
            font-size: 1.1em;
            font-weight: 500;
        }

        .quality-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 12px 30px rgba(0,0,0,0.15);
            text-align: center;
            border-left: 6px solid;
            transition: all 0.4s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, var(--primary), var(--success), var(--purple));
        }

        .stat-card:hover {
            transform: translateY(-12px) scale(1.02);
            box-shadow: 0 25px 50px rgba(0,0,0,0.2);
        }

        .stat-icon {
            font-size: 3em;
            margin-bottom: 20px;
            opacity: 0.9;
        }

        .stat-number {
            font-size: 3.2em;
            font-weight: 900;
            margin-bottom: 15px;
            background: linear-gradient(135deg, var(--primary), var(--info), var(--purple));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .stat-label {
            color: #64748b;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.95em;
            letter-spacing: 1.5px;
        }

        .quality-excellent { border-left-color: var(--success); }
        .quality-good      { border-left-color: var(--info); }
        .quality-fair      { border-left-color: var(--warning); }
        .quality-poor      { border-left-color: var(--danger); }

        .controls-card {
            background: white;
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 12px 30px rgba(0,0,0,0.15);
        }

        .controls-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            align-items: end;
        }

        .form-group {
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 12px;
            font-weight: 700;
            color: #374151;
            font-size: 1.1em;
        }

        .select2-container .select2-selection--multiple {
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 8px;
            min-height: 50px;
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 14px;
        }

        .charts-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 30px;
            margin-bottom: 30px;
        }

        .chart-card {
            background: white;
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 12px 30px rgba(0,0,0,0.15);
        }

        .chart-title {
            font-size: 1.4em;
            font-weight: 800;
            margin-bottom: 25px;
            color: #1f2937;
            text-align: center;
        }

        .chart-wrapper {
            position: relative;
            height: 350px;
            width: 100%;
        }

        .quality-table {
            background: white;
            border-radius: 20px;
            box-shadow: 0 12px 30px rgba(0,0,0,0.15);
            overflow: hidden;
            margin-bottom: 30px;
        }

        .table-header {
            background: linear-gradient(135deg, var(--primary), #764ba2);
            color: white;
            padding: 30px;
        }

        .table-header h3 {
            margin: 0;
            font-size: 1.6em;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .table-container {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            background: #f8fafc;
            padding: 20px 15px;
            text-align: left;
            font-weight: 800;
            color: #374151;
            border-bottom: 3px solid #e5e7eb;
        }

        .data-table td {
            padding: 18px 15px;
            border-bottom: 2px solid #f1f5f9;
            vertical-align: middle;
        }

        .quality-badge {
            padding: 10px 18px;
            border-radius: 25px;
            font-size: 0.9em;
            font-weight: 800;
            text-transform: uppercase;
            display: inline-block;
        }

        .badge-excellent { 
            background: linear-gradient(135deg, var(--success), #34d399); 
            color: white;
        }
        .badge-good { 
            background: linear-gradient(135deg, var(--info), #60a5fa); 
            color: white;
        }
        .badge-fair { 
            background: linear-gradient(135deg, var(--warning), #fbbf24); 
            color: white;
        }
        .badge-poor { 
            background: linear-gradient(135deg, var(--danger), #f87171); 
            color: white;
        }

        .progress-bar {
            height: 12px;
            background: #e5e7eb;
            border-radius: 6px;
            overflow: hidden;
            margin: 10px 0;
        }

        .progress-fill {
            height: 100%;
            border-radius: 6px;
            transition: width 0.8s ease;
        }

        .progress-excellent { background: linear-gradient(90deg, var(--success), #34d399); }
        .progress-good      { background: linear-gradient(90deg, var(--info), #60a5fa); }
        .progress-fair      { background: linear-gradient(90deg, var(--warning), #fbbf24); }
        .progress-poor      { background: linear-gradient(90deg, var(--danger), #f87171); }

        .export-buttons {
            display: flex;
            gap: 20px;
            margin-top: 30px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .export-btn {
            padding: 15px 30px;
            border: none;
            border-radius: 15px;
            cursor: pointer;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.4s ease;
            color: white;
            font-size: 1.1em;
        }

        .export-pdf   { background: linear-gradient(135deg, var(--danger), #dc2626); }
        .export-excel { background: linear-gradient(135deg, var(--success), #059669); }
        .export-word  { background: linear-gradient(135deg, var(--info), #2563eb); }
        .export-csv   { background: linear-gradient(135deg, var(--purple), #7c3aed); }
        .export-image { background: linear-gradient(135deg, #f59e0b, #d97706); }

        .data-source-indicators {
            display: flex;
            gap: 15px;
            margin: 20px 0;
            justify-content: center;
            flex-wrap: wrap;
        }

        .data-source-badge {
            padding: 10px 20px;
            border-radius: 20px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            background: #f1f5f9;
            color: #475569;
        }

        .data-source-badge.active {
            background: linear-gradient(135deg, var(--primary), var(--info));
            color: white;
        }

        .real-time-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            background: linear-gradient(135deg, var(--success), #059669);
            color: white;
            padding: 15px 20px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            z-index: 1000;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideInRight 0.5s ease-out;
        }

        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to   { transform: translateX(0); opacity: 1; }
        }

        @keyframes slideInDown {
            from { opacity: 0; transform: translateY(-50px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 768px) {
            .quality-stats {
                grid-template-columns: 1fr;
            }
            .charts-container {
                grid-template-columns: 1fr;
            }
            .controls-form {
                grid-template-columns: 1fr;
            }
            .export-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
<?php if (isset($realTimeUpdates) && !empty($realTimeUpdates)): ?>
    <div class="real-time-notification" id="realTimeNotification">
        <i class="fas fa-sync-alt fa-spin"></i>
        <div>
            <strong>Real-time Data Updated!</strong>
            <div style="font-size: 0.9em; margin-top: 5px;">
                <?php echo implode(', ', array_slice($realTimeUpdates, 0, 2)); ?>
                <?php if (count($realTimeUpdates) > 2): ?>...<?php endif; ?>
            </div>
        </div>
    </div>
    <script>
        setTimeout(() => {
            document.getElementById('realTimeNotification')?.remove();
        }, 5000);
    </script>
<?php endif; ?>

<div class="data-quality-container">
    <!-- AI Message Banner -->
    <div class="ai-message-banner">
        <div class="ai-message-text">
            🤖 <?php 
            // Headline score kept as timeliness average as before
            $headlineScore = $metrics['summary']['dimensions']['timeliness']['average'] ?? 0;
            echo getAIMessage($headlineScore, $aiMessages); 
            ?>
        </div>
        <div class="ai-message-subtext">
            💡 Real-time Monitoring: <?php echo count($metrics['projects']); ?> active projects • 
            Overall Quality (Timeliness): <?php echo round($headlineScore, 1); ?>% • 
            <?php echo getQualityLabel($headlineScore); ?> Level • 
            Last updated: <?php echo date('M j, Y g:i A'); ?>
            
            <div style="margin-top: 15px;">
                <a href="?<?php echo http_build_query(array_merge($_GET, ['real_time_update' => 'true'])); ?>" 
                   class="export-btn" 
                   style="background: linear-gradient(135deg, var(--success), #059669); padding: 10px 20px; font-size: 0.9em; display: inline-flex;">
                    <i class="fas fa-bolt"></i> Update Real-time Data
                </a>
            </div>
        </div>
        
        <div class="data-source-indicators">
            <div class="data-source-badge <?php echo $metrics['data_sources']['reporting'] > 0 ? 'active' : ''; ?>">
                <i class="fas fa-chart-bar"></i> Reporting System: <?php echo $metrics['data_sources']['reporting']; ?> projects
            </div>
            <div class="data-source-badge <?php echo $metrics['data_sources']['enter_data'] > 0 ? 'active' : ''; ?>">
                <i class="fas fa-keyboard"></i> Enter Data App: <?php echo $metrics['data_sources']['enter_data']; ?> projects
            </div>
            <div class="data-source-badge <?php echo $metrics['data_sources']['cfm'] > 0 ? 'active' : ''; ?>">
                <i class="fas fa-comments"></i> CFM System: <?php echo $metrics['data_sources']['cfm']; ?> projects
            </div>
            <div class="data-source-badge <?php echo $metrics['data_sources']['budget'] > 0 ? 'active' : ''; ?>">
                <i class="fas fa-wallet"></i> Budget Module: <?php echo $metrics['data_sources']['budget']; ?> projects
            </div>
            <div class="data-source-badge <?php echo $metrics['data_sources']['aggregation'] > 0 ? 'active' : ''; ?>">
                <i class="fas fa-layer-group"></i> Aggregation Reports: <?php echo $metrics['data_sources']['aggregation']; ?> projects
            </div>
            <div class="data-source-badge <?php echo $metrics['data_sources']['activity_reports'] > 0 ? 'active' : ''; ?>">
                <i class="fas fa-tasks"></i> Activity Reports: <?php echo $metrics['data_sources']['activity_reports']; ?> projects
            </div>
        </div>
    </div>

    <!-- Header -->
    <div class="card" style="background: white; padding: 30px; border-radius: 20px; box-shadow: 0 12px 30px rgba(0,0,0,0.15);">
        <h1 style="margin: 0; font-size: 2.5em; background: linear-gradient(135deg, var(--primary), var(--purple)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; display: flex; align-items: center; gap: 15px;">
            📊 Data Quality Excellence Dashboard
        </h1>
        <p style="font-size: 1.2em; color: #64748b; margin-top: 15px;">
            8-Dimensional Quality Monitoring • AI-Powered Insights • Real-time Analytics • Comprehensive Reporting • Multi-Source Data Integration
        </p>
    </div>

    <!-- Enhanced Controls -->
    <div class="controls-card">
        <form method="GET" class="controls-form" id="qualityForm">
            <div class="form-group">
                <label for="time_filter"><i class="fas fa-calendar-alt"></i> Time Period:</label>
                <select name="time_filter" id="time_filter" class="form-control">
                    <option value="weekly"   <?php echo $timeFilter === 'weekly'   ? 'selected' : ''; ?>>Weekly Analysis</option>
                    <option value="monthly"  <?php echo $timeFilter === 'monthly'  ? 'selected' : ''; ?>>Monthly Overview</option>
                    <option value="quarterly"<?php echo $timeFilter === 'quarterly'? 'selected' : ''; ?>>Quarterly Review</option>
                    <option value="yearly"   <?php echo $timeFilter === 'yearly'   ? 'selected' : ''; ?>>Annual Report</option>
                    <option value="custom"   <?php echo $timeFilter === 'custom'   ? 'selected' : ''; ?>>Custom Range</option>
                </select>
            </div>

            <div class="form-group">
                <label for="year"><i class="fas fa-calendar"></i> Years:</label>
                <select name="year[]" id="year" class="form-control" multiple="multiple">
                    <option value="all" <?php echo in_array('all', $yearFilter) ? 'selected' : ''; ?>>All Years</option>
                    <?php foreach ($years as $year): ?>
                        <option value="<?php echo $year; ?>" <?php echo in_array((string)$year, $yearFilter) ? 'selected' : ''; ?>>
                            <?php echo $year; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="month"><i class="fas fa-calendar-week"></i> Months:</label>
                <select name="month[]" id="month" class="form-control" multiple="multiple">
                    <option value="all" <?php echo in_array('all', $monthFilter) ? 'selected' : ''; ?>>All Months</option>
                    <?php foreach ($months as $key => $month): ?>
                        <option value="<?php echo $key; ?>" <?php echo in_array((string)$key, $monthFilter) ? 'selected' : ''; ?>>
                            <?php echo $month; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="project_id"><i class="fas fa-project-diagram"></i> Projects:</label>
                <select name="project_id[]" id="project_id" class="form-control" multiple="multiple">
                    <option value="all" <?php echo in_array('all', $projectFilter) ? 'selected' : ''; ?>>All Projects</option>
                    <?php foreach ($projectList as $project): ?>
                        <option value="<?php echo $project['id']; ?>" 
                            <?php echo in_array((string)$project['id'], $projectFilter, true) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($project['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="region_id"><i class="fas fa-globe-africa"></i> Regions:</label>
                <select name="region_id[]" id="region_id" class="form-control" multiple="multiple">
                    <option value="all" <?php echo in_array('all', $regionFilter) ? 'selected' : ''; ?>>All Regions</option>
                    <?php foreach ($regions as $region): ?>
                        <option value="<?php echo $region['id']; ?>" 
                            <?php echo in_array((string)$region['id'], $regionFilter, true) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($region['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="zone_id"><i class="fas fa-map"></i> Zones:</label>
                <select name="zone_id[]" id="zone_id" class="form-control" multiple="multiple">
                    <option value="all" <?php echo in_array('all', $zoneFilter) ? 'selected' : ''; ?>>All Zones</option>
                    <?php foreach ($zones as $zone): ?>
                        <option value="<?php echo $zone['id']; ?>" 
                            <?php echo in_array((string)$zone['id'], $zoneFilter, true) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($zone['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="woreda_id"><i class="fas fa-map-marker-alt"></i> Woredas:</label>
                <select name="woreda_id[]" id="woreda_id" class="form-control" multiple="multiple">
                    <option value="all" <?php echo in_array('all', $woredaFilter) ? 'selected' : ''; ?>>All Woredas</option>
                    <?php foreach ($woredas as $woreda): ?>
                        <option value="<?php echo $woreda['id']; ?>" 
                            <?php echo in_array((string)$woreda['id'], $woredaFilter, true) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($woreda['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- NEW: Focus Dimension filter -->
            <div class="form-group">
                <label for="quality_dimension"><i class="fas fa-filter"></i> Focus Dimension:</label>
                <select name="quality_dimension" id="quality_dimension" class="form-control">
                    <option value="all" <?php echo $qualityDimension === 'all' ? 'selected' : ''; ?>>All Dimensions</option>
                    <?php foreach ($qualityDimensions as $dimKey => $dimInfo): ?>
                        <option value="<?php echo $dimKey; ?>" <?php echo $qualityDimension === $dimKey ? 'selected' : ''; ?>>
                            <?php echo $dimInfo['name']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group date-range-group" id="customDateRange" style="display: none;">
                <div style="margin-bottom: 10px;">
                    <label for="start_date">From Date:</label>
                    <input type="date" name="start_date" id="start_date" value="<?php echo htmlspecialchars($startDate); ?>" class="form-control">
                </div>
                <div>
                    <label for="end_date">To Date:</label>
                    <input type="date" name="end_date" id="end_date" value="<?php echo htmlspecialchars($endDate); ?>" class="form-control">
                </div>
            </div>

            <div class="form-group">
                <button type="submit" class="export-btn" style="background: linear-gradient(135deg, var(--primary), var(--info)); width: 100%; justify-content: center;">
                    <i class="fas fa-sync-alt"></i> Apply Filters
                </button>
            </div>
        </form>
    </div>

    <!-- Overall Statistics -->
    <div class="quality-stats">
        <?php foreach ($qualityDimensions as $dimKey => $dimInfo): ?>
            <?php
            if ($qualityDimension !== 'all' && $qualityDimension !== $dimKey) {
                continue;
            }
            $score = $metrics['summary']['dimensions'][$dimKey]['average'] ?? 0;
            ?>
            <div class="stat-card quality-<?php echo getQualityClass($score); ?>">
                <div class="stat-icon"><?php echo $dimInfo['icon']; ?></div>
                <div class="stat-number"><?php echo $score; ?>%</div>
                <div class="stat-label"><?php echo $dimInfo['name']; ?></div>
                <div class="progress-bar">
                    <div class="progress-fill progress-<?php echo getQualityClass($score); ?>" style="width: <?php echo $score; ?>%"></div>
                </div>
                <small style="color: #64748b; margin-top: 10px; display: block;">
                    <?php echo $dimInfo['description']; ?>
                </small>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Charts (Radar & Regional Bar) -->
    <div class="charts-container">
        <div class="chart-card">
            <div class="chart-title">
                <i class="fas fa-bullseye"></i> 8-Dimensional Data Quality Radar
                <?php if ($qualityDimension !== 'all'): ?>
                    (Focus: <?php echo htmlspecialchars($qualityDimensions[$qualityDimension]['name'] ?? ''); ?>)
                <?php endif; ?>
            </div>
            <div class="chart-wrapper">
                <canvas id="dimensionRadarChart"></canvas>
            </div>
        </div>
        <div class="chart-card">
            <div class="chart-title">
                <i class="fas fa-globe-africa"></i> Regional Overall Data Quality
            </div>
            <div class="chart-wrapper">
                <canvas id="regionBarChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Data Sources Summary -->
    <div class="quality-table">
        <div class="table-header">
            <h3><i class="fas fa-database"></i> Data Sources Integration Summary</h3>
        </div>
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Data Source</th>
                        <th>Active Projects</th>
                        <th>Total Entries</th>
                        <th>Integration Status</th>
                        <th>Last Sync</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Check integration status for each module
                    $reportingIntegrated = isModuleIntegrated($pdo, 'reporting');
                    $enterDataIntegrated = isModuleIntegrated($pdo, 'enter_data');
                    $cfmIntegrated = isModuleIntegrated($pdo, 'cfm');
                    $budgetIntegrated = isModuleIntegrated($pdo, 'budget');
                    $aggregationIntegrated = isModuleIntegrated($pdo, 'aggregation');
                    $activityReportsIntegrated = isModuleIntegrated($pdo, 'activity_reports');
                    ?>
                    <tr>
                        <td><i class="fas fa-chart-bar"></i> <strong>Reporting System</strong></td>
                        <td><?php echo $metrics['data_sources']['reporting']; ?></td>
                        <td><?php echo array_sum(array_map(function($p) { return $p['dimensions']['reporting_info']['total_reports'] ?? 0; }, $metrics['projects'])); ?></td>
                        <td><span class="quality-badge badge-<?php echo $reportingIntegrated ? 'excellent' : 'poor'; ?>"><?php echo $reportingIntegrated ? 'Integrated' : 'Not Integrated'; ?></span></td>
                        <td><?php echo date('M j, Y H:i'); ?></td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-keyboard"></i> <strong>Enter Data App</strong></td>
                        <td><?php echo $metrics['data_sources']['enter_data']; ?></td>
                        <td><?php echo array_sum(array_map(function($p) { return $p['dimensions']['reporting_info']['enter_data_entries'] ?? 0; }, $metrics['projects'])); ?></td>
                        <td><span class="quality-badge badge-<?php echo $enterDataIntegrated ? 'good' : 'poor'; ?>"><?php echo $enterDataIntegrated ? 'Integrated' : 'Not Integrated'; ?></span></td>
                        <td><?php echo date('M j, Y H:i'); ?></td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-comments"></i> <strong>CFM System</strong></td>
                        <td><?php echo $metrics['data_sources']['cfm']; ?></td>
                        <td><?php echo array_sum(array_map(function($p) { return $p['dimensions']['reporting_info']['cfm_feedbacks'] ?? 0; }, $metrics['projects'])); ?></td>
                        <td><span class="quality-badge badge-<?php echo $cfmIntegrated ? 'good' : 'poor'; ?>"><?php echo $cfmIntegrated ? 'Integrated' : 'Not Integrated'; ?></span></td>
                        <td><?php echo date('M j, Y H:i'); ?></td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-wallet"></i> <strong>Budget Module</strong></td>
                        <td><?php echo $metrics['data_sources']['budget']; ?></td>
                        <td><?php echo array_sum(array_map(function($p) { return $p['dimensions']['reporting_info']['budget_records'] ?? 0; }, $metrics['projects'])); ?></td>
                        <td><span class="quality-badge badge-<?php echo $budgetIntegrated ? 'good' : 'poor'; ?>"><?php echo $budgetIntegrated ? 'Integrated' : 'Not Integrated'; ?></span></td>
                        <td><?php echo date('M j, Y H:i'); ?></td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-layer-group"></i> <strong>Aggregation Reports</strong></td>
                        <td><?php echo $metrics['data_sources']['aggregation']; ?></td>
                        <td><?php echo array_sum(array_map(function($p) { return $p['dimensions']['reporting_info']['aggregation_records'] ?? 0; }, $metrics['projects'])); ?></td>
                        <td><span class="quality-badge badge-<?php echo $aggregationIntegrated ? 'good' : 'poor'; ?>"><?php echo $aggregationIntegrated ? 'Integrated' : 'Not Integrated'; ?></span></td>
                        <td><?php echo date('M j, Y H:i'); ?></td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-tasks"></i> <strong>Project Activity Reports</strong></td>
                        <td><?php echo $metrics['data_sources']['activity_reports']; ?></td>
                        <td><?php echo array_sum(array_map(function($p) { return $p['dimensions']['reporting_info']['activity_reports'] ?? 0; }, $metrics['projects'])); ?></td>
                        <td><span class="quality-badge badge-<?php echo $activityReportsIntegrated ? 'excellent' : 'poor'; ?>"><?php echo $activityReportsIntegrated ? 'Integrated' : 'Not Integrated'; ?></span></td>
                        <td><?php 
                            $lastActivityReport = null;
                            foreach ($metrics['projects'] as $p) {
                                $arData = $p['dimensions']['reporting_info']['activity_report_data'] ?? [];
                                if (!empty($arData['last_report_date'])) {
                                    if (!$lastActivityReport || strtotime($arData['last_report_date']) > strtotime($lastActivityReport)) {
                                        $lastActivityReport = $arData['last_report_date'];
                                    }
                                }
                            }
                            echo $lastActivityReport ? date('M j, Y H:i', strtotime($lastActivityReport)) : date('M j, Y H:i');
                        ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Regional Performance -->
    <div class="quality-table">
        <div class="table-header">
            <h3><i class="fas fa-globe-africa"></i> Regional Performance Overview</h3>
        </div>
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Region</th>
                        <th>Projects</th>
                        <?php foreach ($qualityDimensions as $dimKey => $dimInfo): ?>
                            <th><?php echo $dimInfo['icon']; ?> <?php echo $dimInfo['name']; ?></th>
                        <?php endforeach; ?>
                        <th>Overall Score</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($metrics['by_region'] as $region => $data): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($region); ?></strong></td>
                        <td><?php echo $data['count']; ?></td>
                        <?php foreach ($qualityDimensions as $dimKey => $dimInfo): ?>
                        <td>
                            <span class="quality-badge badge-<?php echo getQualityClass($data[$dimKey]); ?>">
                                <?php echo $data[$dimKey]; ?>%
                            </span>
                        </td>
                        <?php endforeach; ?>
                        <td>
                            <span class="quality-badge badge-<?php echo getQualityClass($data['overall']); ?>">
                                <?php echo $data['overall']; ?>%
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Comprehensive Data Quality Table -->
    <div class="quality-table">
        <div class="table-header">
            <h3><i class="fas fa-table"></i> Comprehensive Data Quality Metrics & AI Recommendations</h3>
        </div>
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Project Details</th>
                        <th>Location</th>
                        <th>Data Sources</th>
                        <?php foreach ($qualityDimensions as $dimKey => $dimInfo): ?>
                            <th class="dimension-cell">
                                <?php echo $dimInfo['icon']; ?><br>
                                <?php echo $dimInfo['name']; ?>
                            </th>
                        <?php endforeach; ?>
                        <th>Overall</th>
                        <th>AI Feedback</th>
                        <th>Action Plan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($metrics['projects']) > 0): ?>
                        <?php foreach ($metrics['projects'] as $projectId => $project): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($project['title']); ?></strong><br>
                                <?php if (!empty($project['code'])): ?><small style="color: #64748b;"><?php echo htmlspecialchars($project['code']); ?></small><br><?php endif; ?>
                                <small style="color: #94a3b8;"><?php echo $project['frequency']; ?> reporting</small>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($project['region']); ?><br>
                                <small style="color: #64748b;">
                                    <?php echo htmlspecialchars($project['zone']); ?> • 
                                    <?php echo htmlspecialchars($project['woreda']); ?>
                                </small>
                            </td>
                            <td>
                                <?php 
                                $sources = $project['data_sources'] ?? [];
                                if (count($sources) > 0): 
                                    foreach ($sources as $source): 
                                        $icons = [
                                            'reporting'        => '📊',
                                            'enter_data'      => '⌨️',
                                            'cfm'             => '💬',
                                            'budget'          => '💰',
                                            'aggregation'     => '🧮',
                                            'activity_reports'=> '📋'
                                        ];
                                        echo '<span style="display: block; margin: 2px 0; font-size: 0.9em;">' . 
                                             ($icons[$source] ?? '🔗') . ' ' . ucfirst(str_replace('_', ' ', $source)) . 
                                             '</span>';
                                    endforeach;
                                else:
                                    echo '<span style="color: #ef4444;">No active sources</span>';
                                endif;
                                ?>
                            </td>
                            <?php foreach ($qualityDimensions as $dimKey => $dimInfo): 
                                $score = $project['dimensions'][$dimKey] ?? 0;
                            ?>
                            <td class="dimension-cell">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span style="font-weight: 700; min-width: 40px;"><?php echo $score; ?>%</span>
                                    <div class="progress-bar" style="flex: 1;">
                                        <div class="progress-fill progress-<?php echo getQualityClass($score); ?>" style="width: <?php echo $score; ?>%"></div>
                                    </div>
                                </div>
                                <span class="quality-badge badge-<?php echo getQualityClass($score); ?>" style="margin-top: 5px; display: block; text-align: center;">
                                    <?php echo getQualityLabel($score); ?>
                                </span>
                            </td>
                            <?php endforeach; ?>
                            <td>
                                <span class="quality-badge badge-<?php echo getQualityClass($project['overall_score']); ?>" style="font-size: 1.1em;">
                                    <?php echo round($project['overall_score'], 1); ?>%
                                </span>
                            </td>
                            <td class="feedback-cell">
                                <small><?php echo $project['feedback']; ?></small>
                            </td>
                            <td class="actions-cell">
                                <span style="color: #ef4444; font-weight: 600;"><?php echo $project['actions']; ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo count($qualityDimensions) + 6; ?>" style="text-align: center; padding: 40px;">
                                <h3>No data found for the selected filters</h3>
                                <p>Try adjusting your filter criteria to see data quality metrics</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Export & Print -->
    <div class="export-buttons">
        <button class="export-btn export-pdf"   onclick="exportToPDF()">
            <i class="fas fa-file-pdf"></i> Export PDF
        </button>
        <button class="export-btn export-excel" onclick="exportToExcel()">
            <i class="fas fa-file-excel"></i> Export Excel
        </button>
        <button class="export-btn export-word"  onclick="exportToWord()">
            <i class="fas fa-file-word"></i> Export Word
        </button>
        <button class="export-btn export-csv"   onclick="exportToCSV()">
            <i class="fas fa-file-csv"></i> Export CSV
        </button>
        <button class="export-btn export-image" onclick="exportToImage()">
            <i class="fas fa-image"></i> Export Image
        </button>
        <button class="export-btn export-image" onclick="window.print()">
            <i class="fas fa-print"></i> Print Report
        </button>
    </div>
</div>

<script>
    // PHP → JS data
    const dimensionLabels = <?php echo json_encode($dimensionLabels); ?>;
    const dimensionScores = <?php echo json_encode($dimensionScoresForChart); ?>;
    const regionLabels    = <?php echo json_encode($regionLabels); ?>;
    const regionScores    = <?php echo json_encode($regionScoresForChart); ?>;

    const zonesData   = <?php echo json_encode($zones); ?>;
    const woredasData = <?php echo json_encode($woredas); ?>;
    const initialZoneSelection   = <?php echo json_encode($zoneFilter); ?>;
    const initialWoredaSelection = <?php echo json_encode($woredaFilter); ?>;

    $(document).ready(function() {
        $('#project_id, #region_id, #zone_id, #woreda_id, #year, #month').select2({
            placeholder: "Select options...",
            allowClear: true,
            width: '100%'
        });

        // Show/hide custom date range
        $('#time_filter').on('change', function() {
            if (this.value === 'custom') {
                $('#customDateRange').show();
            } else {
                $('#customDateRange').hide();
            }
        }).trigger('change');

        // Cascading filters for Region → Zone → Woreda (using same tables as other apps)
        let zonesInitialized   = false;
        let woredasInitialized = false;

        function rebuildZones() {
            const selectedRegions = $('#region_id').val() || [];
            const allRegions      = selectedRegions.includes('all');

            const $zone = $('#zone_id');
            const currentSelection = $zone.val() || [];

            $zone.empty();
            $zone.append(new Option('All Zones', 'all', false, selectedRegions.includes('all')));

            zonesData.forEach(z => {
                if (allRegions || selectedRegions.includes(String(z.region_id))) {
                    const opt = new Option(z.name, z.id, false, false);
                    $zone.append(opt);
                }
            });

            if (!zonesInitialized) {
                $zone.val(initialZoneSelection).trigger('change');
                zonesInitialized = true;
            } else {
                const newSelection = currentSelection.filter(v => $('#zone_id option[value="' + v + '"]').length);
                $zone.val(newSelection).trigger('change');
            }
        }

        function rebuildWoredas() {
            const selectedZones = $('#zone_id').val() || [];
            const allZones      = selectedZones.includes('all');

            const $woreda = $('#woreda_id');
            const currentSelection = $woreda.val() || [];

            $woreda.empty();
            $woreda.append(new Option('All Woredas', 'all', false, selectedZones.includes('all')));

            woredasData.forEach(w => {
                if (allZones || selectedZones.includes(String(w.zone_id))) {
                    const opt = new Option(w.name, w.id, false, false);
                    $woreda.append(opt);
                }
            });

            if (!woredasInitialized) {
                $woreda.val(initialWoredaSelection).trigger('change');
                woredasInitialized = true;
            } else {
                const newSelection = currentSelection.filter(v => $('#woreda_id option[value="' + v + '"]').length);
                $woreda.val(newSelection).trigger('change');
            }
        }

        $('#region_id').on('change', function() {
            rebuildZones();
        });

        $('#zone_id').on('change', function() {
            rebuildWoredas();
        });

        // Initial cascade build
        rebuildZones();
        rebuildWoredas();
    });

    // Chart.js Radar chart
    (function() {
        const radarCanvas = document.getElementById('dimensionRadarChart');
        if (!radarCanvas || !dimensionLabels.length) return;

        const radarCtx = radarCanvas.getContext('2d');
        new Chart(radarCtx, {
            type: 'radar',
            data: {
                labels: dimensionLabels,
                datasets: [{
                    label: 'Average score (%)',
                    data: dimensionScores,
                    fill: true,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    tension: 0.3
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                    tooltip: { enabled: true }
                },
                scales: {
                    r: {
                        beginAtZero: true,
                        max: 100,
                        ticks: { stepSize: 20 }
                    }
                }
            }
        });
    })();

    // Chart.js Bar chart
    (function() {
        const barCanvas = document.getElementById('regionBarChart');
        if (!barCanvas || !regionLabels.length) return;

        const barCtx = barCanvas.getContext('2d');
        new Chart(barCtx, {
            type: 'bar',
            data: {
                labels: regionLabels,
                datasets: [{
                    label: 'Overall score (%)',
                    data: regionScores,
                    borderRadius: 10
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                    tooltip: { enabled: true }
                },
                scales: {
                    x: {
                        ticks: {
                            maxRotation: 60,
                            minRotation: 0
                        }
                    },
                    y: {
                        beginAtZero: true,
                        max: 100
                    }
                }
            }
        });
    })();

    // Export: PDF
    function exportToPDF() {
        const { jsPDF } = window.jspdf;
        const doc       = new jsPDF('p', 'mm', 'a4');
        const pageWidth = doc.internal.pageSize.getWidth();
        const pageHeight= doc.internal.pageSize.getHeight();
        
        doc.setFontSize(20);
        doc.setTextColor(40, 40, 40);
        doc.text('Data Quality Excellence Report', pageWidth / 2, 20, { align: 'center' });
        
        doc.setFontSize(12);
        doc.setTextColor(100, 100, 100);
        const timeFilterSelect = document.getElementById('time_filter');
        const timeFilterText   = timeFilterSelect.options[timeFilterSelect.selectedIndex].text;
        doc.text(`Generated on: ${new Date().toLocaleDateString()}`, 20, 35);
        doc.text(`Time Period: ${timeFilterText}`, 20, 42);
        
        doc.setFontSize(16);
        doc.setTextColor(40, 40, 40);
        doc.text('Quality Dimension Summary', 20, 60);
        
        doc.setFontSize(10);
        let yPosition = 70;
        <?php foreach ($qualityDimensions as $dimKey => $dimInfo): ?>
        doc.text(`<?php echo $dimInfo['icon']; ?> <?php echo $dimInfo['name']; ?>: <?php echo $metrics['summary']['dimensions'][$dimKey]['average']; ?>%`, 20, yPosition);
        yPosition += 6;
        <?php endforeach; ?>
        
        yPosition += 10;
        doc.setFontSize(12);
        doc.text('Project Quality Metrics', 20, yPosition);
        
        yPosition += 10;
        doc.setFontSize(8);
        doc.text('Project', 20, yPosition);
        doc.text('Overall %', 120, yPosition);
        doc.text('Status', 150, yPosition);
        
        yPosition += 5;
        doc.line(20, yPosition, 180, yPosition);
        yPosition += 5;
        
        <?php foreach ($metrics['projects'] as $project): ?>
        if (yPosition > pageHeight - 20) {
            doc.addPage();
            yPosition = 20;
        }
        doc.text('<?php echo addslashes(substr($project['title'], 0, 30)); ?>', 20, yPosition);
        doc.text('<?php echo round($project['overall_score'], 1); ?>%', 120, yPosition);
        doc.text('<?php echo getQualityLabel($project['overall_score']); ?>', 150, yPosition);
        yPosition += 6;
        <?php endforeach; ?>
        
        doc.save('data-quality-report.pdf');
    }

    // Export: Excel
    function exportToExcel() {
        const wb = XLSX.utils.book_new();
        const projectData = [];
        
        const headers = ['Project', 'Code', 'Region', 'Zone', 'Woreda', 'Data Sources', 'Overall Score'];
        <?php foreach ($qualityDimensions as $dimKey => $dimInfo): ?>
        headers.push('<?php echo $dimInfo['name']; ?>');
        <?php endforeach; ?>
        headers.push('Last Report', 'Status', 'Actions Needed');
        projectData.push(headers);

        <?php foreach ($metrics['projects'] as $project): ?>
        (function() {
            const row = [
                '<?php echo addslashes($project['title']); ?>',
                '<?php echo addslashes($project['code'] ?? ''); ?>',
                '<?php echo addslashes($project['region']); ?>',
                '<?php echo addslashes($project['zone']); ?>',
                '<?php echo addslashes($project['woreda']); ?>',
                '<?php echo implode(", ", $project['data_sources'] ?? []); ?>',
                <?php echo $project['overall_score']; ?>
            ];
            <?php foreach ($qualityDimensions as $dimKey => $dimInfo): ?>
            row.push(<?php echo $project['dimensions'][$dimKey] ?? 0; ?>);
            <?php endforeach; ?>
            row.push('<?php echo $project['last_report'] ? date('Y-m-d', strtotime($project['last_report'])) : 'Never'; ?>');
            row.push('<?php echo getQualityLabel($project['overall_score']); ?>');
            row.push('<?php echo addslashes($project['actions']); ?>');
            projectData.push(row);
        })();
        <?php endforeach; ?>
        
        const ws = XLSX.utils.aoa_to_sheet(projectData);
        XLSX.utils.book_append_sheet(wb, ws, 'Project Quality Data');
        
        const summaryData = [
            ['Dimension', 'Average Score', 'Status'],
            <?php foreach ($qualityDimensions as $dimKey => $dimInfo): ?>
            ['<?php echo $dimInfo['name']; ?>', <?php echo $metrics['summary']['dimensions'][$dimKey]['average']; ?>, '<?php echo getQualityLabel($metrics['summary']['dimensions'][$dimKey]['average']); ?>'],
            <?php endforeach; ?>
        ];
        const ws2 = XLSX.utils.aoa_to_sheet(summaryData);
        XLSX.utils.book_append_sheet(wb, ws2, 'Quality Summary');
        
        const sourceData = [
            ['Data Source', 'Active Projects', 'Integration Status'],
            ['Reporting System',  <?php echo $metrics['data_sources']['reporting']; ?>,   '<?php echo isModuleIntegrated($pdo, 'reporting') ? 'Integrated' : 'Not Integrated'; ?>'],
            ['Enter Data App',    <?php echo $metrics['data_sources']['enter_data']; ?>,  '<?php echo isModuleIntegrated($pdo, 'enter_data') ? 'Integrated' : 'Not Integrated'; ?>'],
            ['CFM System',        <?php echo $metrics['data_sources']['cfm']; ?>,         '<?php echo isModuleIntegrated($pdo, 'cfm') ? 'Integrated' : 'Not Integrated'; ?>'],
            ['Budget Module',     <?php echo $metrics['data_sources']['budget']; ?>,      '<?php echo isModuleIntegrated($pdo, 'budget') ? 'Integrated' : 'Not Integrated'; ?>'],
            ['Aggregation Reports',<?php echo $metrics['data_sources']['aggregation']; ?>,'<?php echo isModuleIntegrated($pdo, 'aggregation') ? 'Integrated' : 'Not Integrated'; ?>'],
            ['Activity Reports',   <?php echo $metrics['data_sources']['activity_reports']; ?>,'<?php echo isModuleIntegrated($pdo, 'activity_reports') ? 'Integrated' : 'Not Integrated'; ?>']
        ];
        const ws3 = XLSX.utils.aoa_to_sheet(sourceData);
        XLSX.utils.book_append_sheet(wb, ws3, 'Data Sources');
        
        XLSX.writeFile(wb, 'data-quality-report.xlsx');
    }

    // Export: Word
    function exportToWord() {
        const timeFilterSelect = document.getElementById('time_filter');
        const timeFilterText   = timeFilterSelect.options[timeFilterSelect.selectedIndex].text;

        const content = `
        <html xmlns:o='urn:schemas-microsoft-com:office:office' 
              xmlns:w='urn:schemas-microsoft-com:office:word' 
              xmlns='http://www.w3.org/TR/REC-html40'>
        <head>
            <meta charset="utf-8">
            <title>Data Quality Report</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                h1 { color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 10px; }
                h2 { color: #34495e; margin-top: 20px; }
                table { border-collapse: collapse; width: 100%; margin: 20px 0; }
                th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
                th { background-color: #f8f9fa; font-weight: bold; }
                .excellent { background-color: #d4edda; color: #155724; }
                .good      { background-color: #d1ecf1; color: #0c5460; }
                .fair      { background-color: #fff3cd; color: #856404; }
                .poor      { background-color: #f8d7da; color: #721c24; }
            </style>
        </head>
        <body>
            <h1>Data Quality Excellence Report</h1>
            <p><strong>Generated on:</strong> ${new Date().toLocaleDateString()}</p>
            <p><strong>Time Period:</strong> ${timeFilterText}</p>
            
            <h2>Data Sources Integration</h2>
            <table>
                <tr>
                    <th>Data Source</th>
                    <th>Active Projects</th>
                    <th>Status</th>
                </tr>
                <tr>
                    <td>Reporting System</td>
                    <td><?php echo $metrics['data_sources']['reporting']; ?></td>
                    <td class="<?php echo isModuleIntegrated($pdo, 'reporting') ? 'excellent' : 'poor'; ?>"><?php echo isModuleIntegrated($pdo, 'reporting') ? 'Integrated' : 'Not Integrated'; ?></td>
                </tr>
                <tr>
                    <td>Enter Data App</td>
                    <td><?php echo $metrics['data_sources']['enter_data']; ?></td>
                    <td class="<?php echo isModuleIntegrated($pdo, 'enter_data') ? 'good' : 'poor'; ?>"><?php echo isModuleIntegrated($pdo, 'enter_data') ? 'Integrated' : 'Not Integrated'; ?></td>
                </tr>
                <tr>
                    <td>CFM System</td>
                    <td><?php echo $metrics['data_sources']['cfm']; ?></td>
                    <td class="<?php echo isModuleIntegrated($pdo, 'cfm') ? 'good' : 'poor'; ?>"><?php echo isModuleIntegrated($pdo, 'cfm') ? 'Integrated' : 'Not Integrated'; ?></td>
                </tr>
                <tr>
                    <td>Budget Module</td>
                    <td><?php echo $metrics['data_sources']['budget']; ?></td>
                    <td class="<?php echo isModuleIntegrated($pdo, 'budget') ? 'good' : 'poor'; ?>"><?php echo isModuleIntegrated($pdo, 'budget') ? 'Integrated' : 'Not Integrated'; ?></td>
                </tr>
                <tr>
                    <td>Aggregation Reports</td>
                    <td><?php echo $metrics['data_sources']['aggregation']; ?></td>
                    <td class="<?php echo isModuleIntegrated($pdo, 'aggregation') ? 'good' : 'poor'; ?>"><?php echo isModuleIntegrated($pdo, 'aggregation') ? 'Integrated' : 'Not Integrated'; ?></td>
                </tr>
                <tr>
                    <td>Activity Reports</td>
                    <td><?php echo $metrics['data_sources']['activity_reports']; ?></td>
                    <td class="<?php echo isModuleIntegrated($pdo, 'activity_reports') ? 'excellent' : 'poor'; ?>"><?php echo isModuleIntegrated($pdo, 'activity_reports') ? 'Integrated' : 'Not Integrated'; ?></td>
                </tr>
            </table>
            
            <h2>Quality Dimension Summary</h2>
            <table>
                <tr>
                    <th>Dimension</th>
                    <th>Average Score</th>
                    <th>Status</th>
                </tr>
                <?php foreach ($qualityDimensions as $dimKey => $dimInfo): ?>
                <tr>
                    <td><?php echo $dimInfo['name']; ?></td>
                    <td><?php echo $metrics['summary']['dimensions'][$dimKey]['average']; ?>%</td>
                    <td class="<?php echo getQualityClass($metrics['summary']['dimensions'][$dimKey]['average']); ?>"><?php echo getQualityLabel($metrics['summary']['dimensions'][$dimKey]['average']); ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            
            <h2>Project Quality Metrics</h2>
            <table>
                <tr>
                    <th>Project</th>
                    <th>Region</th>
                    <th>Data Sources</th>
                    <th>Overall Score</th>
                    <th>Status</th>
                </tr>
                <?php foreach ($metrics['projects'] as $project): ?>
                <tr>
                    <td><?php echo htmlspecialchars($project['title']); ?></td>
                    <td><?php echo htmlspecialchars($project['region']); ?></td>
                    <td><?php echo implode(", ", $project['data_sources'] ?? []); ?></td>
                    <td><?php echo round($project['overall_score'], 1); ?>%</td>
                    <td class="<?php echo getQualityClass($project['overall_score']); ?>"><?php echo getQualityLabel($project['overall_score']); ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </body>
        </html>
        `;
        
        const blob = new Blob([content], { type: 'application/msword' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'data-quality-report.doc';
        link.click();
        URL.revokeObjectURL(link.href);
    }

    // Export: CSV
    function exportToCSV() {
        let csv = '';
        
        const headers = ['Project', 'Code', 'Region', 'Zone', 'Woreda', 'Data Sources', 'Overall Score'];
        <?php foreach ($qualityDimensions as $dimKey => $dimInfo): ?>
        headers.push('<?php echo $dimInfo['name']; ?>');
        <?php endforeach; ?>
        headers.push('Last Report', 'Status', 'Actions Needed');
        csv += headers.map(h => `"${h}"`).join(',') + '\n';
        
        <?php foreach ($metrics['projects'] as $project): ?>
        (function() {
            const row = [
                '<?php echo addslashes($project['title']); ?>',
                '<?php echo addslashes($project['code'] ?? ''); ?>',
                '<?php echo addslashes($project['region']); ?>',
                '<?php echo addslashes($project['zone']); ?>',
                '<?php echo addslashes($project['woreda']); ?>',
                '<?php echo implode(", ", $project['data_sources'] ?? []); ?>',
                <?php echo $project['overall_score']; ?>
            ];
            <?php foreach ($qualityDimensions as $dimKey => $dimInfo): ?>
            row.push(<?php echo $project['dimensions'][$dimKey] ?? 0; ?>);
            <?php endforeach; ?>
            row.push('<?php echo $project['last_report'] ? date('Y-m-d', strtotime($project['last_report'])) : 'Never'; ?>');
            row.push('<?php echo getQualityLabel($project['overall_score']); ?>');
            row.push('<?php echo addslashes($project['actions']); ?>');
            csv += row.map(c => `"${c}"`).join(',') + '\n';
        })();
        <?php endforeach; ?>
        
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'data-quality-report.csv';
        link.click();
        URL.revokeObjectURL(link.href);
    }

    // Export: PNG image
    function exportToImage() {
        html2canvas(document.querySelector('.data-quality-container'), {
            scale: 2,
            useCORS: true,
            logging: false,
            windowWidth:  document.querySelector('.data-quality-container').scrollWidth,
            windowHeight: document.querySelector('.data-quality-container').scrollHeight
        }).then(canvas => {
            const link = document.createElement('a');
            link.download = 'data-quality-dashboard.png';
            link.href = canvas.toDataURL('image/png');
            link.click();
        });
    }

    // Auto-refresh every 2 minutes for real-time updates
    setTimeout(() => {
        window.location.href = '?<?php echo http_build_query(array_merge($_GET, ['real_time_update' => 'true'])); ?>';
    }, 120000);
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>
