<?php
// app_management.php
// Central, visual, searchable overview of all SMART Nexus apps (modules)
// + Admin center for permissions & layout (Kobo-style)
// + Global bulk assignment of app permissions

// ---------------------------------------------------------------------
// 0. SMART REQUIRE: safely locate helpers, header, footer
// ---------------------------------------------------------------------

$rootPath = dirname(__DIR__); // e.g. C:\xampp\htdocs\health_reporting_system

if (!function_exists('smart_require_once')) {
    /**
     * Try several candidate paths and require the first that exists.
     * If none exist, throw a clear exception (caught by helpers handlers later).
     */
    function smart_require_once(string $label, array $candidates): void
    {
        $tried = [];
        foreach ($candidates as $path) {
            $tried[] = $path;
            if (file_exists($path)) {
                require_once $path;
                return;
            }
        }

        $msg = $label . " file not found. Tried: \n" . implode("\n", $tried);
        throw new Exception($msg);
    }
}

// 0.1 Load helpers.php (must be first, so we get all shared functions)
smart_require_once('helpers.php', [
    $rootPath . '/helpers.php',          // root/helpers.php  (most likely)
    __DIR__ . '/helpers.php',            // pages/helpers.php
    $rootPath . '/includes/helpers.php', // root/includes/helpers.php
]);

require_login();

// User & role info
$user    = current_user();
$isAdmin = current_user_is_admin();

// If you ever want this page to be STRICT admin-only, uncomment next line:
// if (!$isAdmin) { safe_redirect('index.php'); }

// Configure page printing/export header (used if user prints the page)
setup_app_printing(
    'SMART Nexus – App Management & Navigation',
    'Overview of all modules and quick navigation'
);

// ---------------------------------------------------------------------
// 0.2 Local helpers for this page (DB table for layout, etc.)
// ---------------------------------------------------------------------

if (!function_exists('ensure_app_layout_schema')) {
    function ensure_app_layout_schema(PDO $pdo): void {
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS app_layout (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    app_key VARCHAR(50) NOT NULL UNIQUE,
                    group_name VARCHAR(100) NOT NULL,
                    sort_order INT NOT NULL DEFAULT 0
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            // Ignore errors – page will still work with default layout
        }
    }
}

$pdo = getPDO();
ensure_app_layout_schema($pdo);

