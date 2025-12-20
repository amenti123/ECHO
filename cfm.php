<?php
// cfm.php - AAP-Compliant Feedback Mechanism Reporting System
require_once __DIR__ . '/../header.php';
require_permission('cfm', 'view');

$pdo  = getPDO();
$user = current_user();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// -----------------------------------------------------------------------------
// CFM UPGRADE: Safe AJAX + Server-side Export Endpoints (no HTML output)
// -----------------------------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_report') {
    // Ensure no buffered HTML corrupts JSON
    if (function_exists('hrs_prepare_json_response')) { hrs_prepare_json_response(); }
    if (!function_exists('hrs_prepare_csv_download')) {
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            $fname = 'cfm_reports_' . date('Y-m-d') . '.csv';
            header('Content-Disposition: attachment; filename="' . $fname . '"');
        }
        // Excel-friendly UTF-8 BOM
        echo "\xEF\xBB\xBF";
    }

    $project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
    $region_id  = isset($_GET['region_id'])  ? (int)$_GET['region_id']  : 0;
    $status     = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
    $from_date  = isset($_GET['from']) ? trim((string)$_GET['from']) : '';
    $to_date    = isset($_GET['to'])   ? trim((string)$_GET['to'])   : '';

    $where = [];
    $args  = [];

    if ($project_id > 0) { $where[] = "cr.project_id = ?"; $args[] = $project_id; }
    if ($region_id  > 0) { $where[] = "cr.region_id = ?";  $args[] = $region_id; }
    if ($status !== '')  { $where[] = "cr.feedback_status = ?"; $args[] = $status; }
    if ($from_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date)) { $where[] = "cr.date_feedback_received >= ?"; $args[] = $from_date; }
    if ($to_date   !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date))   { $where[] = "cr.date_feedback_received <= ?"; $args[] = $to_date; }

    $sql = "
        SELECT 
            cr.id,
            cr.project_id,
            p.title AS project_title,
            cr.reported_by,
            cr.position,
            cr.date_feedback_received,
            cr.date_of_report,
            cr.feedback_type,
            cr.organization,
            cr.region_id,
            r.name AS region_name,
            cr.zone_name,
            cr.woreda_name,
            cr.gender,
            cr.age,
            cr.community_type,
            cr.vulnerability,
            cr.language,
            cr.actual_feedback,
            cr.feedback_channel,
            cr.feedback_category,
            cr.feedback_concern,
            cr.feedback_status,
            cr.actions_taken,
            cr.responsibility_follow_up,
            cr.expected_closure_date,
            cr.reason_closure_passed,
            cr.recommendation,
            cr.ai_recommendation,
            cr.created_at,
            cr.updated_at
        FROM cfm_reports cr
        LEFT JOIN projects p ON cr.project_id = p.id
        LEFT JOIN regions  r ON cr.region_id  = r.id
    ";
    if (!empty($where)) { $sql .= " WHERE " . implode(" AND ", $where); }
    $sql .= " ORDER BY cr.id DESC";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);

        // CSV header
        $headers = [
            'id','project_id','project_title','reported_by','position','date_feedback_received','date_of_report',
            'feedback_type','organization','region_id','region_name','zone_name','woreda_name',
            'gender','age','community_type','vulnerability','language',
            'actual_feedback','feedback_channel','feedback_category','feedback_concern','feedback_status',
            'actions_taken','responsibility_follow_up','expected_closure_date','reason_closure_passed',
            'recommendation','ai_recommendation','created_at','updated_at'
        ];
        echo implode(',', array_map(function($h){ return '"' . str_replace('"','""',$h) . '"'; }, $headers)) . "\n";

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $line = [];
            foreach ($headers as $h) {
                $val = isset($row[$h]) ? (string)$row[$h] : '';
                $val = preg_replace("/\r\n|\r|\n/", " ", $val);
                $line[] = '"' . str_replace('"','""', $val) . '"';
            }
            echo implode(',', $line) . "\n";
        }
        exit;
    } catch (Throwable $e) {
        // Minimal CSV error row
        echo "\"error\"\n\"Failed to export\"\n";
        exit;
    }
}

