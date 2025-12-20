<?php
require_once __DIR__ . '/_preflight.php';

// Simple login page compatible with existing DB if present.
// If a users table exists with email/password_hash, use it.
// Otherwise provide a demo login that sets session user_id=1.

$pdo = getPDO();
$error = '';

function hrs_users_table_exists(PDO $pdo): bool {
    try {
        $st = $pdo->query("SHOW TABLES LIKE 'users'");
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');

    if (hrs_users_table_exists($pdo) && $email !== '' && $pass !== '') {
        try {
            $st = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
            $st->execute([$email]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if ($u) {
                $hash = $u['password_hash'] ?? ($u['password'] ?? '');
                $ok = false;
                if ($hash && function_exists('password_verify')) {
                    $ok = password_verify($pass, $hash);
                }
                // fallback: plain-text match if legacy
                if (!$ok && $hash && hash_equals((string)$hash, (string)$pass)) $ok = true;

                if ($ok) {
                    $_SESSION['user_id'] = (int)($u['id'] ?? 1);
                    $_SESSION['name'] = $u['full_name'] ?? ($u['name'] ?? ($u['email'] ?? 'User'));
                    $_SESSION['email'] = $u['email'] ?? $email;
                    // Backwards-compatible session keys used across apps
                    $_SESSION['user_name'] = $_SESSION['name'];
                    $_SESSION['username']  = $_SESSION['email'];
                    $_SESSION['role']      = $u['role'] ?? ($u['user_role'] ?? 'user');
                    $_SESSION['user']      = $u; // allow current_user() cache
                    header('Location: planning.php');
                    exit;
                }
            }
            $error = 'Invalid email or password.';
        } catch (Throwable $e) {
            $error = 'Login error: ' . $e->getMessage();
        }
    } else {
        // Demo login (no users table)
        $_SESSION['user_id'] = 1;
        $_SESSION['name'] = 'Demo User';
        $_SESSION['email'] = 'demo@example.com';
        $_SESSION['user_name'] = $_SESSION['name'];
        $_SESSION['username']  = $_SESSION['email'];
        $_SESSION['role']      = 'admin';
        $_SESSION['user']      = ['id'=>1,'full_name'=>'Demo User','email'=>'demo@example.com','role'=>'admin'];
        header('Location: planning.php');
        exit;
    }
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Login — SMART Nexus HRS</title>
  <style>
    body{font-family:Arial, sans-serif; background:#f5f7fb; margin:0; padding:40px;}
    .card{max-width:420px; margin:0 auto; background:#fff; padding:22px; border-radius:10px; box-shadow:0 6px 20px rgba(0,0,0,.08);}
    label{display:block; margin-top:12px; font-size:14px;}
    input{width:100%; padding:10px; margin-top:6px; border:1px solid #d7dbe7; border-radius:8px;}
    button{margin-top:16px; width:100%; padding:10px; border:0; border-radius:8px; cursor:pointer;}
    .err{color:#b00020; margin-top:10px;}
    .hint{font-size:12px; opacity:.7; margin-top:10px;}
  </style>
</head>
<body>
  <div class="card">
    <h2>SMART Nexus HRS</h2>
    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="on">
      <label>Email</label>
      <input name="email" type="email" placeholder="you@example.com">
      <label>Password</label>
      <input name="password" type="password" placeholder="••••••••">
      <button type="submit">Login</button>
      <div class="hint">If no users table exists, the system will use a demo login.</div>
    </form>
  </div>
</body>
</html>