// ---------------------------------------------------------------------
// 1. HANDLE ADMIN POST ACTIONS (permissions + layout + bulk permissions)
// ---------------------------------------------------------------------
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $redirectParams = [];

    // Preserve current selected user & sort in redirect
    if (isset($_POST['user_id'])) {
        $redirectParams['user_id'] = (int)$_POST['user_id'];
    } elseif (isset($_GET['user_id'])) {
        $redirectParams['user_id'] = (int)$_GET['user_id'];
    }
    if (isset($_GET['sort'])) {
        $redirectParams['sort'] = $_GET['sort'];
    }

    try {
        if ($action === 'save_permissions') {
            // Single user + single app
            $targetUserId = (int)($_POST['user_id'] ?? 0);
            $appKey       = $_POST['app_key'] ?? '';

            if ($targetUserId > 0 && $appKey !== '') {
                $canView = isset($_POST['can_view']) ? 1 : 0;
                $canEdit = isset($_POST['can_edit']) ? 1 : 0;
                $isHidden = isset($_POST['is_hidden']) ? 1 : 0;

                $stmt = $pdo->prepare("
                    INSERT INTO app_permissions (user_id, app_key, can_view, can_edit, is_hidden)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        can_view = VALUES(can_view),
                        can_edit = VALUES(can_edit),
                        is_hidden = VALUES(is_hidden)
                ");
                $stmt->execute([$targetUserId, $appKey, $canView, $canEdit, $isHidden]);

                set_flash_message("Permissions updated for {$appKey}.", 'success');
            }
        } elseif ($action === 'reset_permissions') {
            // Reset single user + single app
            $targetUserId = (int)($_POST['user_id'] ?? 0);
            $appKey       = $_POST['app_key'] ?? '';

            if ($targetUserId > 0 && $appKey !== '') {
                $stmt = $pdo->prepare("DELETE FROM app_permissions WHERE user_id = ? AND app_key = ?");
                $stmt->execute([$targetUserId, $appKey]);
                set_flash_message("Permissions reset to default for {$appKey}.", 'info');
            }

        } elseif ($action === 'save_layout') {
            // Save group & order for a single app (for header + this page)
            $appKey    = $_POST['app_key'] ?? '';
            $groupName = trim($_POST['group_name'] ?? '');
            $sortOrder = (int)($_POST['sort_order'] ?? 0);

            if ($appKey !== '' && $groupName !== '') {
                $stmt = $pdo->prepare("
                    INSERT INTO app_layout (app_key, group_name, sort_order)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        group_name = VALUES(group_name),
                        sort_order = VALUES(sort_order)
                ");
                $stmt->execute([$appKey, $groupName, $sortOrder]);
                set_flash_message("Layout updated for {$appKey}.", 'success');
            }

        } elseif ($action === 'bulk_permissions') {
            // NEW: Global bulk assignment – multi user + multi app
            $userIds = $_POST['user_ids'] ?? [];
            $appKeys = $_POST['app_keys'] ?? [];
            $preset  = $_POST['preset'] ?? 'view';

            $userIds = array_map('intval', $userIds);
            $userIds = array_values(array_filter($userIds, fn($id) => $id > 0));
            $appKeys = array_values(array_filter(array_map('trim', $appKeys)));

            if (!empty($userIds) && !empty($appKeys)) {
                // Custom booleans (used if preset === 'custom')
                $customCanView  = isset($_POST['can_view']) ? 1 : 0;
                $customCanEdit  = isset($_POST['can_edit']) ? 1 : 0;
                $customIsHidden = isset($_POST['is_hidden']) ? 1 : 0;

                $updatedCount = 0;
                $deletedCount = 0;

                foreach ($userIds as $uid) {
                    foreach ($appKeys as $ak) {
                        if ($preset === 'revoke') {
                            // Remove row – fall back to default system behaviour
                            $stmt = $pdo->prepare("DELETE FROM app_permissions WHERE user_id = ? AND app_key = ?");
                            $stmt->execute([$uid, $ak]);
                            $deletedCount++;
                            continue;
                        }

                        // Resolve rights based on preset
                        $canView = 0;
                        $canEdit = 0;
                        $isHidden = 0;

                        if ($preset === 'full') {
                            $canView = 1;
                            $canEdit = 1;
                            $isHidden = 0;
                        } elseif ($preset === 'view') {
                            $canView = 1;
                            $canEdit = 0;
                            $isHidden = 0;
                        } elseif ($preset === 'hide') {
                            // Hide from header and normal navigation, but still manageable here
                            $canView = 0;
                            $canEdit = 0;
                            $isHidden = 1;
                        } elseif ($preset === 'custom') {
                            $canView  = $customCanView;
                            $canEdit  = $customCanEdit;
                            $isHidden = $customIsHidden;
                        }

                        $stmt = $pdo->prepare("
                            INSERT INTO app_permissions (user_id, app_key, can_view, can_edit, is_hidden)
                            VALUES (?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE
                                can_view = VALUES(can_view),
                                can_edit = VALUES(can_edit),
                                is_hidden = VALUES(is_hidden)
                        ");
                        $stmt->execute([$uid, $ak, $canView, $canEdit, $isHidden]);
                        $updatedCount++;
                    }
                }

                if ($preset === 'revoke') {
                    set_flash_message("Bulk revoke completed: removed {$deletedCount} permission entries.", 'info');
                } else {
                    set_flash_message("Bulk permissions applied to {$updatedCount} user/app combinations.", 'success');
                }
            } else {
                set_flash_message("Please select at least one user and one app for bulk permissions.", 'warning');
            }
        }
    } catch (Throwable $e) {
        set_flash_message("Error: " . $e->getMessage(), 'danger');
    }

    // Redirect back (avoid form resubmission)
    $redirectUrl = 'app_management.php';
    if (!empty($redirectParams)) {
        $redirectUrl .= '?' . http_build_query($redirectParams);
    }
    safe_redirect($redirectUrl);
}

// ---------------------------------------------------------------------
// 2. BASE APP METADATA (GROUPS, DESCRIPTIONS, ICONS, URLS)
//    – Auto-detect "Other Apps" so new apps in APP_KEYS appear automatically
// ---------------------------------------------------------------------

// Define core app groups (keys must match APP_KEYS)
$appGroups = [
    'Overview' => [
        'dashboard',
    ],
    'Project Setup' => [
        'projects',
        'geography',
        'indicators',
        'planning',
        'budget',
    ],
    'Implementation & Data Entry' => [
        'enter_data',
        'cfm',
        'messages',
    ],
    'Reporting & Analysis' => [
        'view_reports',
        'custom_report',
        'aggregation',
        'pivot',
        'progress',
        'data_quality',
    ],
    'System & Intelligence' => [
        'nexus_ai',
        'users',
    ],
];

// Short descriptions for each app (shown in the cards)
$appDescriptions = [
    'dashboard'     => 'High-level overview of project performance and key indicators.',
    'projects'      => 'Register and manage project metadata, codes, donors and locations.',
    'geography'     => 'Manage regions, zones and woredas – shared by all modules.',
    'indicators'    => 'Define indicators, baselines, targets and disaggregation rules.',
    'planning'      => 'Plan logframe, activities and workplans linked to indicators.',
    'budget'        => 'Set and review project budgets and funding allocations.',
    'enter_data'    => 'Enter monthly/quarterly indicator data and SADD figures.',
    'view_reports'  => 'Standard project reports and indicator summaries.',
    'custom_report' => 'Build customized reports with filters and SADD views.',
    'aggregation'   => 'Aggregate data across periods, locations and beneficiary types.',
    'pivot'         => 'Pivot-style analysis tables across multiple dimensions.',
    'progress'      => 'Visual dashboards to track progress against targets.',
    'cfm'           => 'Complaint & Feedback Mechanism: cases, follow-up and analysis.',
    'messages'      => 'Internal messaging and notifications between users.',
    'data_quality'  => 'Data consistency, completeness and validation checks.',
    'nexus_ai'      => 'AI assistant for smart analysis and guidance within the system.',
    'users'         => 'User accounts, roles, permissions and project access.',
];

// Map app keys to their URLs
$appUrls = [
    'dashboard'     => 'index.php',
    'projects'      => 'projects.php',
    'geography'     => 'geography.php',
    'indicators'    => 'indicators.php',
    'planning'      => 'planning.php',
    'budget'        => 'budget.php',
    'enter_data'    => 'enter_data.php',
    'view_reports'  => 'view_reports.php',
    'custom_report' => 'custom_report.php',
    'aggregation'   => 'aggregation.php',
    'pivot'         => 'pivot.php',
    'progress'      => 'progress.php',
    'cfm'           => 'cfm.php',
    'messages'      => 'messages.php',
    'data_quality'  => 'data_quality.php',
    'nexus_ai'      => 'nexus_ai.php',
    'users'         => 'users.php',
];

// Optional icon mapping (Font Awesome icon names)
$appIcons = [
    'dashboard'     => 'tachometer-alt',
    'projects'      => 'project-diagram',
    'geography'     => 'globe-africa',
    'indicators'    => 'bullseye',
    'planning'      => 'tasks',
    'budget'        => 'money-bill-wave',
    'enter_data'    => 'keyboard',
    'view_reports'  => 'file-alt',
    'custom_report' => 'file-invoice',
    'aggregation'   => 'layer-group',
    'pivot'         => 'table',
    'progress'      => 'chart-line',
    'cfm'           => 'comments',
    'messages'      => 'envelope',
    'data_quality'  => 'check-circle',
    'nexus_ai'      => 'robot',
    'users'         => 'user-cog',
];

// Labels from APP_KEYS (defined in helpers.php)
$appLabels = defined('APP_KEYS') ? APP_KEYS : [];

// ---------------------------------------------------------------------
// 3. APPLY LAYOUT OVERRIDES (admin-configured grouping & order)
// ---------------------------------------------------------------------
$layoutByApp = [];
try {
    $stmt = $pdo->query("SELECT app_key, group_name, sort_order FROM app_layout");
    foreach ($stmt as $row) {
        $layoutByApp[$row['app_key']] = [
            'group_name' => $row['group_name'],
            'sort_order' => (int)$row['sort_order'],
        ];
    }
} catch (Throwable $e) {
    // Ignore, fallback to default groups
}

// Apply overrides: move apps into chosen group, with sort_order used later
if (!empty($layoutByApp)) {
    foreach ($layoutByApp as $appKey => $info) {
        // Remove from any existing group
        foreach ($appGroups as $gName => $apps) {
            $appGroups[$gName] = array_values(array_filter(
                $apps,
                fn($k) => $k !== $appKey
            ));
        }
        $gName = $info['group_name'] ?: 'Other Apps';
        if (!isset($appGroups[$gName])) {
            $appGroups[$gName] = [];
        }
        if (!in_array($appKey, $appGroups[$gName], true)) {
            $appGroups[$gName][] = $appKey;
        }
    }
}

// AUTO-DETECT "OTHER APPS" FROM APP_KEYS (not already in any group)
$allKnownKeys = array_keys($appLabels);
$assignedKeys = [];
foreach ($appGroups as $groupApps) {
    foreach ($groupApps as $k) {
        $assignedKeys[$k] = true;
    }
}
$unassignedApps = [];
foreach ($allKnownKeys as $k) {
    if (!isset($assignedKeys[$k])) {
        $unassignedApps[] = $k;
    }
}
if (!empty($unassignedApps)) {
    if (!isset($appGroups['Other Apps'])) {
        $appGroups['Other Apps'] = [];
    }
    foreach ($unassignedApps as $k) {
        if (!in_array($k, $appGroups['Other Apps'], true)) {
            $appGroups['Other Apps'][] = $k;
        }
    }
}

// ---------------------------------------------------------------------
// 4. PERMISSION-AWARE FILTERING – non-admin: show only allowed apps
// ---------------------------------------------------------------------
function get_visible_apps_for_user(array $groupApps): array {
    $visible = [];
    foreach ($groupApps as $key) {
        if (!defined('APP_KEYS') || !array_key_exists($key, APP_KEYS)) {
            continue;
        }
        if (user_can_view_app($key)) {
            $visible[] = $key;
        }
    }
    return $visible;
}

// System status (nice to show on this page)
$systemStatus = get_system_status();

// ---------------------------------------------------------------------
// 5. ADMIN-SIDE: LOAD USERS & PERMISSIONS FOR "GRANT ACCESS" PANEL
// ---------------------------------------------------------------------
$allUsers = [];
$selectedUserId = $user['id'] ?? 0;

if ($isAdmin) {
    try {
        $stmt = $pdo->query("SELECT id, full_name, email, role FROM users ORDER BY role DESC, full_name, email");
        $allUsers = $stmt->fetchAll();
    } catch (Throwable $e) {
        $allUsers = [];
    }

    if (isset($_GET['user_id'])) {
        $selectedUserId = (int)$_GET['user_id'];
    } elseif (!empty($allUsers)) {
        $selectedUserId = (int)$allUsers[0]['id'];
    }

    // Ensure selected user is valid
    $ids = array_column($allUsers, 'id');
    if (!in_array($selectedUserId, $ids, true) && !empty($allUsers)) {
        $selectedUserId = (int)$allUsers[0]['id'];
    }
} else {
    // Non-admins can only see their own effective permissions
    $selectedUserId = $user['id'] ?? 0;
}

$appPerms = [];
if ($selectedUserId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT app_key, can_view, can_edit, is_hidden FROM app_permissions WHERE user_id = ?");
        $stmt->execute([$selectedUserId]);
        foreach ($stmt as $row) {
            $appPerms[$row['app_key']] = [
                'can_view' => (int)$row['can_view'],
                'can_edit' => (int)$row['can_edit'],
                'is_hidden'=> (int)$row['is_hidden'],
            ];
        }
    } catch (Throwable $e) {
        $appPerms = [];
    }
}

// Sorting mode: default group-based, or alphabetical
$sortMode = $_GET['sort'] ?? 'group'; // 'group' or 'alpha'

// ---------------------------------------------------------------------
// 6. RENDER PAGE (header.php resolved robustly)
// ---------------------------------------------------------------------
smart_require_once('header.php', [
    __DIR__ . '/header.php',            // pages/header.php
    $rootPath . '/header.php',          // root/header.php
    $rootPath . '/includes/header.php', // root/includes/header.php
]);
?>
<style>
    .app-management-page {
        padding-top: 10px;
        padding-bottom: 20px;
    }
    .app-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
        gap: 14px;
    }
    .app-card {
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        background: #ffffff;
        padding: 10px 12px;
        display: flex;
        flex-direction: column;
        gap: 6px;
        box-shadow: 0 1px 3px rgba(15,23,42,0.08);
        transition: transform 0.12s ease, box-shadow 0.12s ease, border-color 0.12s ease;
    }
    .app-card:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(15,23,42,0.12);
        border-color: #4f46e5;
    }
    .app-card-header {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .app-card-icon {
        width: 34px;
        height: 34px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg,#4f46e5,#06b6d4);
        color: #fff;
        flex-shrink: 0;
        font-size: 16px;
    }
    .app-card-title {
        font-size: 13px;
        font-weight: 700;
        color: #111827;
    }
    .app-card-badge {
        font-size: 10px;
        font-weight: 600;
        padding: 1px 5px;
        border-radius: 999px;
        background: #eef2ff;
        color: #4f46e5;
        margin-left: auto;
    }
    .app-card-body {
        font-size: 11px;
        color: #6b7280;
        min-height: 32px;
    }
    .app-card-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 4px;
        gap: 6px;
        flex-wrap: wrap;
    }
    .btn-open-app {
        font-size: 11px;
        padding: 4px 8px;
        border-radius: 999px;
        border: none;
        background: #4f46e5;
        color: #fff;
        cursor: pointer;
    }
    .btn-open-app:hover {
        background: #4338ca;
    }
    .app-tag {
        font-size: 10px;
        padding: 2px 6px;
        border-radius: 999px;
        background: #f3f4f6;
        color: #4b5563;
    }
    .app-search-wrapper {
        display: flex;
        gap: 10px;
        align-items: center;
        margin-bottom: 10px;
        flex-wrap: wrap;
    }
    .app-search-input {
        flex: 1;
        min-width: 220px;
        font-size: 12px;
        padding: 6px 10px;
        border-radius: 999px;
        border: 1px solid #d1d5db;
        outline: none;
    }
    .app-search-input:focus {
        border-color: #4f46e5;
        box-shadow: 0 0 0 1px rgba(79,70,229,0.35);
    }
    .status-pill {
        font-size: 10px;
        padding: 3px 8px;
        border-radius: 999px;
        background: #ecfdf5;
        color: #047857;
    }
    .status-pill.badge-warn {
        background: #fffbeb;
        color: #b45309;
    }
    .status-pill.badge-error {
        background: #fef2f2;
        color: #b91c1c;
    }
    .admin-panel {
        background:#f9fafb;
        border:1px solid #e5e7eb;
        border-radius:10px;
        padding:10px 12px;
        margin-bottom:10px;
        font-size:12px;
    }
    .admin-panel label {
        font-size:11px;
        color:#4b5563;
        margin-right:4px;
    }
    .admin-permissions {
        margin-top:6px;
        padding:6px 8px;
        border-radius:8px;
        background:#f9fafb;
        border:1px dashed #d1d5db;
        font-size:11px;
    }
    .admin-permissions small {
        font-size:10px;
        color:#6b7280;
    }
    .admin-permissions form {
        margin-top:4px;
        display:flex;
        flex-wrap:wrap;
        gap:6px;
        align-items:center;
    }
    .admin-permissions input[type="checkbox"] {
        margin-right:2px;
    }
    .admin-permissions .btn-small {
        font-size:10px;
        padding:2px 6px;
        border-radius:999px;
        border:none;
        cursor:pointer;
    }
    .btn-small.save {
        background:#10b981;
        color:#fff;
    }
    .btn-small.reset {
        background:#e5e7eb;
        color:#374151;
    }
    .btn-small.layout {
        background:#3b82f6;
        color:#fff;
    }
    .btn-small.bulk {
        background:#4f46e5;
        color:#fff;
    }
    .preset-select {
        font-size:10px;
        padding:2px 4px;
        border-radius:999px;
        border:1px solid #d1d5db;
    }
