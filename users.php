<?php
require_once __DIR__ . '/../helpers.php';
require_login();
require_permission('users', 'edit'); // only admin / privileged users can manage users

$pdo     = getPDO();
$message = '';

// ---------- Small helpers for schema evolution ----------
if (!function_exists('table_has_column')) {
    function table_has_column(PDO $pdo, string $table, string $column): bool {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch();
    }
}

if (!function_exists('ensure_column')) {
    function ensure_column(PDO $pdo, string $table, string $column, string $definition): void {
        if (!table_has_column($pdo, $table, $column)) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    }
}

// ---------- Helper: count active admins (to avoid locking system) ----------
if (!function_exists('count_admin_users')) {
    function count_admin_users(PDO $pdo): int {
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1");
        return (int)$stmt->fetchColumn();
    }
}

// ---------- Ensure extra columns exist on users table ----------
ensure_column($pdo, 'users', 'full_name', "VARCHAR(190) NULL");
ensure_column($pdo, 'users', 'phone', "VARCHAR(50) NULL");
ensure_column($pdo, 'users', 'role', "ENUM('admin','user','guest') NOT NULL DEFAULT 'user'");
ensure_column($pdo, 'users', 'is_active', "TINYINT(1) NOT NULL DEFAULT 1");
ensure_column($pdo, 'users', 'email_verified', "TINYINT(1) NOT NULL DEFAULT 0");
ensure_column($pdo, 'users', 'verification_token', "VARCHAR(64) DEFAULT NULL");
ensure_column($pdo, 'users', 'photo_path', "VARCHAR(255) DEFAULT NULL");
ensure_column($pdo, 'users', 'password_hash', "VARCHAR(255) DEFAULT NULL");
ensure_column($pdo, 'users', 'last_login_at', "DATETIME DEFAULT NULL");
ensure_column($pdo, 'users', 'last_logout_at', "DATETIME DEFAULT NULL");
ensure_column($pdo, 'users', 'last_seen_at', "DATETIME DEFAULT NULL");
ensure_column($pdo, 'users', 'is_online', "TINYINT(1) NOT NULL DEFAULT 0");
ensure_column($pdo, 'users', 'failed_logins', "INT NOT NULL DEFAULT 0");
ensure_column($pdo, 'users', 'last_failed_login_at', "DATETIME DEFAULT NULL");
ensure_column($pdo, 'users', 'locked_until', "DATETIME DEFAULT NULL");
ensure_column($pdo, 'users', 'access_all_projects', "TINYINT(1) NOT NULL DEFAULT 1"); // NEW