if (isset($_GET['print']) && $_GET['print'] === '1' && isset($_GET['view_id'])) {
    $id = (int)$_GET['view_id'];
    if ($id > 0) {
        try {
            $stmt = $pdo->prepare("
                SELECT cr.*, p.title AS project_title, r.name AS region_name
                FROM cfm_reports cr
                LEFT JOIN projects p ON cr.project_id = p.id
                LEFT JOIN regions r  ON cr.region_id  = r.id
                WHERE cr.id = ?
                LIMIT 1
            ");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                ?>
                <!doctype html>
                <html>
                <head>
                    <meta charset="utf-8">
                    <title>CFM Report #<?php echo (int)$row['id']; ?></title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 24px; }
                        h1 { margin: 0 0 8px; }
                        .meta { color:#555; margin-bottom: 18px; }
                        table { width:100%; border-collapse: collapse; }
                        td, th { border:1px solid #ddd; padding:8px; vertical-align: top; }
                        th { background:#f3f4f6; text-align:left; width: 260px; }
                    </style>
                </head>
                <body onload="window.print()">
                    <h1>CFM Report Details</h1>
                    <div class="meta">
                        <strong>Project:</strong> <?php echo htmlspecialchars($row['project_title'] ?? 'N/A'); ?> |
                        <strong>Region:</strong> <?php echo htmlspecialchars($row['region_name'] ?? 'N/A'); ?> |
                        <strong>Date Received:</strong> <?php echo htmlspecialchars($row['date_feedback_received'] ?? ''); ?>
                    </div>
                    <table>
                        <?php foreach ($row as $k => $v): ?>
                            <tr>
                                <th><?php echo htmlspecialchars($k); ?></th>
                                <td><?php echo nl2br(htmlspecialchars((string)$v)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </body>
                </html>
                <?php
                exit;
            }
        } catch (Throwable $e) {
            // fall through
        }
    }
}


/**
 * REGION & PROJECT HELPERS
 * - Reuse global helpers from helpers.php where possible so the CFM app
 *   auto-receives the same project list as other apps (projects.php, planning.php, etc.).
 */

/** Regions: use global get_regions() if already defined elsewhere */
if (!function_exists('get_regions')) {
    function get_regions() {
        global $pdo;
        try {
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'regions'")->fetch();
            if (!$tableCheck) {
                create_regions_table();
                return [];
            }

            $stmt = $pdo->prepare("SELECT id, name FROM regions ORDER BY name");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching regions: " . $e->getMessage());
            return [];
        }
    }
}

/** Create regions table (only if it does not exist) */
function create_regions_table() {
    global $pdo;
    try {
        $sql = "
            CREATE TABLE IF NOT EXISTS regions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL UNIQUE,
                code VARCHAR(50),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ";
        $pdo->exec($sql);

        $default_regions = [
            'Addis Ababa', 'Afar', 'Amhara', 'Benishangul-Gumuz',
            'Dire Dawa', 'Gambela', 'Harari', 'Oromia',
            'Sidama', 'Somali', "Southern Nations, Nationalities, and Peoples' Region",
            'Tigray'
        ];

        $stmt = $pdo->prepare("INSERT IGNORE INTO regions (name) VALUES (?)");
        foreach ($default_regions as $region) {
            $stmt->execute([$region]);
        }
        return true;
    } catch (PDOException $e) {
        error_log("Error creating regions table: " . $e->getMessage());
        return false;
    }
}

/**
 * Project helper for CFM – do NOT create a separate projects table.
 * - If global get_projects() exists (from helpers.php), we use that.
 * - Otherwise, fall back to a simple query on the existing projects table.
 */
if (!function_exists('cfm_get_projects_fallback')) {
    function cfm_get_projects_fallback(PDO $pdo) {
        try {
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'projects'")->fetch();
            if (!$tableCheck) {
                // No projects table – just return empty; projects.php should create it.
                return [];
            }

            // Fetch all project metadata; other apps may use region/zone/woreda fields.
            $stmt = $pdo->query("
                SELECT *
                FROM projects
                ORDER BY 
                    CASE WHEN start_date IS NULL THEN 1 ELSE 0 END,
                    start_date,
                    id DESC
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching projects (CFM fallback): " . $e->getMessage());
            return [];
        }
    }
}

/**
 * CFM TABLE
 */
function create_cfm_table() {
    global $pdo;
    try {
        $sql = "
            CREATE TABLE IF NOT EXISTS cfm_reports (
                id INT AUTO_INCREMENT PRIMARY KEY,
                project_id INT,
                reported_by VARCHAR(255) NOT NULL,
                position VARCHAR(255),
                date_feedback_received DATE NOT NULL,
                date_of_report DATE NOT NULL,
                feedback_type ENUM('new', 'pending') DEFAULT 'new',
                organization VARCHAR(255),
                region_id INT,
                zone_name VARCHAR(255),
                woreda_name VARCHAR(255),
                gender ENUM('Male', 'Female', 'Other'),
                age INT,
                community_type VARCHAR(100),
                vulnerability VARCHAR(100),
                language VARCHAR(100),
                actual_feedback TEXT NOT NULL,
                feedback_channel VARCHAR(100),
                feedback_category VARCHAR(100),
                feedback_concern TEXT,
                feedback_status ENUM('New', 'Under Review', 'Action Taken', 'Resolved', 'Closed') DEFAULT 'New',
                actions_taken TEXT,
                responsibility_follow_up VARCHAR(255),
                expected_closure_date DATE,
                reason_closure_passed TEXT,
                recommendation TEXT,
                ai_recommendation TEXT,
                created_by INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (project_id) REFERENCES projects(id),
                FOREIGN KEY (region_id) REFERENCES regions(id),
                FOREIGN KEY (created_by) REFERENCES users(id)
            )
        ";
        $pdo->exec($sql);

        // Safety: ensure columns exist if table was created in old versions
        $columns = $pdo->query("SHOW COLUMNS FROM cfm_reports")->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array('ai_recommendation', $columns)) {
            $pdo->exec("ALTER TABLE cfm_reports ADD COLUMN ai_recommendation TEXT AFTER recommendation");
        }
        if (!in_array('zone_name', $columns)) {
            $pdo->exec("ALTER TABLE cfm_reports ADD COLUMN zone_name VARCHAR(255) AFTER region_id");
        }
        if (!in_array('woreda_name', $columns)) {
            $pdo->exec("ALTER TABLE cfm_reports ADD COLUMN woreda_name VARCHAR(255) AFTER zone_name");
        }

        return true;
    } catch (PDOException $e) {
        error_log("Error creating CFM table: " . $e->getMessage());
        return false;
    }
}

/**
 * GET CFM REPORTS
 */
function get_cfm_reports_with_details() {
    global $pdo;

    try {
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'cfm_reports'")->fetch();
        if (!$tableCheck) {
            create_cfm_table();
            return [];
        }

        $sql = "
            SELECT 
                cr.*,
                p.title as project_title,
                r.name as region_name,
                u.full_name as creator_name
            FROM cfm_reports cr
            LEFT JOIN projects p ON cr.project_id = p.id
            LEFT JOIN regions r ON cr.region_id = r.id
            LEFT JOIN users u ON cr.created_by = u.id
            ORDER BY cr.date_feedback_received DESC, cr.id DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching CFM reports: " . $e->getMessage());
        return [];
    }
}

/**
 * DATA QUALITY HELPERS
 */
function sendToDataQuality($report_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT cr.*, p.title as project_title, r.name as region_name 
            FROM cfm_reports cr 
            LEFT JOIN projects p ON cr.project_id = p.id 
            LEFT JOIN regions r ON cr.region_id = r.id 
            WHERE cr.id = ?
        ");
        $stmt->execute([$report_id]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($report) {
            $quality_score = calculateDataQualityScore($report);

            $quality_data = [
                'report_id'            => $report_id,
                'report_type'          => 'cfm',
                'data_quality_score'   => $quality_score,
                'completeness_score'   => calculateCompletenessScore($report),
                'timeliness_score'     => calculateTimelinessScore($report),
                'accuracy_score'       => calculateAccuracyScore($report),
                'feedback_content'     => $report['actual_feedback'],
                'ai_recommendation'    => $report['ai_recommendation'],
                'submitted_at'         => date('Y-m-d H:i:s')
            ];

            $_SESSION['data_quality_report'] = $quality_data;
            error_log("Data quality report prepared for CFM ID: " . $report_id);
            return $quality_score;
        }
    } catch (Exception $e) {
        error_log("Error sending to data quality: " . $e->getMessage());
    }
    return 0;
}

function calculateDataQualityScore($report) {
    $completeness = calculateCompletenessScore($report);
    $timeliness   = calculateTimelinessScore($report);
    $accuracy     = calculateAccuracyScore($report);

    return ($completeness + $timeliness + $accuracy) / 3;
}

function calculateCompletenessScore($report) {
    $required_fields = [
        'project_id', 'reported_by', 'date_feedback_received', 'date_of_report',
        'organization', 'region_id', 'gender', 'language', 'actual_feedback',
        'feedback_channel', 'feedback_category', 'feedback_concern', 'feedback_status'
    ];

    $filled = 0;
    foreach ($required_fields as $field) {
        if (!empty($report[$field])) {
            $filled++;
        }
    }

    return ($filled / count($required_fields)) * 100;
}

function calculateTimelinessScore($report) {
    if (empty($report['date_feedback_received']) || empty($report['date_of_report'])) {
        return 0;
    }

    try {
        $received_date = new DateTime($report['date_feedback_received']);
        $report_date   = new DateTime($report['date_of_report']);
        $interval      = $received_date->diff($report_date);
        $days_diff     = $interval->days;

        if ($days_diff <= 1)  return 100;
        if ($days_diff <= 3)  return 80;
        if ($days_diff <= 7)  return 60;
        if ($days_diff <= 14) return 40;
        return 20;
    } catch (Exception $e) {
        error_log("Error calculating timeliness: " . $e->getMessage());
        return 0;
    }
}

function calculateAccuracyScore($report) {
    $score = 100;

    if (strlen($report['actual_feedback'] ?? '') < 10) $score -= 20;
    if (empty($report['feedback_concern'])) $score -= 15;
    if (empty($report['actions_taken']) && ($report['feedback_status'] ?? '') == 'Action Taken') $score -= 25;

    return max(0, $score);
}

function calculateDaysOpen($received_date, $reference_date = null) {
    if (!$received_date) return 0;
    if (!$reference_date) {
        $reference_date = date('Y-m-d');
    }
    try {
        $received  = new DateTime($received_date);
        $reference = new DateTime($reference_date);
        $interval  = $received->diff($reference);
        return $interval->days;
    } catch (Exception $e) {
        error_log("Date calculation error: " . $e->getMessage());
        return 0;
    }
}

function calculateDaysToClose($received_date, $closure_date) {
    if (!$received_date || !$closure_date) return null;

    try {
        $received = new DateTime($received_date);
        $closed   = new DateTime($closure_date);
        $interval = $received->diff($closed);
        return $interval->days;
    } catch (Exception $e) {
        error_log("Date to close calculation error: " . $e->getMessage());
        return null;
    }
}

function formatDaysDuration($days) {
    if ($days < 29) {
        return $days . " days";
    } elseif ($days == 30) {
        return "1 month";
    } elseif ($days > 30) {
        $months         = floor($days / 30);
        $remaining_days = $days % 30;
        if ($remaining_days > 0) {
            return $months . " month" . ($months > 1 ? "s" : "") . " " . $remaining_days . " days";
        }
        return $months . " month" . ($months > 1 ? "s" : "");
    }
    return $days . " days";
}

/**
 * STATISTICS (EXTENDED)
 */
function getCFMStatistics($reports) {
    $stats = [
        'total'              => count($reports),
        'by_status'          => [],
        'by_region'          => [],
        'by_category'        => [],
        'by_channel'         => [],
        'by_gender'          => [],
        'by_project'         => [],
        'by_community_type'  => [],
        'by_vulnerability'   => [],
        'by_age_group'       => [],
        'avg_resolution_time'=> 0,
        'pending_over_30_days' => 0
    ];

    $total_days       = 0;
    $count_with_dates = 0;

    foreach ($reports as $report) {
        // Status
        $status = $report['feedback_status'] ?? 'Unknown';
        if (!isset($stats['by_status'][$status])) {
            $stats['by_status'][$status] = 0;
        }
        $stats['by_status'][$status]++;

        // Region
        $region = $report['region_name'] ?? 'Unknown';
        if (!isset($stats['by_region'][$region])) {
            $stats['by_region'][$region] = 0;
        }
        $stats['by_region'][$region]++;

        // Category
        $category = $report['feedback_category'] ?? 'Unknown';
        if (!isset($stats['by_category'][$category])) {
            $stats['by_category'][$category] = 0;
        }
        $stats['by_category'][$category]++;

        // Channel
        $channel = $report['feedback_channel'] ?? 'Unknown';
        if (!isset($stats['by_channel'][$channel])) {
            $stats['by_channel'][$channel] = 0;
        }
        $stats['by_channel'][$channel]++;

        // Gender
        $gender = $report['gender'] ?? 'Unknown';
        if (!isset($stats['by_gender'][$gender])) {
            $stats['by_gender'][$gender] = 0;
        }
        $stats['by_gender'][$gender]++;

        // Project
        $project = $report['project_title'] ?? 'Unknown Project';
        if (!isset($stats['by_project'][$project])) {
            $stats['by_project'][$project] = 0;
        }
        $stats['by_project'][$project]++;

        // Community Type
        $community = $report['community_type'] ?? 'Unknown';
        if (!isset($stats['by_community_type'][$community])) {
            $stats['by_community_type'][$community] = 0;
        }
        $stats['by_community_type'][$community]++;

        // Vulnerability
        $vul = $report['vulnerability'] ?? 'Unknown';
        if (!isset($stats['by_vulnerability'][$vul])) {
            $stats['by_vulnerability'][$vul] = 0;
        }
        $stats['by_vulnerability'][$vul]++;

        // Age group (10-year intervals)
        if (!empty($report['age']) && is_numeric($report['age'])) {
            $age  = (int)$report['age'];
            if ($age < 0) $age = 0;
            $bucketIndex = (int)floor($age / 10);
            if ($bucketIndex >= 10) {
                $label = '100+';
            } else {
                $lower = $bucketIndex * 10;
                $upper = $lower + 9;
                $label = "{$lower}-{$upper}";
            }
            if (!isset($stats['by_age_group'][$label])) {
                $stats['by_age_group'][$label] = 0;
            }
            $stats['by_age_group'][$label]++;
        }

        // Days open & pending >30 days
        if (!empty($report['date_feedback_received'])) {
            $days_open = calculateDaysOpen($report['date_feedback_received']);
            $total_days += $days_open;
            $count_with_dates++;

            if ($days_open > 30 && in_array($status, ['New', 'Under Review', 'Action Taken'])) {
                $stats['pending_over_30_days']++;
            }
        }
    }

    if ($count_with_dates > 0) {
        $stats['avg_resolution_time'] = round($total_days / $count_with_dates, 1);
    }

    if (!empty($stats['by_age_group'])) {
        ksort($stats['by_age_group']);
    }

    return $stats;
}

/**
 * AI RECOMMENDATION
 */
function generateAIRecommendation($data) {
    $feedback     = strtolower($data['actual_feedback'] ?? '');
    $category     = $data['feedback_category'] ?? '';
    $status       = $data['feedback_status'] ?? '';
    $channel      = $data['feedback_channel'] ?? '';
    $vulnerability= $data['vulnerability'] ?? '';

    $recommendations = [];
    $urgency_level   = "Medium";

    $sentiment = analyzeFeedbackSentiment($feedback);

    if ($sentiment === 'negative' || strpos($feedback, 'urgent') !== false || strpos($feedback, 'emergency') !== false) {
        $urgency_level = "High";
    }

    if (strpos($feedback, 'death') !== false || strpos($feedback, 'die') !== false) {
        $urgency_level   = "Critical";
        $recommendations[] = "🚨 CRITICAL: Immediate life-saving intervention required!";
    }

    if (strpos($feedback, 'delay') !== false || strpos($feedback, 'late') !== false || strpos($feedback, 'waiting') !== false) {
        $recommendations[] = "⏰ Urgent follow-up required for timely resolution";
        if (strpos($feedback, 'medicine') !== false || strpos($feedback, 'treatment') !== false) {
            $recommendations[] = "💊 Medical delay detected - escalate to health department immediately";
        }
    }

    if (strpos($feedback, 'quality') !== false || strpos($feedback, 'poor') !== false || strpos($feedback, 'bad') !== false) {
        $recommendations[] = "🔍 Quality assurance team should investigate and provide corrective measures";
        if (strpos($feedback, 'water') !== false) {
            $recommendations[] = "💧 Water quality issue - notify WASH team for immediate testing";
        }
    }

    if (strpos($feedback, 'payment') !== false || strpos($feedback, 'money') !== false || strpos($feedback, 'cash') !== false) {
        $recommendations[] = "💰 Finance department involvement recommended for resolution";
        $urgency_level = "High";
    }

    if (strpos($feedback, 'safety') !== false || strpos($feedback, 'danger') !== false || strpos($feedback, 'unsafe') !== false) {
        $recommendations[] = "🛡️ Immediate safety assessment required";
        $urgency_level = "High";
    }

    if (strpos($feedback, 'food') !== false || strpos($feedback, 'hunger') !== false) {
        $recommendations[] = "🍲 Food security concern - escalate to nutrition team";
        if (strpos($feedback, 'child') !== false) {
            $recommendations[] = "👶 Child malnutrition risk - immediate screening needed";
        }
    }

    if (strpos($feedback, 'corruption') !== false || strpos($feedback, 'bribe') !== false || strpos($feedback, 'steal') !== false) {
        $recommendations[] = "⚖️ Ethics and compliance team notification required";
        $urgency_level = "High";
    }

    // Vulnerability-based
    if ($vulnerability === 'Child') {
        $recommendations[] = "👶 Child protection protocols must be followed";
        $urgency_level = "High";
    } elseif ($vulnerability === 'Disability') {
        $recommendations[] = "♿ Ensure accessibility and reasonable accommodation";
    } elseif ($vulnerability === 'Pregnant') {
        $recommendations[] = "🤰 Pregnant woman - prioritize maternal health services";
        $urgency_level = "High";
    }

    // Category-based
    switch ($category) {
        case 'Complaint':
            $recommendations[] = "📋 Immediate acknowledgment and investigation needed";
            if ($urgency_level === "Medium") $urgency_level = "High";
            break;
        case 'Suggestion':
            $recommendations[] = "💡 Review for potential implementation in program improvement";
            $urgency_level = "Low";
            break;
        case 'Appreciation':
            $recommendations[] = "⭐ Share positive feedback with relevant team for morale boosting";
            $urgency_level = "Low";
            break;
        case 'Question':
            $recommendations[] = "❓ Provide clear and timely response within 48 hours";
            break;
    }

    // Status-based
    if ($status === 'New') {
        $recommendations[] = "🆕 Assign to relevant department within 24 hours";
    } elseif ($status === 'Under Review') {
        $recommendations[] = "🔍 Set clear timeline for resolution and communicate to complainant";
    } elseif ($status === 'Action Taken') {
        $recommendations[] = "✅ Verify effectiveness of actions and follow up with complainant";
    }

    // Channel-based
    if ($channel === 'Hotline') {
        $recommendations[] = "📞 Ensure callback mechanism is in place for follow-up";
    } elseif ($channel === 'Community Meeting') {
        $recommendations[] = "👥 Document in community meeting minutes and share action plan";
    } elseif ($channel === 'Suggestion Box') {
        $recommendations[] = "📬 Check suggestion box regularly and provide public responses";
    }

    $recommendations = array_unique($recommendations);
    if (empty($recommendations)) {
        $recommendations[] = "📊 Standard monitoring and evaluation process to be followed";
    }

    $urgency_icon = "🟡";
    if ($urgency_level === "High")     $urgency_icon = "🟠";
    if ($urgency_level === "Critical") $urgency_icon = "🔴";

    return "$urgency_icon **$urgency_level Priority**\n\n🤖 AI Recommendations for MEAL/Program Dept:\n• " . implode("\n• ", $recommendations);
}

function analyzeFeedbackSentiment($feedback) {
    $negative_words = ['bad','poor','terrible','awful','horrible','failed','broken','wrong',
        'problem','issue','complaint','angry','frustrated','disappointed','unsatisfied','delay',
        'late','emergency','urgent','danger','unsafe','corruption','bribe','steal','death','die',
        'hunger','suffering'];
    $positive_words = ['good','great','excellent','wonderful','amazing','happy','satisfied',
        'thank','appreciate','helpful','working','success','improved','better'];

    $feedback_lower = strtolower($feedback);
    $negative_count = 0;
    $positive_count = 0;

    foreach ($negative_words as $word) {
        if (strpos($feedback_lower, $word) !== false) $negative_count++;
    }
    foreach ($positive_words as $word) {
        if (strpos($feedback_lower, $word) !== false) $positive_count++;
    }

    if ($negative_count > $positive_count) return 'negative';
    if ($positive_count > $negative_count) return 'positive';
    return 'neutral';
}

/**
 * ETHIOPIAN LANGUAGES
 */
$ethiopian_languages = [
    'Amharic', 'Afaan Oromo', 'Tigrinya', 'Somali', 'Afar', 'Sidamo', 'Wolaytta', 'Gurage',
    'Hadiyya', 'Gamo', 'Gedeo', 'Kafa', 'Siltʼe', 'Kambaata', 'Harari', 'Awi', 'Bench',
    'Kunama', 'Murle', 'Nuer', 'Anuak', 'Berta', 'Gumuz', 'Majang', 'Suri', 'Mursi',
    'Hammer', 'Aari', 'Dime', 'Konso', 'Burji', 'Alaba', 'Koyra', 'Zay', 'Oyda', 'Goffa',
    'Dawro', 'Basketo', 'Kontoma', 'Kachama', 'Koorete', 'Zargulla', 'Ganjule', 'Haro'
];

/**
 * REUSABLE INSERT HELPER
 */
function insertCFMReport($pdo, $payload) {
    $sql = "
        INSERT INTO cfm_reports (
            project_id, reported_by, position, date_feedback_received, date_of_report,
            feedback_type, organization, region_id, zone_name, woreda_name, gender, age,
            community_type, vulnerability, language, actual_feedback, feedback_channel,
            feedback_category, feedback_concern, feedback_status, actions_taken,
            responsibility_follow_up, expected_closure_date, reason_closure_passed,
            recommendation, ai_recommendation, created_by
        ) VALUES (
            :project_id, :reported_by, :position, :date_feedback_received, :date_of_report,
            :feedback_type, :organization, :region_id, :zone_name, :woreda_name, :gender, :age,
            :community_type, :vulnerability, :language, :actual_feedback, :feedback_channel,
            :feedback_category, :feedback_concern, :feedback_status, :actions_taken,
            :responsibility_follow_up, :expected_closure_date, :reason_closure_passed,
            :recommendation, :ai_recommendation, :created_by
        )
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':project_id'            => $payload['project_id'],
        ':reported_by'           => $payload['reported_by'],
        ':position'              => $payload['position'],
        ':date_feedback_received'=> $payload['date_feedback_received'],
        ':date_of_report'        => $payload['date_of_report'],
        ':feedback_type'         => $payload['feedback_type'],
        ':organization'          => $payload['organization'],
        ':region_id'             => $payload['region_id'],
        ':zone_name'             => $payload['zone_name'],
        ':woreda_name'           => $payload['woreda_name'],
        ':gender'                => $payload['gender'],
        ':age'                   => $payload['age'],
        ':community_type'        => $payload['community_type'],
        ':vulnerability'         => $payload['vulnerability'],
        ':language'              => $payload['language'],
        ':actual_feedback'       => $payload['actual_feedback'],
        ':feedback_channel'      => $payload['feedback_channel'],
        ':feedback_category'     => $payload['feedback_category'],
        ':feedback_concern'      => $payload['feedback_concern'],
        ':feedback_status'       => $payload['feedback_status'],
        ':actions_taken'         => $payload['actions_taken'],
        ':responsibility_follow_up' => $payload['responsibility_follow_up'],
        ':expected_closure_date' => $payload['expected_closure_date'],
        ':reason_closure_passed' => $payload['reason_closure_passed'],
        ':recommendation'        => $payload['recommendation'],
        ':ai_recommendation'     => $payload['ai_recommendation'],
        ':created_by'            => $payload['created_by'],
    ]);

    return $pdo->lastInsertId();
}

/**
 * FORM SUBMISSION HANDLERS
 */
function handleAddCFM($pdo, $user) {
    try {
        $age                   = !empty($_POST['age']) ? (int)$_POST['age'] : null;
        $expected_closure_date = !empty($_POST['expected_closure_date']) ? $_POST['expected_closure_date'] : null;

        $ai_recommendation = generateAIRecommendation($_POST);

        $payload = [
            'project_id'            => $_POST['project_id'] ?? null,
            'reported_by'           => $_POST['reported_by'] ?? '',
            'position'              => $_POST['position'] ?? '',
            'date_feedback_received'=> $_POST['date_feedback_received'] ?? '',
            'date_of_report'        => $_POST['date_of_report'] ?? date('Y-m-d'),
            'feedback_type'         => $_POST['feedback_type'] ?? 'new',
            'organization'          => $_POST['organization'] ?? '',
            'region_id'             => $_POST['region_id'] ?? null,
            'zone_name'             => $_POST['zone_name'] ?? '',
            'woreda_name'           => $_POST['woreda_name'] ?? '',
            'gender'                => $_POST['gender'] ?? '',
            'age'                   => $age,
            'community_type'        => $_POST['community_type'] ?? '',
            'vulnerability'         => $_POST['vulnerability'] ?? '',
            'language'              => $_POST['language'] ?? 'Amharic',
            'actual_feedback'       => $_POST['actual_feedback'] ?? '',
            'feedback_channel'      => $_POST['feedback_channel'] ?? '',
            'feedback_category'     => $_POST['feedback_category'] ?? '',
            'feedback_concern'      => $_POST['feedback_concern'] ?? '',
            'feedback_status'       => $_POST['feedback_status'] ?? 'New',
            'actions_taken'         => $_POST['actions_taken'] ?? '',
            'responsibility_follow_up' => $_POST['responsibility_follow_up'] ?? '',
            'expected_closure_date' => $expected_closure_date,
            'reason_closure_passed' => $_POST['reason_closure_passed'] ?? '',
            'recommendation'        => $_POST['recommendation'] ?? '',
            'ai_recommendation'     => $ai_recommendation,
            'created_by'            => $user['id']
        ];

        $report_id = insertCFMReport($pdo, $payload);

        // store for "View Details" button
        $_SESSION['last_cfm_report_id'] = $report_id;

        // send to data quality
        sendToDataQuality($report_id);

        set_flash_message("CFM report added successfully! AI recommendation generated for MEAL/Program Dept.", 'success');

        return ['id' => $report_id, 'success' => true];
    } catch (Exception $e) {
        set_flash_message("Error adding CFM report: " . $e->getMessage(), 'error');
        return ['error' => $e->getMessage()];
    }
}

function handleUpdateCFM($pdo) {
    try {
        if (!isset($_POST['report_id'])) {
            throw new Exception("Report ID is required for update");
        }

        $age                   = !empty($_POST['age']) ? (int)$_POST['age'] : null;
        $expected_closure_date = !empty($_POST['expected_closure_date']) ? $_POST['expected_closure_date'] : null;

        $ai_recommendation = generateAIRecommendation($_POST);

        $stmt = $pdo->prepare("
            UPDATE cfm_reports SET
                project_id = ?, reported_by = ?, position = ?, date_feedback_received = ?, date_of_report = ?,
                feedback_type = ?, organization = ?, region_id = ?, zone_name = ?, woreda_name = ?, gender = ?, age = ?,
                community_type = ?, vulnerability = ?, language = ?, actual_feedback = ?, feedback_channel = ?,
                feedback_category = ?, feedback_concern = ?, feedback_status = ?, actions_taken = ?,
                responsibility_follow_up = ?, expected_closure_date = ?, reason_closure_passed = ?,
                recommendation = ?, ai_recommendation = ?, updated_at = NOW()
            WHERE id = ?
        ");

        $stmt->execute([
            $_POST['project_id'] ?? null,
            $_POST['reported_by'] ?? '',
            $_POST['position'] ?? '',
            $_POST['date_feedback_received'] ?? '',
            $_POST['date_of_report'] ?? date('Y-m-d'),
            $_POST['feedback_type'] ?? 'new',
            $_POST['organization'] ?? '',
            $_POST['region_id'] ?? null,
            $_POST['zone_name'] ?? '',
            $_POST['woreda_name'] ?? '',
            $_POST['gender'] ?? '',
            $age,
            $_POST['community_type'] ?? '',
            $_POST['vulnerability'] ?? '',
            $_POST['language'] ?? 'Amharic',
            $_POST['actual_feedback'] ?? '',
            $_POST['feedback_channel'] ?? '',
            $_POST['feedback_category'] ?? '',
            $_POST['feedback_concern'] ?? '',
            $_POST['feedback_status'] ?? 'New',
            $_POST['actions_taken'] ?? '',
            $_POST['responsibility_follow_up'] ?? '',
            $expected_closure_date,
            $_POST['reason_closure_passed'] ?? '',
            $_POST['recommendation'] ?? '',
            $ai_recommendation,
            $_POST['report_id']
        ]);

        set_flash_message("CFM report updated successfully!", 'success');
    } catch (Exception $e) {
        set_flash_message("Error updating CFM report: " . $e->getMessage(), 'error');
    }
}

function handleDeleteCFM($pdo) {
    try {
        if (!isset($_POST['report_id'])) {
            throw new Exception("Report ID is required for deletion");
        }

        $stmt = $pdo->prepare("DELETE FROM cfm_reports WHERE id = ?");
        $stmt->execute([$_POST['report_id']]);

        set_flash_message("CFM report deleted successfully!", 'success');
    } catch (Exception $e) {
        set_flash_message("Error deleting CFM report: " . $e->getMessage(), 'error');
    }
}

/**
 * XLSX SIMPLE PARSER (first sheet only)
 */
function parseXLSXToRows($filePath) {
    $rows = [];
    if (!class_exists('ZipArchive')) {
        return $rows;
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== TRUE) {
        return $rows;
    }

    $sharedStrings = [];
    $sharedIndex   = $zip->locateName('xl/sharedStrings.xml');
    if ($sharedIndex !== false) {
        $xml = simplexml_load_string($zip->getFromIndex($sharedIndex));
        foreach ($xml->si as $i => $si) {
            $t = '';
            if (isset($si->t)) {
                $t = (string)$si->t;
            } elseif (isset($si->r)) {
                foreach ($si->r as $run) {
                    $t .= (string)$run->t;
                }
            }
            $sharedStrings[(int)$i] = $t;
        }
    }

    $sheetIndex = $zip->locateName('xl/worksheets/sheet1.xml');
    if ($sheetIndex === false) {
        $zip->close();
        return $rows;
    }

    $xml = simplexml_load_string($zip->getFromIndex($sheetIndex));
    if (!$xml || !isset($xml->sheetData)) {
        $zip->close();
        return $rows;
    }

    foreach ($xml->sheetData->row as $row) {
        $rowData = [];
        foreach ($row->c as $c) {
            $v    = isset($c->v) ? (string)$c->v : '';
            $type = isset($c['t']) ? (string)$c['t'] : '';
            if ($type === 's' && $v !== '' && isset($sharedStrings[(int)$v])) {
                $rowData[] = $sharedStrings[(int)$v];
            } else {
                $rowData[] = $v;
            }
        }
        if (!empty($rowData)) {
            $rows[] = $rowData;
        }
    }

    $zip->close();
    return $rows;
}

function handleImportCFM($pdo, $user) {
    try {
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Please select a valid file to import");
        }

        $file     = $_FILES['import_file']['tmp_name'];
        $fileType = strtolower(pathinfo($_FILES['import_file']['name'], PATHINFO_EXTENSION));

        if (!in_array($fileType, ['csv', 'xlsx', 'xls'])) {
            throw new Exception("Only CSV and Excel files are supported");
        }

        $rows = [];

        if ($fileType === 'csv') {
            $handle = fopen($file, 'r');
            if (!$handle) {
                throw new Exception("Cannot open uploaded CSV file");
            }

            while (($data = fgetcsv($handle)) !== false) {
                if (!empty(array_filter($data, 'strlen'))) {
                    $rows[] = $data;
                }
            }
            fclose($handle);
        } elseif ($fileType === 'xlsx') {
            $rows = parseXLSXToRows($file);
        } elseif ($fileType === 'xls') {
            set_flash_message("XLS (old Excel) is not supported. Please save as CSV or XLSX and import again.", 'error');
            return;
        }

        if (empty($rows) || count($rows) < 2) {
            throw new Exception("No data rows found in the file");
        }

        $header = array_map('trim', $rows[0]);
        $imported = 0;

        foreach (array_slice($rows, 1) as $dataRow) {
            if (!is_array($dataRow)) continue;

            // Normalize row length to header length
            if (count($dataRow) < count($header)) {
                $dataRow = array_pad($dataRow, count($header), '');
            } elseif (count($dataRow) > count($header)) {
                $dataRow = array_slice($dataRow, 0, count($header));
            }

            $reportData = @array_combine($header, $dataRow);
            if ($reportData === false) {
                continue;
            }

            // Skip totally empty lines
            if (!array_filter($reportData, 'strlen')) {
                continue;
            }

            $ai_recommendation = generateAIRecommendation($reportData);

            $age = (!empty($reportData['age']) && is_numeric($reportData['age']))
                ? (int)$reportData['age'] : null;

            $expected_closure_date = !empty($reportData['expected_closure_date'])
                ? $reportData['expected_closure_date'] : null;

            $payload = [
                'project_id'            => $reportData['project_id'] ?? null,
                'reported_by'           => $reportData['reported_by'] ?? '',
                'position'              => $reportData['position'] ?? '',
                'date_feedback_received'=> $reportData['date_feedback_received'] ?? date('Y-m-d'),
                'date_of_report'        => $reportData['date_of_report'] ?? date('Y-m-d'),
                'feedback_type'         => $reportData['feedback_type'] ?? 'new',
                'organization'          => $reportData['organization'] ?? '',
                'region_id'             => $reportData['region_id'] ?? null,
                'zone_name'             => $reportData['zone_name'] ?? '',
                'woreda_name'           => $reportData['woreda_name'] ?? '',
                'gender'                => $reportData['gender'] ?? '',
                'age'                   => $age,
                'community_type'        => $reportData['community_type'] ?? '',
                'vulnerability'         => $reportData['vulnerability'] ?? '',
                'language'              => $reportData['language'] ?? 'Amharic',
                'actual_feedback'       => $reportData['actual_feedback'] ?? '',
                'feedback_channel'      => $reportData['feedback_channel'] ?? '',
                'feedback_category'     => $reportData['feedback_category'] ?? '',
                'feedback_concern'      => $reportData['feedback_concern'] ?? '',
                'feedback_status'       => $reportData['feedback_status'] ?? 'New',
                'actions_taken'         => $reportData['actions_taken'] ?? '',
                'responsibility_follow_up' => $reportData['responsibility_follow_up'] ?? '',
                'expected_closure_date' => $expected_closure_date,
                'reason_closure_passed' => $reportData['reason_closure_passed'] ?? '',
                'recommendation'        => $reportData['recommendation'] ?? '',
                'ai_recommendation'     => $ai_recommendation,
                'created_by'            => $user['id']
            ];

            insertCFMReport($pdo, $payload);
            $imported++;
        }

        set_flash_message("Successfully imported {$imported} CFM reports!", 'success');
    } catch (Exception $e) {
        set_flash_message("Error importing CFM reports: " . $e->getMessage(), 'error');
    }
}

/**
 * HANDLE POST + LOAD DATA
 */
create_cfm_table(); // ensure table exists

$regions = get_regions();
if (function_exists('get_projects')) {
    // Use the global helper shared with other apps (projects.php, planning.php, etc.)
    $projects = get_projects();
} else {
    // Fallback (same DB, no dummy projects)
    $projects = cfm_get_projects_fallback($pdo);
}


// -----------------------------------------------------------------------------
// CFM UPGRADE: Clear (Delete) CFM records to allow safe Project deletion
// -----------------------------------------------------------------------------
function handleClearProjectCFM(PDO $pdo) {
    try {
        $pid = isset($_POST['clear_project_id']) ? (int)$_POST['clear_project_id'] : 0;
        if ($pid <= 0) {
            throw new Exception("Please select a valid project to clear.");
        }
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("DELETE FROM cfm_reports WHERE project_id = ?");
        $stmt->execute([$pid]);
        $deleted = $stmt->rowCount();
        $pdo->commit();

        set_flash_message("Cleared {$deleted} CFM record(s) for the selected project. You can now delete the project safely.", 'success');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        set_flash_message("Error clearing project CFM data: " . $e->getMessage(), 'error');
    }
}

function handleClearAllCFM(PDO $pdo) {
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("DELETE FROM cfm_reports");
        $stmt->execute();
        $deleted = $stmt->rowCount();
        $pdo->commit();

        set_flash_message("Cleared ALL CFM records ({$deleted}).", 'success');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        set_flash_message("Error clearing all CFM data: " . $e->getMessage(), 'error');
    }
}

if ($_POST) {
    if (isset($_POST['add_cfm'])) {
        handleAddCFM($pdo, $user);
    } elseif (isset($_POST['update_cfm'])) {
        handleUpdateCFM($pdo);
    } elseif (isset($_POST['delete_cfm'])) {
        handleDeleteCFM($pdo);
    } elseif (isset($_POST['import_cfm'])) {
        handleImportCFM($pdo, $user);
    } elseif (isset($_POST['clear_project_cfm'])) {
        handleClearProjectCFM($pdo);
    } elseif (isset($_POST['clear_all_cfm'])) {
        handleClearAllCFM($pdo);
    }
}

$cfm_reports = get_cfm_reports_with_details();
$stats       = getCFMStatistics($cfm_reports);

// PRINT HEADER CONTEXT (for print/PDF, no system header)
$printProjectTitle = 'All Nexus Ethiopia Projects';
$printRegion       = 'All';
$printZone         = 'All';
$printWoreda       = 'All';
$printMonthLabel   = date('F Y');

if (!empty($cfm_reports)) {
    $first = $cfm_reports[0];

    if (!empty($first['project_title'])) {
        $printProjectTitle = $first['project_title'];
    }
    if (!empty($first['region_name'])) {
        $printRegion = $first['region_name'];
    }
    if (!empty($first['zone_name'])) {
        $printZone = $first['zone_name'];
    }
    if (!empty($first['woreda_name'])) {
        $printWoreda = $first['woreda_name'];
    }
    if (!empty($first['date_of_report'])) {
        $printMonthLabel = date('F Y', strtotime($first['date_of_report']));
    }
}

// AI dynamic messages
$cfm_messages = [
    "🌟 Every feedback is an opportunity to improve our services!",
    "📊 Real-time feedback tracking ensures timely responses",
    "💡 Your complaints help us serve you better!",
    "🚀 Building trust through transparent feedback mechanisms",
    "📝 Documenting feedback creates accountability and learning",
    "🤝 Community voices matter - every complaint is important",
    "⚡ Quick resolution builds community confidence",
    "🎯 Turning complaints into positive change opportunities"
];
$current_cfm_message = $cfm_messages[array_rand($cfm_messages)];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AAP-Compliant Feedback Mechanism - SMART Nexus</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #4361ee;
            --gradient-primary: linear-gradient(135deg, #4361ee 0%, #3a0ca3 100%);
            --gradient-success: linear-gradient(135deg, #4cc9f0 0%, #4361ee 100%);
            --gradient-warning: linear-gradient(135deg, #f72585 0%, #b5179e 100%);
            --border-radius: 12px;
            --shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        .cfm-container {
            background: white;
            border-radius: var(--border-radius);
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: var(--shadow);
            animation: fadeInUp 0.8s ease-out;
        }
        .cfm-header {
            background: var(--gradient-primary);
            color: white;
            padding: 30px;
            border-radius: var(--border-radius);
            margin-bottom: 25px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .cfm-header::before {
            content: "";
            position: absolute;
            inset: 0;
            background: url('data:image/svg+xml;utf8,<svg viewBox="0 0 1200 120" xmlns="http://www.w3.org/2000/svg"><path d="M0 0v46.29c47.79 22.2 103.59 32.17 158 28 70.36-5.37 136.33-33.31 206.8-37.5 73.84-4.36 147.54 16.88 218.2 35.26 69.27 18 138.3 24.88 209.4 13.08 36.15-6 69.85-17.84 104.45-29.34C989.49 25 1113-14.29 1200 52.47V0z" fill="%23ffffff" opacity=".1"/></svg>');
            background-size: cover;
            animation: wave 20s linear infinite;
        }
        .cfm-header-content { position: relative; z-index: 2; }
        .cfm-title { font-size: 2.5rem; font-weight: 900; margin-bottom: 15px; text-shadow: 0 4px 12px rgba(0,0,0,0.3); }
        .cfm-subtitle { font-size: 1.2rem; opacity: 0.95; margin-bottom: 20px; }
        .cfm-message {
            background: rgba(255,255,255,0.2);
            padding: 15px 25px;
            border-radius: 50px;
            display: inline-block;
            backdrop-filter: blur(10px);
            font-weight: 600;
            font-size: 1.1rem;
        }
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .dashboard-card {
            background: white;
            padding: 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            border-top: 4px solid var(--primary);
        }
        .chart-container { height: 260px; position: relative; }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        .form-section {
            background: #f8fafc;
            padding: 25px;
            border-radius: 12px;
            border-left: 4px solid var(--primary);
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .form-section h3 {
            color: var(--primary);
            margin-bottom: 20px;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 10px;
        }
        .form-group { margin-bottom: 20px; }
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #374151;
            font-size: 0.95rem;
        }
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: white;
            box-sizing: border-box;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }
        .form-select {
            appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 15px center;
            background-size: 16px;
            padding-right: 45px;
        }
        .btn-group {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-top: 30px;
            justify-content: center;
        }
        .btn {
            padding: 14px 28px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-size: 1rem;
            text-decoration: none;
        }
        .btn-primary { background: var(--gradient-primary); color: white; }
        .btn-success { background: var(--gradient-success); color: white; }
        .btn-warning { background: var(--gradient-warning); color: white; }
        .btn-danger { background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%); color: white; }
        .btn-info { background: linear-gradient(135deg, #4ecdc4 0%, #44a08d 100%); color: white; }
        .btn-secondary { background: linear-gradient(135deg, #6c757d 0%, #495057 100%); color: white; }
        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
        }
        .table-container {
            overflow-x: auto;
            margin-top: 30px;
            border-radius: 12px;
            box-shadow: var(--shadow);
        }
        .cfm-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 12px;
            overflow: hidden;
        }
        .cfm-table th {
            background: var(--gradient-primary);
            color: white;
            padding: 18px 15px;
            text-align: left;
            font-weight: 600;
            font-size: 0.9rem;
            white-space: nowrap;
        }
        .cfm-table td {
            padding: 15px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.9rem;
            vertical-align: top;
        }
        .cfm-table tr:hover {
            background: #f8fafc;
            transform: scale(1.01);
            transition: all 0.2s ease;
        }
        .status-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-new { background: #dbeafe; color: #1e40af; }
        .status-under-review { background: #fef3c7; color: #92400e; }
        .status-action-taken { background: #ddd6fe; color: #5b21b6; }
        .status-resolved { background: #d1fae5; color: #065f46; }
        .status-closed { background: #e5e7eb; color: #374151; }
        .action-buttons {
            display: flex;
            gap: 8px;
            justify-content: center;
        }
        .action-btn {
            padding: 8px 12px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.8rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .edit-btn { background: #3b82f6; color: white; }
        .delete-btn { background: #ef4444; color: white; }
        .view-btn { background: #10b981; color: white; }
        .action-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        .import-export-section {
            background: #f8fafc;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 30px;
            border: 2px dashed #cbd5e1;
        }
        .file-input {
            padding: 12px;
            border: 2px dashed #cbd5e1;
            border-radius: 8px;
            background: white;
            width: 100%;
            box-sizing: border-box;
        }
        .notification {
            padding: 18px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            font-weight: 600;
            border-left: 5px solid;
            animation: slideIn 0.5s ease-out;
        }
        .notification.success { background: #d1fae5; color: #065f46; border-left-color: #10b981; }
        .notification.error { background: #fee2e2; color: #991b1b; border-left-color: #ef4444; }
        .dynamic-message {
            background: var(--gradient-warning);
            color: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            text-align: center;
            font-weight: 600;
            animation: pulse 2s infinite;
            font-size: 1.1rem;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            text-align: center;
            box-shadow: var(--shadow);
            border-top: 4px solid var(--primary);
        }
        .stat-number {
            font-size: 2.5rem;
            font-weight: 900;
            color: var(--primary);
            margin-bottom: 10px;
        }
        .stat-label {
            color: #6b7280;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 0.85rem;
        }
        .ai-recommendation {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 12px;
            margin: 20px 0;
            border-left: 5px solid #4cc9f0;
        }
        .text-warning { color: #f59e0b; font-weight: bold; }
        .data-quality-badge {
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .quality-high   { background: #d1fae5; color: #065f46; }
        .quality-medium { background: #fef3c7; color: #92400e; }
        .quality-low    { background: #fee2e2; color: #991b1b; }
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.02); }
            100% { transform: scale(1); }
        }
        @keyframes wave {
            0% { transform: translateX(0); }
            50% { transform: translateX(-10px); }
            100% { transform: translateX(0); }
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-20px); }
            to   { opacity: 1; transform: translateX(0); }
        }
        .print-header {
            display: none;
            text-align: center;
            margin-bottom: 30px;
            padding: 15px 0 25px;
            border-bottom: 3px solid #000;
        }
        .print-header h1, .print-header h2, .print-header h3 {
            margin: 4px 0;
        }
        .print-header p {
            margin: 2px 0;
            font-size: 0.95rem;
        }
        .no-print { }

        @media print {
            /* Hide global/system header & nav from header.php */
            header, nav, .sidebar, .topbar, .app-header, .main-header {
                display: none !important;
            }
            .no-print, .cfm-header, .dynamic-message, .import-export-section {
                display: none !important;
            }
            .print-header {
                display: block;
            }
            .cfm-table { box-shadow: none; }
            .btn-group { display: none !important; }
            .form-section { break-inside: avoid; }
            body {
                background: #ffffff !important;
            }
        }

        @media (max-width: 768px) {
            .form-grid { grid-template-columns: 1fr; }
            .cfm-title { font-size: 1.8rem; }
            .btn-group { flex-direction: column; }
            .action-buttons { flex-direction: column; }
            .dashboard-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="page-container">

    <!-- PRINT HEADER ONLY (no system header) -->
    <div class="print-header">
        <h2>Nexus Ethiopia</h2>
        <h3>Compliant Feedback Mechanism (CFM) Report</h3>
        <p><strong>Project Title:</strong> <?php echo h($printProjectTitle); ?></p>
        <p>
            <strong>Project Location:</strong>
            Region: <?php echo h($printRegion); ?>,
            Zone: <?php echo h($printZone); ?>,
            Woreda: <?php echo h($printWoreda); ?>
        </p>
        <p><strong>Month of Report:</strong> <?php echo h($printMonthLabel); ?></p>
        <p><small>Generated on: <?php echo date('d/m/Y H:i'); ?></small></p>
    </div>

    <!-- HEADER (screen only) -->
    <div class="cfm-header no-print">
        <div class="cfm-header-content">
            <h1 class="cfm-title">📝 Nexus Ethiopia Compliant Feedback Mechanism (CFM)</h1>
            <p class="cfm-subtitle">AAP-Compliant Feedback Reporting System - Building Trust Through Transparent Communication</p>
            <div class="cfm-message">
                🤝 Your feedback matters! Help us improve our services by sharing your experience.
            </div>
        </div>
    </div>

    <!-- Dynamic message -->
    <div class="dynamic-message no-print">
        🚀 <?php echo $current_cfm_message; ?>
    </div>

    <!-- FLASH MESSAGES -->
    <?php if ($flash = get_flash_message()): ?>
        <div class="notification <?php echo $flash['type']; ?>">
            <?php echo $flash['message']; ?>
            <?php if ($flash['type'] === 'success' && isset($_SESSION['last_cfm_report_id'])): ?>
                <!-- View Details link: make sure cfm_view.php exists in same folder as cfm.php -->
                <a href="cfm_view.php?id=<?php echo (int)$_SESSION['last_cfm_report_id']; ?>"
                   class="btn btn-info" style="margin-left: 15px; padding: 6px 12px; font-size: 0.8rem;">
                    <i class="fas fa-eye"></i> View Details
                </a>
                <?php unset($_SESSION['last_cfm_report_id']); ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Data Quality Notification -->
    <?php if (isset($_SESSION['data_quality_report'])): ?>
        <div class="notification success">
            <i class="fas fa-chart-line"></i>
            Data quality report generated and sent to data quality system!
            Score: <?php echo $_SESSION['data_quality_report']['data_quality_score']; ?>%
            <button onclick="viewDataQuality()" class="btn btn-info"
                    style="margin-left: 15px; padding: 5px 10px; font-size: 0.8rem;">
                View Details
            </button>
        </div>
        <?php unset($_SESSION['data_quality_report']); ?>
    <?php endif; ?>

    <!-- CFM FORM FIRST -->
    <div class="cfm-container">
        <h2 style="color: var(--primary); margin-bottom: 25px; display: flex; align-items: center; gap: 12px;">
            <i class="fas fa-clipboard-list"></i> 📋 CFM Report Entry Form
        </h2>
        <form method="POST" id="cfmForm">
            <div class="form-grid">
                <!-- Project Information -->
                <div class="form-section">
                    <h3><i class="fas fa-building"></i> Project Information</h3>
                    <div class="form-group">
                        <label class="form-label">Project Title *</label>
                        <select name="project_id" class="form-control form-select" required>
                            <option value="">Select Project</option>
                            <?php foreach ($projects as $project): ?>
                                <option value="<?php echo $project['id']; ?>">
                                    <?php echo h($project['title'] ?? $project['name'] ?? ('Project ' . $project['id'])); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Reported By *</label>
                        <input type="text" name="reported_by" class="form-control" required
                               value="<?php echo h($user['full_name'] ?? $user['name'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Position *</label>
                        <input type="text" name="position" class="form-control" required
                               placeholder="e.g., Field Officer, MEAL Officer">
                    </div>
                </div>

                <!-- Feedback Timing -->
                <div class="form-section">
                    <h3><i class="fas fa-clock"></i> Feedback Timing</h3>
                    <div class="form-group">
                        <label class="form-label">Date Feedback Received *</label>
                        <input type="date" name="date_feedback_received" class="form-control" required
                               max="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Date of Report *</label>
                        <input type="date" name="date_of_report" class="form-control" required
                               value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Days Since Complaint</label>
                        <input type="text" id="days_calculation" class="form-control" readonly
                               placeholder="Calculated automatically">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Feedback Type *</label>
                        <select name="feedback_type" class="form-control form-select" required>
                            <option value="new">New Feedback This Month</option>
                            <option value="pending">Pending/Unanswered from Last Month</option>
                        </select>
                    </div>
                </div>

                <!-- Geographical Information -->
                <div class="form-section">
                    <h3><i class="fas fa-map-marker-alt"></i> Geographical Information</h3>
                    <div class="form-group">
                        <label class="form-label">Organization *</label>
                        <input type="text" name="organization" class="form-control" required
                               value="Nexus Ethiopia" placeholder="Organization name">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Region *</label>
                        <select name="region_id" id="region_id" class="form-control form-select geo-region region-select" required>
                            <option value="">Select Region</option>
                            <?php foreach ($regions as $region): ?>
                                <option value="<?php echo $region['id']; ?>">
                                    <?php echo h($region['name']); ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="other">Other (specify)</option>
                        </select>
                        <input type="text" name="region_other" id="region_other" class="form-control"
                               placeholder="If region not in list, specify here"
                               style="margin-top:4px; display:none;">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Zone</label>
                        <select name="zone_id" id="zone_id" class="form-control form-select geo-zone zone-select">
                            <option value="">Select Zone</option>
                            <option value="other">Other (specify)</option>
                        </select>
                        <input type="text" name="zone_other" id="zone_other" class="form-control"
                               placeholder="If zone not in list, specify here"
                               style="margin-top:4px; display:none;">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Woreda</label>
                        <select name="woreda_id" id="woreda_id" class="form-control form-select geo-woreda woreda-select">
                            <option value="">Select Woreda</option>
                            <option value="other">Other (specify)</option>
                        </select>
                        <input type="text" name="woreda_other" id="woreda_other" class="form-control"
                               placeholder="If woreda not in list, specify here"
                               style="margin-top:4px; display:none;">
                    </div>
                </div>

                <!-- Complainant -->
                <div class="form-section">
                    <h3><i class="fas fa-user"></i> Complainant Information</h3>
                    <div class="form-group">
                        <label class="form-label">Gender *</label>
                        <select name="gender" class="form-control form-select" required>
                            <option value="">Select Gender</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Age</label>
                        <input type="number" name="age" class="form-control" min="1" max="120"
                               placeholder="Enter age">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Community Type</label>
                        <select name="community_type" class="form-control form-select">
                            <option value="">Select Community Type</option>
                            <option value="Urban">Urban</option>
                            <option value="Rural">Rural</option>
                            <option value="Pastoralist">Pastoralist</option>
                            <option value="IDP">Internally Displaced</option>
                            <option value="Refugee">Refugee</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Vulnerability</label>
                        <select name="vulnerability" class="form-control form-select">
                            <option value="">Select Vulnerability</option>
                            <option value="None">None</option>
                            <option value="Child">Child</option>
                            <option value="Elderly">Elderly</option>
                            <option value="Disability">Person with Disability</option>
                            <option value="Pregnant">Pregnant/Lactating</option>
                            <option value="Chronic">Chronic Illness</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Language *</label>
                        <select name="language" class="form-control form-select" required>
                            <option value="">Select Language</option>
                            <?php foreach ($ethiopian_languages as $language): ?>
                                <option value="<?php echo h($language); ?>"
                                    <?php echo $language === 'Amharic' ? 'selected' : ''; ?>>
                                    <?php echo h($language); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Feedback Details -->
                <div class="form-section">
                    <h3><i class="fas fa-comments"></i> Feedback Details</h3>
                    <div class="form-group">
                        <label class="form-label">Actual Feedback *</label>
                        <textarea name="actual_feedback" class="form-control" rows="4" required
                                  placeholder="Describe the actual feedback or complaint received..."
                                  oninput="updateAIRecommendation()"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Feedback Channel *</label>
                        <select name="feedback_channel" class="form-control form-select" required
                                onchange="updateAIRecommendation()">
                            <option value="">Select Channel</option>
                            <option value="Hotline">Hotline</option>
                            <option value="Suggestion Box">Suggestion Box</option>
                            <option value="Community Meeting">Community Meeting</option>
                            <option value="Field Staff">Field Staff</option>
                            <option value="Email">Email</option>
                            <option value="Social Media">Social Media</option>
                            <option value="Direct Complaint">Direct Complaint</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Feedback Category *</label>
                        <select name="feedback_category" class="form-control form-select" required
                                onchange="updateAIRecommendation()">
                            <option value="">Select Category</option>
                            <option value="Complaint">Complaint</option>
                            <option value="Suggestion">Suggestion</option>
                            <option value="Appreciation">Appreciation</option>
                            <option value="Question">Question</option>
                            <option value="Report">Report</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Feedback Concern by Sector/Subsector *</label>
                        <textarea name="feedback_concern" class="form-control" rows="3" required
                                  placeholder="Describe the sector or subsector concern..."></textarea>
                    </div>
                </div>

                <!-- Feedback Management -->
                <div class="form-section">
                    <h3><i class="fas fa-tasks"></i> Feedback Management</h3>
                    <div class="form-group">
                        <label class="form-label">Feedback Status *</label>
                        <select name="feedback_status" class="form-control form-select" required
                                onchange="updateAIRecommendation()">
                            <option value="New">New</option>
                            <option value="Under Review">Under Review</option>
                            <option value="Action Taken">Action Taken</option>
                            <option value="Resolved">Resolved</option>
                            <option value="Closed">Closed</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Actions Taken</label>
                        <textarea name="actions_taken" class="form-control" rows="3"
                                  placeholder="Describe actions taken to address the feedback..."></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Responsibility for Follow Up</label>
                        <input type="text" name="responsibility_follow_up" class="form-control"
                               placeholder="Person/department responsible">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Expected Feedback Closure Date</label>
                        <input type="date" name="expected_closure_date" class="form-control"
                               min="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Reason if Closure Date Passed</label>
                        <textarea name="reason_closure_passed" class="form-control" rows="2"
                                  placeholder="Reason for delay..."></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Recommendation (MEAL/Program Dept)</label>
                        <textarea name="recommendation" id="recommendation_field" class="form-control" rows="3"
                                  placeholder="Recommendations from MEAL or Program department..."></textarea>
                    </div>
                </div>
            </div>

            <!-- AI Recommendation Preview -->
            <div class="ai-recommendation" id="aiRecommendationPreview">
                <h4><i class="fas fa-robot"></i> AI-Powered Recommendation Preview</h4>
                <p id="aiRecommendationText">Fill in the form to see AI-generated recommendations for MEAL/Program Dept...</p>
            </div>

            <div class="btn-group no-print">
                <input type="hidden" name="report_id" id="report_id" value="">

                <button type="submit" name="add_cfm" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save CFM Report
                </button>
                <button type="submit" name="update_cfm" id="btnUpdateCFM" class="btn btn-success" style="display:none;">
                    <i class="fas fa-save"></i> Update CFM Report
                </button>
                <button type="button" id="btnCancelEditCFM" class="btn btn-secondary" style="display:none;" onclick="cancelEditCFM()">
                    <i class="fas fa-times"></i> Cancel Edit
                </button>
                <button type="reset" class="btn btn-warning">
                    <i class="fas fa-redo"></i> Reset Form
                </button>
                <button type="button" onclick="window.print()" class="btn btn-secondary">
                    <i class="fas fa-print"></i> Print Form
                </button>
                <button type="button" onclick="generateAIRecommendationForMEAL()" class="btn btn-info">
                    <i class="fas fa-magic"></i> Generate AI Recommendation for MEAL
                </button>
            </div>
        </form>
    </div>

    <!-- IMPORT / EXPORT -->
    <div class="import-export-section no-print">
        <h3 style="color: var(--primary); margin-bottom: 20px; text-align: center;">
            <i class="fas fa-file-import"></i> 📁 Import/Export CFM Reports
        </h3>
        <div class="form-grid">
            <div class="form-section">
                <h4><i class="fas fa-upload"></i> Import from Excel/CSV</h4>
                <form method="POST" enctype="multipart/form-data" id="importForm">
                    <div class="form-group">
                        <label class="form-label">Select File</label>
                        <input type="file" name="import_file" class="file-input" accept=".xlsx,.xls,.csv" required>
                        <small style="display: block; margin-top: 5px; color: #6b7280;">
                            Supported formats: Excel (.xlsx, .xls*) or CSV (.csv).
                            <br>*For .xls please convert to .xlsx or .csv.
                        </small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Download Template</label>
                        <div>
                            <button type="button" onclick="downloadCSVTemplate()" class="btn btn-info">
                                <i class="fas fa-download"></i> Download CSV Template
                            </button>
                        </div>
                    </div>
                    <button type="submit" name="import_cfm" class="btn btn-info">
                        <i class="fas fa-file-import"></i> Import Data
                    </button>
                </form>
            </div>
            <div class="form-section">
                <h4><i class="fas fa-download"></i> Export Reports</h4>
                <div class="btn-group" style="justify-content: flex-start;">
                    <button onclick="exportToPDF()" class="btn btn-danger">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </button>
                    <button onclick="exportToExcel()" class="btn btn-success">
                        <i class="fas fa-file-excel"></i> Export Excel/CSV
                    </button>
                </div>
            </div>
            <div class="form-section">
                <h4><i class="fas fa-share-alt"></i> Share Reports</h4>
                <div class="btn-group" style="justify-content: flex-start;">
                    <button onclick="shareViaEmail()" class="btn btn-info">
                        <i class="fas fa-envelope"></i> Email
                    </button>
                    <button onclick="shareViaTelegram()" class="btn btn-info">
                        <i class="fab fa-telegram"></i> Telegram
                    </button>
                    <button onclick="shareViaWhatsApp()" class="btn btn-success">
                        <i class="fab fa-whatsapp"></i> WhatsApp
                    </button>
                </div>
            </div>
        </div>
    </div>

    
    <!-- DATA MANAGEMENT (CLEAR) -->
    <div class="import-export-section no-print" style="border-left: 6px solid #dc2626;">
        <h3 style="color: #b91c1c; margin-bottom: 10px; text-align: center;">
            <i class="fas fa-triangle-exclamation"></i> 🧹 Clear CFM Data (for Safe Project Deletion)
        </h3>
        <p style="margin: 0 0 18px; color: #6b7280; text-align:center;">
            If a project cannot be deleted because CFM records exist, use this tool to clear CFM records linked to that project.
        </p>

        <div class="form-grid">
            <div class="form-section">
                <h4><i class="fas fa-broom"></i> Clear by Project</h4>
                <form method="POST" onsubmit="return confirm('This will DELETE ALL CFM records for the selected project. Continue?');">
                    <div class="form-group">
                        <label class="form-label">Select Project *</label>
                        <select name="clear_project_id" class="form-control" required>
                            <option value="">-- Select project --</option>
                            <?php foreach ($projects as $p): ?>
                                <option value="<?php echo (int)$p['id']; ?>">
                                    <?php echo h($p['title'] ?? $p['name'] ?? ('Project #' . $p['id'])); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" name="clear_project_cfm" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Clear Project CFM Records
                    </button>
                </form>
            </div>

            <div class="form-section">
                <h4><i class="fas fa-skull-crossbones"></i> Clear ALL CFM Records</h4>
                <p style="color:#6b7280; margin-top:-6px;">
                    Use only if you want to remove ALL CFM records from the system.
                </p>
                <form method="POST" onsubmit="return confirm('DANGER: This will DELETE ALL CFM records in the database. Continue?');">
                    <button type="submit" name="clear_all_cfm" class="btn btn-danger">
                        <i class="fas fa-trash-can"></i> Clear ALL CFM Records
                    </button>
                </form>
            </div>

            <div class="form-section">
                <h4><i class="fas fa-file-csv"></i> Export Full Database CSV</h4>
                <p style="color:#6b7280; margin-top:-6px;">
                    Exports directly from the database (all records, not only visible rows).
                </p>
                <button type="button" onclick="exportFullCFMCSV()" class="btn btn-success">
                    <i class="fas fa-file-excel"></i> Export Full CSV (DB)
                </button>
            </div>
        </div>
    </div>

    <!-- STATS OVERVIEW -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number"><?php echo $stats['total']; ?></div>
            <div class="stat-label">Total Reports</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo $stats['by_status']['New'] ?? 0; ?></div>
            <div class="stat-label">New Reports</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo $stats['by_status']['Resolved'] ?? 0; ?></div>
            <div class="stat-label">Resolved</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo $stats['pending_over_30_days']; ?></div>
            <div class="stat-label">Pending >30 Days</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo $stats['avg_resolution_time']; ?>d</div>
            <div class="stat-label">Avg Resolution Time</div>
        </div>
    </div>

    <!-- ANALYTICS DASHBOARD -->
    <div class="cfm-container">
        <h2 style="color: var(--primary); margin-bottom: 25px; display: flex; align-items: center; gap: 12px;">
            <i class="fas fa-chart-bar"></i> 📈 CFM Analytics Dashboard
        </h2>

        <div class="dashboard-grid">
            <div class="dashboard-card">
                <h3>Feedback by Status</h3>
                <div class="chart-container"><canvas id="statusChart"></canvas></div>
            </div>
            <div class="dashboard-card">
                <h3>Feedback by Category</h3>
                <div class="chart-container"><canvas id="categoryChart"></canvas></div>
            </div>
            <div class="dashboard-card">
                <h3>Feedback by Channel</h3>
                <div class="chart-container"><canvas id="channelChart"></canvas></div>
            </div>
            <div class="dashboard-card">
                <h3>Regional Distribution</h3>
                <div class="chart-container"><canvas id="regionChart"></canvas></div>
            </div>
            <div class="dashboard-card">
                <h3>Feedback by Gender</h3>
                <div class="chart-container"><canvas id="genderChart"></canvas></div>
            </div>
            <div class="dashboard-card">
                <h3>Feedback by Vulnerability</h3>
                <div class="chart-container"><canvas id="vulnerabilityChart"></canvas></div>
            </div>
            <div class="dashboard-card">
                <h3>Feedback by Community Type</h3>
                <div class="chart-container"><canvas id="communityChart"></canvas></div>
            </div>
            <div class="dashboard-card">
                <h3>Age Distribution (10-year intervals)</h3>
                <div class="chart-container"><canvas id="ageChart"></canvas></div>
            </div>
            <div class="dashboard-card">
                <h3>Feedback by Project</h3>
                <div class="chart-container"><canvas id="projectChart"></canvas></div>
            </div>
        </div>
    </div>

    <!-- CFM REPORTS TABLE -->
    <div class="cfm-container">
        <h2 style="color: var(--primary); margin-bottom: 25px; display: flex; align-items: center; gap: 12px;">
            <i class="fas fa-table"></i> 📊 CFM Reports Overview
        </h2>

        <?php if (empty($cfm_reports)): ?>
            <div style="text-align: center; padding: 40px; background: #f8fafc; border-radius: 12px;">
                <i class="fas fa-inbox" style="font-size: 3rem; color: #9ca3af; margin-bottom: 15px;"></i>
                <h3 style="color: #6b7280; margin-bottom: 10px;">No CFM Reports Yet</h3>
                <p style="color: #9ca3af;">Start by adding your first complaint feedback mechanism report using the form above.</p>
            </div>
        <?php else: ?>
            <div class="table-container">
                <table class="cfm-table">
                    <thead>
                    <tr>
                        <th>Project</th>
                        <th>Reported By</th>
                        <th>Position</th>
                        <th>Date Received</th>
                        <th>Region</th>
                        <th>Zone</th>
                        <th>Woreda</th>
                        <th>Gender</th>
                        <th>Age</th>
                        <th>Community Type</th>
                        <th>Vulnerability</th>
                        <th>Feedback Preview</th>
                        <th>Category</th>
                        <th>Status</th>
                        <th>Days Open</th>
                        <th>Expected Closure</th>
                        <th>Days (Received → Expected Close)</th>
                        <th>Data Quality</th>
                        <th>AI Recommendation</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($cfm_reports as $report):
                        $days_open    = calculateDaysOpen($report['date_feedback_received']);
                        $duration     = formatDaysDuration($days_open);
                        $quality_score= calculateDataQualityScore($report);
                        $quality_class= 'quality-high';
                        if ($quality_score < 70) $quality_class = 'quality-medium';
                        if ($quality_score < 50) $quality_class = 'quality-low';

                        $days_to_close = null;
                        if (!empty($report['expected_closure_date'])) {
                            $days_to_close = calculateDaysToClose($report['date_feedback_received'], $report['expected_closure_date']);
                        }
                    ?>
                        <tr>
                            <td><strong><?php echo h($report['project_title'] ?? 'N/A'); ?></strong></td>
                            <td><?php echo h($report['reported_by']); ?></td>
                            <td><?php echo h($report['position']); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($report['date_feedback_received'])); ?></td>
                            <td><?php echo h($report['region_name'] ?? 'N/A'); ?></td>
                            <td><?php echo h($report['zone_name'] ?? 'N/A'); ?></td>
                            <td><?php echo h($report['woreda_name'] ?? 'N/A'); ?></td>
                            <td><?php echo h($report['gender'] ?? 'N/A'); ?></td>
                            <td><?php echo h($report['age'] ?? ''); ?></td>
                            <td><?php echo h($report['community_type'] ?? ''); ?></td>
                            <td><?php echo h($report['vulnerability'] ?? ''); ?></td>
                            <td title="<?php echo h($report['actual_feedback']); ?>">
                                <?php echo substr(h($report['actual_feedback']), 0, 50) . '...'; ?>
                            </td>
                            <td><?php echo h($report['feedback_category']); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $report['feedback_status'])); ?>">
                                    <?php echo h($report['feedback_status']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="<?php echo $days_open > 30 ? 'text-warning' : ''; ?>">
                                    <?php echo $duration; ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                echo !empty($report['expected_closure_date'])
                                    ? date('d/m/Y', strtotime($report['expected_closure_date']))
                                    : 'N/A';
                                ?>
                            </td>
                            <td>
                                <?php echo $days_to_close !== null ? $days_to_close . ' days' : 'N/A'; ?>
                            </td>
                            <td>
                                <span class="data-quality-badge <?php echo $quality_class; ?>"
                                      title="Data Quality Score: <?php echo $quality_score; ?>%">
                                    <?php echo $quality_score; ?>%
                                </span>
                            </td>
                            <td title="<?php echo h($report['ai_recommendation'] ?? 'No AI recommendation'); ?>">
                                <?php
                                $ai_preview = $report['ai_recommendation'] ?? '';
                                $short = substr(h($ai_preview), 0, 30);
                                echo $short . (strlen($ai_preview) > 30 ? '...' : '');
                                ?>
                            </td>
                            <td>
                                <div class="action-buttons no-print">
                                    <button onclick="editReport(<?php echo $report['id']; ?>)" class="action-btn edit-btn" title="Edit Report">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button onclick="viewReport(<?php echo $report['id']; ?>)" class="action-btn view-btn" title="View Details">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <form method="POST" style="display:inline;"
                                          onsubmit="return confirm('Are you sure you want to delete this report?')">
                                        <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                        <button type="submit" name="delete_cfm" class="action-btn delete-btn" title="Delete Report">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    // RAW STATS FROM PHP
    const stats = {
        by_status: <?php echo json_encode($stats['by_status']); ?>,
        by_category: <?php echo json_encode($stats['by_category']); ?>,
        by_channel: <?php echo json_encode($stats['by_channel']); ?>,
        by_region: <?php echo json_encode($stats['by_region']); ?>,
        by_gender: <?php echo json_encode($stats['by_gender']); ?>,
        by_vulnerability: <?php echo json_encode($stats['by_vulnerability']); ?>,
        by_community_type: <?php echo json_encode($stats['by_community_type']); ?>,
        by_age_group: <?php echo json_encode($stats['by_age_group']); ?>,
        by_project: <?php echo json_encode($stats['by_project']); ?>
    };

    let statusChart, categoryChart, channelChart, regionChart,
        genderChart, vulnerabilityChart, communityChart, ageChart, projectChart;

    function initializeCharts() {
        if (typeof Chart === 'undefined') {
            console.warn('Chart.js not loaded; charts will not be displayed.');
            return;
        }

        // Status
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        statusChart = new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: Object.keys(stats.by_status),
                datasets: [{
                    data: Object.values(stats.by_status),
                    backgroundColor: ['#4361ee', '#4cc9f0', '#f72585', '#7209b7', '#3a0ca3']
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
        });

        // Category
        const categoryCtx = document.getElementById('categoryChart').getContext('2d');
        categoryChart = new Chart(categoryCtx, {
            type: 'pie',
            data: {
                labels: Object.keys(stats.by_category),
                datasets: [{
                    data: Object.values(stats.by_category),
                    backgroundColor: ['#4361ee', '#4cc9f0', '#f72585', '#7209b7', '#3a0ca3']
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
        });

        // Channel
        const channelCtx = document.getElementById('channelChart').getContext('2d');
        channelChart = new Chart(channelCtx, {
            type: 'bar',
            data: {
                labels: Object.keys(stats.by_channel),
                datasets: [{
                    label: 'Feedback Count',
                    data: Object.values(stats.by_channel),
                    backgroundColor: '#4361ee'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: { y: { beginAtZero: true } }
            }
        });

        // Region
        const regionCtx = document.getElementById('regionChart').getContext('2d');
        regionChart = new Chart(regionCtx, {
            type: 'bar',
            data: {
                labels: Object.keys(stats.by_region),
                datasets: [{
                    label: 'Reports by Region',
                    data: Object.values(stats.by_region),
                    backgroundColor: '#4cc9f0'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                scales: { x: { beginAtZero: true } }
            }
        });

        // Gender
        const genderCtx = document.getElementById('genderChart').getContext('2d');
        genderChart = new Chart(genderCtx, {
            type: 'pie',
            data: {
                labels: Object.keys(stats.by_gender),
                datasets: [{
                    data: Object.values(stats.by_gender),
                    backgroundColor: ['#4361ee', '#f72585', '#4cc9f0']
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
        });

        // Vulnerability
        const vulnerabilityCtx = document.getElementById('vulnerabilityChart').getContext('2d');
        vulnerabilityChart = new Chart(vulnerabilityCtx, {
            type: 'bar',
            data: {
                labels: Object.keys(stats.by_vulnerability),
                datasets: [{
                    label: 'By Vulnerability',
                    data: Object.values(stats.by_vulnerability),
                    backgroundColor: '#f72585'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: { y: { beginAtZero: true } }
            }
        });

        // Community type
        const communityCtx = document.getElementById('communityChart').getContext('2d');
        communityChart = new Chart(communityCtx, {
            type: 'bar',
            data: {
                labels: Object.keys(stats.by_community_type),
                datasets: [{
                    label: 'By Community Type',
                    data: Object.values(stats.by_community_type),
                    backgroundColor: '#ffb703'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: { y: { beginAtZero: true } }
            }
        });

        // Age
        const ageCtx = document.getElementById('ageChart').getContext('2d');
        ageChart = new Chart(ageCtx, {
            type: 'bar',
            data: {
                labels: Object.keys(stats.by_age_group),
                datasets: [{
                    label: 'By Age Group',
                    data: Object.values(stats.by_age_group),
                    backgroundColor: '#3a86ff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: { y: { beginAtZero: true } }
            }
        });

        // Project
        const projectCtx = document.getElementById('projectChart').getContext('2d');
        projectChart = new Chart(projectCtx, {
            type: 'bar',
            data: {
                labels: Object.keys(stats.by_project),
                datasets: [{
                    label: 'By Project',
                    data: Object.values(stats.by_project),
                    backgroundColor: '#52b788'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                scales: { x: { beginAtZero: true } }
            }
        });
    }

    // DATE / DAYS CALC
    const receivedDateInput = document.querySelector('input[name="date_feedback_received"]');
    const reportDateInput   = document.querySelector('input[name="date_of_report"]');
    const daysCalculation   = document.getElementById('days_calculation');

    function updateDaysCalculation() {
        if (!receivedDateInput || !reportDateInput || !daysCalculation) return;

        const received = receivedDateInput.value;
        const reported = reportDateInput.value;

        if (received && reported) {
            const receivedDate = new Date(received);
            const reportedDate = new Date(reported);

            if (receivedDate > reportedDate) {
                daysCalculation.value = 'Invalid: Received date after report date';
                daysCalculation.style.color = 'red';
                return;
            }

            const diffTime  = Math.abs(reportedDate - receivedDate);
            const diffDays  = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            let durationText;

            if (diffDays < 29) {
                durationText = `${diffDays} days`;
            } else if (diffDays === 30) {
                durationText = '1 month';
            } else {
                const months = Math.floor(diffDays / 30);
                const remainingDays = diffDays % 30;
                if (remainingDays > 0) {
                    durationText = `${months} month${months > 1 ? 's' : ''} ${remainingDays} days`;
                } else {
                    durationText = `${months} month${months > 1 ? 's' : ''}`;
                }
            }

            daysCalculation.value = durationText;
            daysCalculation.style.color = 'green';
        } else {
            daysCalculation.value = '';
            daysCalculation.style.color = '';
        }
    }

    if (receivedDateInput && reportDateInput) {
        ['change', 'input'].forEach(evt => {
            receivedDateInput.addEventListener(evt, updateDaysCalculation);
            reportDateInput.addEventListener(evt, updateDaysCalculation);
        });
    }

    // AI RECOMMENDATION (JS preview)
    function generateAIRecommendationJS() {
        const feedback     = (document.querySelector('textarea[name="actual_feedback"]').value || '').toLowerCase();
        const category     = document.querySelector('select[name="feedback_category"]').value;
        const status       = document.querySelector('select[name="feedback_status"]').value;
        const channel      = document.querySelector('select[name="feedback_channel"]').value;
        const vulnerability= document.querySelector('select[name="vulnerability"]').value;

        let recommendations = [];
        let urgency_level   = "Medium";

        const negative_words = ['bad','poor','terrible','awful','horrible','failed','broken','wrong',
            'problem','issue','complaint','angry','frustrated'];
        const positive_words = ['good','great','excellent','wonderful','amazing','happy','satisfied','thank','appreciate'];
        const emergency_words= ['urgent','emergency','immediate','critical','death','die','danger','unsafe'];

        let negative_count = 0, positive_count = 0, emergency_count = 0;

        negative_words.forEach(w => { if (feedback.includes(w)) negative_count++; });
        positive_words.forEach(w => { if (feedback.includes(w)) positive_count++; });
        emergency_words.forEach(w => { if (feedback.includes(w)) emergency_count++; });

        if (emergency_count > 0) urgency_level = "Critical";
        else if (negative_count > positive_count) urgency_level = "High";
        else if (positive_count > negative_count) urgency_level = "Low";

        if (feedback.includes('delay') || feedback.includes('late') || feedback.includes('waiting')) {
            recommendations.push("⏰ Urgent follow-up required for timely resolution");
            if (feedback.includes('medicine') || feedback.includes('treatment')) {
                recommendations.push("💊 Medical delay detected - escalate to health department immediately");
            }
        }
        if (feedback.includes('quality') || feedback.includes('poor') || feedback.includes('bad')) {
            recommendations.push("🔍 Quality assurance team should investigate and provide corrective measures");
        }
        if (feedback.includes('payment') || feedback.includes('money') || feedback.includes('cash')) {
            recommendations.push("💰 Finance department involvement recommended for resolution");
            urgency_level = "High";
        }
        if (feedback.includes('safety') || feedback.includes('danger') || feedback.includes('unsafe')) {
            recommendations.push("🛡️ Immediate safety assessment required");
            urgency_level = "High";
        }

        switch (category) {
            case 'Complaint':
                recommendations.push("📋 Immediate acknowledgment and investigation needed");
                if (urgency_level === "Medium") urgency_level = "High";
                break;
            case 'Suggestion':
                recommendations.push("💡 Review for potential implementation in program improvement");
                urgency_level = "Low";
                break;
            case 'Appreciation':
                recommendations.push("⭐ Share positive feedback with relevant team for morale boosting");
                urgency_level = "Low";
                break;
            case 'Question':
                recommendations.push("❓ Provide clear and timely response within 48 hours");
                break;
        }

        if (status === 'New') {
            recommendations.push("🆕 Assign to relevant department within 24 hours");
        } else if (status === 'Under Review') {
            recommendations.push("🔍 Set clear timeline for resolution and communicate to complainant");
        } else if (status === 'Action Taken') {
            recommendations.push("✅ Verify effectiveness of actions and follow up with complainant");
        }

        if (channel === 'Hotline') {
            recommendations.push("📞 Ensure callback mechanism is in place for follow-up");
        } else if (channel === 'Community Meeting') {
            recommendations.push("👥 Document in community meeting minutes and share action plan");
        }

        if (vulnerability === 'Child') {
            recommendations.push("👶 Child protection protocols must be followed");
            urgency_level = "High";
        } else if (vulnerability === 'Disability') {
            recommendations.push("♿ Ensure accessibility and reasonable accommodation");
        } else if (vulnerability === 'Pregnant') {
            recommendations.push("🤰 Pregnant woman - prioritize maternal health services");
            urgency_level = "High";
        }

        recommendations = [...new Set(recommendations)];
        if (!recommendations.length) {
            recommendations.push("📊 Standard monitoring and evaluation process to be followed");
        }

        let icon = "🟡";
        if (urgency_level === "High") icon = "🟠";
        if (urgency_level === "Critical") icon = "🔴";

        return `${icon} **${urgency_level} Priority**\n\n🤖 AI Recommendations for MEAL/Program Dept:\n• ${recommendations.join("\n• ")}`;
    }

    function updateAIRecommendation() {
        const text = generateAIRecommendationJS();
        document.getElementById('aiRecommendationText').textContent = text;
    }

    function generateAIRecommendationForMEAL() {
        const text = generateAIRecommendationJS();
        document.getElementById('recommendation_field').value = text;
        updateAIRecommendation();
    }

    // EXPORTS
    function exportToPDF() {
        const originalTitle = document.title;
        document.title = "CFM_Reports_" + new Date().toISOString().split('T')[0];
        window.print();
        document.title = originalTitle;
    }

    function exportToExcel() {
        // Prefer server-side export (full DB) for reliability
        window.location.href = 'cfm.php?export=csv';
        return;

        const table = document.querySelector('.cfm-table');
        if (!table) {
            alert('No data to export');
            return;
        }

        let csv = [];

        // Optional first row: same header info as print header for Excel/CSV
        csv.push([
            "Project Title", "Region", "Zone", "Woreda", "Month of Report"
        ].join(','));
        csv.push([
            <?php echo json_encode($printProjectTitle); ?>,
            <?php echo json_encode($printRegion); ?>,
            <?php echo json_encode($printZone); ?>,
            <?php echo json_encode($printWoreda); ?>,
            <?php echo json_encode($printMonthLabel); ?>
        ].map(v => `"${(v || '').replace(/"/g, '""')}"`).join(','));

        // Blank row
        csv.push('');

        const headers = [];
        table.querySelectorAll('thead th').forEach(th => {
            headers.push(th.textContent.trim());
        });
        csv.push(headers.join(','));

        table.querySelectorAll('tbody tr').forEach(tr => {
            const row = [];
            tr.querySelectorAll('td').forEach(td => {
                let text = td.textContent.trim();
                text = text.replace(/\s+/g, ' ').replace(/"/g, '""');
                row.push(`"${text}"`);
            });
            csv.push(row.join(','));
        });

        const csvContent = "data:text/csv;charset=utf-8," + csv.join('\n');
        const encodedUri = encodeURI(csvContent);
        const link = document.createElement("a");
        link.setAttribute("href", encodedUri);
        link.setAttribute("download", "cfm_reports_" + new Date().toISOString().split('T')[0] + ".csv");
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    // Template
    function downloadCSVTemplate() {
        const headers = [
            'project_id','reported_by','position','date_feedback_received','date_of_report',
            'feedback_type','organization','region_id','zone_name','woreda_name','gender','age',
            'community_type','vulnerability','language','actual_feedback','feedback_channel',
            'feedback_category','feedback_concern','feedback_status','actions_taken',
            'responsibility_follow_up','expected_closure_date','reason_closure_passed','recommendation'
        ];
        const csvContent = "data:text/csv;charset=utf-8," + headers.join(',');
        const encodedUri = encodeURI(csvContent);
        const link = document.createElement("a");
        link.setAttribute("href", encodedUri);
        link.setAttribute("download", "cfm_template.csv");
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    // Data Quality view (ensure correct relative path; data_quality.php is usually one level up)
    function viewDataQuality() {
        window.open('../data_quality.php?source=cfm', '_blank');
    }

    // Share
    function shareViaEmail() {
        const subject = encodeURIComponent('Nexus Ethiopia CFM Reports');
        const body = encodeURIComponent('Please find attached the CFM reports from our system.');
        window.location.href = `mailto:?subject=${subject}&body=${body}`;
    }
    function shareViaTelegram() {
        const text = encodeURIComponent('Check out the Nexus Ethiopia CFM Reports');
        window.open(`https://t.me/share/url?url=${encodeURIComponent(window.location.href)}&text=${text}`, '_blank');
    }
    function shareViaWhatsApp() {
        const text = encodeURIComponent('Check out the Nexus Ethiopia CFM Reports: ' + window.location.href);
        window.open(`https://wa.me/?text=${text}`, '_blank');
    }

    // Edit / View – assumes cfm_edit.php and cfm_view.php are in same folder as cfm.php
    
    // -------------------------------------------------------------------------
    // CFM UPGRADE: Inline Edit (no separate pages). Loads record, fills form, updates.
    // -------------------------------------------------------------------------
    function _setField(name, value) {
        const el = document.querySelector(`[name="${name}"]`);
        if (!el) return;

        const v = (value === null || value === undefined) ? '' : String(value);

        if (el.type === 'checkbox') {
            el.checked = !!value;
        } else {
            el.value = v;
        }

        // Trigger change so dependent logic updates
        el.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function _toggleEditMode(isEdit) {
        const addBtn = document.querySelector('button[name="add_cfm"]');
        const updBtn = document.getElementById('btnUpdateCFM');
        const cancelBtn = document.getElementById('btnCancelEditCFM');
        if (!addBtn || !updBtn || !cancelBtn) return;

        if (isEdit) {
            addBtn.style.display = 'none';
            updBtn.style.display = 'inline-flex';
            cancelBtn.style.display = 'inline-flex';
        } else {
            addBtn.style.display = 'inline-flex';
            updBtn.style.display = 'none';
            cancelBtn.style.display = 'none';
            const rid = document.getElementById('report_id');
            if (rid) rid.value = '';
        }
    }

    async function editReport(id) {
        if (!confirm('Edit this CFM report? This will load the saved data back into the form for update.')) return;

        try {
            const res = await fetch(`cfm.php?ajax=get_report&id=${encodeURIComponent(id)}`, { credentials: 'same-origin' });
            const json = await res.json();
            if (!json || !json.ok) throw new Error((json && json.error) ? json.error : 'Failed to load');

            const d = json.data || {};
            _setField('project_id', d.project_id);
            _setField('reported_by', d.reported_by);
            _setField('position', d.position);
            _setField('date_feedback_received', d.date_feedback_received);
            _setField('date_of_report', d.date_of_report);
            _setField('feedback_type', d.feedback_type);
            _setField('organization', d.organization);
            _setField('region_id', d.region_id);
            _setField('zone_name', d.zone_name);
            _setField('woreda_name', d.woreda_name);
            _setField('gender', d.gender);
            _setField('age', d.age);
            _setField('community_type', d.community_type);
            _setField('vulnerability', d.vulnerability);
            _setField('language', d.language);
            _setField('actual_feedback', d.actual_feedback);
            _setField('feedback_channel', d.feedback_channel);
            _setField('feedback_category', d.feedback_category);
            _setField('feedback_concern', d.feedback_concern);
            _setField('feedback_status', d.feedback_status);
            _setField('actions_taken', d.actions_taken);
            _setField('responsibility_follow_up', d.responsibility_follow_up);
            _setField('expected_closure_date', d.expected_closure_date);
            _setField('reason_closure_passed', d.reason_closure_passed);
            _setField('recommendation', d.recommendation);

            const rid = document.getElementById('report_id');
            if (rid) rid.value = d.id || id;

            _toggleEditMode(true);

            const topEl = document.getElementById('cfmForm') || document.querySelector('.cfm-container');
            if (topEl) topEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (e) {
            alert('Could not load report for editing: ' + (e && e.message ? e.message : e));
        }
    }

    function viewReport(id) {
        window.open(`cfm.php?print=1&view_id=${encodeURIComponent(id)}`, '_blank');
    }

    function cancelEditCFM() {
        const form = document.getElementById('cfmForm');
        if (form) form.reset();
        _toggleEditMode(false);
    }

    function exportFullCFMCSV() {
        window.location.href = 'cfm.php?export=csv';
    }


    // Basic required validation
    document.getElementById('cfmForm').addEventListener('submit', function(e) {
        const requiredFields = this.querySelectorAll('[required]');
        let valid = true;
        requiredFields.forEach(field => {
            if (!field.value.trim()) {
                valid = false;
                field.style.borderColor = '#ef4444';
            } else {
                field.style.borderColor = '';
            }
        });
        if (!valid) {
            e.preventDefault();
            alert('Please fill in all required fields marked with *');
        }
    });

    document.addEventListener('DOMContentLoaded', function() {
        const today = new Date().toISOString().split('T')[0];
        if (receivedDateInput) receivedDateInput.max = today;
        if (reportDateInput) {
            reportDateInput.max = today;
            if (!reportDateInput.value) {
                reportDateInput.value = today;
            }
        }
        
        // Handle "other" option for region/zone/woreda
        const regionSelect = document.getElementById('region_id');
        const regionOther = document.getElementById('region_other');
        const zoneSelect = document.getElementById('zone_id');
        const zoneOther = document.getElementById('zone_other');
        const woredaSelect = document.getElementById('woreda_id');
        const woredaOther = document.getElementById('woreda_other');
        
        if (regionSelect && regionOther) {
            regionSelect.addEventListener('change', function() {
                if (this.value === 'other') {
                    regionOther.style.display = 'block';
                    regionOther.required = true;
                } else {
                    regionOther.style.display = 'none';
                    regionOther.required = false;
                    regionOther.value = '';
                }
            });
            // Trigger on load if "other" is selected
            if (regionSelect.value === 'other') {
                regionOther.style.display = 'block';
                regionOther.required = true;
            }
        }
        
        if (zoneSelect && zoneOther) {
            zoneSelect.addEventListener('change', function() {
                if (this.value === 'other') {
                    zoneOther.style.display = 'block';
                } else {
                    zoneOther.style.display = 'none';
                    zoneOther.value = '';
                }
            });
            if (zoneSelect.value === 'other') {
                zoneOther.style.display = 'block';
            }
        }
        
        if (woredaSelect && woredaOther) {
            woredaSelect.addEventListener('change', function() {
                if (this.value === 'other') {
                    woredaOther.style.display = 'block';
                } else {
                    woredaOther.style.display = 'none';
                    woredaOther.value = '';
                }
            });
            if (woredaSelect.value === 'other') {
                woredaOther.style.display = 'block';
            }
        }
        updateDaysCalculation();
        initializeCharts();

        const aiInputs = document.querySelectorAll(
            'select[name="feedback_category"], select[name="feedback_status"], select[name="feedback_channel"], select[name="vulnerability"]'
        );
        aiInputs.forEach(input => input.addEventListener('change', updateAIRecommendation));
    });
</script>
</body>
</html>