</style>

<div class="container app-management-page">

    <?php
    echo render_block_header(
        'SMART Nexus – App Management & Navigation',
        'Search, explore and open any module from one place.',
        'th-large'
    );

    // AI hint – more focused on navigation / reporting
    echo render_ai_hint('report_generation');

    if ($flash = get_flash_message()): ?>
        <div style="margin-bottom:8px;font-size:11px;
            padding:6px 8px;border-radius:6px;
            background:<?php echo $flash['type']==='danger' ? '#fef2f2' : ($flash['type']==='info' ? '#eff6ff' : ($flash['type']==='warning' ? '#fffbeb' : '#ecfdf5')); ?>;
            color:<?php echo $flash['type']==='danger' ? '#b91c1c' : ($flash['type']==='info' ? '#1d4ed8' : ($flash['type']==='warning' ? '#92400e' : '#047857')); ?>;
            border:1px solid rgba(148,163,184,0.6);">
            <?php echo h($flash['message']); ?>
        </div>
    <?php endif; ?>

    <!-- Top controls: search + quick status + admin selector -->
    <div class="app-search-wrapper">
        <input
            type="text"
            id="app-search"
            class="app-search-input"
            placeholder="Search apps by name, purpose or category..."
            oninput="filterApps()"
        />
        <div style="display:flex;flex-direction:column;gap:2px;min-width:200px;">
            <div class="status-pill">
                <i class="fas fa-check-circle"></i>
                System: <?php echo h($systemStatus['cross_app_communication']); ?> &middot;
                Print: <?php echo h($systemStatus['print_system']); ?>
            </div>
            <div class="status-pill badge-warn">
                <i class="fas fa-users"></i>
                Active users (approx): <?php echo (int)$systemStatus['active_users']; ?>
                &nbsp;|&nbsp;
                Pending messages: <?php echo (int)$systemStatus['pending_messages']; ?>
            </div>
        </div>
    </div>

    <?php if ($isAdmin): ?>
        <!-- PER-USER CONTROL PANEL -->
        <div class="admin-panel">
            <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
                <div>
                    <label for="perm-user">Grant permissions for user:</label>
                    <select id="perm-user"
                            onchange="changePermUser(this.value)"
                            style="font-size:11px;padding:4px 6px;border-radius:6px;border:1px solid #d1d5db;min-width:170px;">
                        <?php foreach ($allUsers as $u): ?>
                            <option value="<?php echo (int)$u['id']; ?>"
                                <?php echo ($selectedUserId == (int)$u['id']) ? 'selected' : ''; ?>>
                                <?php
                                $name = $u['full_name'] ?: $u['email'];
                                echo h($name . ' (' . ($u['role'] ?? 'user') . ')');
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="sort-mode">Order apps:</label>
                    <select id="sort-mode"
                            onchange="changeSortMode(this.value)"
                            style="font-size:11px;padding:4px 6px;border-radius:6px;border:1px solid #d1d5db;">
                        <option value="group" <?php echo $sortMode === 'group' ? 'selected' : ''; ?>>
                            By group (default layout)
                        </option>
                        <option value="alpha" <?php echo $sortMode === 'alpha' ? 'selected' : ''; ?>>
                            Alphabetical A–Z
                        </option>
                    </select>
                </div>
                <div style="font-size:11px;color:#6b7280;">
                    <strong>Tip:</strong> This page is fully functional only for admins. Other users see apps but
                    cannot change permissions.
                </div>
            </div>
        </div>

        <!-- NEW: GLOBAL BULK APP PERMISSIONS PANEL -->
        <div class="admin-panel">
            <strong>Global App Permissions (bulk assignment)</strong>
            <div style="font-size:11px;color:#6b7280;margin-top:4px;">
                Select one or more users and one or more apps to grant or revoke access in one step.
                Hidden apps are removed from normal headers for those users, but always remain visible here
                for admins so you can restore them later.
            </div>
            <form method="post" style="margin-top:8px;display:flex;flex-wrap:wrap;gap:10px;align-items:flex-start;">
                <input type="hidden" name="action" value="bulk_permissions">

                <div style="min-width:220px;flex:1;">
                    <label style="display:block;margin-bottom:3px;">Users (multi-select):</label>
                    <select name="user_ids[]" multiple size="5"
                            style="width:100%;font-size:11px;padding:4px 6px;border-radius:6px;border:1px solid #d1d5db;">
                        <?php foreach ($allUsers as $u): ?>
                            <option value="<?php echo (int)$u['id']; ?>">
                                <?php
                                $name = $u['full_name'] ?: $u['email'];
                                echo h($name . ' (' . ($u['role'] ?? 'user') . ')');
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div style="font-size:10px;color:#9ca3af;margin-top:2px;">
                        Hold Ctrl (or Cmd on Mac) to select multiple.
                    </div>
                </div>

                <div style="min-width:220px;flex:1;">
                    <label style="display:block;margin-bottom:3px;">Apps (multi-select):</label>
                    <select name="app_keys[]" multiple size="5"
                            style="width:100%;font-size:11px;padding:4px 6px;border-radius:6px;border:1px solid #d1d5db;">
                        <?php foreach ($appLabels as $k => $label): ?>
                            <option value="<?php echo h($k); ?>">
                                <?php echo h($label . ' [' . $k . ']'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div style="font-size:10px;color:#9ca3af;margin-top:2px;">
                        All apps in the system, including hidden ones.
                    </div>
                </div>

                <div style="min-width:220px;flex:1;">
                    <label style="display:block;margin-bottom:3px;">Preset:</label>
                    <select name="preset"
                            style="font-size:11px;padding:4px 6px;border-radius:6px;border:1px solid #d1d5db;margin-bottom:6px;">
                        <option value="full">Full access (view + edit)</option>
                        <option value="view">View only</option>
                        <option value="hide">Hide (remove from header/menu)</option>
                        <option value="revoke">Revoke (delete custom row)</option>
                        <option value="custom">Custom (use checkboxes below)</option>
                    </select>

                    <div style="font-size:11px;color:#374151;margin-bottom:4px;">
                        Custom rights (used only if preset = Custom):
                    </div>
                    <label style="margin-right:6px;">
                        <input type="checkbox" name="can_view"> View
                    </label>
                    <label style="margin-right:6px;">
                        <input type="checkbox" name="can_edit"> Edit
                    </label>
                    <label>
                        <input type="checkbox" name="is_hidden"> Hide app (header only)
                    </label>

                    <div style="margin-top:8px;">
                        <button type="submit" class="btn-small bulk">
                            <i class="fas fa-layer-group"></i> Apply bulk permissions
                        </button>
                    </div>

                    <div style="font-size:10px;color:#9ca3af;margin-top:4px;">
                        • <strong>Revoke</strong> deletes the app_permissions row so the system falls back to the default.<br>
                        • <strong>Hide</strong> makes the app disappear from normal headers for selected users, but
                          admins will still see it here in App Management and can unhide later.
                    </div>
                </div>
            </form>
        </div>
    <?php else: ?>
        <div style="font-size:11px;color:#6b7280;margin-bottom:8px;">
            You are viewing <strong>available apps</strong>. Only administrators can change visibility and permissions.
        </div>
    <?php endif; ?>

    <!-- Small legend -->
    <div style="font-size:11px;color:#6b7280;margin-bottom:8px;">
        <strong>Legend:</strong>
        <span class="app-tag">Core</span>
        <span class="app-tag">Data Entry</span>
        <span class="app-tag">Reporting</span>
        <span class="app-tag">System / Admin</span>
    </div>

    <?php
    // Determine group order for rendering (for alpha mode we collapse into one group)
    $renderGroups = $appGroups;

    if ($sortMode === 'alpha') {
        // Flatten all apps, unique, then sort A–Z
        $allAppsFlat = [];
        foreach ($renderGroups as $gName => $apps) {
            foreach ($apps as $k) {
                if (!in_array($k, $allAppsFlat, true)) {
                    $allAppsFlat[] = $k;
                }
            }
        }
        usort($allAppsFlat, function($a, $b) use ($appLabels) {
            $la = $appLabels[$a] ?? ucfirst(str_replace('_',' ',$a));
            $lb = $appLabels[$b] ?? ucfirst(str_replace('_',' ',$b));
            return strcasecmp($la, $lb);
        });
        $renderGroups = [
            'All Apps (A–Z)' => $allAppsFlat
        ];
    }

    // Render each group
    foreach ($renderGroups as $groupName => $appsInGroup):

        // NEW: for admins, always show ALL apps in the group (even if hidden / no access),
        // so hidden apps like "view_reports" never disappear from this page.
        if ($isAdmin) {
            $visibleApps = [];
            foreach ($appsInGroup as $key) {
                if (!empty($appLabels) && !array_key_exists($key, $appLabels)) {
                    continue;
                }
                $visibleApps[] = $key;
            }
        } else {
            // Non-admin: permission-based filtering
            $visibleApps = get_visible_apps_for_user($appsInGroup);
        }

        if (empty($visibleApps)) {
            continue;
        }

        // Sort within group by custom sort_order if available, else by label
        usort($visibleApps, function($a, $b) use ($layoutByApp, $groupName, $appLabels) {
            $oa = $layoutByApp[$a]['sort_order'] ?? 0;
            $ob = $layoutByApp[$b]['sort_order'] ?? 0;
            if ($oa !== $ob) {
                return $oa <=> $ob;
            }
            $la = $appLabels[$a] ?? ucfirst(str_replace('_',' ',$a));
            $lb = $appLabels[$b] ?? ucfirst(str_replace('_',' ',$b));
            return strcasecmp($la, $lb);
        });

        echo render_block_header($groupName, 'Available modules in this category.', 'folder');
    ?>
        <div class="app-grid" data-app-group="<?php echo h($groupName); ?>">
            <?php foreach ($visibleApps as $key):
                $label = $appLabels[$key] ?? ucfirst(str_replace('_', ' ', $key));
                $desc  = $appDescriptions[$key] ?? ('Module for ' . $label . ' within SMART Nexus.');
                $url   = $appUrls[$key] ?? ($key . '.php');
                $icon  = $appIcons[$key] ?? 'cubes';

                // Tag by type (for style only)
                $tagText = 'Core';
                if (in_array($key, ['enter_data', 'cfm', 'messages'], true)) {
                    $tagText = 'Data Entry';
                } elseif (in_array($key, ['view_reports', 'custom_report', 'aggregation', 'pivot', 'progress', 'data_quality'], true)) {
                    $tagText = 'Reporting';
                } elseif (in_array($key, ['users', 'nexus_ai'], true)) {
                    $tagText = 'System / Admin';
                }

                $perm = $appPerms[$key] ?? null;
                $canView = $perm ? (bool)$perm['can_view'] : true;
                $canEdit = $perm ? (bool)$perm['can_edit'] : false;
                $isHidden = $perm ? (bool)$perm['is_hidden'] : false;

                $layoutInfo = $layoutByApp[$key] ?? [
                    'group_name' => $groupName,
                    'sort_order' => 0
                ];
            ?>
            <div class="app-card"
                 id="app-<?php echo h($key); ?>"
                 data-app-key="<?php echo h($key); ?>"
                 data-app-label="<?php echo h(strtolower($label)); ?>"
                 data-app-desc="<?php echo h(strtolower($desc)); ?>"
                 data-app-group-label="<?php echo h(strtolower($groupName)); ?>">
                <div class="app-card-header">
                    <div class="app-card-icon">
                        <i class="fas fa-<?php echo h($icon); ?>"></i>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <div class="app-card-title" title="<?php echo h($label); ?>">
                            <?php echo h($label); ?>
                        </div>
                        <div style="font-size:10px;color:#9ca3af;">
                            Key: <code style="font-size:10px;"><?php echo h($key); ?></code>
                        </div>
                    </div>
                    <?php if ($key === 'users' || $key === 'nexus_ai'): ?>
                        <span class="app-card-badge">Pro</span>
                    <?php else: ?>
                        <span class="app-card-badge">App</span>
                    <?php endif; ?>
                </div>
                <?php if ($desc): ?>
                <div class="app-card-body">
                    <?php echo h($desc); ?>
                </div>
                <?php endif; ?>
                <div class="app-card-footer">
                    <button class="btn-open-app"
                            onclick="window.location.href='<?php echo h($url); ?>';"
                            <?php echo (!$canView && !$isAdmin) ? 'disabled' : ''; ?>>
                        <i class="fas fa-arrow-right"></i>
                        <?php echo ($canView || $isAdmin) ? 'Open app' : 'No access'; ?>
                    </button>
                    <span class="app-tag"><?php echo h($tagText); ?></span>
                </div>

                <?php if ($isAdmin): ?>
                    <div class="admin-permissions">
                        <strong>Admin controls</strong>
                        <small> – for user ID <?php echo (int)$selectedUserId; ?></small>
                        <!-- Permission form -->
                        <form method="post">
                            <input type="hidden" name="action" value="save_permissions">
                            <input type="hidden" name="user_id" value="<?php echo (int)$selectedUserId; ?>">
                            <input type="hidden" name="app_key" value="<?php echo h($key); ?>">

                            <label>
                                <input type="checkbox" name="can_view" <?php echo $canView ? 'checked' : ''; ?>>
                                View
                            </label>
                            <label>
                                <input type="checkbox" name="can_edit" <?php echo $canEdit ? 'checked' : ''; ?>>
                                Edit
                            </label>
                            <label>
                                <input type="checkbox" name="is_hidden" <?php echo $isHidden ? 'checked' : ''; ?>>
                                Hide app (header)
                            </label>

                            <select class="preset-select"
                                    onchange="applyPresetToRow(this, '<?php echo h($key); ?>');">
                                <option value="">Preset…</option>
                                <option value="full">Full access</option>
                                <option value="view">View only</option>
                                <option value="hidden">Hidden</option>
                                <option value="clear">Default (no row)</option>
                            </select>

                            <button type="submit" class="btn-small save">
                                <i class="fas fa-save"></i> Save rights
                            </button>
                        </form>

                        <form method="post">
                            <input type="hidden" name="action" value="reset_permissions">
                            <input type="hidden" name="user_id" value="<?php echo (int)$selectedUserId; ?>">
                            <input type="hidden" name="app_key" value="<?php echo h($key); ?>">
                            <button type="submit" class="btn-small reset">
                                <i class="fas fa-undo"></i> Reset to default
                            </button>
                        </form>

                        <!-- Layout (group + order) -->
                        <form method="post" style="margin-top:4px;">
                            <input type="hidden" name="action" value="save_layout">
                            <input type="hidden" name="app_key" value="<?php echo h($key); ?>">
                            <label>Group:
                                <input type="text"
                                       name="group_name"
                                       value="<?php echo h($layoutInfo['group_name']); ?>"
                                       style="font-size:10px;padding:2px 4px;border-radius:6px;border:1px solid #d1d5db;max-width:120px;">
                            </label>
                            <label>Order:
                                <input type="number"
                                       name="sort_order"
                                       value="<?php echo (int)$layoutInfo['sort_order']; ?>"
                                       style="font-size:10px;padding:2px 4px;border-radius:6px;border:1px solid #d1d5db;width:60px;">
                            </label>
                            <button type="submit" class="btn-small layout">
                                <i class="fas fa-layer-group"></i> Save layout
                            </button>
                        </form>

                        <small>
                            <em>Note:</em> “Hide app” removes it from the header/app bar for that user,
                            but it <strong>always stays visible here in App Management</strong> so you can
                            restore it later. Layout changes affect how apps are grouped and ordered on
                            this page and in the header groups.
                        </small>
                    </div>
                <?php endif; ?>

            </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

</div>

<script>
// Simple client-side filtering by search text (name, description, group)
function filterApps() {
    var input  = document.getElementById('app-search');
    var query  = (input.value || '').toLowerCase();

    var cards  = document.querySelectorAll('.app-card');
    var groups = document.querySelectorAll('.app-grid');

    cards.forEach(function(card) {
        var label = card.getAttribute('data-app-label') || '';
        var desc  = card.getAttribute('data-app-desc') || '';
        var group = card.getAttribute('data-app-group-label') || '';
        var key   = card.getAttribute('data-app-key') || '';

        var haystack = (label + ' ' + desc + ' ' + group + ' ' + key).toLowerCase();
        var match = haystack.indexOf(query) !== -1;
        card.style.display = match ? '' : 'none';
    });

    // Hide whole group if all its cards are hidden
    groups.forEach(function(grid) {
        var visible = grid.querySelectorAll('.app-card:not([style*="display: none"])');
        grid.style.display = visible.length ? 'grid' : 'none';
    });
}

function changePermUser(userId) {
    var url = new URL(window.location.href);
    url.searchParams.set('user_id', userId);
    window.location.href = url.toString();
}

function changeSortMode(mode) {
    var url = new URL(window.location.href);
    url.searchParams.set('sort', mode);
    var userId = document.getElementById('perm-user');
    if (userId) {
        url.searchParams.set('user_id', userId.value);
    }
    window.location.href = url.toString();
}

// Apply Kobo-style permission presets to the row (view/edit/hide)
function applyPresetToRow(selectEl, appKey) {
    var preset = selectEl.value;
    if (!preset) return;

    var card = document.getElementById('app-' + appKey);
    if (!card) return;

    var form = card.querySelector('.admin-permissions form');
    if (!form) return;

    var viewCb  = form.querySelector('input[name="can_view"]');
    var editCb  = form.querySelector('input[name="can_edit"]');
    var hideCb  = form.querySelector('input[name="is_hidden"]');

    if (!viewCb || !editCb || !hideCb) return;

    if (preset === 'full') {
        viewCb.checked = true;
        editCb.checked = true;
        hideCb.checked = false;
    } else if (preset === 'view') {
        viewCb.checked = true;
        editCb.checked = false;
        hideCb.checked = false;
    } else if (preset === 'hidden') {
        viewCb.checked = false;
        editCb.checked = false;
        hideCb.checked = true;
    } else if (preset === 'clear') {
        // Clear to default – uncheck all then admin can click "Reset to default" if desired
        viewCb.checked = false;
        editCb.checked = false;
        hideCb.checked = false;
    }

    // Reset preset dropdown back to placeholder
    selectEl.value = '';
}
</script>

<?php
smart_require_once('footer.php', [
    __DIR__ . '/footer.php',            // pages/footer.php
    $rootPath . '/footer.php',          // root/footer.php
    $rootPath . '/includes/footer.php', // root/includes/footer.php
]);