// ---------- Ensure user_projects & app_permissions extra column ----------
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_projects (
            user_id   INT NOT NULL,
            project_id INT NOT NULL,
            PRIMARY KEY (user_id, project_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Throwable $e) {
    // ignore if DB user has no permission; you can add FKs manually if desired
}

try {
    ensure_column($pdo, 'app_permissions', 'is_hidden', "TINYINT(1) NOT NULL DEFAULT 0");
} catch (Throwable $e) {
    // app_permissions is created in helpers.php; if not available, ignore
}

// ---------- Load projects list for project access controls ----------
$allProjects = [];
try {
    if (function_exists('get_projects')) {
        $allProjects = get_projects();
    } else {
        $allProjects = $pdo->query("
            SELECT id, title, name
            FROM projects
            ORDER BY id ASC
        ")->fetchAll();
    }
} catch (Throwable $e) {
    $allProjects = [];
}

// app_permissions table is already created in helpers.php by ensure_user_schema_and_permissions()

// ---------- Helper for photo handling ----------
function delete_profile_photo_file(?string $relativePath): void {
    if (!$relativePath) return;
    $file = __DIR__ . '/../' . $relativePath;
    if (is_file($file)) {
        @unlink($file);
    }
}

// ---------- Handle POST actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CREATE USER (admin-created)
    if (isset($_POST['create'])) {
        $full_name           = trim($_POST['full_name'] ?? '');
        $email               = trim($_POST['email'] ?? '');
        $phone               = trim($_POST['phone'] ?? '');
        $role                = $_POST['role'] ?? 'user';
        $password            = $_POST['password'] ?? '';
        $access_all_projects = isset($_POST['access_all_projects']) ? 1 : 0;
        $project_ids         = array_map('intval', $_POST['project_ids'] ?? []);

        if ($full_name === '' || $email === '' || $password === '') {
            $message = 'Full name, email and password are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Please enter a valid email address.';
        } else {
            // Check if email already exists, to avoid 1062 duplicate error
            $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stmtCheck->execute([$email]);
            $existing = $stmtCheck->fetch();

            if ($existing) {
                $message = 'A user with this email already exists. '
                         . 'Please use the "Existing users" table below to update their role or details.';
            } else {
                $hash  = password_hash($password, PASSWORD_DEFAULT);
                $token = bin2hex(random_bytes(32));

                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO users (
                            full_name, email, phone, password_hash, role,
                            is_active, email_verified, verification_token,
                            access_all_projects
                        )
                        VALUES (?, ?, ?, ?, ?, 1, 0, ?, ?)
                    ");
                    $stmt->execute([
                        $full_name,
                        $email,
                        $phone,
                        $hash,
                        $role,
                        $token,
                        $access_all_projects
                    ]);

                    $newUserId = (int)$pdo->lastInsertId();

                    // If not all-projects, link selected projects
                    if (!$access_all_projects && !empty($project_ids)) {
                        $project_ids = array_unique(array_filter($project_ids));
                        $ins = $pdo->prepare("
                            INSERT INTO user_projects (user_id, project_id)
                            VALUES (?, ?)
                        ");
                        foreach ($project_ids as $pid) {
                            $ins->execute([$newUserId, $pid]);
                        }
                    }

                    // Optional: send welcome / verification email
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $verify_link = sprintf(
                        '%s://%s/pages/verify_email.php?token=%s',
                        $scheme,
                        $_SERVER['HTTP_HOST'] ?? 'localhost',
                        urlencode($token)
                    );

                    // Enhanced email with account details
                    $login_url = sprintf(
                        '%s://%s/login.php',
                        $scheme,
                        $_SERVER['HTTP_HOST'] ?? 'localhost'
                    );
                    
                    $body = "
                        <html>
                        <head>
                            <style>
                                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                                .header { background: #4361ee; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                                .content { background: #f9fafb; padding: 20px; border: 1px solid #e5e7eb; }
                                .details { background: white; padding: 15px; margin: 15px 0; border-radius: 4px; border-left: 4px solid #4361ee; }
                                .details table { width: 100%; border-collapse: collapse; }
                                .details td { padding: 8px; border-bottom: 1px solid #e5e7eb; }
                                .details td:first-child { font-weight: bold; width: 40%; }
                                .button { display: inline-block; padding: 12px 24px; background: #4361ee; color: white; text-decoration: none; border-radius: 4px; margin: 15px 0; }
                                .footer { text-align: center; padding: 20px; color: #6b7280; font-size: 12px; }
                            </style>
                        </head>
                        <body>
                            <div class=\"container\">
                                <div class=\"header\">
                                    <h2>🚀 Welcome to N-SMART System</h2>
                                </div>
                                <div class=\"content\">
                                    <p>Dear <strong>{$full_name}</strong>,</p>
                                    <p>Your account has been successfully created on the <strong>Nexus Ethiopia – SMART-Nexus Project Monitoring &amp; Reporting System</strong>.</p>
                                    
                                    <div class=\"details\">
                                        <h3>📋 Your Account Details</h3>
                                        <table>
                                            <tr><td>Full Name:</td><td>{$full_name}</td></tr>
                                            <tr><td>Email:</td><td>{$email}</td></tr>
                                            <tr><td>Phone:</td><td>" . ($phone ?: 'Not provided') . "</td></tr>
                                            <tr><td>Role:</td><td>" . ucfirst($role) . "</td></tr>
                                            <tr><td>Account Status:</td><td>Active</td></tr>
                                            <tr><td>Created On:</td><td>" . date('Y-m-d H:i:s') . "</td></tr>
                                        </table>
                                    </div>
                                    
                                    <p><strong>Next Steps:</strong></p>
                                    <ol>
                                        <li>Click the verification link below to verify your email address</li>
                                        <li>Log in using your email and the password provided by the administrator</li>
                                        <li>Change your password after first login (recommended)</li>
                                        <li>Update your profile and upload a profile photo</li>
                                    </ol>
                                    
                                    <p style=\"text-align: center;\">
                                        <a href=\"{$verify_link}\" class=\"button\">✅ Verify My Account</a>
                                    </p>
                                    
                                    <p>Or copy and paste this link into your browser:</p>
                                    <p style=\"word-break: break-all; color: #6b7280; font-size: 12px;\">{$verify_link}</p>
                                    
                                    <p><strong>Login URL:</strong> <a href=\"{$login_url}\">{$login_url}</a></p>
                                    
                                    <p style=\"margin-top: 20px; padding-top: 15px; border-top: 1px solid #e5e7eb;\">
                                        <small>If you did not request this account, please contact the system administrator immediately.</small>
                                    </p>
                                </div>
                                <div class=\"footer\">
                                    <p>This is an automated email from the N-SMART Reporting System.</p>
                                    <p>Please do not reply to this email.</p>
                                </div>
                            </div>
                        </body>
                        </html>
                    ";
                    if (function_exists('send_app_email')) {
                        send_app_email($email, 'Your N-SMART Account Has Been Created - Action Required', $body);
                    }

                    $message = 'User created and welcome/verification email sent.';
                } catch (PDOException $e) {
                    // If, for any reason, the unique constraint still fires, handle gracefully
                    if ($e->getCode() === '23000') {
                        $message = 'Could not create user: this email is already registered.';
                    } else {
                        $message = 'An unexpected database error occurred while creating the user.';
                    }
                }
            }
        }

    // UPDATE USER (name, phone, role, active, profile photo)
    } elseif (isset($_POST['update'])) {
        $id        = (int)($_POST['id'] ?? 0);
        $full_name = trim($_POST['full_name'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $role      = $_POST['role'] ?? 'user';
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($id > 0 && $full_name !== '') {

            // Get existing user (to handle last-admin protection + photo)
            $stmt = $pdo->prepare("SELECT photo_path, role AS old_role, is_active AS old_active FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $existing = $stmt->fetch();

            if ($existing) {
                $currentPhoto = $existing['photo_path'] ?? null;
                $oldRole      = $existing['old_role'] ?? $existing['old_role'] ?? $existing['role'] ?? null; // backward-safe
                $oldRole      = $existing['old_role'] ?? $existing['role'] ?? null;
                $oldActive    = isset($existing['old_active']) ? (int)$existing['old_active'] : (int)$existing['is_active'];

                // If this user is currently an active admin, avoid making them non-admin or inactive if they are last admin
                if ($oldRole === 'admin' && $oldActive === 1) {
                    $adminCount = count_admin_users($pdo);
                    $becomesNonAdmin = ($role !== 'admin' || $is_active !== 1);

                    if ($adminCount <= 1 && $becomesNonAdmin) {
                        $message = 'You cannot remove or deactivate the last active admin user.';
                    }
                }

                // Only continue update if we did not block it
                if ($message === '') {

                    // Handle delete photo checkbox
                    if (isset($_POST['delete_photo']) && $currentPhoto) {
                        delete_profile_photo_file($currentPhoto);
                        $currentPhoto = null;
                    }

                    // Handle new upload (image only)
                    if (!empty($_FILES['profile_photo']['name'])) {
                        $tmp  = $_FILES['profile_photo']['tmp_name'];
                        $name = $_FILES['profile_photo']['name'];
                        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                        $allowed = ['jpg','jpeg','png','gif','webp'];

                        if (in_array($ext, $allowed, true) && is_uploaded_file($tmp)) {
                            $uploadDir = __DIR__ . '/../uploads/profile_photos';
                            if (!is_dir($uploadDir)) {
                                mkdir($uploadDir, 0777, true);
                            }
                            $newName = 'user_' . $id . '_' . time() . '.' . $ext;
                            $target  = $uploadDir . '/' . $newName;

                            if (move_uploaded_file($tmp, $target)) {
                                // remove old file
                                if ($currentPhoto) {
                                    delete_profile_photo_file($currentPhoto);
                                }
                                // store relative path (no leading slash)
                                $currentPhoto = 'uploads/profile_photos/' . $newName;
                            } else {
                                $message .= ' (Photo upload failed.)';
                            }
                        } else {
                            $message .= ' (Invalid photo format – use JPG, PNG, GIF or WEBP.)';
                        }
                    }

                    // Update user including photo (access_all_projects handled in project-permissions form)
                    // Also update profile_photo column if it exists for compatibility
                    $stmtUp = $pdo->prepare("
                        UPDATE users 
                           SET full_name = ?, phone = ?, role = ?, is_active = ?, photo_path = ?
                         WHERE id = ?
                    ");
                    $stmtUp->execute([$full_name, $phone, $role, $is_active, $currentPhoto, $id]);
                    
                    // Also update profile_photo column if it exists (for compatibility with profile.php)
                    if (table_has_column($pdo, 'users', 'profile_photo')) {
                        $pdo->prepare("UPDATE users SET profile_photo = ? WHERE id = ?")
                            ->execute([$currentPhoto, $id]);
                    }
                    if (!$message) {
                        $message = 'User updated.';
                    }

                    // If current logged-in user updated their own record, refresh session
                    $me = current_user();
                    if ($me && (int)$me['id'] === $id && isset($_SESSION['user']) && is_array($_SESSION['user'])) {
                        $_SESSION['user']['full_name']  = $full_name;
                        $_SESSION['user']['phone']      = $phone;
                        $_SESSION['user']['role']       = $role;
                        $_SESSION['user']['is_active']  = $is_active;
                        $_SESSION['user']['photo_path'] = $currentPhoto;
                    }
                }

            } else {
                $message = 'User not found for update.';
            }
        }

    // DELETE USER (hard delete, except current & last admin)
    } elseif (isset($_POST['delete'])) {
        $id = (int)($_POST['id'] ?? 0);
        $me = current_user();

        if ($id > 0 && $me && $id !== (int)$me['id']) {

            // Load role to enforce last-admin rule
            $stmt = $pdo->prepare("SELECT role, is_active, photo_path FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $u = $stmt->fetch();

            if ($u) {
                $role      = $u['role'] ?? 'user';
                $is_active = (int)($u['is_active'] ?? 0);

                if ($role === 'admin' && $is_active === 1) {
                    $adminCount = count_admin_users($pdo);
                    if ($adminCount <= 1) {
                        $message = 'You cannot delete the last active admin user.';
                    }
                }

                if ($message === '') {
                    // delete photo file first
                    if (!empty($u['photo_path'])) {
                        delete_profile_photo_file($u['photo_path']);
                    }

                    // delete explicit app permissions for this user
                    $pdo->prepare("DELETE FROM app_permissions WHERE user_id = ?")->execute([$id]);
                    $pdo->prepare("DELETE FROM user_projects WHERE user_id = ?")->execute([$id]);

                    $stmtDel = $pdo->prepare("DELETE FROM users WHERE id = ?");
                    $stmtDel->execute([$id]);
                    $message = 'User deleted.';
                }
            } else {
                $message = 'User not found for deletion.';
            }
        }

    // RESEND VERIFICATION EMAIL
    } elseif (isset($_POST['resend_verification'])) {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id, full_name, email, verification_token FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $u = $stmt->fetch();
        if ($u) {
            $token = $u['verification_token'] ?: bin2hex(random_bytes(32));
            if (!$u['verification_token']) {
                $pdo->prepare("UPDATE users SET verification_token = ? WHERE id = ?")
                    ->execute([$token, $u['id']]);
            }

            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $verify_link = sprintf(
                '%s://%s/pages/verify_email.php?token=%s',
                $scheme,
                $_SERVER['HTTP_HOST'] ?? 'localhost',
                urlencode($token)
            );
            $body = "
                <p>Dear {$u['full_name']},</p>
                <p>Please verify your N-SMART account by clicking the link below:</p>
                <p><a href=\"{$verify_link}\">Verify my N-SMART account</a></p>
            ";
            if (function_exists('send_app_email')) {
                send_app_email($u['email'], 'Verify your N-SMART reporting system account', $body);
            }
            $message = 'Verification email resent.';
        }

    // SAVE PROJECT ACCESS (all projects vs selected list)
    } elseif (isset($_POST['save_project_access'])) {
        $permUserId = (int)($_POST['perm_user_id'] ?? 0);
        if ($permUserId > 0) {
            $accessAll   = isset($_POST['access_all_projects']) ? 1 : 0;
            $projectIds  = array_map('intval', $_POST['project_ids'] ?? []);
            $projectIds  = array_unique(array_filter($projectIds));

            $pdo->prepare("
                UPDATE users
                   SET access_all_projects = ?
                 WHERE id = ?
            ")->execute([$accessAll, $permUserId]);

            $pdo->prepare("DELETE FROM user_projects WHERE user_id = ?")
                ->execute([$permUserId]);

            if (!$accessAll && $projectIds) {
                $ins = $pdo->prepare("
                    INSERT INTO user_projects (user_id, project_id)
                    VALUES (?, ?)
                ");
                foreach ($projectIds as $pid) {
                    $ins->execute([$permUserId, $pid]);
                }
            }

            $message = 'Project access updated.';
            safe_redirect('settings_users.php?perm_user_id=' . $permUserId . '#permissions');
        }

    // SAVE APP-LEVEL PERMISSIONS
    } elseif (isset($_POST['save_permissions'])) {
        $permUserId = (int)($_POST['perm_user_id'] ?? 0);
        if ($permUserId > 0) {
            // Clear existing explicit permissions
            $stmt = $pdo->prepare("DELETE FROM app_permissions WHERE user_id = ?");
            $stmt->execute([$permUserId]);

            // Re-insert from form
            if (defined('APP_KEYS')) {
                $insert = $pdo->prepare("
                    INSERT INTO app_permissions (user_id, app_key, can_view, can_edit, is_hidden)
                    VALUES (?, ?, ?, ?, ?)
                ");
                foreach (APP_KEYS as $key => $label) {
                    $view  = !empty($_POST['perm_' . $key . '_view']) ? 1 : 0;
                    $edit  = !empty($_POST['perm_' . $key . '_edit']) ? 1 : 0;
                    $hide  = !empty($_POST['perm_' . $key . '_hide']) ? 1 : 0;

                    if ($hide) {
                        // Hide overrides everything: no view, no edit
                        $view = 0;
                        $edit = 0;
                    } elseif ($edit && !$view) {
                        // If edit is checked, ensure view is also true
                        $view = 1;
                    }

                    if ($view || $edit || $hide) {
                        $insert->execute([$permUserId, $key, $view, $edit, $hide]);
                    }
                }
            }

            $message = 'App-level permissions updated.';
            safe_redirect('settings_users.php?perm_user_id=' . $permUserId . '#permissions');
        }
    }
}

// ---------- Fetch users list ----------
// Get both photo_path and profile_photo for compatibility
$photoCol = table_has_column($pdo, 'users', 'photo_path') ? 'photo_path' : 
            (table_has_column($pdo, 'users', 'profile_photo') ? 'profile_photo' : 'NULL as photo_path');
$users = $pdo->query("
    SELECT id, full_name, email, phone, role, is_active, email_verified,
           is_online, last_login_at, last_logout_at, last_seen_at, 
           COALESCE(photo_path, profile_photo, NULL) as photo_path,
           access_all_projects
      FROM users
  ORDER BY id DESC
")->fetchAll();

// ---------- Fetch all app permissions ----------
$permsByUser = [];
$stmtPerms   = $pdo->query("
    SELECT user_id, app_key, can_view, can_edit, is_hidden
      FROM app_permissions
");
foreach ($stmtPerms as $row) {
    $uid = (int)$row['user_id'];
    if (!isset($permsByUser[$uid])) {
        $permsByUser[$uid] = [];
    }
    $permsByUser[$uid][$row['app_key']] = [
        'view'   => (bool)$row['can_view'],
        'edit'   => (bool)$row['can_edit'],
        'hidden' => (bool)$row['is_hidden'],
    ];
}

// ---------- Fetch project assignments per user ----------
$projectsByUser = [];
try {
    $rs = $pdo->query("SELECT user_id, project_id FROM user_projects");
    foreach ($rs as $row) {
        $uid = (int)$row['user_id'];
        if (!isset($projectsByUser[$uid])) {
            $projectsByUser[$uid] = [];
        }
        $projectsByUser[$uid][] = (int)$row['project_id'];
    }
} catch (Throwable $e) {
    // ignore
}

// Selected user for permission editing (via link ?perm_user_id=ID)
$permUserId = isset($_GET['perm_user_id']) ? (int)$_GET['perm_user_id'] : 0;
$permUser   = null;
if ($permUserId > 0) {
    foreach ($users as $u) {
        if ((int)$u['id'] === $permUserId) {
            $permUser = $u;
            break;
        }
    }
}

require_once __DIR__ . '/../header.php';
?>
<div class="card">
    <h1>User Management &amp; Roles / Permissions</h1>

    <?php if ($message): ?>
        <p class="badge-success"><?php echo h($message); ?></p>
    <?php endif; ?>

    <p>
        This module lets you manage <strong>N-SMART</strong> user accounts, roles
        (<strong>Admin / User / Guest</strong>), project access and per-app permissions
        (view / edit / hide). Guests are view-only and restricted to non-sensitive apps.
    </p>
</div>

<!-- CREATE USER -->
<div class="card">
    <h2>Create user</h2>
    <form method="post">
        <div class="form-row">
            <label>Full name
                <input type="text" name="full_name" required>
            </label>
            <label>Email
                <input type="email" name="email" required>
            </label>
            <label>Phone
                <input type="text" name="phone" placeholder="+251...">
            </label>
        </div>
        <div class="form-row">
            <label>Initial password
                <input type="password" name="password" required>
            </label>
            <label>Role
                <select name="role">
                    <option value="user">User</option>
                    <option value="admin">Admin</option>
                    <option value="guest">Guest (view-only)</option>
                </select>
            </label>
        </div>

        <div class="form-row">
            <label style="margin-right:16px;">
                <input type="checkbox" name="access_all_projects" value="1" checked>
                Access <strong>all projects</strong> (existing &amp; future)
            </label>
        </div>

        <?php if ($allProjects): ?>
            <div class="form-row" style="margin-top:4px;">
                <label>
                    Specific projects (use when you uncheck "all projects")<br>
                    <select name="project_ids[]" multiple size="5" style="min-width:260px;">
                        <?php foreach ($allProjects as $p): ?>
                            <?php
                                $pid   = (int)$p['id'];
                                $label = $p['title'] ?? ($p['name'] ?? ('Project #' . $pid));
                            ?>
                            <option value="<?php echo $pid; ?>"><?php echo h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div style="font-size:11px;color:#6b7280;margin-left:8px;">
                    Tip: Hold <strong>Ctrl</strong> (or ⌘ on Mac) to select multiple projects.
                    <br>On many systems <strong>Ctrl+A</strong> selects all.
                </div>
            </div>
        <?php endif; ?>

        <div class="form-row" style="margin-top:8px;">
            <button type="submit" name="create" class="btn-sm">Create user</button>
        </div>

        <p style="font-size:12px; color:#6b7280; margin-top:4px;">
            After creation, the system emails the user a welcome / verification link.
            You can further refine project and app-level permissions in the section below.
        </p>
    </form>
</div>

<!-- EXISTING USERS -->
<div class="card">
    <h2>Existing users</h2>
    <table class="table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Photo</th>
                <th>Full name</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Role</th>
                <th>Status</th>
                <th>Online?</th>
                <th>Last login</th>
                <th>Last logout</th>
                <th>Last seen</th>
                <th>Perm.</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <form method="post" enctype="multipart/form-data">
                    <td><?php echo (int)$u['id']; ?></td>
                    <td>
                        <?php 
                        $photoPath = $u['photo_path'] ?? $u['profile_photo'] ?? null;
                        $photoUrl = '';
                        if ($photoPath) {
                            // Handle both absolute and relative paths
                            if (strpos($photoPath, 'http') === 0) {
                                $photoUrl = $photoPath;
                            } elseif (strpos($photoPath, '/') === 0) {
                                $photoUrl = $photoPath;
                            } else {
                                $photoUrl = '/' . ltrim($photoPath, '/');
                            }
                            // Add cache busting
                            $photoUrl .= '?t=' . time();
                        }
                        ?>
                        <?php if ($photoPath && file_exists(__DIR__ . '/../' . ltrim($photoPath, '/'))): ?>
                            <img src="<?php echo h($photoUrl); ?>"
                                 alt="photo"
                                 style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid #ddd;"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';">
                            <span style="display:none;font-size:11px;color:#9ca3af;">Photo not found</span><br>
                            <label style="font-size:11px;">
                                <input type="checkbox" name="delete_photo" value="1">
                                Delete
                            </label>
                        <?php else: ?>
                            <span style="font-size:11px;color:#9ca3af;">No photo</span>
                        <?php endif; ?>
                        <div style="margin-top:4px;">
                            <input type="file" name="profile_photo" accept="image/*" style="font-size:11px;">
                        </div>
                    </td>
                    <td>
                        <input type="text" name="full_name"
                               value="<?php echo h($u['full_name']); ?>" required>
                    </td>
                    <td><?php echo h($u['email']); ?></td>
                    <td>
                        <input type="text" name="phone"
                               value="<?php echo h($u['phone']); ?>" style="width:110px;">
                    </td>
                    <td>
                        <select name="role">
                            <option value="user"  <?php echo $u['role']==='user'  ? 'selected' : ''; ?>>User</option>
                            <option value="admin" <?php echo $u['role']==='admin' ? 'selected' : ''; ?>>Admin</option>
                            <option value="guest" <?php echo $u['role']==='guest' ? 'selected' : ''; ?>>Guest</option>
                        </select>
                    </td>
                    <td>
                        <label style="font-size:12px;">
                            <input type="checkbox" name="is_active" value="1"
                                   <?php echo $u['is_active'] ? 'checked' : ''; ?>>
                            Active
                        </label>
                        <div style="font-size:11px;">
                            <?php echo $u['email_verified'] ? 'Verified' : 'Not verified'; ?>
                        </div>
                        <div style="font-size:11px;color:#6b7280;">
                            Projects:
                            <?php echo $u['access_all_projects'] ? 'All' : 'Custom'; ?>
                        </div>
                    </td>
                    <td>
                        <?php if ($u['is_online']): ?>
                            <span style="color:green;font-weight:bold;">Online</span>
                        <?php else: ?>
                            <span style="color:#9ca3af;">Offline</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:11px;"><?php echo h($u['last_login_at']); ?></td>
                    <td style="font-size:11px;"><?php echo h($u['last_logout_at']); ?></td>
                    <td style="font-size:11px;"><?php echo h($u['last_seen_at']); ?></td>
                    <td style="font-size:11px;">
                        <a href="settings_users.php?perm_user_id=<?php echo (int)$u['id']; ?>#permissions">
                            Edit
                        </a>
                    </td>
                    <td>
                        <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                        <button type="submit" name="update" class="btn-sm">Save</button>
                        <?php if (current_user() && (int)$u['id'] !== (int)current_user()['id']): ?>
                            <button type="submit" name="delete" class="btn-sm btn-secondary"
                                    onclick="return confirm('Delete this user? This cannot be undone.');">
                                Delete
                            </button>
                        <?php endif; ?>
                        <?php if (!$u['email_verified']): ?>
                            <button type="submit" name="resend_verification" class="btn-sm btn-secondary">
                                Resend verification
                            </button>
                        <?php endif; ?>
                    </td>
                </form>
            </tr>
        <?php endforeach; ?>
        <?php if (!$users): ?>
            <tr><td colspan="13">No users yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- PROJECT & APP PERMISSIONS -->
<div class="card" id="permissions">
    <h2>User access: projects &amp; apps</h2>

    <?php if ($permUser): ?>
        <p>
            Managing access for:
            <strong><?php echo h($permUser['full_name'] ?: $permUser['email']); ?></strong>
            (Role: <?php echo h(ucfirst($permUser['role'])); ?>)
        </p>

        <!-- PROJECT ACCESS -->
        <h3>Project access</h3>
        <form method="post" style="margin-bottom:16px;">
            <input type="hidden" name="perm_user_id" value="<?php echo (int)$permUser['id']; ?>">

            <label style="display:block;margin-bottom:6px;">
                <input type="checkbox" name="access_all_projects" value="1"
                       <?php echo $permUser['access_all_projects'] ? 'checked' : ''; ?>>
                Access to <strong>all projects</strong> (existing &amp; future)
            </label>

            <?php if ($allProjects): ?>
                <?php
                    $selectedProjects = $projectsByUser[(int)$permUser['id']] ?? [];
                ?>
                <label>
                    Specific projects (used when "all projects" is unchecked):
                    <br>
                    <select name="project_ids[]" multiple size="6" style="min-width:260px;">
                        <?php foreach ($allProjects as $p): ?>
                            <?php
                                $pid   = (int)$p['id'];
                                $label = $p['title'] ?? ($p['name'] ?? ('Project #' . $pid));
                                $sel   = in_array($pid, $selectedProjects, true) ? 'selected' : '';
                            ?>
                            <option value="<?php echo $pid; ?>" <?php echo $sel; ?>>
                                <?php echo h($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div style="font-size:11px;color:#6b7280;margin-top:4px;">
                    Tip: Hold <strong>Ctrl</strong> (or ⌘ on Mac) to select multiple projects.
                    On many systems <strong>Ctrl+A</strong> selects all projects in the list.
                </div>
            <?php else: ?>
                <p style="font-size:12px;color:#9ca3af;">No projects found yet.</p>
            <?php endif; ?>

            <div style="margin-top:8px;">
                <button type="submit" name="save_project_access" class="btn-sm">
                    Save project access
                </button>
            </div>
        </form>

        <!-- APP-LEVEL PERMISSIONS -->
        <h3>App-level permissions</h3>
        <p style="font-size:12px;color:#6b7280;">
            If you leave all checkboxes empty for an app, N-SMART will use the <strong>role defaults</strong>:
            Admin = full access; User = standard access to most apps; Guest = view-only to selected
            non-sensitive apps. Explicit settings here override the defaults for this user.
            <br>
            <strong>Hide app</strong> completely removes the app from menus and denies access, even if the role
            would normally allow it.
        </p>

        <form method="post">
            <input type="hidden" name="perm_user_id" value="<?php echo (int)$permUser['id']; ?>">
            <table class="table">
                <thead>
                    <tr>
                        <th>App</th>
                        <th>Description</th>
                        <th>Can view</th>
                        <th>Can edit<br><span style="font-size:10px;">(enter reports / customize)</span></th>
                        <th>Hide app<br><span style="font-size:10px;">(no menu, no access)</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (defined('APP_KEYS')): ?>
                    <?php foreach (APP_KEYS as $key => $label): ?>
                        <?php
                            $p = $permsByUser[(int)$permUser['id']][$key] ?? null;
                            $v = $p['view']   ?? false;
                            $e = $p['edit']   ?? false;
                            $h = $p['hidden'] ?? false;
                        ?>
                        <tr>
                            <td><code><?php echo h($key); ?></code></td>
                            <td><?php echo h($label); ?></td>
                            <td style="text-align:center;">
                                <input type="checkbox"
                                       name="perm_<?php echo h($key); ?>_view"
                                       value="1" <?php echo $v ? 'checked' : ''; ?>>
                            </td>
                            <td style="text-align:center;">
                                <input type="checkbox"
                                       name="perm_<?php echo h($key); ?>_edit"
                                       value="1" <?php echo $e ? 'checked' : ''; ?>>
                            </td>
                            <td style="text-align:center;">
                                <input type="checkbox"
                                       name="perm_<?php echo h($key); ?>_hide"
                                       value="1" <?php echo $h ? 'checked' : ''; ?>>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="5">No APP_KEYS defined in helpers.php.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            <div style="margin-top:8px;">
                <button type="submit" name="save_permissions" class="btn-sm">Save app permissions</button>
            </div>
        </form>
    <?php else: ?>
        <p style="font-size:13px;">
            Select a user from the table above (click <strong>“Edit”</strong> in the Perm. column)
            to manage project and app-level permissions.
        </p>
    <?php endif; ?>
</div>

<!-- EXPORT/IMPORT SECTION -->
<div class="card no-print">
    <h2>📥 Export / Import Users</h2>
    
    <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 20px;">
        <a href="?action=export_users_csv" class="btn-sm btn-success">📊 Export CSV</a>
        <a href="?action=export_users_excel" class="btn-sm btn-success">📊 Export Excel</a>
        <a href="?action=export_users_template" class="btn-sm btn-info">📥 Download Template</a>
        <button onclick="document.getElementById('importForm').style.display='block'" class="btn-sm btn-primary">📤 Import Users</button>
        <button onclick="window.print()" class="btn-sm btn-warning">🖨️ Print</button>
    </div>
    
    <form id="importForm" method="post" enctype="multipart/form-data" style="display: none; padding: 15px; background: #f8f9fa; border-radius: 8px; margin-bottom: 15px;">
        <input type="hidden" name="action" value="import_users">
        <label><strong>Select CSV/Excel File:</strong>
            <input type="file" name="import_file" accept=".csv,.xls,.xlsx" required>
        </label>
        <button type="submit" class="btn-sm btn-primary">Upload & Import</button>
        <button type="button" class="btn-sm btn-secondary" onclick="document.getElementById('importForm').style.display='none'">Cancel</button>
    </form>
</div>

<?php
// Handle export/import actions
$action = $_GET['action'] ?? '';
if ($action === 'export_users_csv' || $action === 'export_users_excel' || $action === 'export_users_template') {
    // Fetch all users with complete data
    $exportUsers = $pdo->query("
        SELECT id, full_name, email, phone, role, is_active, email_verified,
               last_login_at, created_at, COALESCE(photo_path, profile_photo, NULL) as photo_path
        FROM users
        ORDER BY id DESC
    ")->fetchAll();
    
    if ($action === 'export_users_csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="users_export_' . date('Y-m-d') . '.csv"');
        $output = fopen('php://output', 'w');
        
        // Add header
        fputcsv($output, ['Health Reporting System - Users Export']);
        fputcsv($output, ['Export Date: ' . date('Y-m-d H:i:s')]);
        fputcsv($output, []);
        
        // Column headers
        fputcsv($output, ['ID', 'Full Name', 'Email', 'Phone', 'Role', 'Is Active', 'Email Verified', 'Last Login', 'Created At', 'Photo Path']);
        
        // Data rows
        foreach ($exportUsers as $u) {
            fputcsv($output, [
                $u['id'],
                $u['full_name'] ?? '',
                $u['email'] ?? '',
                $u['phone'] ?? '',
                $u['role'] ?? 'user',
                $u['is_active'] ? 'Yes' : 'No',
                $u['email_verified'] ? 'Yes' : 'No',
                $u['last_login_at'] ?? '',
                $u['created_at'] ?? '',
                $u['photo_path'] ?? ''
            ]);
        }
        fclose($output);
        exit;
    } elseif ($action === 'export_users_excel') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="users_export_' . date('Y-m-d') . '.xls"');
        
        echo "<html><head><meta charset='UTF-8'><title>Users Export</title></head><body>";
        echo "<h2>Health Reporting System - Users Export</h2>";
        echo "<p>Export Date: " . date('Y-m-d H:i:s') . "</p>";
        echo "<table border='1'>";
        echo "<tr><th>ID</th><th>Full Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Is Active</th><th>Email Verified</th><th>Last Login</th><th>Created At</th><th>Photo Path</th></tr>";
        foreach ($exportUsers as $u) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($u['id']) . "</td>";
            echo "<td>" . htmlspecialchars($u['full_name'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($u['email'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($u['phone'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($u['role'] ?? 'user') . "</td>";
            echo "<td>" . ($u['is_active'] ? 'Yes' : 'No') . "</td>";
            echo "<td>" . ($u['email_verified'] ? 'Yes' : 'No') . "</td>";
            echo "<td>" . htmlspecialchars($u['last_login_at'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($u['created_at'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($u['photo_path'] ?? '') . "</td>";
            echo "</tr>";
        }
        echo "</table></body></html>";
        exit;
    } elseif ($action === 'export_users_template') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="users_import_template.csv"');
        $output = fopen('php://output', 'w');
        
        fputcsv($output, ['Full Name', 'Email', 'Phone', 'Role', 'Password', 'Is Active']);
        fputcsv($output, ['John Doe', 'john@example.com', '+251911234567', 'user', 'password123', '1']);
        fputcsv($output, ['Jane Smith', 'jane@example.com', '+251922345678', 'admin', 'password123', '1']);
        
        fclose($output);
        exit;
    }
}

// Handle import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_users') {
    if (isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['import_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (in_array($ext, ['csv', 'xls', 'xlsx'])) {
            $tmp = $file['tmp_name'];
            $imported = 0;
            $errors = [];
            
            if ($ext === 'csv') {
                $handle = fopen($tmp, 'r');
                $header = fgetcsv($handle); // Skip header
                
                while (($row = fgetcsv($handle)) !== false) {
                    if (count($row) < 2) continue;
                    
                    $full_name = trim($row[0] ?? '');
                    $email = trim($row[1] ?? '');
                    $phone = trim($row[2] ?? '');
                    $role = trim($row[3] ?? 'user');
                    $password = trim($row[4] ?? '');
                    $is_active = isset($row[5]) ? (int)$row[5] : 1;
                    
                    if ($full_name && $email && $password) {
                        // Check if email exists
                        $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                        $check->execute([$email]);
                        if (!$check->fetch()) {
                            $hash = password_hash($password, PASSWORD_DEFAULT);
                            $token = bin2hex(random_bytes(32));
                            
                            try {
                                $stmt = $pdo->prepare("
                                    INSERT INTO users (full_name, email, phone, password_hash, role, is_active, verification_token)
                                    VALUES (?, ?, ?, ?, ?, ?, ?)
                                ");
                                $stmt->execute([$full_name, $email, $phone, $hash, $role, $is_active, $token]);
                                $imported++;
                            } catch (PDOException $e) {
                                $errors[] = "Failed to import {$email}: " . $e->getMessage();
                            }
                        } else {
                            $errors[] = "Email {$email} already exists";
                        }
                    }
                }
                fclose($handle);
            }
            
            if ($imported > 0) {
                $message = "Successfully imported {$imported} user(s).";
                if (!empty($errors)) {
                    $message .= " Errors: " . implode(', ', array_slice($errors, 0, 5));
                }
            } else {
                $message = "No users imported. " . (!empty($errors) ? implode(', ', array_slice($errors, 0, 5)) : '');
            }
        } else {
            $message = 'Invalid file format. Please upload CSV or Excel file.';
        }
    } else {
        $message = 'File upload failed.';
    }
}
?>

<style>
@media print {
    .no-print, button, a.btn, #importForm { display: none !important; }
    .table { page-break-inside: auto; }
    .table tr { page-break-inside: avoid; }
    body::before {
        content: "Health Reporting System - Users Management";
        display: block;
        font-size: 18px;
        font-weight: bold;
        color: #4361ee;
        padding: 15px;
        border-bottom: 3px solid #4361ee;
        margin-bottom: 20px;
    }
}
</style>

<?php require_once __DIR__ . '/../footer.php'; ?>
