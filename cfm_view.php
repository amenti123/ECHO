<?php
// cfm_view.php - Detailed view for a single CFM report (fancy style + print header)
require_once __DIR__ . '/../header.php';
require_permission('cfm', 'view');

$pdo = getPDO();

if (!function_exists('h')) {
    function h($str) {
        return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
    }
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    set_flash_message("Invalid CFM report ID.", "error");
    header("Location: cfm.php");
    exit;
}

$sql = "
    SELECT 
        cr.*,
        p.title AS project_title,
        r.name AS region_name
    FROM cfm_reports cr
    LEFT JOIN projects p ON cr.project_id = p.id
    LEFT JOIN regions r ON cr.region_id = r.id
    WHERE cr.id = ?
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    set_flash_message("CFM report not found.", "error");
    header("Location: cfm.php");
    exit;
}

// PRINT HEADER CONTEXT (matching main cfm.php)
$printProjectTitle = $report['project_title'] ?? 'All Nexus Ethiopia Projects';
$printRegion       = $report['region_name'] ?? 'All';
$printZone         = $report['zone_name'] ?? 'All';
$printWoreda       = $report['woreda_name'] ?? 'All';
$printMonthLabel   = !empty($report['date_of_report'])
    ? date('F Y', strtotime($report['date_of_report']))
    : date('F Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>CFM Report View - SMART Nexus</title>
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --gradient-primary: linear-gradient(135deg, #4361ee 0%, #3a0ca3 100%);
            --gradient-success: linear-gradient(135deg, #4cc9f0 0%, #4361ee 100%);
            --gradient-warning: linear-gradient(135deg, #f72585 0%, #b5179e 100%);
            --border-radius: 12px;
            --shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        body {
            font-family: Arial, sans-serif;
            background: #f3f4f6;
            margin: 0;
            padding: 0;
        }
        .page-container {
            max-width: 1200px;
            margin: 20px auto 40px;
            padding: 0 15px;
        }
        .cfm-header {
            background: var(--gradient-primary);
            color: white;
            padding: 25px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
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
        .cfm-title {
            font-size: 2rem;
            font-weight: 900;
            margin-bottom: 10px;
            text-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        .cfm-subtitle {
            font-size: 1rem;
            opacity: 0.9;
        }

        .cfm-container {
            background: white;
            border-radius: var(--border-radius);
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: var(--shadow);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 600;
            transition: all 0.3s ease;
            color: white;
        }
        .btn-primary { background: var(--gradient-primary); }
        .btn-secondary { background: linear-gradient(135deg, #6c757d 0%, #495057 100%); }
        .btn-print { background: var(--gradient-success); }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(0,0,0,0.18);
        }
        .btn-group-top {
            margin-bottom: 15px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .section-title {
            font-weight: 700;
            margin-top: 20px;
            margin-bottom: 8px;
            color: #111827;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .meta-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        .meta-table td {
            padding: 5px 6px;
            vertical-align: top;
            font-size: 0.95rem;
        }
        .meta-label {
            width: 28%;
            font-weight: 600;
            color: #374151;
        }
        .meta-value {
            width: 72%;
            color: #111827;
        }

        .ai-recommendation {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 18px;
            border-radius: 12px;
            margin-top: 18px;
            border-left: 5px solid #4cc9f0;
        }

        .print-header {
            display: none;
            text-align: center;
            margin-bottom: 25px;
            padding: 10px 0 20px;
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

        @keyframes wave {
            0% { transform: translateX(0); }
            50% { transform: translateX(-10px); }
            100% { transform: translateX(0); }
        }

        @media print {
            /* hide global system header/nav */
            header, nav, .sidebar, .topbar, .app-header, .main-header {
                display: none !important;
            }
            .no-print, .cfm-header {
                display: none !important;
            }
            .print-header {
                display: block;
            }
            body {
                background: #ffffff !important;
            }
            .cfm-container {
                box-shadow: none;
                border-radius: 0;
                padding-top: 0;
            }
        }
    </style>
</head>
<body>
<div class="page-container">

    <!-- PRINT HEADER (exact format as cfm.php) -->
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

    <!-- SCREEN HEADER -->
    <div class="cfm-header no-print">
        <div class="cfm-header-content">
            <h1 class="cfm-title">
                <i class="fas fa-eye"></i> CFM Report Detail View
            </h1>
            <p class="cfm-subtitle">
                Project: <?php echo h($printProjectTitle); ?> • Region: <?php echo h($printRegion); ?>
            </p>
        </div>
    </div>

    <!-- TOP BUTTONS -->
    <div class="btn-group-top no-print">
        <a href="cfm.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to CFM List
        </a>
        <a href="cfm_edit.php?id=<?php echo (int)$report['id']; ?>" class="btn btn-primary">
            <i class="fas fa-pen"></i> Edit this Report
        </a>
        <button class="btn btn-print" onclick="window.print();return false;">
            <i class="fas fa-print"></i> Print
        </button>
    </div>

    <div class="cfm-container">
        <h2><i class="fas fa-file-alt"></i> CFM Report #<?php echo (int)$report['id']; ?></h2>

        <div class="section-title">
            <i class="fas fa-building"></i> Project & Location
        </div>
        <table class="meta-table">
            <tr>
                <td class="meta-label">Project Title</td>
                <td class="meta-value"><?php echo h($report['project_title'] ?? ''); ?></td>
            </tr>
            <tr>
                <td class="meta-label">Organization</td>
                <td class="meta-value"><?php echo h($report['organization'] ?? ''); ?></td>
            </tr>
            <tr>
                <td class="meta-label">Region / Zone / Woreda</td>
                <td class="meta-value">
                    <?php echo h($report['region_name'] ?? ''); ?> /
                    <?php echo h($report['zone_name'] ?? ''); ?> /
                    <?php echo h($report['woreda_name'] ?? ''); ?>
                </td>
            </tr>
            <tr>
                <td class="meta-label">Date Feedback Received</td>
                <td class="meta-value"><?php echo h($report['date_feedback_received']); ?></td>
            </tr>
            <tr>
                <td class="meta-label">Date of Report</td>
                <td class="meta-value"><?php echo h($report['date_of_report']); ?></td>
            </tr>
            <tr>
                <td class="meta-label">Feedback Type</td>
                <td class="meta-value"><?php echo h($report['feedback_type'] ?? ''); ?></td>
            </tr>
        </table>

        <div class="section-title">
            <i class="fas fa-user"></i> Complainant Information
        </div>
        <table class="meta-table">
            <tr>
                <td class="meta-label">Reported By</td>
                <td class="meta-value"><?php echo h($report['reported_by'] ?? ''); ?></td>
            </tr>
            <tr>
                <td class="meta-label">Position</td>
                <td class="meta-value"><?php echo h($report['position'] ?? ''); ?></td>
            </tr>
            <tr>
                <td class="meta-label">Gender / Age</td>
                <td class="meta-value">
                    <?php echo h($report['gender'] ?? ''); ?>
                    <?php if (!empty($report['age'])): ?>
                        (<?php echo (int)$report['age']; ?>)
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td class="meta-label">Community Type</td>
                <td class="meta-value"><?php echo h($report['community_type'] ?? ''); ?></td>
            </tr>
            <tr>
                <td class="meta-label">Vulnerability</td>
                <td class="meta-value"><?php echo h($report['vulnerability'] ?? ''); ?></td>
            </tr>
            <tr>
                <td class="meta-label">Language</td>
                <td class="meta-value"><?php echo h($report['language'] ?? ''); ?></td>
            </tr>
        </table>

        <div class="section-title">
            <i class="fas fa-comments"></i> Feedback Details
        </div>
        <table class="meta-table">
            <tr>
                <td class="meta-label">Feedback Channel</td>
                <td class="meta-value"><?php echo h($report['feedback_channel'] ?? ''); ?></td>
            </tr>
            <tr>
                <td class="meta-label">Feedback Category</td>
                <td class="meta-value"><?php echo h($report['feedback_category'] ?? ''); ?></td>
            </tr>
            <tr>
                <td class="meta-label">Feedback Concern</td>
                <td class="meta-value"><?php echo nl2br(h($report['feedback_concern'] ?? '')); ?></td>
            </tr>
            <tr>
                <td class="meta-label">Actual Feedback</td>
                <td class="meta-value"><?php echo nl2br(h($report['actual_feedback'] ?? '')); ?></td>
            </tr>
        </table>

        <div class="section-title">
            <i class="fas fa-tasks"></i> Management & Follow-up
        </div>
        <table class="meta-table">
            <tr>
                <td class="meta-label">Feedback Status</td>
                <td class="meta-value"><?php echo h($report['feedback_status'] ?? ''); ?></td>
            </tr>
            <tr>
                <td class="meta-label">Actions Taken</td>
                <td class="meta-value"><?php echo nl2br(h($report['actions_taken'] ?? '')); ?></td>
            </tr>
            <tr>
                <td class="meta-label">Responsible for Follow Up</td>
                <td class="meta-value"><?php echo h($report['responsibility_follow_up'] ?? ''); ?></td>
            </tr>
            <tr>
                <td class="meta-label">Expected Closure Date</td>
                <td class="meta-value"><?php echo h($report['expected_closure_date'] ?? ''); ?></td>
            </tr>
            <tr>
                <td class="meta-label">Reason if Closure Date Passed</td>
                <td class="meta-value"><?php echo nl2br(h($report['reason_closure_passed'] ?? '')); ?></td>
            </tr>
            <tr>
                <td class="meta-label">Recommendation (MEAL/Program)</td>
                <td class="meta-value"><?php echo nl2br(h($report['recommendation'] ?? '')); ?></td>
            </tr>
        </table>

        <?php if (!empty($report['ai_recommendation'])): ?>
            <div class="ai-recommendation">
                <h4><i class="fas fa-robot"></i> AI Recommendation</h4>
                <div><?php echo nl2br(h($report['ai_recommendation'])); ?></div>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
