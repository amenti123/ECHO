<?php
// File: health_reporting_system/pages/project_activity_report.php

// Correct root dir for header/footer (same pattern as other pages)
$root_dir = dirname(__DIR__);
require_once __DIR__ . '/_preflight.php';
// header is included AFTER action handling to keep AJAX JSON clean
// require_once $root_dir . '/header.php';
require_login();

$pdo      = getPDO();
if (function_exists('hrs_ensure_app_database')) { hrs_ensure_app_database($pdo); }
$message  = '';
$errorMsg = '';


// -----------------------------------------------------------
// Controller: handle POST actions BEFORE loading header.php
// -----------------------------------------------------------
$data   = $_POST ?? [];
$action = (string)($data['action'] ?? '');

$project_id = (int)($data['project_id'] ?? ($_GET['project_id'] ?? 0));
if ($project_id <= 0 && function_exists('hrs_selected_project_id')) {
    $project_id = (int)hrs_selected_project_id();
}
if ($project_id > 0 && function_exists('hrs_set_selected_project_id')) {
    hrs_set_selected_project_id($project_id);
}

if (!function_exists('hrs_table_exists')) {
    function hrs_table_exists(PDO $pdo, string $table): bool {
        try {
            $st = $pdo->prepare("SHOW TABLES LIKE ?");
            $st->execute([$table]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) { return false; }
    }
}

if (!function_exists('hrs_get_project_details')) {
    function hrs_get_project_details(int $projectId): array {
        try {
            if (function_exists('get_project_with_locations')) {
                $p = get_project_with_locations($projectId);
                if ($p) return ['success'=>true,'project'=>$p,'message'=>'OK'];
            }
            if (function_exists('db_get_project_by_id')) {
                $p = db_get_project_by_id(getPDO(), $projectId);
                if ($p) return ['success'=>true,'project'=>$p,'message'=>'OK'];
            }
            return ['success'=>false,'project'=>null,'message'=>'Project not found'];
        } catch (Throwable $e) {
            return ['success'=>false,'project'=>null,'message'=>$e->getMessage()];
        }
    }
}

// AJAX: planned activities for a project (used to auto-populate report form)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $action === 'get_planned_activities') {
    $activities = [];
    if ($project_id > 0) {
        try {
            // Prefer planning_activities if present; fallback to activities / project_activities
            if (hrs_table_exists($pdo, 'planning_activities')) {
                $st = $pdo->prepare("SELECT * FROM planning_activities WHERE project_id = ? ORDER BY id ASC");
                $st->execute([$project_id]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $activities[] = [
                        'result_level' => 'output',
                        'project_activity' => $r['activity_name'] ?? ($r['name'] ?? ''),
                        'unit_of_measurement' => $r['unit_type'] ?? ($r['unit'] ?? ''),
                        'beneficiary_type' => '',
                        'target_value' => (float)($r['target_total'] ?? ($r['target'] ?? 0)),
                        'lock_type' => true
                    ];
                }
            } elseif (hrs_table_exists($pdo, 'activities')) {
                $st = $pdo->prepare("SELECT a.*, rc.level as result_level FROM activities a 
                                     LEFT JOIN results_chain rc ON rc.id = a.output_id
                                     WHERE a.project_id = ? ORDER BY a.id ASC");
                $st->execute([$project_id]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $activities[] = [
                        'result_level' => $r['result_level'] ?? 'output',
                        'project_activity' => $r['name'] ?? '',
                        'unit_of_measurement' => $r['unit'] ?? '',
                        'beneficiary_type' => '',
                        'target_value' => (float)($r['target'] ?? 0),
                        'lock_type' => true
                    ];
                }
            } elseif (hrs_table_exists($pdo, 'project_activities')) {
                $st = $pdo->prepare("SELECT * FROM project_activities WHERE project_id = ? ORDER BY id ASC");
                $st->execute([$project_id]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $activities[] = [
                        'result_level' => 'output',
                        'project_activity' => $r['activity_name'] ?? ($r['name'] ?? ''),
                        'unit_of_measurement' => $r['unit'] ?? '',
                        'beneficiary_type' => '',
                        'target_value' => (float)($r['target'] ?? 0),
                        'lock_type' => true
                    ];
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>true,'activities'=>$activities,'message'=>$activities?'Loaded.':'No planned activities found.']);
    exit;
}

// Save report
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $action === 'save_report') {
    $res = ['success'=>false,'message'=>'Unable to save report'];
    try {
        $res = save_activity_report($data, (int)$current_user_id);
    } catch (Throwable $e) {
        $res = ['success'=>false,'message'=>$e->getMessage()];
    }
    if (($res['success'] ?? false) && isset($res['report_id'])) {
        // redirect to view page
        safe_redirect('project_activity_report.php?report_id=' . (int)$res['report_id']);
        exit;
    }
    $errorMsg = $res['message'] ?? 'Save failed';
}

// Delete report
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $action === 'delete_report') {
    $rid = (int)($data['report_id'] ?? 0);
    $res = ['success'=>false,'message'=>'Unable to delete report'];
    try {
        $res = delete_activity_report($rid);
    } catch (Throwable $e) {
        $res = ['success'=>false,'message'=>$e->getMessage()];
    }
    if ($res['success'] ?? false) {
        safe_redirect('project_activity_report.php?deleted=1');
        exit;
    }
    $errorMsg = $res['message'] ?? 'Delete failed';
}

// Include header AFTER actions (safe for AJAX)
require_once $root_dir . '/header.php';
// Fallback for has_access if not defined in helpers/header
if (!function_exists('has_access')) {
    /**
     * Fallback access check – allow all logged-in users.
     * Replace with your real permission logic if needed.
     */
    function has_access(string $moduleKey): bool
    {
        return true;
    }
}

// Get current user info
$current_user_id    = $_SESSION['user_id']  ?? 0;
$current_user_name  = $_SESSION['name']     ?? 'Unknown User';
$current_user_email = $_SESSION['email']    ?? '';

// Check if user has access to this module
if (!has_access('project_activity_report')) {
    echo "<div class='card'>
            <div class='card-content'>
                <div class='notification error'>
                    <strong>Access Denied:</strong> You don't have permission to access Project Activity Reports.
                </div>
            </div>
          </div>";
    require_once $root_dir . '/footer.php';
    exit;
}

/**
 * Ensure an index exists on a table (MySQL-safe, no IF NOT EXISTS).
 */
function ensure_index(PDO $pdo, string $table, string $indexName, string $columns): void
{
    try {
        $check = $pdo->prepare("SHOW INDEX FROM `$table` WHERE Key_name = ?");
        $check->execute([$indexName]);
        if (!$check->fetch()) {
            $pdo->exec("ALTER TABLE `$table` ADD INDEX `$indexName` ($columns)");
        }
    } catch (Throwable $e) {
        // ignore index errors (non-fatal)
    }
}


// Save activity report function
function save_activity_report(array $data, int $user_id): array
{
    global $pdo;

    try {
        $pdo->beginTransaction();

        // Get user info (Reported By = signed-in user)
        $user_stmt = $pdo->prepare("SELECT name, email FROM users WHERE id = ?");
        $user_stmt->execute([$user_id]);
        $user       = $user_stmt->fetch();
        $user_name  = $user['name']  ?? 'Unknown';
        $user_email = $user['email'] ?? '';

        // Prepare report data
        $report_id  = (int)($data['report_id']  ?? 0);
        $project_id = (int)($data['project_id'] ?? 0);

        if ($project_id <= 0) {
            throw new Exception("Project selection is required");
        }

        // Get project details for auto-fill
        $project_details = get_project_details($project_id);
        if (!$project_details['success']) {
            throw new Exception($project_details['message']);
        }
        $project = $project_details['project'];

        if ($report_id > 0) {
            // Update existing report
            $stmt = $pdo->prepare("
                UPDATE project_activity_reports SET
                    project_id = ?, 
                    report_title = ?, 
                    main_sector = ?, 
                    specific_sector = ?,
                    region_id = ?, 
                    zone_id = ?, 
                    woreda_id = ?, 
                    region_other = ?, 
                    zone_other = ?, 
                    woreda_other = ?,
                    report_type = ?, 
                    report_month = ?, 
                    report_year = ?, 
                    report_date = ?,
                    report_period_start = ?, 
                    report_period_end = ?, 
                    status = 'draft',
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND reported_by_user_id = ?
            ");

            $stmt->execute([
                $project_id,
                $data['report_title']       ?? '',
                $project['main_sectors']    ?? '',
                $project['specific_sectors']?? '',
                $project['region_id']       ?? null,
                $project['zone_id']         ?? null,
                $project['woreda_id']       ?? null,
                $project['region_name']     ?? null,
                $project['zone_name']       ?? null,
                $project['woreda_name']     ?? null,
                $data['report_type']        ?? '',
                (int)($data['report_month'] ?? 0),
                (int)($data['report_year']  ?? 0),
                $data['report_date']        ?? null,
                $data['report_period_start']?? null,
                $data['report_period_end']  ?? null,
                $report_id,
                $user_id
            ]);

            if ($stmt->rowCount() === 0) {
                throw new Exception("Report not found or you don't have permission to edit it");
            }
        } else {
            // Insert new report
            $stmt = $pdo->prepare("
                INSERT INTO project_activity_reports (
                    project_id, report_title, main_sector, specific_sector,
                    region_id, zone_id, woreda_id, region_other, zone_other, woreda_other,
                    report_type, report_month, report_year, report_date,
                    report_period_start, report_period_end, reported_by_user_id, reported_by_name, reported_by_email
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $project_id,
                $data['report_title']       ?? '',
                $project['main_sectors']    ?? '',
                $project['specific_sectors']?? '',
                $project['region_id']       ?? null,
                $project['zone_id']         ?? null,
                $project['woreda_id']       ?? null,
                $project['region_name']     ?? null,
                $project['zone_name']       ?? null,
                $project['woreda_name']     ?? null,
                $data['report_type']        ?? '',
                (int)($data['report_month'] ?? 0),
                (int)($data['report_year']  ?? 0),
                $data['report_date']        ?? null,
                $data['report_period_start']?? null,
                $data['report_period_end']  ?? null,
                $user_id,
                $user_name,
                $user_email
            ]);

            $report_id = (int)$pdo->lastInsertId();
        }

        // Delete existing lines
        $delete_stmt = $pdo->prepare("DELETE FROM project_activity_report_lines WHERE report_id = ?");
        $delete_stmt->execute([$report_id]);

        // Save activity lines
        $activities           = $data['activities'] ?? [];
        $total_beneficiaries  = 0;
        $total_progress       = 0;
        $activity_count       = 0;

        foreach ($activities as $index => $activity) {
            if (empty(trim($activity['project_activity'] ?? ''))) {
                continue;
            }

            $achievement_type  = $activity['achievement_type']  ?? 'persons';
            $achievement_value = (float)($activity['achievement_value'] ?? 0);
            $target_value      = (float)($activity['target_value']      ?? 0);

            // Calculate progress percentage
            $progress_percentage = 0;
            if ($target_value > 0) {
                $progress_percentage = min(100, ($achievement_value / $target_value) * 100);
            }

            // Beneficiaries (for person-type)
            $boys          = (int)($activity['boys_under_18']         ?? 0);
            $girls         = (int)($activity['girls_under_18']        ?? 0);
            $men           = (int)($activity['men_18_59']             ?? 0);
            $women         = (int)($activity['women_18_59']           ?? 0);
            $elderly_men   = (int)($activity['elderly_men_60_plus']   ?? 0);
            $elderly_women = (int)($activity['elderly_women_60_plus'] ?? 0);

            $total_beneficiaries_line = $boys + $girls + $men + $women + $elderly_men + $elderly_women;

            if ($achievement_type === 'persons') {
                $total_beneficiaries += $total_beneficiaries_line;
            }

            // Cumulative achievement: sum of this period + all previous periods
$cumulative_achievement = $achievement_value;
try {
    $cumStmt = $pdo->prepare("
        SELECT SUM(l.achievement_value) AS total_ach
        FROM project_activity_report_lines l
        JOIN project_activity_reports r ON l.report_id = r.id
        WHERE r.project_id = ?
          AND l.project_activity = ?
          AND l.unit_of_measurement = ?
          AND l.beneficiary_type = ?
    ");
    $cumStmt->execute([
        $project_id,
        $activity['project_activity']    ?? '',
        $activity['unit_of_measurement'] ?? '',
        $activity['beneficiary_type']    ?? ''
    ]);
    $cumRow = $cumStmt->fetch();
    $prevTotal = (float)($cumRow['total_ach'] ?? 0);

    // Add this period’s achievement on top of previous total
    $cumulative_achievement = $prevTotal + $achievement_value;
} catch (Exception $e) {
    // Fallback: only this period if something goes wrong
    $cumulative_achievement = $achievement_value;
}

$cumulative_progress = 0;
if ($target_value > 0) {
    $cumulative_progress = min(100, ($cumulative_achievement / $target_value) * 100);
}
            // Budget calculations
            $allocated_budget = (float)($activity['allocated_budget'] ?? 0);
            $budget_utilized  = (float)($activity['budget_utilized']  ?? 0);
            $budget_burn_rate = 0;
            if ($allocated_budget > 0) {
                $budget_burn_rate = min(100, ($budget_utilized / $allocated_budget) * 100);
            }
            $remaining_budget = $allocated_budget - $budget_utilized;

            // Determine progress status
            $progress_status = 'not_started';
            if ($progress_percentage >= 100) {
                $progress_status = 'completed';
            } elseif ($progress_percentage > 0) {
                $progress_status = 'ongoing';
            } elseif ($progress_percentage == 0 && $target_value > 0) {
                $progress_status = 'not_started';
            } else {
                $progress_status = $activity['progress_status'] ?? 'not_started';
            }

            $line_stmt = $pdo->prepare("
                INSERT INTO project_activity_report_lines (
                    report_id, result_level, project_activity, unit_of_measurement, beneficiary_type,
                    target_value, achievement_value, achievement_type, progress_percentage,
                    boys_under_18, girls_under_18, men_18_59, women_18_59, elderly_men_60_plus, elderly_women_60_plus,
                    total_beneficiaries, cumulative_achievement, cumulative_progress_percentage,
                    progress_status, allocated_budget, budget_utilized, budget_burn_rate, remaining_budget, activity_feedback
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $line_stmt->execute([
                $report_id,
                $activity['result_level']        ?? 'output',
                $activity['project_activity']    ?? '',
                $activity['unit_of_measurement'] ?? '',
                $activity['beneficiary_type']    ?? '',
                $target_value,
                $achievement_value,
                $achievement_type,
                $progress_percentage,
                $boys,
                $girls,
                $men,
                $women,
                $elderly_men,
                $elderly_women,
                $total_beneficiaries_line,
                $cumulative_achievement,
                $cumulative_progress,
                $progress_status,
                $allocated_budget,
                $budget_utilized,
                $budget_burn_rate,
                $remaining_budget,
                $activity['activity_feedback'] ?? ''
            ]);

            $total_progress += $progress_percentage;
            $activity_count++;
        }

        // Calculate overall progress
        $overall_progress = 0;
        if ($activity_count > 0) {
            $overall_progress = $total_progress / $activity_count;
        }

        // Update report totals
        $update_stmt = $pdo->prepare("
            UPDATE project_activity_reports 
            SET total_beneficiaries = ?, overall_progress = ?
            WHERE id = ?
        ");
        $update_stmt->execute([$total_beneficiaries, $overall_progress, $report_id]);

        // Run data quality checks (server-side)
        run_data_quality_checks($report_id);

        $pdo->commit();

        return [
            'success'   => true,
            'message'   => 'Project activity report saved successfully!',
            'report_id' => $report_id
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'success' => false,
            'message' => 'Error saving report: ' . $e->getMessage()
        ];
    }
}

// Run data quality checks (server side)
function run_data_quality_checks(int $report_id): void
{
    global $pdo;

    try {
        // Clear previous quality checks
        $delete_stmt = $pdo->prepare("DELETE FROM activity_report_data_quality WHERE report_id = ?");
        $delete_stmt->execute([$report_id]);

        // Get report data
        $report_stmt = $pdo->prepare("
            SELECT r.*, l.* 
            FROM project_activity_reports r
            LEFT JOIN project_activity_report_lines l ON r.id = l.report_id
            WHERE r.id = ?
        ");
        $report_stmt->execute([$report_id]);
        $lines = $report_stmt->fetchAll();

        $quality_checks = [];

        // Check 1: Missing mandatory fields
        foreach ($lines as $line) {
            if (empty($line['project_activity'])) {
                $quality_checks[] = [
                    'check_type'        => 'Mandatory Fields',
                    'check_description' => 'Project activity description is missing',
                    'status'            => 'error',
                    'severity'          => 'high',
                    'suggested_action'  => 'Please provide a description for the project activity'
                ];
            }
        }

        // Check 2: Achievement exceeds target
        foreach ($lines as $line) {
            if (($line['achievement_value'] ?? 0) > ($line['target_value'] ?? 0) && ($line['target_value'] ?? 0) > 0) {
                $quality_checks[] = [
                    'check_type'        => 'Data Validation',
                    'check_description' => "Achievement ({$line['achievement_value']}) exceeds target ({$line['target_value']}) for activity: " . substr((string)$line['project_activity'], 0, 50) . "...",
                    'status'            => 'warning',
                    'severity'          => 'medium',
                    'suggested_action'  => 'Verify achievement data or update target if necessary'
                ];
            }
        }

        // Check 3: Budget utilization exceeds allocation
        foreach ($lines as $line) {
            if (($line['budget_utilized'] ?? 0) > ($line['allocated_budget'] ?? 0) && ($line['allocated_budget'] ?? 0) > 0) {
                $quality_checks[] = [
                    'check_type'        => 'Budget Validation',
                    'check_description' => "Budget utilized ({$line['budget_utilized']}) exceeds allocated budget ({$line['allocated_budget']})",
                    'status'            => 'error',
                    'severity'          => 'high',
                    'suggested_action'  => 'Review budget utilization or request budget revision'
                ];
            }
        }

        // Check 4: Beneficiary disaggregation doesn't match total
        foreach ($lines as $line) {
            if (($line['achievement_type'] ?? '') === 'persons') {
                $calculated_total = ($line['boys_under_18'] ?? 0) +
                                    ($line['girls_under_18'] ?? 0) +
                                    ($line['men_18_59'] ?? 0) +
                                    ($line['women_18_59'] ?? 0) +
                                    ($line['elderly_men_60_plus'] ?? 0) +
                                    ($line['elderly_women_60_plus'] ?? 0);

                if ($calculated_total != ($line['total_beneficiaries'] ?? 0)) {
                    $quality_checks[] = [
                        'check_type'        => 'Data Consistency',
                        'check_description' => "Beneficiary disaggregation total ($calculated_total) doesn't match recorded total ({$line['total_beneficiaries']})",
                        'status'            => 'warning',
                        'severity'          => 'medium',
                        'suggested_action'  => 'Verify beneficiary disaggregation data'
                    ];
                }
            }
        }

        // Check 5: Zero progress on activities with targets
        foreach ($lines as $line) {
            if (($line['target_value'] ?? 0) > 0 && ($line['achievement_value'] ?? 0) == 0) {
                $quality_checks[] = [
                    'check_type'        => 'Progress Monitoring',
                    'check_description' => "No progress reported for activity: " . substr((string)$line['project_activity'], 0, 50) . "...",
                    'status'            => 'warning',
                    'severity'          => 'low',
                    'suggested_action'  => 'Consider updating achievement or reviewing activity status'
                ];
            }
        }

        // Save quality checks
        $quality_stmt = $pdo->prepare("
            INSERT INTO activity_report_data_quality (report_id, check_type, check_description, status, severity, suggested_action)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        foreach ($quality_checks as $check) {
            $quality_stmt->execute([
                $report_id,
                $check['check_type'],
                $check['check_description'],
                $check['status'],
                $check['severity'],
                $check['suggested_action']
            ]);
        }

        // Calculate data quality score (errors & warnings reduce score)
        $total_checks   = count($quality_checks);
        $error_checks   = array_filter($quality_checks, fn($c) => $c['status'] === 'error');
        $warning_checks = array_filter($quality_checks, fn($c) => $c['status'] === 'warning');

        $quality_score = 100;
        if ($total_checks > 0) {
            $quality_score = max(0, 100 - (count($error_checks) * 10 + count($warning_checks) * 5));
        }

        $score_stmt = $pdo->prepare("UPDATE project_activity_reports SET data_quality_score = ? WHERE id = ?");
        $score_stmt->execute([$quality_score, $report_id]);
    } catch (Exception $e) {
        error_log("Error running data quality checks: " . $e->getMessage());
    }
}

// Delete activity report
function delete_activity_report(int $report_id): array
{
    global $pdo;

    try {
        $pdo->beginTransaction();

        // Check if user owns the report
        $check_stmt = $pdo->prepare("SELECT id FROM project_activity_reports WHERE id = ? AND reported_by_user_id = ?");
        $check_stmt->execute([$report_id, $_SESSION['user_id'] ?? 0]);

        if ($check_stmt->fetch()) {
            // Delete related records first
            $pdo->prepare("DELETE FROM activity_report_data_quality WHERE report_id = ?")->execute([$report_id]);
            $pdo->prepare("DELETE FROM project_activity_report_lines  WHERE report_id = ?")->execute([$report_id]);

            // Delete main report
            $stmt = $pdo->prepare("DELETE FROM project_activity_reports WHERE id = ?");
            $stmt->execute([$report_id]);

            $pdo->commit();

            return [
                'success' => true,
                'message' => 'Activity report deleted successfully'
            ];
        }

        $pdo->rollBack();
        return [
            'success' => false,
            'message' => 'Report not found or you do not have permission to delete it'
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'success' => false,
            'message' => 'Error deleting report: ' . $e->getMessage()
        ];
    }
}

// Get user's projects (active)
$user_projects = [];
try {
    $project_stmt = $pdo->prepare("
        SELECT p.*, r.name as region_name, z.name as zone_name, w.name as woreda_name
        FROM projects p
        LEFT JOIN regions r ON p.region_id = r.id
        LEFT JOIN zones   z ON p.zone_id   = z.id
        LEFT JOIN woredas w ON p.woreda_id = w.id
        WHERE p.status = 'active'
        ORDER BY p.title
    ");
    $project_stmt->execute();
    $user_projects = $project_stmt->fetchAll();
} catch (Exception $e) {
    $user_projects = [];
    error_log("Error fetching user projects: " . $e->getMessage());
}

// Get existing reports for current user
$user_reports = [];
try {
    $report_stmt = $pdo->prepare("
        SELECT r.*, p.title as project_title,
               (SELECT COUNT(*) FROM project_activity_report_lines l WHERE l.report_id = r.id) as activity_count
        FROM project_activity_reports r
        JOIN projects p ON r.project_id = p.id
        WHERE r.reported_by_user_id = ?
        ORDER BY r.created_at DESC
    ");
    $report_stmt->execute([$current_user_id]);
    $user_reports = $report_stmt->fetchAll();
} catch (Exception $e) {
    $user_reports = [];
    error_log("Error fetching user reports: " . $e->getMessage());
}

// Calculate statistics for summary cards
$total_reports        = count($user_reports);
$completed_activities = 0;
$ongoing_activities   = 0;
$total_quality_score  = 0;

foreach ($user_reports as $report) {
    try {
        $status_stmt = $pdo->prepare("
            SELECT progress_status, COUNT(*) as count 
            FROM project_activity_report_lines 
            WHERE report_id = ? 
            GROUP BY progress_status
        ");
        $status_stmt->execute([$report['id']]);
        $status_counts = $status_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $completed_activities += $status_counts['completed'] ?? 0;
        $ongoing_activities   += $status_counts['ongoing']  ?? 0;
        $total_quality_score  += $report['data_quality_score'] ?? 0;
    } catch (Exception $e) {
        error_log("Error calculating report statistics: " . $e->getMessage());
    }
}

$avg_quality_score = $total_reports > 0 ? round($total_quality_score / $total_reports, 1) : 0;

// Report types
$report_types = [
    'Monthly Report',
    'Quarterly Report',
    'Semi-Annual Report',
    'Annual Report',
    'Special Report',
    'Emergency Response Report'
];

// Months for selection
$months = [
    1 => 'January',   2 => 'February', 3 => 'March',     4 => 'April',
    5 => 'May',       6 => 'June',     7 => 'July',      8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

$current_year = (int)date('Y');
// UPDATED: years from this year up to +50 years
$years        = range($current_year, $current_year + 50);

// Result levels
$result_levels = [
    'impact'  => 'Impact',
    'outcome' => 'Outcome',
    'output'  => 'Output'
];

// Progress status options
$progress_statuses = [
    'completed'   => 'Completed',
    'ongoing'     => 'Ongoing',
    'not_started' => 'Not Started',
    'delayed'     => 'Delayed'
];
?>

<style>
    :root {
        --primary-color: #667eea;
        --secondary-color: #764ba2;
        --success-color: #28a745;
        --warning-color: #ffc107;
        --danger-color: #dc3545;
        --info-color: #17a2b8;
    }

    .card {
        background: white;
        border-radius: 10px;
        box-shadow: 0 2px 20px rgba(0,0,0,0.1);
        margin-bottom: 20px;
        overflow: hidden;
        border: 1px solid #e0e0e0;
    }
    .card h1, .card h2 {
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
        color: white;
        padding: 15px 20px;
        margin: 0;
        font-size: 1.5rem;
    }
    .card-content { padding: 20px; }

    .form-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 15px;
        margin-bottom: 15px;
    }
    .form-row label {
        display: flex;
        flex-direction: column;
        font-weight: 500;
        color: #333;
    }
    .form-row input,
    .form-row select,
    .form-row textarea {
        padding: 10px 12px;
        border: 1px solid #ddd;
        border-radius: 5px;
        margin-top: 5px;
        font-size: 14px;
        transition: border-color 0.3s ease;
    }
    .form-row input:focus,
    .form-row select:focus,
    .form-row textarea:focus {
        outline: none;
        border-color: var(--primary-color);
        box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.1);
    }

    .activity-table {
    width: 100%;
    min-width: 1600px; /* Force horizontal scroll on smaller screens */
    border-collapse: collapse;
    margin: 20px 0;
    font-size: 12px;
    background: white;
    }
    .activity-table th {
        background: #f8f9fa;
        padding: 10px 6px;
        text-align: left;
        font-weight: 600;
        border: 1px solid #dee2e6;
        font-size: 11px;
    }
    .activity-table td {
        padding: 6px;
        border: 1px solid #dee2e6;
        vertical-align: top;
    }
    .activity-table input,
    .activity-table select,
    .activity-table textarea {
        width: 100%;
        padding: 4px;
        border: 1px solid #ddd;
        border-radius: 3px;
        font-size: 11px;
        min-height: 28px;
    }
    .activity-table textarea {
        min-height: 40px;
        resize: vertical;
    }

    .btn {
        padding: 10px 20px;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        font-size: 14px;
        transition: all 0.3s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        font-weight: 500;
    }
    .btn-primary   { background: var(--primary-color); color: white; }
    .btn-success   { background: var(--success-color); color: white; }
    .btn-danger    { background: var(--danger-color);  color: white; }
    .btn-warning   { background: var(--warning-color); color: #212529; }
    .btn-info      { background: var(--info-color);    color: white; }
    .btn-secondary { background: #6c757d;              color: white; }
    .btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        opacity: 0.9;
    }
    .btn:active {
        transform: translateY(0);
    }
    .btn-sm {
        padding: 6px 12px;
        font-size: 12px;
    }

    .badge {
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        display: inline-block;
    }
    .badge-success { background: #d4edda; color: #155724; }
    .badge-warning { background: #fff3cd; color: #856404; }
    .badge-danger  { background: #f8d7da; color: #721c24; }
    .badge-info    { background: #d1ecf1; color: #0c5460; }
    .badge-primary { background: var(--primary-color); color: #ffffff; }

    .data-quality-panel {
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 15px;
        margin: 15px 0;
    }
    .quality-item {
        padding: 10px;
        margin: 5px 0;
        border-left: 4px solid #28a745;
        background: white;
        border-radius: 4px;
    }
    .quality-item.error   { border-left-color: var(--danger-color); }
    .quality-item.warning { border-left-color: var(--warning-color); }
    .quality-item.success { border-left-color: var(--success-color); }

    .summary-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin: 20px 0;
    }
    .summary-card {
        padding: 20px;
        border-radius: 8px;
        text-align: center;
        color: white;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    .summary-card h3 {
        margin: 0;
        font-size: 14px;
        opacity: 0.9;
    }
    .summary-card p {
        font-size: 24px;
        font-weight: bold;
        margin: 10px 0 0 0;
    }

    .auto-calc {
        background: #e8f5e8;
        font-weight: bold;
        color: #2e7d32;
    }

    .progress-bar {
        width: 100%;
        height: 10px;
        background: #e9ecef;
        border-radius: 5px;
        overflow: hidden;
        margin: 5px 0;
    }

    .progress-fill {
        height: 100%;
        background: var(--success-color);
        transition: width 0.3s ease;
        border-radius: 5px;
    }

    @media (max-width: 1200px) {
        .activity-table {
            font-size: 11px;
        }
        .activity-table th,
        .activity-table td {
            padding: 4px 3px;
        }
    }

    @media (max-width: 768px) {
        .form-row {
            grid-template-columns: 1fr;
        }
        .activity-table-container {
            overflow-x: auto;
        }
        .activity-table {
            min-width: 1200px;
        }
        .summary-cards {
            grid-template-columns: repeat(2, 1fr);
        }
    }
    .activity-table-container {
    width: 100%;
    overflow-x: auto;   /* Always allow horizontal scroll */
}
    .section-header {
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
        color: white;
        padding: 12px 15px;
        margin: 20px 0 10px 0;
        border-radius: 5px;
        font-weight: 600;
        font-size: 1.1rem;
    }

    .notification {
        padding: 12px 15px;
        border-radius: 5px;
        margin: 10px 0;
        border-left: 4px solid;
        font-weight: 500;
    }

    .notification.success {
        background: #d4edda;
        border-color: var(--success-color);
        color: #155724;
    }

    .notification.error {
        background: #f8d7da;
        border-color: var(--danger-color);
        color: #721c24;
    }

    .notification.info {
        background: #d1ecf1;
        border-color: var(--info-color);
        color: #0c5460;
    }

    .table-actions {
        display: flex;
        gap: 5px;
        flex-wrap: wrap;
    }

    .required::after {
        content: " *";
        color: var(--danger-color);
    }

    .help-text {
        font-size: 12px;
        color: #6c757d;
        margin-top: 3px;
    }

    .export-buttons {
        display: flex;
        gap: 10px;
        margin: 15px 0;
        flex-wrap: wrap;
    }

    .floating-actions {
        position: fixed;
        bottom: 20px;
        right: 20px;
        display: flex;
        flex-direction: column;
        gap: 10px;
        z-index: 1000;
    }

    .floating-btn {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        font-size: 18px;
    }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="card">
    <h1>📊 Project Activity Report System</h1>
    <div class="card-content">
        <?php if ($message): ?>
            <div class="notification success">✅ <?php echo h($message); ?></div>
        <?php endif; ?>

        <?php if ($errorMsg): ?>
            <div class="notification error">❌ <?php echo h($errorMsg); ?></div>
        <?php endif; ?>

        <div class="notification info">
            💡 <strong>System Overview:</strong> This system allows you to create comprehensive project activity reports with automatic data validation,
            progress tracking, and integration with project planning and budget systems.
        </div>

        <!-- Quick Stats -->
        <div class="summary-cards">
            <div class="summary-card" style="background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);">
                <h3>📋 Total Reports</h3>
                <p><?php echo (int)$total_reports; ?></p>
            </div>
            <div class="summary-card" style="background: linear-gradient(135deg, var(--success-color) 0%, #20c997 100%);">
                <h3>✅ Completed Activities</h3>
                <p><?php echo (int)$completed_activities; ?></p>
            </div>
            <div class="summary-card" style="background: linear-gradient(135deg, var(--warning-color) 0%, #fd7e14 100%);">
                <h3>⏱️ Ongoing Activities</h3>
                <p><?php echo (int)$ongoing_activities; ?></p>
            </div>
            <div class="summary-card" style="background: linear-gradient(135deg, var(--info-color) 0%, #6f42c1 100%);">
                <h3>📈 Data Quality Score</h3>
                <p><?php echo $avg_quality_score; ?>%</p>
            </div>
        </div>

        <!-- Export Buttons -->
        <div class="export-buttons">
            <button type="button" class="btn btn-success" onclick="exportToExcel()">
                📊 Export to Excel
            </button>
            <button type="button" class="btn btn-primary" onclick="generatePDF()">
                📄 Generate PDF Report
            </button>
            <button type="button" class="btn btn-info" onclick="showReportTemplates()">
                🎨 Report Templates
            </button>
        </div>
    </div>
</div>

<!-- Create New Report -->
<div class="card">
    <h2>➕ Create New Activity Report</h2>
    <div class="card-content">
        <form method="post" id="activityReportForm" onsubmit="return validateForm()">
            <input type="hidden" name="action" value="save_report">
            <input type="hidden" name="report_id" value="0" id="report_id">

            <div class="section-header">📋 Report Basic Information</div>

            <div class="form-row">
                <label class="required">Project Title
                    <select name="project_id" id="project_id" required onchange="updateProjectDetails()">
                        <option value="">-- Select Project --</option>
                        <?php foreach ($user_projects as $project): ?>
                            <option value="<?php echo (int)$project['id']; ?>"
                                    data-main-sector="<?php echo h($project['main_sectors'] ?? ''); ?>"
                                    data-specific-sector="<?php echo h($project['specific_sectors'] ?? ''); ?>"
                                    data-region="<?php echo h($project['region_name'] ?? $project['region_other'] ?? ''); ?>"
                                    data-zone="<?php echo h($project['zone_name'] ?? $project['zone_other'] ?? ''); ?>"
                                    data-woreda="<?php echo h($project['woreda_name'] ?? $project['woreda_other'] ?? ''); ?>">
                                <?php echo h($project['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="help-text">Select the project you are reporting on. Planned activities will be loaded automatically.</div>
                </label>
                <label class="required">Report Title
                    <input type="text" name="report_title" required placeholder="e.g., Monthly Activity Report - January 2024">
                    <div class="help-text">Give your report a descriptive title</div>
                </label>
                <label class="required">Report Type
                    <select name="report_type" required>
                        <option value="">-- Select Report Type --</option>
                        <?php foreach ($report_types as $type): ?>
                            <option value="<?php echo h($type); ?>"><?php echo h($type); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <div class="form-row">
                <label>Main Sector
                    <input type="text" name="main_sector" id="main_sector" readonly class="auto-calc">
                </label>
                <label>Specific Sector
                    <input type="text" name="specific_sector" id="specific_sector" readonly class="auto-calc">
                </label>
                <label>Region
                    <input type="text" name="region" id="region" readonly class="auto-calc">
                </label>
            </div>

            <div class="form-row">
                <label>Zone
                    <input type="text" name="zone" id="zone" readonly class="auto-calc">
                </label>
                <label>Woreda/Town
                    <input type="text" name="woreda" id="woreda" readonly class="auto-calc">
                </label>
                <label>Reported By
                    <!-- Reported By = signed-in user, not editable -->
                    <input type="text" value="<?php echo h($current_user_name); ?> (<?php echo h($current_user_email); ?>)" readonly class="auto-calc">
                </label>
            </div>

            <div class="form-row">
                <label>Month of Report
                    <select name="report_month">
                        <option value="">-- Select Month --</option>
                        <?php foreach ($months as $num => $name): ?>
                            <option value="<?php echo (int)$num; ?>" <?php echo $num == (int)date('n') ? 'selected' : ''; ?>>
                                <?php echo h($name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Year
                    <select name="report_year">
                        <option value="">-- Select Year --</option>
                        <?php foreach ($years as $year): ?>
                            <option value="<?php echo (int)$year; ?>" <?php echo $year == $current_year ? 'selected' : ''; ?>>
                                <?php echo (int)$year; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Date of Report
                    <input type="date" name="report_date" value="<?php echo h(date('Y-m-d')); ?>">
                </label>
            </div>

            <div class="form-row">
                <label>Reporting Period From
                    <input type="date" name="report_period_start" id="report_period_start">
                </label>
                <label>Reporting Period To
                    <input type="date" name="report_period_end" id="report_period_end">
                </label>
            </div>

            <div class="section-header">📈 Activity Reporting Template</div>

            <div class="notification info">
                💡 <strong>Instructions:</strong> After selecting a project, planned activities (Result Level, Activity, Unit, Beneficiary Type, Target, Type)
                will be loaded automatically from the planning module. You mainly enter achievements, SADD, and budget details.
            </div>

            <div class="activity-table-container">
                <table class="activity-table" id="reportsTable">
                    <thead>
                        <tr>
                            <th width="30">SN</th>
                            <th width="100">Result Level</th>
                            <th>Project Activities *</th>
                            <th width="120">Unit of Measurement</th>
                            <th width="150">Beneficiary Types</th>
                            <th width="80">Target</th>
                            <th width="100">Achievement</th>
                            <th width="80">Type</th>
                            <th width="80">% Progress</th>
                            <th colspan="6" style="text-align: center;">Beneficiary Reached (Age &amp; Sex Disaggregation)</th>
                            <th width="60">Total</th>
                            <th width="100">Cumulative Achievement</th>
                            <th width="80">% Cumulative</th>
                            <th width="100">Progress Status</th>
                            <th width="100">Allocated Budget</th>
                            <th width="100">Budget Utilized</th>
                            <th width="80">Burn Rate</th>
                            <th width="100">Remaining Budget</th>
                            <th>Feedback on Activity Status</th>
                            <th width="60">Actions</th>
                        </tr>
                        <tr>
                            <th colspan="8"></th>
                            <th colspan="6" style="text-align: center; background: #e9ecef;">
                                👥 Beneficiary Breakdown
                            </th>
                            <th colspan="10"></th>
                        </tr>
                        <tr>
                            <th colspan="8"></th>
                            <th>Boys &lt;18</th>
                            <th>Girls &lt;18</th>
                            <th>Men 18-59</th>
                            <th>Women 18-59</th>
                            <th>Elderly Men 60+</th>
                            <th>Elderly Women 60+</th>
                            <th colspan="10"></th>
                        </tr>
                    </thead>
                    <tbody id="activitiesBody">
                        <!-- Rows added by JS -->
                    </tbody>
                </table>
            </div>

            <div style="text-align: center; margin: 20px 0;">
                <button type="button" class="btn btn-success" onclick="addActivityRow()">
                    ➕ Add Activity Row
                </button>
                <button type="button" class="btn btn-info" onclick="addSampleData()">
                    🧪 Add Sample Data
                </button>
                <button type="button" class="btn btn-warning" onclick="clearAllActivities()">
                    🗑️ Clear All Activities
                </button>
                <button type="button" class="btn btn-primary" onclick="addMultipleRows(5)">
                    📝 Add 5 Blank Rows
                </button>
            </div>

            <!-- Data Quality Panel -->
            <div class="data-quality-panel" id="dataQualityPanel" style="display: none;">
                <h3>🔍 Data Quality Check Results</h3>
                <div id="qualityChecks"></div>
            </div>

            <!-- Summary Statistics -->
            <div class="data-quality-panel" id="summaryPanel" style="display: none;">
                <h3>📊 Report Summary</h3>
                <div class="form-row">
                    <label>Total Activities: <span id="totalActivities" class="badge badge-info">0</span></label>
                    <label>Total Beneficiaries: <span id="totalBeneficiaries" class="badge badge-success">0</span></label>
                    <label>Overall Progress: <span id="overallProgress" class="badge badge-primary">0%</span></label>
                    <label>Data Quality Score: <span id="dataQualityScore" class="badge badge-warning">0%</span></label>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" id="overallProgressBar" style="width: 0%"></div>
                </div>
            </div>

            <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
                <button type="submit" class="btn btn-primary" style="padding: 12px 30px; font-size: 16px;">
                    💾 Save Activity Report
                </button>
                <button type="button" class="btn btn-success" onclick="saveAndSubmit()">
                    📤 Save &amp; Submit for Review
                </button>
                <button type="button" class="btn btn-warning" onclick="runQualityChecks()">
                    🔍 Run Data Quality Check
                </button>
                <button type="button" class="btn btn-info" onclick="calculateSummary()">
                    📊 Calculate Summary
                </button>
                <button type="reset" class="btn btn-secondary">
                    🗑️ Reset Form
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Existing Reports -->
<?php if ($user_reports): ?>
<div class="card">
    <h2>📋 Your Activity Reports (<?php echo count($user_reports); ?>)</h2>
    <div class="card-content">
        <div class="activity-table-container">
            <table class="activity-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Report Title</th>
                        <th>Project</th>
                        <th>Report Type</th>
                        <th>Period</th>
                        <th>Activities</th>
                        <th>Progress</th>
                        <th>Quality Score</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($user_reports as $report): ?>
                    <tr>
                        <td><?php echo (int)$report['id']; ?></td>
                        <td>
                            <strong><?php echo h($report['report_title']); ?></strong>
                            <?php if ($report['status'] === 'draft'): ?>
                                <span class="badge badge-warning">Draft</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo h($report['project_title']); ?></td>
                        <td><?php echo h($report['report_type']); ?></td>
                        <td>
                            <?php
                            if (!empty($report['report_period_start']) && !empty($report['report_period_end'])) {
                                echo date('M j', strtotime($report['report_period_start'])) . ' - ' .
                                     date('M j, Y', strtotime($report['report_period_end']));
                            } else {
                                echo 'N/A';
                            }
                            ?>
                        </td>
                        <td><?php echo (int)$report['activity_count']; ?></td>
                        <td>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo (float)($report['overall_progress'] ?? 0); ?>%"></div>
                            </div>
                            <small><?php echo number_format((float)($report['overall_progress'] ?? 0), 1); ?>%</small>
                        </td>
                        <td>
                            <span class="badge badge-<?php echo ($report['data_quality_score'] ?? 0) >= 80 ? 'success' : (($report['data_quality_score'] ?? 0) >= 60 ? 'warning' : 'danger'); ?>">
                                <?php echo number_format((float)($report['data_quality_score'] ?? 0), 1); ?>%
                            </span>
                        </td>
                        <td>
                            <?php
                            $statusClass = 'warning';
                            switch ($report['status']) {
                                case 'submitted':
                                    $statusClass = 'info';
                                    break;
                                case 'approved':
                                    $statusClass = 'success';
                                    break;
                                case 'rejected':
                                    $statusClass = 'danger';
                                    break;
                                default:
                                    $statusClass = 'warning';
                                    break;
                            }
                            ?>
                            <span class="badge badge-<?php echo $statusClass; ?>">
                                <?php echo ucfirst($report['status']); ?>
                            </span>
                        </td>
                        <td><?php echo date('M j, Y', strtotime($report['created_at'])); ?></td>
                        <td>
                            <div class="table-actions">
                                <button type="button" class="btn btn-sm btn-primary" onclick="editReport(<?php echo (int)$report['id']; ?>)">
                                    ✏️ Edit
                                </button>
                                <button type="button" class="btn btn-sm btn-info" onclick="viewReport(<?php echo (int)$report['id']; ?>)">
                                    👁️ View
                                </button>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="action" value="delete_report">
                                    <input type="hidden" name="report_id" value="<?php echo (int)$report['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger"
                                            onclick="return confirm('Are you sure you want to delete this report? This action cannot be undone.')">
                                        🗑️ Delete
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="card-content">
        <div class="notification info">
            ℹ️ You haven't created any activity reports yet. Use the form above to create your first report.
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Floating Action Buttons -->
<div class="floating-actions">
    <button class="btn btn-primary floating-btn" onclick="scrollToTop()" title="Scroll to Top">
        ↑
    </button>
    <button class="btn btn-success floating-btn" onclick="addActivityRow()" title="Add Activity">
        +
    </button>
    <button class="btn btn-info floating-btn" onclick="runQualityChecks()" title="Quality Check">
        ✓
    </button>
</div>

<script>
    let activityRowCount = 0;
    let currentEditingReportId = 0;

    // Update project details when project is selected
    function updateProjectDetails() {
        const projectSelect = document.getElementById('project_id');
        const projectId = projectSelect.value;

        if (!projectId) {
            document.getElementById('main_sector').value = '';
            document.getElementById('specific_sector').value = '';
            document.getElementById('region').value = '';
            document.getElementById('zone').value = '';
            document.getElementById('woreda').value = '';
            clearAllActivities();
            return;
        }

        const selectedOption = projectSelect.options[projectSelect.selectedIndex];
        document.getElementById('main_sector').value     = selectedOption.getAttribute('data-main-sector')     || '';
        document.getElementById('specific_sector').value = selectedOption.getAttribute('data-specific-sector') || '';
        document.getElementById('region').value          = selectedOption.getAttribute('data-region')          || '';
        document.getElementById('zone').value            = selectedOption.getAttribute('data-zone')            || '';
        document.getElementById('woreda').value          = selectedOption.getAttribute('data-woreda')          || '';

        // Load planned activities from planning.php / planning table
        loadPlannedActivities(projectId);
    }

    // Load planned activities via AJAX
    function loadPlannedActivities(projectId) {
        const tbody = document.getElementById('activitiesBody');
        tbody.innerHTML = '';
        activityRowCount = 0;

        if (!projectId) {
            addActivityRow();
            return;
        }

        const url = window.location.href;
        const params = new URLSearchParams();
        params.append('action', 'get_planned_activities');
        params.append('project_id', projectId);

        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: params.toString()
        })
        .then(response => response.json())
        .then(data => {
            if (data && data.success && Array.isArray(data.activities) && data.activities.length > 0) {
                data.activities.forEach(activity => {
                    // Mark planning-based rows so that Type can be locked
                    activity.from_planning = true;
                    addActivityRow(activity);
                });
                calculateSummary();
                showNotification('Planned activities loaded from project plan. You can now enter achievements, SADD, and budget details.', 'success');
            } else {
                addActivityRow();
                if (data && data.message) {
                    showNotification(data.message, 'warning');
                } else {
                    showNotification('No planned activities found for this project. You can still add activities manually.', 'info');
                }
            }
        })
        .catch(err => {
            console.error(err);
            addActivityRow();
            showNotification('Could not load planned activities. You can still enter activities manually.', 'error');
        });
    }

    // Add new activity row
    function addActivityRow(activityData = {}) {
        const tbody   = document.getElementById('activitiesBody');
        const rowIndex = activityRowCount++;

        const resultLevel      = activityData.result_level || 'output';
        const achievementType  = activityData.achievement_type || 'persons';
        const lockType         = activityData.lock_type || activityData.from_planning || false;
        const lockTypeAttr     = (lockType && achievementType === 'persons') ? ' disabled data-locked="1"' : '';

        const row = document.createElement('tr');
        row.id = `activityRow_${rowIndex}`;
        row.innerHTML = `
            <td>${rowIndex + 1}</td>
            <td>
                <select name="activities[${rowIndex}][result_level]" onchange="updateCalculations(${rowIndex})">
                    <option value="output" ${resultLevel === 'output' ? 'selected' : ''}>Output</option>
                    <option value="outcome" ${resultLevel === 'outcome' ? 'selected' : ''}>Outcome</option>
                    <option value="impact" ${resultLevel === 'impact' ? 'selected' : ''}>Impact</option>
                </select>
            </td>
            <td>
                <input type="text" name="activities[${rowIndex}][project_activity]"
                       placeholder="Describe project activity..." required
                       onchange="updateCalculations(${rowIndex})"
                       value="${activityData.project_activity ? escapeHtml(activityData.project_activity) : ''}">
            </td>
            <td>
                <input type="text" name="activities[${rowIndex}][unit_of_measurement]"
                       placeholder="e.g., persons, households..."
                       onchange="updateCalculations(${rowIndex})"
                       value="${activityData.unit_of_measurement ? escapeHtml(activityData.unit_of_measurement) : ''}">
            </td>
            <td>
                <input type="text" name="activities[${rowIndex}][beneficiary_type]"
                       placeholder="e.g., children, women..."
                       onchange="updateCalculations(${rowIndex})"
                       value="${activityData.beneficiary_type ? escapeHtml(activityData.beneficiary_type) : ''}">
            </td>
            <td>
                <input type="number" step="0.01" name="activities[${rowIndex}][target_value]"
                       value="${activityData.target_value != null ? activityData.target_value : 0}" min="0" onchange="updateCalculations(${rowIndex})">
            </td>
            <td>
                <input type="number" step="0.01" name="activities[${rowIndex}][achievement_value]"
                       value="${activityData.achievement_value != null ? activityData.achievement_value : 0}" min="0" onchange="updateCalculations(${rowIndex})">
            </td>
            <td>
                <!-- Type (persons / non-persons). For planning-based 'persons', this will be deactivated (locked). -->
                <select name="activities[${rowIndex}][achievement_type]" onchange="toggleBeneficiaryFields(${rowIndex})"${lockTypeAttr}>
                    <option value="persons" ${achievementType === 'persons' ? 'selected' : ''}>Persons</option>
                    <option value="non_persons" ${achievementType === 'non_persons' ? 'selected' : ''}>Non-Persons</option>
                </select>
            </td>
            <td>
                <input type="text" name="activities[${rowIndex}][progress_percentage]"
                       value="${activityData.progress_percentage != null ? activityData.progress_percentage : 0}" readonly class="auto-calc">
            </td>
            <td>
                <input type="number" name="activities[${rowIndex}][boys_under_18]"
                       value="${activityData.boys_under_18 != null ? activityData.boys_under_18 : 0}" min="0" onchange="updateBeneficiaryTotal(${rowIndex})">
            </td>
            <td>
                <input type="number" name="activities[${rowIndex}][girls_under_18]"
                       value="${activityData.girls_under_18 != null ? activityData.girls_under_18 : 0}" min="0" onchange="updateBeneficiaryTotal(${rowIndex})">
            </td>
            <td>
                <input type="number" name="activities[${rowIndex}][men_18_59]"
                       value="${activityData.men_18_59 != null ? activityData.men_18_59 : 0}" min="0" onchange="updateBeneficiaryTotal(${rowIndex})">
            </td>
            <td>
                <input type="number" name="activities[${rowIndex}][women_18_59]"
                       value="${activityData.women_18_59 != null ? activityData.women_18_59 : 0}" min="0" onchange="updateBeneficiaryTotal(${rowIndex})">
            </td>
            <td>
                <input type="number" name="activities[${rowIndex}][elderly_men_60_plus]"
                       value="${activityData.elderly_men_60_plus != null ? activityData.elderly_men_60_plus : 0}" min="0" onchange="updateBeneficiaryTotal(${rowIndex})">
            </td>
            <td>
                <input type="number" name="activities[${rowIndex}][elderly_women_60_plus]"
                       value="${activityData.elderly_women_60_plus != null ? activityData.elderly_women_60_plus : 0}" min="0" onchange="updateBeneficiaryTotal(${rowIndex})">
            </td>
            <td>
                <input type="number" name="activities[${rowIndex}][total_beneficiaries]"
                       value="${activityData.total_beneficiaries != null ? activityData.total_beneficiaries : 0}" readonly class="auto-calc">
            </td>
            <td>
                <input type="number" step="0.01" name="activities[${rowIndex}][cumulative_achievement]"
                       value="${activityData.cumulative_achievement != null ? activityData.cumulative_achievement : 0}" readonly class="auto-calc">
            </td>
            <td>
                <input type="text" name="activities[${rowIndex}][cumulative_progress_percentage]"
                       value="${activityData.cumulative_progress_percentage != null ? activityData.cumulative_progress_percentage : 0}" readonly class="auto-calc">
            </td>
            <td>
                <select name="activities[${rowIndex}][progress_status]" onchange="updateProgressStatus(${rowIndex})">
                    <option value="not_started" ${activityData.progress_status === 'not_started' ? 'selected' : ''}>Not Started</option>
                    <option value="ongoing" ${activityData.progress_status === 'ongoing' ? 'selected' : ''}>Ongoing</option>
                    <option value="completed" ${activityData.progress_status === 'completed' ? 'selected' : ''}>Completed</option>
                    <option value="delayed" ${activityData.progress_status === 'delayed' ? 'selected' : ''}>Delayed</option>
                </select>
            </td>
            <td>
                <input type="number" step="0.01" name="activities[${rowIndex}][allocated_budget]"
                       value="${activityData.allocated_budget != null ? activityData.allocated_budget : 0}" min="0" onchange="updateBudgetCalculations(${rowIndex})">
            </td>
            <td>
                <input type="number" step="0.01" name="activities[${rowIndex}][budget_utilized]"
                       value="${activityData.budget_utilized != null ? activityData.budget_utilized : 0}" min="0" onchange="updateBudgetCalculations(${rowIndex})">
            </td>
            <td>
                <input type="text" name="activities[${rowIndex}][budget_burn_rate]"
                       value="${activityData.budget_burn_rate != null ? activityData.budget_burn_rate : 0}" readonly class="auto-calc">
            </td>
            <td>
                <input type="number" step="0.01" name="activities[${rowIndex}][remaining_budget]"
                       value="${activityData.remaining_budget != null ? activityData.remaining_budget : 0}" readonly class="auto-calc">
            </td>
            <td>
                <textarea name="activities[${rowIndex}][activity_feedback]"
                          placeholder="Enter feedback on activity status..."
                          rows="2">${activityData.activity_feedback ? escapeHtml(activityData.activity_feedback) : ''}</textarea>
            </td>
            <td>
                <button type="button" class="btn btn-sm btn-danger" onclick="deleteActivityRow(${rowIndex})" title="Delete this activity">
                    🗑️
                </button>
            </td>
        `;

        tbody.appendChild(row);
        updateCalculations(rowIndex);
        toggleBeneficiaryFields(rowIndex);
    }

    // Simple HTML escape for injected values
    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    // Add multiple blank rows
    function addMultipleRows(count) {
        for (let i = 0; i < count; i++) {
            addActivityRow();
        }
    }

    // Delete activity row
    function deleteActivityRow(rowIndex) {
        const row = document.getElementById(`activityRow_${rowIndex}`);
        if (row) {
            row.remove();
            renumberRows();
            calculateSummary();
        }
    }

    // Only renumber SN column; keep input names & indices stable
    function renumberRows() {
        const rows = document.querySelectorAll('#activitiesBody tr');
        rows.forEach((row, index) => {
            row.cells[0].textContent = index + 1;
        });
    }

    // Clear all activities
    function clearAllActivities() {
        if (!confirm('Are you sure you want to clear all activities? This cannot be undone.')) {
            return;
        }
        const tbody = document.getElementById('activitiesBody');
        tbody.innerHTML = '';
        activityRowCount = 0;
        calculateSummary();
        hidePanels();
    }

    // Toggle beneficiary fields based on achievement type
    function toggleBeneficiaryFields(rowIndex) {
        const typeSelect = document.querySelector(`select[name="activities[${rowIndex}][achievement_type]"]`);
        if (!typeSelect) return;

        const achievementType = typeSelect.value;
        const beneficiaryInputs = document.querySelectorAll(
            `input[name="activities[${rowIndex}][boys_under_18]"],
             input[name="activities[${rowIndex}][girls_under_18]"],
             input[name="activities[${rowIndex}][men_18_59]"],
             input[name="activities[${rowIndex}][women_18_59]"],
             input[name="activities[${rowIndex}][elderly_men_60_plus]"],
             input[name="activities[${rowIndex}][elderly_women_60_plus]"]`
        );

        const isDisabled = achievementType === 'non_persons';
        beneficiaryInputs.forEach(input => {
            input.disabled = isDisabled;
            if (isDisabled) {
                input.value = '0';
            }
        });

        updateBeneficiaryTotal(rowIndex);
    }

    // Update beneficiary total
    function updateBeneficiaryTotal(rowIndex) {
        const getVal = (selector) => {
            const el = document.querySelector(selector);
            return el ? parseInt(el.value) || 0 : 0;
        };

        const boys         = getVal(`input[name="activities[${rowIndex}][boys_under_18]"]`);
        const girls        = getVal(`input[name="activities[${rowIndex}][girls_under_18]"]`);
        const men          = getVal(`input[name="activities[${rowIndex}][men_18_59]"]`);
        const women        = getVal(`input[name="activities[${rowIndex}][women_18_59]"]`);
        const elderlyMen   = getVal(`input[name="activities[${rowIndex}][elderly_men_60_plus]"]`);
        const elderlyWomen = getVal(`input[name="activities[${rowIndex}][elderly_women_60_plus]"]`);

        const total = boys + girls + men + women + elderlyMen + elderlyWomen;
        const totalField = document.querySelector(`input[name="activities[${rowIndex}][total_beneficiaries]"]`);
        if (totalField) {
            totalField.value = total;
        }

        calculateSummary();
    }

    // Update progress calculations
    function updateCalculations(rowIndex) {
        const getNum = (selector) => {
            const el = document.querySelector(selector);
            return el ? parseFloat(el.value) || 0 : 0;
        };

        const target      = getNum(`input[name="activities[${rowIndex}][target_value]"]`);
        const achievement = getNum(`input[name="activities[${rowIndex}][achievement_value]"]`);

        let progress = 0;
        if (target > 0) {
            progress = Math.min(100, (achievement / target) * 100);
        }
        const progressField = document.querySelector(`input[name="activities[${rowIndex}][progress_percentage]"]`);
        if (progressField) {
            progressField.value = progress.toFixed(1);
        }

        const cumAchField = document.querySelector(`input[name="activities[${rowIndex}][cumulative_achievement]"]`);
        if (cumAchField) {
            cumAchField.value = achievement;
        }

        let cumulativeProgress = 0;
        if (target > 0) {
            cumulativeProgress = Math.min(100, (achievement / target) * 100);
        }
        const cumProgField = document.querySelector(`input[name="activities[${rowIndex}][cumulative_progress_percentage]"]`);
        if (cumProgField) {
            cumProgField.value = cumulativeProgress.toFixed(1);
        }

        updateProgressStatus(rowIndex);
        updateBudgetCalculations(rowIndex);
        calculateSummary();
    }

    // Update progress status based on percentage
    function updateProgressStatus(rowIndex) {
        const progressField = document.querySelector(`input[name="activities[${rowIndex}][progress_percentage]"]`);
        const statusSelect  = document.querySelector(`select[name="activities[${rowIndex}][progress_status]"]`);
        if (!progressField || !statusSelect) return;

        const progressPercentage = parseFloat(progressField.value) || 0;

        if (statusSelect.value !== 'delayed') {
            if (progressPercentage >= 100) {
                statusSelect.value = 'completed';
            } else if (progressPercentage > 0) {
                statusSelect.value = 'ongoing';
            } else {
                statusSelect.value = 'not_started';
            }
        }
    }

    // Update budget calculations
    function updateBudgetCalculations(rowIndex) {
        const getNum = (selector) => {
            const el = document.querySelector(selector);
            return el ? parseFloat(el.value) || 0 : 0;
        };

        const allocated = getNum(`input[name="activities[${rowIndex}][allocated_budget]"]`);
        const utilized  = getNum(`input[name="activities[${rowIndex}][budget_utilized]"]`);

        let burnRate = 0;
        if (allocated > 0) {
            burnRate = Math.min(100, (utilized / allocated) * 100);
        }

        const burnRateField = document.querySelector(`input[name="activities[${rowIndex}][budget_burn_rate]"]`);
        if (burnRateField) {
            burnRateField.value = burnRate.toFixed(1) + '%';
        }

        const remainingField = document.querySelector(`input[name="activities[${rowIndex}][remaining_budget]"]`);
        if (remainingField) {
            remainingField.value = (allocated - utilized).toFixed(2);
        }
    }

    // Add sample data for testing
    function addSampleData() {
        const sampleActivities = [
            {
                project_activity: 'Mobile Health and Nutrition Services',
                unit_of_measurement: 'persons',
                beneficiary_type: 'Children under 5, Pregnant Women',
                target_value: 1000,
                achievement_value: 750,
                achievement_type: 'persons',
                boys_under_18: 200,
                girls_under_18: 180,
                women_18_59: 370,
                allocated_budget: 50000,
                budget_utilized: 32500,
                progress_status: 'ongoing',
                activity_feedback: 'Good progress, on track to meet target. Some challenges with mobile clinic access in remote areas.'
            },
            {
                project_activity: 'Construction of Water Wells',
                unit_of_measurement: 'wells',
                beneficiary_type: 'Community members',
                target_value: 5,
                achievement_value: 3,
                achievement_type: 'non_persons',
                allocated_budget: 25000,
                budget_utilized: 18000,
                progress_status: 'ongoing',
                activity_feedback: 'Two wells completed, third well delayed due to equipment issues.'
            }
        ];

        const tbody = document.getElementById('activitiesBody');
        tbody.innerHTML = '';
        activityRowCount = 0;

        sampleActivities.forEach(activity => addActivityRow(activity));
        calculateSummary();
    }

    // Calculate summary statistics
    function calculateSummary() {
        const rows = document.querySelectorAll('#activitiesBody tr');
        let totalActivities = rows.length;
        let totalBeneficiaries = 0;
        let totalProgress = 0;
        let validActivities = 0;
        let totalBudgetAllocated = 0;
        let totalBudgetUtilized = 0;

        rows.forEach((row, index) => {
            const getNum = (selector) => {
                const el = document.querySelector(selector);
                return el ? parseFloat(el.value) || 0 : 0;
            };
            const getInt = (selector) => {
                const el = document.querySelector(selector);
                return el ? parseInt(el.value) || 0 : 0;
            };

            const beneficiaries = getInt(`input[name="activities[${index}][total_beneficiaries]"]`);
            const progress      = getNum(`input[name="activities[${index}][progress_percentage]"]`);
            const allocated     = getNum(`input[name="activities[${index}][allocated_budget]"]`);
            const utilized      = getNum(`input[name="activities[${index}][budget_utilized]"]`);

            totalBeneficiaries   += beneficiaries;
            totalBudgetAllocated += allocated;
            totalBudgetUtilized  += utilized;

            if (!isNaN(progress)) {
                totalProgress += progress;
                validActivities++;
            }
        });

        const overallProgress   = validActivities > 0 ? (totalProgress / validActivities) : 0;

        document.getElementById('totalActivities').textContent     = totalActivities;
        document.getElementById('totalBeneficiaries').textContent  = totalBeneficiaries.toLocaleString();
        document.getElementById('overallProgress').textContent     = overallProgress.toFixed(1) + '%';
        document.getElementById('overallProgressBar').style.width  = overallProgress + '%';
        document.getElementById('dataQualityScore').textContent    = '0%'; // Updated after quality check

        document.getElementById('summaryPanel').style.display = totalActivities > 0 ? 'block' : 'none';
    }

    // Run data quality checks (client-side helper)
    function runQualityChecks() {
        const panel           = document.getElementById('dataQualityPanel');
        const checksContainer = document.getElementById('qualityChecks');

        const activities = [];
        const rows = document.querySelectorAll('#activitiesBody tr');

        rows.forEach((row, index) => {
            const getNum = (selector) => {
                const el = document.querySelector(selector);
                return el ? parseFloat(el.value) || 0 : 0;
            };
            const getInt = (selector) => {
                const el = document.querySelector(selector);
                return el ? parseInt(el.value) || 0 : 0;
            };
            const getStr = (selector) => {
                const el = document.querySelector(selector);
                return el ? el.value || '' : '';
            };

            activities.push({
                project_activity:       getStr(`input[name="activities[${index}][project_activity]"]`),
                target_value:           getNum(`input[name="activities[${index}][target_value]"]`),
                achievement_value:      getNum(`input[name="activities[${index}][achievement_value]"]`),
                achievement_type:       getStr(`select[name="activities[${index}][achievement_type]"]`),
                allocated_budget:       getNum(`input[name="activities[${index}][allocated_budget]"]`),
                budget_utilized:        getNum(`input[name="activities[${index}][budget_utilized]"]`),
                boys_under_18:          getInt(`input[name="activities[${index}][boys_under_18]"]`),
                girls_under_18:         getInt(`input[name="activities[${index}][girls_under_18]"]`),
                men_18_59:              getInt(`input[name="activities[${index}][men_18_59]"]`),
                women_18_59:            getInt(`input[name="activities[${index}][women_18_59]"]`),
                elderly_men_60_plus:    getInt(`input[name="activities[${index}][elderly_men_60_plus]"]`),
                elderly_women_60_plus:  getInt(`input[name="activities[${index}][elderly_women_60_plus]"]`),
                total_beneficiaries:    getInt(`input[name="activities[${index}][total_beneficiaries]"]`)
            });
        });

        const qualityChecks = [];
        let errorCount   = 0;
        let warningCount = 0;

        // Check 1: Missing mandatory fields
        activities.forEach((activity, index) => {
            if (!activity.project_activity.trim()) {
                qualityChecks.push({
                    type: 'error',
                    message: `❌ Activity ${index + 1}: Project activity description is missing`,
                    suggestion: 'Please provide a description for the project activity'
                });
                errorCount++;
            }
        });

        // Check 2: Achievement exceeds target
        activities.forEach((activity, index) => {
            if (activity.achievement_value > activity.target_value && activity.target_value > 0) {
                qualityChecks.push({
                    type: 'warning',
                    message: `⚠️ Activity ${index + 1}: Achievement (${activity.achievement_value}) exceeds target (${activity.target_value})`,
                    suggestion: 'Verify achievement data or update target if necessary'
                });
                warningCount++;
            }
        });

        // Check 3: Budget utilization exceeds allocation
        activities.forEach((activity, index) => {
            if (activity.budget_utilized > activity.allocated_budget && activity.allocated_budget > 0) {
                qualityChecks.push({
                    type: 'error',
                    message: `❌ Activity ${index + 1}: Budget utilized (${activity.budget_utilized}) exceeds allocated budget (${activity.allocated_budget})`,
                    suggestion: 'Review budget utilization or request budget revision'
                });
                errorCount++;
            }
        });

        // Check 4: Beneficiary disaggregation doesn't match total
        activities.forEach((activity, index) => {
            if (activity.achievement_type === 'persons') {
                const calculatedTotal =
                    activity.boys_under_18 +
                    activity.girls_under_18 +
                    activity.men_18_59 +
                    activity.women_18_59 +
                    activity.elderly_men_60_plus +
                    activity.elderly_women_60_plus;

                if (calculatedTotal !== activity.total_beneficiaries) {
                    qualityChecks.push({
                        type: 'warning',
                        message: `⚠️ Activity ${index + 1}: Beneficiary disaggregation total (${calculatedTotal}) doesn't match recorded total (${activity.total_beneficiaries})`,
                        suggestion: 'Verify beneficiary disaggregation data'
                    });
                    warningCount++;
                }
            }
        });

        // Check 5: Zero progress on activities with targets
        activities.forEach((activity, index) => {
            if (activity.target_value > 0 && activity.achievement_value === 0) {
                qualityChecks.push({
                    type: 'warning',
                    message: `⚠️ Activity ${index + 1}: No progress reported for activity with target`,
                    suggestion: 'Consider updating achievement or reviewing activity status'
                });
                warningCount++;
            }
        });

        // Quality score (client-side mirror of server logic)
        const qualityScore = Math.max(0, 100 - (errorCount * 10 + warningCount * 5));
        document.getElementById('dataQualityScore').textContent = qualityScore.toFixed(1) + '%';

        checksContainer.innerHTML = '';
        if (qualityChecks.length === 0) {
            qualityChecks.push({
                type: 'success',
                message: '✅ All data quality checks passed! Your report data looks excellent.',
                suggestion: ''
            });
        }

        qualityChecks.forEach(check => {
            const div = document.createElement('div');
            div.className = `quality-item ${check.type}`;
            div.innerHTML = `
                <strong>${check.message}</strong>
                ${check.suggestion ? `<br><small>💡 ${check.suggestion}</small>` : ''}
            `;
            checksContainer.appendChild(div);
        });

        panel.style.display = 'block';

        if (errorCount > 0) {
            showNotification(`Data quality check completed with ${errorCount} error(s) and ${warningCount} warning(s). Please review before submitting.`, 'error');
        } else if (warningCount > 0) {
            showNotification(`Data quality check completed with ${warningCount} warning(s).`, 'warning');
        } else {
            showNotification('All data quality checks passed! Your report is ready for submission.', 'success');
        }
    }

    function hidePanels() {
        document.getElementById('dataQualityPanel').style.display = 'none';
        document.getElementById('summaryPanel').style.display     = 'none';
    }

    // Show notification
    function showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `notification ${type}`;
        notification.innerHTML = message;

        const container = document.querySelector('.card-content');
        if (container) {
            container.prepend(notification);
        } else {
            document.body.prepend(notification);
        }

        setTimeout(() => {
            notification.remove();
        }, 5000);
    }

    // Edit existing report (placeholder)
    function editReport(reportId) {
        if (confirm('This will load the existing report data. Any unsaved changes will be lost. Continue?')) {
            showNotification('Edit functionality would load report data here (AJAX).', 'info');
            currentEditingReportId = reportId;
            document.getElementById('report_id').value = reportId;
        }
    }

    // View report (placeholder)
    function viewReport(reportId) {
        showNotification('View functionality would display the report in read-only mode.', 'info');
    }

    // Form validation before submission
    function validateForm() {
        const projectId  = document.getElementById('project_id').value;
        const activities = document.querySelectorAll('#activitiesBody tr');

        if (!projectId) {
            showNotification('Please select a project before saving the report.', 'error');
            document.getElementById('project_id').focus();
            return false;
        }

        if (activities.length === 0) {
            showNotification('Please add at least one activity before saving the report.', 'error');
            return false;
        }

        let hasEmptyActivities = false;
        activities.forEach((row, index) => {
            const field = document.querySelector(`input[name="activities[${index}][project_activity]"]`);
            if (field && !field.value.trim()) {
                hasEmptyActivities = true;
                field.style.borderColor = 'var(--danger-color)';
            }
        });

        if (hasEmptyActivities) {
            showNotification('Please provide descriptions for all activities before saving the report.', 'error');
            return false;
        }

        return true;
    }

    // Save and submit for review (currently same as save, but with confirmation)
    function saveAndSubmit() {
        if (validateForm()) {
            if (confirm('Are you sure you want to submit this report for review? You will not be able to edit it after submission (once status logic is implemented).')) {
                document.getElementById('activityReportForm').submit();
            }
        }
    }

    function exportToExcel() {
    var activitiesTable = document.getElementById('activitiesTable');
    var reportsTable    = document.getElementById('reportsTable');

    if (!activitiesTable && !reportsTable) {
        showNotification('No data available to export.', 'warning');
        return;
    }

    var reportTitleField = document.querySelector('input[name="report_title"]');
    var reportTitle = reportTitleField ? reportTitleField.value : 'Project Activity Report';

    var projectSelect = document.getElementById('project_id');
    var projectName = '';
    if (projectSelect && projectSelect.value) {
        projectName = projectSelect.options[projectSelect.selectedIndex].text;
    }

    var regionField  = document.getElementById('region');
    var zoneField    = document.getElementById('zone');
    var woredaField  = document.getElementById('woreda');
    var reportTypeSelect = document.querySelector('select[name="report_type"]');
    var monthSelect      = document.querySelector('select[name="report_month"]');
    var yearSelect       = document.querySelector('select[name="report_year"]');
    var periodStartField = document.getElementById('report_period_start');
    var periodEndField   = document.getElementById('report_period_end');

    var region     = regionField  ? regionField.value  : '';
    var zone       = zoneField    ? zoneField.value    : '';
    var woreda     = woredaField  ? woredaField.value  : '';
    var reportType = reportTypeSelect && reportTypeSelect.value ? reportTypeSelect.value : '';
    var monthName  = (monthSelect && monthSelect.selectedIndex > 0)
        ? monthSelect.options[monthSelect.selectedIndex].text
        : '';
    var year       = yearSelect && yearSelect.value ? yearSelect.value : '';
    var periodFrom = periodStartField && periodStartField.value ? periodStartField.value : '';
    var periodTo   = periodEndField && periodEndField.value ? periodEndField.value : '';

    var headerHtml = ''
        + '<table border="1">'
        + '<tr><th colspan="4" style="font-size:16px;">Nexus Ethiopia - Project Activity Report</th></tr>'
        + '<tr><td><b>Project Title</b></td><td>' + escapeHtml(projectName) + '</td>'
        + '<td><b>Report Title</b></td><td>' + escapeHtml(reportTitle) + '</td></tr>'
        + '<tr><td><b>Region</b></td><td>' + escapeHtml(region) + '</td>'
        + '<td><b>Zone</b></td><td>' + escapeHtml(zone) + '</td></tr>'
        + '<tr><td><b>Woreda/Town</b></td><td>' + escapeHtml(woreda) + '</td>'
        + '<td><b>Report Type</b></td><td>' + escapeHtml(reportType) + '</td></tr>'
        + '<tr><td><b>Month / Year</b></td><td>' + escapeHtml(monthName + " " + year) + '</td>'
        + '<td><b>Reporting Period</b></td><td>' + escapeHtml(periodFrom + " to " + periodTo) + '</td></tr>'
        + '</table><br/>';

    var bodyHtml = '';

    if (activitiesTable) {
        bodyHtml += '<h3>Activity Details</h3>' + activitiesTable.outerHTML + '<br/>';
    }
    if (reportsTable) {
        bodyHtml += '<h3>Your Activity Reports</h3>' + reportsTable.outerHTML;
    }

    var html = '<html><head><meta charset="utf-8" /></head><body>' + headerHtml + bodyHtml + '</body></html>';

    var blob = new Blob([html], { type: 'application/vnd.ms-excel' });
    var url  = URL.createObjectURL(blob);
    var a    = document.createElement('a');
    var today = new Date();
    var fileDate = today.getFullYear() + '-'
        + String(today.getMonth() + 1).padStart(2, '0') + '-'
        + String(today.getDate()).padStart(2, '0');

    a.href = url;
    a.download = 'project_activity_report_' + fileDate + '.xls';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);

    showNotification('Excel export generated.', 'success');
}

function generatePDF() {
    var activitiesTable = document.getElementById('activitiesTable');
    var reportsTable    = document.getElementById('reportsTable');

    if (!activitiesTable && !reportsTable) {
        showNotification('No data available to print/PDF.', 'warning');
        return;
    }

    var reportTitleField = document.querySelector('input[name="report_title"]');
    var reportTitle = reportTitleField ? reportTitleField.value : 'Project Activity Report';

    var projectSelect = document.getElementById('project_id');
    var projectName = '';
    if (projectSelect && projectSelect.value) {
        projectName = projectSelect.options[projectSelect.selectedIndex].text;
    }

    var regionField  = document.getElementById('region');
    var zoneField    = document.getElementById('zone');
    var woredaField  = document.getElementById('woreda');
    var reportTypeSelect = document.querySelector('select[name="report_type"]');
    var periodStartField = document.getElementById('report_period_start');
    var periodEndField   = document.getElementById('report_period_end');

    var region     = regionField  ? regionField.value  : '';
    var zone       = zoneField    ? zoneField.value    : '';
    var woreda     = woredaField  ? woredaField.value  : '';
    var reportType = reportTypeSelect && reportTypeSelect.value ? reportTypeSelect.value : '';
    var periodFrom = periodStartField && periodStartField.value ? periodStartField.value : '';
    var periodTo   = periodEndField && periodEndField.value ? periodEndField.value : '';

    var content = '';
    content += '<html><head><meta charset="utf-8">';
    content += '<title>' + escapeHtml(reportTitle) + '</title>';
    content += '<style>';
    content += 'body{font-family:Arial,sans-serif;font-size:11px;}';
    content += 'h1,h2,h3{margin:4px 0;}';
    content += 'table{border-collapse:collapse;width:100%;font-size:10px;}';
    content += 'th,td{border:1px solid #000;padding:3px;vertical-align:top;}';
    content += 'th{background:#f0f0f0;}';
    content += '.header-table td{border:none;padding:2px 3px;}';
    content += '@media print{button{display:none;}}';
    content += '</style>';
    content += '</head><body>';
    content += '<h1 style="text-align:center;">Nexus Ethiopia - Project Activity Report</h1>';
    content += '<table class="header-table">';
    content += '<tr><td><strong>Project Title:</strong></td><td>' + escapeHtml(projectName) + '</td>';
    content += '<td><strong>Report Title:</strong></td><td>' + escapeHtml(reportTitle) + '</td></tr>';
    content += '<tr><td><strong>Region:</strong></td><td>' + escapeHtml(region) + '</td>';
    content += '<td><strong>Zone:</strong></td><td>' + escapeHtml(zone) + '</td></tr>';
    content += '<tr><td><strong>Woreda/Town:</strong></td><td>' + escapeHtml(woreda) + '</td>';
    content += '<td><strong>Report Type:</strong></td><td>' + escapeHtml(reportType) + '</td></tr>';
    content += '<tr><td><strong>Reporting Period:</strong></td><td colspan="3">'
        + escapeHtml(periodFrom + ' to ' + periodTo) + '</td></tr>';
    content += '</table><br/>';

    if (activitiesTable) {
        content += '<h3>Activity Details</h3>' + activitiesTable.outerHTML;
    }
    if (reportsTable) {
        content += '<br/><h3>Your Activity Reports</h3>' + reportsTable.outerHTML;
    }

    content += '<script>window.onload=function(){window.print();};<\/script>';
    content += '</body></html>';

    var printWindow = window.open('', '_blank');
    if (!printWindow) {
        showNotification('Popup blocked. Please allow popups for this site to generate PDF.', 'error');
        return;
    }
    printWindow.document.open();
    printWindow.document.write(content);
    printWindow.document.close();
}

function showReportTemplates() {
    var templates = [
        { id: 1, name: 'Monthly Report (current month)',   type: 'Monthly Report' },
        { id: 2, name: 'Quarterly Report (current quarter)', type: 'Quarterly Report' },
        { id: 3, name: 'Annual Report (current year)',     type: 'Annual Report' },
        { id: 4, name: 'Emergency Response (last 7 days)', type: 'Emergency Response Report' }
    ];

    var msg = 'Select a template by typing its number:\n\n';
    for (var i = 0; i < templates.length; i++) {
        msg += templates[i].id + ') ' + templates[i].name + '\n';
    }

    var choice = prompt(msg, '1');
    var choiceId = parseInt(choice, 10);
    if (isNaN(choiceId)) {
        return;
    }

    var selectedTemplate = null;
    for (var j = 0; j < templates.length; j++) {
        if (templates[j].id === choiceId) {
            selectedTemplate = templates[j];
            break;
        }
    }
    if (!selectedTemplate) {
        return;
    }

    var today = new Date();
    var year  = today.getFullYear();
    var monthIndex = today.getMonth(); // 0-11

    var reportTitleField = document.querySelector('input[name="report_title"]');
    var reportTypeSelect = document.querySelector('select[name="report_type"]');
    var monthSelect      = document.querySelector('select[name="report_month"]');
    var yearSelect       = document.querySelector('select[name="report_year"]');
    var periodStartField = document.getElementById('report_period_start');
    var periodEndField   = document.getElementById('report_period_end');

    if (reportTypeSelect) {
        reportTypeSelect.value = selectedTemplate.type;
    }

    function setMonthYearValues(targetMonthIndex, targetYear) {
        if (monthSelect) {
            monthSelect.value = targetMonthIndex + 1;
        }
        if (yearSelect) {
            yearSelect.value = targetYear;
        }
    }

    function setPeriod(startDate, endDate) {
        if (periodStartField) periodStartField.value = formatDate(startDate);
        if (periodEndField)   periodEndField.value   = formatDate(endDate);
    }

    var title = 'Project Activity Report';

    if (selectedTemplate.id === 1) {
        // Monthly
        var monthName = today.toLocaleString('default', { month: 'long' });
        title = 'Monthly Activity Report - ' + monthName + ' ' + year;
        var firstDay = new Date(year, monthIndex, 1);
        var lastDay  = new Date(year, monthIndex + 1, 0);
        setMonthYearValues(monthIndex, year);
        setPeriod(firstDay, lastDay);
    } else if (selectedTemplate.id === 2) {
        // Quarterly
        var quarter = Math.floor(monthIndex / 3) + 1;
        title = 'Quarter ' + quarter + ' Activity Report - ' + year;
        var quarterStartMonth = (quarter - 1) * 3;
        var qFirst = new Date(year, quarterStartMonth, 1);
        var qLast  = new Date(year, quarterStartMonth + 3, 0);
        if (monthSelect) monthSelect.value = quarterStartMonth + 1;
        if (yearSelect)  yearSelect.value  = year;
        setPeriod(qFirst, qLast);
    } else if (selectedTemplate.id === 3) {
        // Annual
        title = 'Annual Activity Report - ' + year;
        var aFirst = new Date(year, 0, 1);
        var aLast  = new Date(year, 11, 31);
        if (monthSelect) monthSelect.value = 1;
        if (yearSelect)  yearSelect.value  = year;
        setPeriod(aFirst, aLast);
    } else if (selectedTemplate.id === 4) {
        // Emergency - last 7 days
        title = 'Emergency Response Report - ' + year;
        var eEnd   = today;
        var eStart = new Date(year, monthIndex, Math.max(1, today.getDate() - 6));
        setMonthYearValues(monthIndex, year);
        setPeriod(eStart, eEnd);
    }

    if (reportTitleField) {
        reportTitleField.value = title;
    }

    showNotification('Template applied: ' + selectedTemplate.name, 'success');
}

    // Auto-save (placeholder)
    let autoSaveTimer;
    function setupAutoSave() {
        const form = document.getElementById('activityReportForm');
        if (!form) return;
        form.addEventListener('input', function () {
            clearTimeout(autoSaveTimer);
            autoSaveTimer = setTimeout(() => {
                console.log('Auto-save triggered (placeholder)');
            }, 2000);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Default one blank row (in case project not selected yet)
        addActivityRow();

        const today    = new Date();
        const monthName = today.toLocaleString('default', { month: 'long' });
        const titleField = document.querySelector('input[name="report_title"]');
        if (titleField) {
            titleField.value = `Monthly Activity Report - ${monthName} ${today.getFullYear()}`;
        }

        const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
        const lastDay  = new Date(today.getFullYear(), today.getMonth() + 1, 0);

        document.getElementById('report_period_start').value = formatDate(firstDay);
        document.getElementById('report_period_end').value   = formatDate(lastDay);

        const monthSelect = document.querySelector('select[name="report_month"]');
        if (monthSelect) {
            monthSelect.value = today.getMonth() + 1;
        }

        const projectSelect = document.getElementById('project_id');
        if (projectSelect) {
            projectSelect.addEventListener('change', updateProjectDetails);
        }

        setupAutoSave();
    });

    function formatDate(date) {
        const year  = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day   = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }
</script>

<?php
require_once $root_dir . '/footer.php';
?>
