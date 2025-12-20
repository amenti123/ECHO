<?php
// cfm_edit.php - Edit form for a single CFM report (fancy style + AI preview + print header)
require_once __DIR__ . '/../header.php';
require_permission('cfm', 'view'); // or 'edit' if you use a separate permission

$pdo  = getPDO();
$user = current_user();

if (!function_exists('h')) {
    function h($str) {
        return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Copy of generateAIRecommendation() from cfm.php
 * (wrapped in function_exists so you can later move it to helpers without conflict)
 */
if (!function_exists('generateAIRecommendation')) {
    function generateAIRecommendation($data) {
        $feedback      = strtolower($data['actual_feedback'] ?? '');
        $category      = $data['feedback_category'] ?? '';
        $status        = $data['feedback_status'] ?? '';
        $channel       = $data['feedback_channel'] ?? '';
        $vulnerability = $data['vulnerability'] ?? '';

        $recommendations = [];
        $urgency_level   = "Medium";

        // basic sentiment-like analysis
        $negative_words = ['bad','poor','terrible','awful','horrible','failed','broken','wrong',
            'problem','issue','complaint','angry','frustrated','disappointed','unsatisfied','delay',
            'late','emergency','urgent','danger','unsafe','corruption','bribe','steal','death','die',
            'hunger','suffering'];
        $positive_words = ['good','great','excellent','wonderful','amazing','happy','satisfied',
            'thank','appreciate','helpful','working','success','improved','better'];

        $negative_count = 0;
        $positive_count = 0;

        foreach ($negative_words as $w) {
            if (strpos($feedback, $w) !== false) $negative_count++;
        }
        foreach ($positive_words as $w) {
            if (strpos($feedback, $w) !== false) $positive_count++;
        }

        if (strpos($feedback, 'urgent') !== false || strpos($feedback, 'emergency') !== false) {
            $urgency_level = "High";
        }
        if (strpos($feedback, 'death') !== false || strpos($feedback, 'die') !== false) {
            $urgency_level = "Critical";
            $recommendations[] = "🚨 CRITICAL: Immediate life-saving intervention required!";
        }
        if ($negative_count > $positive_count) {
            if ($urgency_level !== "Critical") $urgency_level = "High";
        } elseif ($positive_count > $negative_count) {
            $urgency_level = "Low";
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

        // Vulnerability
        if ($vulnerability === 'Child') {
            $recommendations[] = "👶 Child protection protocols must be followed";
            $urgency_level = "High";
        } elseif ($vulnerability === 'Disability') {
            $recommendations[] = "♿ Ensure accessibility and reasonable accommodation";
        } elseif ($vulnerability === 'Pregnant') {
            $recommendations[] = "🤰 Pregnant woman - prioritize maternal health services";
            $urgency_level = "High";
        }

        // Category
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

        // Status
        if ($status === 'New') {
            $recommendations[] = "🆕 Assign to relevant department within 24 hours";
        } elseif ($status === 'Under Review') {
            $recommendations[] = "🔍 Set clear timeline for resolution and communicate to complainant";
        } elseif ($status === 'Action Taken') {
            $recommendations[] = "✅ Verify effectiveness of actions and follow up with complainant";
        }

        // Channel
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
}

// Load projects and regions
$projects = [];
$regions  = [];
try {
    $projects = $pdo->query("SELECT id, title, name FROM projects ORDER BY title")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
try {
    $regions = $pdo->query("SELECT id, name FROM regions ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get ID
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    set_flash_message("Invalid CFM report ID.", "error");
    header("Location: cfm.php");
    exit;
}

// Handle POST (update)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_cfm'])) {
    try {
        $age = !empty($_POST['age']) ? (int)$_POST['age'] : null;
        $expected_closure_date = !empty($_POST['expected_closure_date']) ? $_POST['expected_closure_date'] : null;

        // recompute AI recommendation on edit
        $ai_recommendation = generateAIRecommendation($_POST);

        $sql = "
            UPDATE cfm_reports SET
                project_id = ?, 
                reported_by = ?, 
                position = ?, 
                date_feedback_received = ?, 
                date_of_report = ?,
                feedback_type = ?, 
                organization = ?, 
                region_id = ?, 
                zone_name = ?, 
                woreda_name = ?, 
                gender = ?, 
                age = ?,
                community_type = ?, 
                vulnerability = ?, 
                language = ?, 
                actual_feedback = ?, 
                feedback_channel = ?,
                feedback_category = ?, 
                feedback_concern = ?, 
                feedback_status = ?, 
                actions_taken = ?,
                responsibility_follow_up = ?, 
                expected_closure_date = ?, 
                reason_closure_passed = ?,
                recommendation = ?,
                ai_recommendation = ?,
                updated_at = NOW()
            WHERE id = ?
        ";

        $stmt = $pdo->prepare($sql);
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
            $id
        ]);

        set_flash_message("CFM report updated successfully (AI recommendation refreshed).", "success");
        header("Location: cfm_view.php?id=" . $id);
        exit;
    } catch (Exception $e) {
        set_flash_message("Error updating CFM report: " . $e->getMessage(), "error");
    }
}

// Load existing report
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
    <title>Edit CFM Report - SMART Nexus</title>
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
        .btn-save { background: var(--gradient-success); }
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

        .field-group {
            margin-bottom: 12px;
        }
        label {
            display: block;
            font-weight: 600;
            margin-bottom: 4px;
            color: #374151;
            font-size: 0.9rem;
        }
        input[type="text"],
        input[type="date"],
        input[type="number"],
        select,
        textarea {
            width: 100%;
            padding: 8px 10px;
            border-radius: 8px;
            border: 2px solid #e5e7eb;
            font-size: 0.9rem;
            box-sizing: border-box;
            transition: all 0.2s ease;
        }
        textarea {
            min-height: 80px;
        }
        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.15);
        }

        .form-grid-2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 20px;
        }

        .ai-recommendation {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 18px;
            border-radius: 12px;
            margin-top: 20px;
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

        @media (max-width: 768px) {
            .form-grid-2 {
                grid-template-columns: 1fr;
            }
            .cfm-title {
                font-size: 1.6rem;
            }
        }
    </style>
</head>
<body>
<div class="page-container">

    <!-- PRINT HEADER (same format as cfm.php) -->
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
                <i class="fas fa-pen-to-square"></i> Edit CFM Report #<?php echo (int)$report['id']; ?>
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
        <a href="cfm_view.php?id=<?php echo (int)$report['id']; ?>" class="btn btn-primary">
            <i class="fas fa-eye"></i> View
        </a>
        <button type="button" class="btn btn-secondary" onclick="window.print();return false;">
            <i class="fas fa-print"></i> Print
        </button>
    </div>

    <div class="cfm-container">
        <h2><i class="fas fa-clipboard-list"></i> Edit CFM Report Details</h2>

        <form method="post" id="cfmEditForm">

            <div class="form-grid-2">
                <!-- Column 1 -->
                <div>
                    <div class="section-title">
                        <i class="fas fa-building"></i> Project & Location
                    </div>

                    <div class="field-group">
                        <label>Project Title *</label>
                        <select name="project_id" required>
                            <option value="">-- Select Project --</option>
                            <?php foreach ($projects as $p): ?>
                                <option value="<?php echo $p['id']; ?>"
                                    <?php echo ($p['id'] == $report['project_id']) ? 'selected' : ''; ?>>
                                    <?php echo h($p['title'] ?? $p['name'] ?? ('Project ' . $p['id'])); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field-group">
                        <label>Organization *</label>
                        <input type="text" name="organization"
                               value="<?php echo h($report['organization'] ?? 'Nexus Ethiopia'); ?>" required>
                    </div>

                    <div class="field-group">
                        <label>Region *</label>
                        <select name="region_id" required>
                            <option value="">-- Select Region --</option>
                            <?php foreach ($regions as $r): ?>
                                <option value="<?php echo $r['id']; ?>"
                                    <?php echo ($r['id'] == $report['region_id']) ? 'selected' : ''; ?>>
                                    <?php echo h($r['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field-group">
                        <label>Zone Name</label>
                        <input type="text" name="zone_name"
                               value="<?php echo h($report['zone_name'] ?? ''); ?>">
                    </div>

                    <div class="field-group">
                        <label>Woreda Name</label>
                        <input type="text" name="woreda_name"
                               value="<?php echo h($report['woreda_name'] ?? ''); ?>">
                    </div>

                    <div class="section-title">
                        <i class="fas fa-clock"></i> Dates & Type
                    </div>

                    <div class="field-group">
                        <label>Date Feedback Received *</label>
                        <input type="date" name="date_feedback_received"
                               value="<?php echo h($report['date_feedback_received']); ?>" required>
                    </div>

                    <div class="field-group">
                        <label>Date of Report *</label>
                        <input type="date" name="date_of_report"
                               value="<?php echo h($report['date_of_report']); ?>" required>
                    </div>

                    <div class="field-group">
                        <label>Feedback Type *</label>
                        <select name="feedback_type" required>
                            <option value="new" <?php echo ($report['feedback_type'] === 'new') ? 'selected' : ''; ?>>
                                New Feedback This Month
                            </option>
                            <option value="pending" <?php echo ($report['feedback_type'] === 'pending') ? 'selected' : ''; ?>>
                                Pending/Unanswered from Last Month
                            </option>
                        </select>
                    </div>
                </div>

                <!-- Column 2 -->
                <div>
                    <div class="section-title">
                        <i class="fas fa-user"></i> Complainant
                    </div>

                    <div class="field-group">
                        <label>Reported By *</label>
                        <input type="text" name="reported_by"
                               value="<?php echo h($report['reported_by'] ?? ''); ?>" required>
                    </div>

                    <div class="field-group">
                        <label>Position *</label>
                        <input type="text" name="position"
                               value="<?php echo h($report['position'] ?? ''); ?>" required>
                    </div>

                    <div class="field-group">
                        <label>Gender *</label>
                        <select name="gender" required>
                            <option value="">-- Select Gender --</option>
                            <option value="Male"   <?php echo ($report['gender'] === 'Male') ? 'selected' : ''; ?>>Male</option>
                            <option value="Female" <?php echo ($report['gender'] === 'Female') ? 'selected' : ''; ?>>Female</option>
                            <option value="Other"  <?php echo ($report['gender'] === 'Other') ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>

                    <div class="field-group">
                        <label>Age</label>
                        <input type="number" name="age" min="1" max="120"
                               value="<?php echo h($report['age'] ?? ''); ?>">
                    </div>

                    <div class="field-group">
                        <label>Community Type</label>
                        <input type="text" name="community_type"
                               value="<?php echo h($report['community_type'] ?? ''); ?>">
                    </div>

                    <div class="field-group">
                        <label>Vulnerability</label>
                        <input type="text" name="vulnerability"
                               value="<?php echo h($report['vulnerability'] ?? ''); ?>">
                    </div>

                    <div class="field-group">
                        <label>Language</label>
                        <input type="text" name="language"
                               value="<?php echo h($report['language'] ?? 'Amharic'); ?>">
                    </div>

                    <div class="section-title">
                        <i class="fas fa-comments"></i> Feedback
                    </div>

                    <div class="field-group">
                        <label>Feedback Channel *</label>
                        <input type="text" name="feedback_channel"
                               value="<?php echo h($report['feedback_channel'] ?? ''); ?>" required>
                    </div>

                    <div class="field-group">
                        <label>Feedback Category *</label>
                        <input type="text" name="feedback_category"
                               value="<?php echo h($report['feedback_category'] ?? ''); ?>" required>
                    </div>
                </div>
            </div>

            <div class="section-title">
                <i class="fas fa-align-left"></i> Feedback Content
            </div>

            <div class="field-group">
                <label>Actual Feedback *</label>
                <textarea name="actual_feedback" required
                          oninput="updateAIRecommendation()"><?php echo h($report['actual_feedback'] ?? ''); ?></textarea>
            </div>

            <div class="field-group">
                <label>Feedback Concern (Sector/Subsector) *</label>
                <textarea name="feedback_concern" required><?php echo h($report['feedback_concern'] ?? ''); ?></textarea>
            </div>

            <div class="section-title">
                <i class="fas fa-tasks"></i> Management & Follow-up
            </div>

            <div class="form-grid-2">
                <div>
                    <div class="field-group">
                        <label>Feedback Status *</label>
                        <select name="feedback_status" required onchange="updateAIRecommendation()">
                            <?php
                            $statuses = ['New','Under Review','Action Taken','Resolved','Closed'];
                            foreach ($statuses as $st):
                            ?>
                                <option value="<?php echo $st; ?>"
                                    <?php echo ($report['feedback_status'] === $st) ? 'selected' : ''; ?>>
                                    <?php echo $st; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field-group">
                        <label>Expected Feedback Closure Date</label>
                        <input type="date" name="expected_closure_date"
                               value="<?php echo h($report['expected_closure_date'] ?? ''); ?>">
                    </div>

                    <div class="field-group">
                        <label>Reason if Closure Date Passed</label>
                        <textarea name="reason_closure_passed"><?php echo h($report['reason_closure_passed'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div>
                    <div class="field-group">
                        <label>Actions Taken</label>
                        <textarea name="actions_taken"><?php echo h($report['actions_taken'] ?? ''); ?></textarea>
                    </div>

                    <div class="field-group">
                        <label>Responsibility for Follow Up</label>
                        <input type="text" name="responsibility_follow_up"
                               value="<?php echo h($report['responsibility_follow_up'] ?? ''); ?>">
                    </div>

                    <div class="field-group">
                        <label>Recommendation (MEAL/Program Dept)</label>
                        <textarea name="recommendation" id="recommendation_field"><?php echo h($report['recommendation'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- AI Recommendation Preview (same idea as main cfm.php) -->
            <div class="ai-recommendation no-print" id="aiRecommendationPreview">
                <h4><i class="fas fa-robot"></i> AI-Powered Recommendation Preview</h4>
                <p id="aiRecommendationText">
                    <?php
                    if (!empty($report['ai_recommendation'])) {
                        echo nl2br(h($report['ai_recommendation']));
                    } else {
                        echo 'Fill or adjust the feedback fields to see AI-generated recommendations...';
                    }
                    ?>
                </p>
                <button type="button" class="btn btn-primary"
                        style="margin-top: 10px;"
                        onclick="generateAIRecommendationForMEAL()">
                    <i class="fas fa-magic"></i> Insert into Recommendation Field
                </button>
            </div>

            <div class="no-print" style="margin-top: 20px; display:flex; gap:10px; flex-wrap:wrap;">
                <button type="submit" name="save_cfm" class="btn btn-save">
                    <i class="fas fa-save"></i> Save Changes
                </button>
                <a href="cfm_view.php?id=<?php echo (int)$report['id']; ?>" class="btn btn-secondary">
                    <i class="fas fa-ban"></i> Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<script>
    // JS AI preview logic - simplified version of main cfm.php logic
    function generateAIRecommendationJS() {
        const feedback     = (document.querySelector('textarea[name="actual_feedback"]').value || '').toLowerCase();
        const category     = (document.querySelector('input[name="feedback_category"]').value || '').trim();
        const status       = (document.querySelector('select[name="feedback_status"]').value || '').trim();
        const channel      = (document.querySelector('input[name="feedback_channel"]').value || '').trim();
        const vulnerability= (document.querySelector('input[name="vulnerability"]').value || '').trim();

        let recommendations = [];
        let urgency_level   = "Medium";

        const negative_words = ['bad','poor','terrible','awful','horrible','failed','broken','wrong',
            'problem','issue','complaint','angry','frustrated','disappointed','unsatisfied','delay',
            'late','emergency','urgent','danger','unsafe','corruption','bribe','steal','death','die',
            'hunger','suffering'];
        const positive_words = ['good','great','excellent','wonderful','amazing','happy','satisfied',
            'thank','appreciate','helpful','working','success','improved','better'];
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

        if (feedback.includes('food') || feedback.includes('hunger')) {
            recommendations.push("🍲 Food security concern - escalate to nutrition team");
            if (feedback.includes('child')) {
                recommendations.push("👶 Child malnutrition risk - immediate screening needed");
            }
        }

        if (feedback.includes('corruption') || feedback.includes('bribe') || feedback.includes('steal')) {
            recommendations.push("⚖️ Ethics and compliance team notification required");
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
        } else if (channel === 'Suggestion Box') {
            recommendations.push("📬 Check suggestion box regularly and provide public responses");
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
        const previewEl = document.getElementById('aiRecommendationText');
        if (!previewEl) return;
        const text = generateAIRecommendationJS();
        previewEl.textContent = text;
    }

    function generateAIRecommendationForMEAL() {
        const text = generateAIRecommendationJS();
        const recField = document.getElementById('recommendation_field');
        if (recField) {
            recField.value = text;
        }
        updateAIRecommendation();
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Attach events for live preview
        const watchSelectors = [
            'textarea[name="actual_feedback"]',
            'input[name="feedback_channel"]',
            'input[name="feedback_category"]',
            'input[name="vulnerability"]',
            'select[name="feedback_status"]'
        ];
        watchSelectors.forEach(sel => {
            document.querySelectorAll(sel).forEach(el => {
                el.addEventListener('input', updateAIRecommendation);
                el.addEventListener('change', updateAIRecommendation);
            });
        });

        // initial preview (based on existing data)
        updateAIRecommendation();
    });
</script>
</body>
</html>
