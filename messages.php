<?php
// Messaging & Email Notifications (extended internal messaging with drafts, attachments, trash)

require_once __DIR__ . '/../helpers.php';
require_login();

$pdo  = getPDO();
$user = current_user();

$currentUserId = $user['id']        ?? null;
$currentEmail  = $user['email']     ?? null;
$currentName   = $user['full_name'] ?? ($user['email'] ?? 'User');

// ---------------------------------------------------------------------
// Safety: ensure we have a logged-in user
// ---------------------------------------------------------------------
if (!$currentUserId) {
    die('User not found in session/current_user(). Please check your auth setup.');
}

// ---------------------------------------------------------------------
// Helpers for schema evolution
// ---------------------------------------------------------------------
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

// ---------------------------------------------------------------------
// Ensure messages table exists and has required columns
// ---------------------------------------------------------------------
$pdo->exec("
    CREATE TABLE IF NOT EXISTS messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NULL,
        from_user_id INT NOT NULL,
        to_user_id INT NULL,
        to_email VARCHAR(255),
        cc_emails TEXT,
        bcc_emails TEXT,
        subject VARCHAR(255) NOT NULL,
        body TEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'queued',
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        is_draft TINYINT(1) NOT NULL DEFAULT 0,
        is_deleted_by_sender TINYINT(1) NOT NULL DEFAULT 0,
        is_deleted_by_recipient TINYINT(1) NOT NULL DEFAULT 0,
        attachment_path VARCHAR(255) DEFAULT NULL,
        attachment_name VARCHAR(255) DEFAULT NULL,
        reply_to_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        read_at DATETIME NULL,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Make sure all columns exist even if an older version already there
ensure_column($pdo, 'messages', 'project_id',              "INT NULL");
ensure_column($pdo, 'messages', 'from_user_id',            "INT NOT NULL");
ensure_column($pdo, 'messages', 'to_user_id',              "INT NULL");
ensure_column($pdo, 'messages', 'to_email',                "VARCHAR(255)");
ensure_column($pdo, 'messages', 'cc_emails',               "TEXT");
ensure_column($pdo, 'messages', 'bcc_emails',              "TEXT");
ensure_column($pdo, 'messages', 'subject',                 "VARCHAR(255) NOT NULL");
ensure_column($pdo, 'messages', 'body',                    "TEXT NOT NULL");
ensure_column($pdo, 'messages', 'status',                  "VARCHAR(20) NOT NULL DEFAULT 'queued'");
ensure_column($pdo, 'messages', 'is_read',                 "TINYINT(1) NOT NULL DEFAULT 0");
ensure_column($pdo, 'messages', 'is_draft',                "TINYINT(1) NOT NULL DEFAULT 0");
ensure_column($pdo, 'messages', 'is_deleted_by_sender',    "TINYINT(1) NOT NULL DEFAULT 0");
ensure_column($pdo, 'messages', 'is_deleted_by_recipient', "TINYINT(1) NOT NULL DEFAULT 0");
ensure_column($pdo, 'messages', 'attachment_path',         "VARCHAR(255) DEFAULT NULL");
ensure_column($pdo, 'messages', 'attachment_name',         "VARCHAR(255) DEFAULT NULL");
ensure_column($pdo, 'messages', 'reply_to_id',             "INT NULL");
ensure_column($pdo, 'messages', 'created_at',              "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
ensure_column($pdo, 'messages', 'read_at',                 "DATETIME NULL");
ensure_column($pdo, 'messages', 'updated_at',              "TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP");

// ---------------------------------------------------------------------
// Lookups: projects & users
// ---------------------------------------------------------------------
$projects = function_exists('get_projects') ? get_projects() : [];

$users = [];
try {
    // Align with your latest schema: full_name + email
    $users = $pdo->query("SELECT id, full_name, email FROM users ORDER BY full_name")->fetchAll();
} catch (Throwable $e) {
    // Adjust if needed
}

// ---------------------------------------------------------------------
// Compose prefill (for reply / forward / edit draft)
// ---------------------------------------------------------------------
$composePrefill = [
    'project_id'     => '',
    'to_user_id'     => '',
    'to_email_other' => '',
    'cc_emails'      => '',
    'bcc_emails'     => '',
    'subject'        => '',
    'body'           => '',
];

$messageSuccess = '';
$messageError   = '';

// Handle reply/forward via GET (pre-fill compose)
if (isset($_GET['reply_to']) || isset($_GET['forward_from'])) {
    $msgId = isset($_GET['reply_to']) ? (int)$_GET['reply_to'] : (int)$_GET['forward_from'];
    $stmt  = $pdo->prepare("
        SELECT *
        FROM messages
        WHERE id = ?
          AND (from_user_id = ? OR to_user_id = ?)
    ");
    $stmt->execute([$msgId, $currentUserId, $currentUserId]);
    $orig = $stmt->fetch();

    if ($orig) {
        if (isset($_GET['reply_to'])) {
            // Reply: back to original sender
            $composePrefill['project_id'] = $orig['project_id'];
            $composePrefill['to_user_id'] = $orig['from_user_id'];
            $prefix = (stripos($orig['subject'], 're:') === 0) ? '' : 'Re: ';
            $composePrefill['subject'] = $prefix . $orig['subject'];
            $composePrefill['body']    = "\n\n--- Previous message ---\n" . $orig['body'];
        } else {
            // Forward: subject Fwd, body quoted
            $composePrefill['project_id'] = $orig['project_id'];
            $prefix = (stripos($orig['subject'], 'fwd:') === 0) ? '' : 'Fwd: ';
            $composePrefill['subject'] = $prefix . $orig['subject'];
            $composePrefill['body']    = "\n\n--- Forwarded message ---\n"
                                       . "Date: " . $orig['created_at'] . "\n"
                                       . "To: " . ($orig['to_email'] ?? '') . "\n\n"
                                       . $orig['body'];
        }
    }
}

// Handle edit_draft prefill (load draft into composer)
if (isset($_GET['edit_draft_id'])) {
    $draftId = (int)$_GET['edit_draft_id'];
    $stmt    = $pdo->prepare("
        SELECT *
        FROM messages
        WHERE id = ? AND from_user_id = ? AND is_draft = 1
    ");
    $stmt->execute([$draftId, $currentUserId]);
    $d = $stmt->fetch();
    if ($d) {
        $composePrefill['project_id'] = $d['project_id'];
        $composePrefill['to_user_id'] = $d['to_user_id'];
        // if there is a direct email but no user, use it as external email
        $composePrefill['to_email_other'] = $d['to_email'] ?? '';
        $composePrefill['cc_emails']      = $d['cc_emails'] ?? '';
        $composePrefill['bcc_emails']     = $d['bcc_emails'] ?? '';
        $composePrefill['subject']        = $d['subject'] ?? '';
        $composePrefill['body']           = $d['body'] ?? '';
    }
}

// ---------------------------------------------------------------------
// Handle POST actions: send, draft, update, delete, mark_read, restore
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // SEND / COMPOSE NEW
    if ($action === 'send' || $action === 'save_draft') {
        $project_id     = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
        $to_user_id     = !empty($_POST['to_user_id']) ? (int)$_POST['to_user_id'] : null;
        $to_email_other = trim($_POST['to_email_other'] ?? '');
        $cc_emails      = trim($_POST['cc_emails'] ?? '');
        $bcc_emails     = trim($_POST['bcc_emails'] ?? '');
        $subject        = trim($_POST['subject'] ?? '');
        $body           = trim($_POST['body'] ?? '');

        // Keep form values if there is an error
        $composePrefill['project_id']     = $project_id;
        $composePrefill['to_user_id']     = $to_user_id;
        $composePrefill['to_email_other'] = $to_email_other;
        $composePrefill['cc_emails']      = $cc_emails;
        $composePrefill['bcc_emails']     = $bcc_emails;
        $composePrefill['subject']        = $subject;
        $composePrefill['body']           = $body;

        $isDraft = ($action === 'save_draft');

        if (!$isDraft && ($subject === '' || $body === '')) {
            $messageError = 'Subject and message body are required to send.';
        } elseif ($isDraft && $subject === '' && $body === '') {
            $messageError = 'Cannot save a completely empty draft.';
        } else {
            // Resolve recipient email from user or free email field
            $to_email = '';
            if ($to_user_id) {
                $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
                try {
                    $stmt->execute([$to_user_id]);
                    $row = $stmt->fetch();
                    if ($row && filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                        $to_email = $row['email'];
                    }
                } catch (Throwable $e) {
                    // ignore; fallback
                }
            }
            if (!$to_email && $to_email_other) {
                $to_email = $to_email_other;
            }

            if (!$isDraft && !$to_email) {
                $messageError = 'No valid recipient email found. Select a user or enter an email address.';
            } else {
                // Handle attachment upload (stored only in app, not as real email attachment)
                $attachmentPath = null;
                $attachmentName = null;

                if (!empty($_FILES['attachment']['name']) &&
                    $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {

                    $tmpName = $_FILES['attachment']['tmp_name'];
                    if (is_uploaded_file($tmpName)) {
                        $originalName = basename($_FILES['attachment']['name']);
                        $safeName = time() . '_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $originalName);
                        $uploadDir = __DIR__ . '/../uploads/message_files';
                        if (!is_dir($uploadDir)) {
                            @mkdir($uploadDir, 0777, true);
                        }
                        $destPath = $uploadDir . '/' . $safeName;
                        if (@move_uploaded_file($tmpName, $destPath)) {
                            $attachmentPath = 'uploads/message_files/' . $safeName;
                            $attachmentName = $originalName;
                        } else {
                            $messageError = 'Could not save attachment. Please check folder permissions.';
                        }
                    }
                }

                if ($messageError === '') {
                    // Save message (draft or to-be-sent)
                    $stmt = $pdo->prepare("
                        INSERT INTO messages
                            (project_id, from_user_id, to_user_id, to_email,
                             cc_emails, bcc_emails, subject, body,
                             status, is_read, is_draft,
                             attachment_path, attachment_name)
                        VALUES
                            (?,?,?,?,?,?,?,?,
                             ?,0,?,
                             ?,?)
                    ");

                    $status = $isDraft ? 'draft' : 'queued';

                    $stmt->execute([
                        $project_id,
                        $currentUserId,
                        $to_user_id,
                        $to_email ?: null,
                        $cc_emails ?: null,
                        $bcc_emails ?: null,
                        $subject,
                        $body,
                        $status,
                        $isDraft ? 1 : 0, // is_draft
                        $attachmentPath,
                        $attachmentName
                    ]);

                    $msgId = (int)$pdo->lastInsertId();

                    if ($isDraft) {
                        $messageSuccess   = 'Draft saved.';
                        // Clear compose form
                        $composePrefill = [
                            'project_id'     => '',
                            'to_user_id'     => '',
                            'to_email_other' => '',
                            'cc_emails'      => '',
                            'bcc_emails'     => '',
                            'subject'        => '',
                            'body'           => '',
                        ];
                    } else {
                        // Try to send real email (when mail server is configured).
                        // On localhost/XAMPP this often fails, so we NEVER show a red error.
                        $headers = [];
                        if ($currentEmail) {
                            $headers[] = 'From: ' . $currentName . ' <' . $currentEmail . '>';
                            $headers[] = 'Reply-To: ' . $currentEmail;
                        }
                        if ($cc_emails) {
                            $headers[] = 'Cc: ' . $cc_emails;
                        }
                        if ($bcc_emails) {
                            $headers[] = 'Bcc: ' . $bcc_emails;
                        }
                        $headers[] = 'MIME-Version: 1.0';
                        $headers[] = 'Content-Type: text/plain; charset=UTF-8';

                        // Mention attachment in email body (simple)
                        if ($attachmentName) {
                            $bodyWithNote = $body . "\n\n[Attachment saved in N-SMART: " . $attachmentName . "]";
                        } else {
                            $bodyWithNote = $body;
                        }

                        $sentOk = @mail($to_email, $subject, $bodyWithNote, implode("\r\n", $headers));

                        // If mail() fails (e.g., no SMTP), still treat as successfully sent INSIDE the system.
                        if ($sentOk) {
                            $finalStatus   = 'sent';
                            $messageSuccess = 'Message sent and email notification delivered (status: sent).';
                        } else {
                            $finalStatus   = 'sent_local';
                            $messageSuccess = 'Message sent inside N-SMART (email server not configured; delivered to user inbox only).';
                        }

                        $stmt = $pdo->prepare("UPDATE messages SET status = ? WHERE id = ?");
                        $stmt->execute([$finalStatus, $msgId]);

                        // Clear compose form
                        $composePrefill = [
                            'project_id'     => '',
                            'to_user_id'     => '',
                            'to_email_other' => '',
                            'cc_emails'      => '',
                            'bcc_emails'     => '',
                            'subject'        => '',
                            'body'           => '',
                        ];
                    }
                }
            }
        }

    // UPDATE existing sent message record (edit subject/body only)
    } elseif ($action === 'update_message') {
        $msgId   = (int)($_POST['message_id'] ?? 0);
        $subject = trim($_POST['subject'] ?? '');
        $body    = trim($_POST['body'] ?? '');

        if ($msgId > 0 && $subject !== '' && $body !== '') {
            $stmt = $pdo->prepare("
                UPDATE messages
                SET subject = ?, body = ?
                WHERE id = ? AND from_user_id = ?
            ");
            $stmt->execute([$subject, $body, $msgId, $currentUserId]);
            $messageSuccess = 'Message updated (internal record only; no re-send).';
        } else {
            $messageError = 'Cannot update message. Ensure subject and body are not empty.';
        }

    // UPDATE DRAFT (edit but keep as draft)
    } elseif ($action === 'update_draft') {
        $msgId   = (int)($_POST['message_id'] ?? 0);
        $subject = trim($_POST['subject'] ?? '');
        $body    = trim($_POST['body'] ?? '');

        if ($msgId > 0 && ($subject !== '' || $body !== '')) {
            $stmt = $pdo->prepare("
                UPDATE messages
                SET subject = ?, body = ?
                WHERE id = ? AND from_user_id = ? AND is_draft = 1
            ");
            $stmt->execute([$subject, $body, $msgId, $currentUserId]);
            $messageSuccess = 'Draft updated.';
        } else {
            $messageError = 'Cannot update draft. Provide at least subject or body.';
        }

    // SEND DRAFT (turn draft into real message)
    } elseif ($action === 'send_draft') {
        $msgId = (int)($_POST['message_id'] ?? 0);
        if ($msgId > 0) {
            $stmt = $pdo->prepare("
                SELECT *
                FROM messages
                WHERE id = ? AND from_user_id = ? AND is_draft = 1
            ");
            $stmt->execute([$msgId, $currentUserId]);
            $draft = $stmt->fetch();

            if ($draft) {
                $to_email = $draft['to_email'];
                if (!$to_email && $draft['to_user_id']) {
                    $s2 = $pdo->prepare("SELECT email FROM users WHERE id = ?");
                    $s2->execute([$draft['to_user_id']]);
                    $row = $s2->fetch();
                    if ($row && filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                        $to_email = $row['email'];
                    }
                }

                if (!$to_email) {
                    $messageError = 'Cannot send draft: no valid recipient email.';
                } else {
                    $headers = [];
                    if ($currentEmail) {
                        $headers[] = 'From: ' . $currentName . ' <' . $currentEmail . '>';
                        $headers[] = 'Reply-To: ' . $currentEmail;
                    }
                    if ($draft['cc_emails']) {
                        $headers[] = 'Cc: ' . $draft['cc_emails'];
                    }
                    if ($draft['bcc_emails']) {
                        $headers[] = 'Bcc: ' . $draft['bcc_emails'];
                    }
                    $headers[] = 'MIME-Version: 1.0';
                    $headers[] = 'Content-Type: text/plain; charset=UTF-8';

                    $bodyWithNote = $draft['body'];
                    if ($draft['attachment_name']) {
                        $bodyWithNote .= "\n\n[Attachment saved in N-SMART: " . $draft['attachment_name'] . "]";
                    }

                    $sentOk = @mail($to_email, $draft['subject'], $bodyWithNote, implode("\r\n", $headers));
                    if ($sentOk) {
                        $finalStatus   = 'sent';
                        $messageSuccess = 'Draft sent successfully (email delivered if server configured).';
                    } else {
                        $finalStatus   = 'sent_local';
                        $messageSuccess = 'Draft sent inside N-SMART (email server not configured; delivered to inbox only).';
                    }

                    $stmtUp = $pdo->prepare("
                        UPDATE messages
                        SET is_draft = 0,
                            status   = ?
                        WHERE id = ?
                    ");
                    $stmtUp->execute([$finalStatus, $msgId]);
                }
            }
        }

    // MOVE TO TRASH (soft delete)
    } elseif ($action === 'delete_message') {
        $msgId = (int)($_POST['message_id'] ?? 0);
        if ($msgId > 0) {
            $stmt = $pdo->prepare("
                SELECT from_user_id, to_user_id
                FROM messages
                WHERE id = ?
            ");
            $stmt->execute([$msgId]);
            $msg = $stmt->fetch();
            if ($msg) {
                $isSender    = ((int)$msg['from_user_id'] === (int)$currentUserId);
                $isRecipient = ((int)$msg['to_user_id']   === (int)$currentUserId);

                if ($isSender && $isRecipient) {
                    // message to self: mark deleted for both sender and recipient views
                    $pdo->prepare("
                        UPDATE messages
                        SET is_deleted_by_sender = 1,
                            is_deleted_by_recipient = 1
                        WHERE id = ?
                    ")->execute([$msgId]);
                    $messageSuccess = 'Message moved to trash (sender & recipient).';
                } elseif ($isSender) {
                    $pdo->prepare("
                        UPDATE messages
                        SET is_deleted_by_sender = 1
                        WHERE id = ?
                    ")->execute([$msgId]);
                    $messageSuccess = 'Message moved to trash (sender view).';
                } elseif ($isRecipient) {
                    $pdo->prepare("
                        UPDATE messages
                        SET is_deleted_by_recipient = 1
                        WHERE id = ?
                    ")->execute([$msgId]);
                    $messageSuccess = 'Message moved to trash (inbox view).';
                }
            }
        }

    // RESTORE FROM TRASH
    } elseif ($action === 'restore_message') {
        $msgId = (int)($_POST['message_id'] ?? 0);
        if ($msgId > 0) {
            $stmt = $pdo->prepare("
                SELECT from_user_id, to_user_id
                FROM messages
                WHERE id = ?
            ");
            $stmt->execute([$msgId]);
            $msg = $stmt->fetch();
            if ($msg) {
                $isSender    = ((int)$msg['from_user_id'] === (int)$currentUserId);
                $isRecipient = ((int)$msg['to_user_id']   === (int)$currentUserId);

                if ($isSender && $isRecipient) {
                    // self-message: clear both flags
                    $pdo->prepare("
                        UPDATE messages
                        SET is_deleted_by_sender = 0,
                            is_deleted_by_recipient = 0
                        WHERE id = ?
                    ")->execute([$msgId]);
                    $messageSuccess = 'Message restored to Inbox & Sent.';
                } elseif ($isSender) {
                    $pdo->prepare("
                        UPDATE messages
                        SET is_deleted_by_sender = 0
                        WHERE id = ?
                    ")->execute([$msgId]);
                    $messageSuccess = 'Message restored to Sent.';
                } elseif ($isRecipient) {
                    $pdo->prepare("
                        UPDATE messages
                        SET is_deleted_by_recipient = 0
                        WHERE id = ?
                    ")->execute([$msgId]);
                    $messageSuccess = 'Message restored to Inbox.';
                }
            }
        }

    // MARK READ
    } elseif ($action === 'mark_read') {
        $msgId = (int)($_POST['message_id'] ?? 0);
        if ($msgId > 0) {
            $stmt = $pdo->prepare("
                UPDATE messages
                SET is_read = 1,
                    read_at = NOW(),
                    status = CASE
                               WHEN status IN ('queued','sent','sent_local') THEN 'read'
                               ELSE status
                             END
                WHERE id = ? AND to_user_id = ?
            ");
            $stmt->execute([$msgId, $currentUserId]);
            $messageSuccess = 'Message marked as read.';
        }
    }
}

// ---------------------------------------------------------------------
// Fetch inbox, sent, drafts, trash + unread count
// ---------------------------------------------------------------------
$inbox       = [];
$sent        = [];
$drafts      = [];
$trash       = [];
$unreadCount = 0;

try {
    // Inbox: messages not deleted, not draft, addressed to me
    $stmt = $pdo->prepare("
        SELECT
            m.*,
            u_from.full_name  AS from_name,
            u_from.email      AS from_email,
            u_to.full_name    AS to_name,
            u_to.email        AS to_system_email,
            p.title           AS project_title,
            p.name            AS project_name
        FROM messages m
        LEFT JOIN users    u_from ON m.from_user_id = u_from.id
        LEFT JOIN users    u_to   ON m.to_user_id   = u_to.id
        LEFT JOIN projects p      ON m.project_id   = p.id
        WHERE
            m.is_draft = 0
            AND m.is_deleted_by_recipient = 0
            AND (m.to_user_id = :uid
                 OR (:uemail IS NOT NULL AND m.to_email = :uemail))
        ORDER BY m.created_at DESC
        LIMIT 100
    ");
    $stmt->execute([
        ':uid'    => $currentUserId,
        ':uemail' => $currentEmail ?: null
    ]);
    $inbox = $stmt->fetchAll();

    // Sent: from me, not draft, not deleted by sender
    $stmt = $pdo->prepare("
        SELECT
            m.*,
            u_from.full_name  AS from_name,
            u_from.email      AS from_email,
            u_to.full_name    AS to_name,
            u_to.email        AS to_system_email,
            p.title           AS project_title,
            p.name            AS project_name
        FROM messages m
        LEFT JOIN users    u_from ON m.from_user_id = u_from.id
        LEFT JOIN users    u_to   ON m.to_user_id   = u_to.id
        LEFT JOIN projects p      ON m.project_id   = p.id
        WHERE m.from_user_id = :uid
          AND m.is_draft = 0
          AND m.is_deleted_by_sender = 0
        ORDER BY m.created_at DESC
        LIMIT 100
    ");
    $stmt->execute([':uid' => $currentUserId]);
    $sent = $stmt->fetchAll();

    // Drafts: from me, drafts, not deleted
    $stmt = $pdo->prepare("
        SELECT
            m.*,
            u_from.full_name  AS from_name,
            u_from.email      AS from_email,
            u_to.full_name    AS to_name,
            u_to.email        AS to_system_email,
            p.title           AS project_title,
            p.name            AS project_name
        FROM messages m
        LEFT JOIN users    u_from ON m.from_user_id = u_from.id
        LEFT JOIN users    u_to   ON m.to_user_id   = u_to.id
        LEFT JOIN projects p      ON m.project_id   = p.id
        WHERE m.from_user_id = :uid
          AND m.is_draft = 1
          AND m.is_deleted_by_sender = 0
        ORDER BY m.created_at DESC
        LIMIT 100
    ");
    $stmt->execute([':uid' => $currentUserId]);
    $drafts = $stmt->fetchAll();

    // Trash: messages marked deleted by me either as sender or recipient
    $stmt = $pdo->prepare("
        SELECT
            m.*,
            u_from.full_name  AS from_name,
            u_from.email      AS from_email,
            u_to.full_name    AS to_name,
            u_to.email        AS to_system_email,
            p.title           AS project_title,
            p.name            AS project_name
        FROM messages m
        LEFT JOIN users    u_from ON m.from_user_id = u_from.id
        LEFT JOIN users    u_to   ON m.to_user_id   = u_to.id
        LEFT JOIN projects p      ON m.project_id   = p.id
        WHERE
            (m.from_user_id = :uid AND m.is_deleted_by_sender = 1)
            OR
            (m.to_user_id   = :uid AND m.is_deleted_by_recipient = 1)
        ORDER BY m.created_at DESC
        LIMIT 100
    ");
    $stmt->execute([':uid' => $currentUserId]);
    $trash = $stmt->fetchAll();

    // Unread count (for notifications)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS c
        FROM messages m
        WHERE
            m.is_read = 0
            AND m.is_draft = 0
            AND m.is_deleted_by_recipient = 0
            AND (m.to_user_id = :uid
                 OR (:uemail IS NOT NULL AND m.to_email = :uemail))
    ");
    $stmt->execute([
        ':uid'    => $currentUserId,
        ':uemail' => $currentEmail ?: null
    ]);
    $unreadCount = (int)($stmt->fetchColumn() ?: 0);

    // Store in session so header / navbar can show badge if you want
    $_SESSION['unread_messages'] = $unreadCount;

} catch (Throwable $e) {
    // Optional: log $e->getMessage()
}

// ---------------------------------------------------------------------
// Render page
// ---------------------------------------------------------------------
require_once __DIR__ . '/../header.php';
?>
<div class="card">
    <h1>Messaging &amp; Email Notifications</h1>

    <?php if ($messageSuccess): ?>
        <p class="badge badge-success"><?php echo h($messageSuccess); ?></p>
    <?php endif; ?>

    <?php if ($messageError): ?>
        <p class="badge badge-danger"><?php echo h($messageError); ?></p>
    <?php endif; ?>

    <p style="font-size:13px;color:#4b5563;">
        This module now behaves like a mini email system:
        <br>• Internal messages between users (Admin ↔ User ↔ Guest),
        <br>• Email notifications (To / CC / BCC) when mail server is configured,
        <br>• Drafts, reply &amp; forward, attachments,
        <br>• Trash with soft delete and restore,
        <br>• Unread badge for notifications.
    </p>

    <p>
        <strong>Unread messages:</strong>
        <span class="badge badge-info"><?php echo (int)$unreadCount; ?></span>
    </p>
</div>

<!-- COMPOSE -->
<div class="card" id="compose">
    <h2>Compose New Message</h2>
    <form method="post" enctype="multipart/form-data">
        <div class="form-row">
            <label>Project (optional)
                <select name="project_id">
                    <option value="">-- None / General --</option>
                    <?php foreach ($projects as $p): ?>
                        <?php $label = $p['title'] ?? ($p['name'] ?? ('Project ' . $p['id'])); ?>
                        <option value="<?php echo (int)$p['id']; ?>"
                            <?php echo ((int)$composePrefill['project_id'] === (int)$p['id']) ? 'selected' : ''; ?>>
                            <?php echo h($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <div class="form-row">
            <label>To (system user)
                <select name="to_user_id">
                    <option value="">-- Select user (optional) --</option>
                    <?php foreach ($users as $u): ?>
                        <?php
                            $uname   = $u['full_name'] ?: ('User #' . $u['id']);
                            $uemail  = $u['email'] ?: '';
                            $display = $uname . ($uemail ? ' (' . $uemail . ')' : '');
                            if ((int)$u['id'] === (int)$currentUserId) {
                                $display .= ' (You)';
                            }
                        ?>
                        <option value="<?php echo (int)$u['id']; ?>"
                            <?php echo ((int)$composePrefill['to_user_id'] === (int)$u['id']) ? 'selected' : ''; ?>>
                            <?php echo h($display); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>Or external email
                <input type="email" name="to_email_other"
                       placeholder="someone@example.org"
                       value="<?php echo h($composePrefill['to_email_other']); ?>">
            </label>
        </div>

        <div class="form-row">
            <label>CC
                <input type="text" name="cc_emails"
                       placeholder="cc1@example.org, cc2@example.org"
                       value="<?php echo h($composePrefill['cc_emails']); ?>">
            </label>
            <label>BCC
                <input type="text" name="bcc_emails"
                       placeholder="bcc1@example.org, bcc2@example.org"
                       value="<?php echo h($composePrefill['bcc_emails']); ?>">
            </label>
        </div>

        <div class="form-row">
            <label>Subject
                <input type="text" name="subject" required
                       value="<?php echo h($composePrefill['subject']); ?>">
            </label>
        </div>

        <div class="form-row">
            <label>Message
                <textarea name="body" rows="5" required><?php echo h($composePrefill['body']); ?></textarea>
            </label>
        </div>

        <div class="form-row">
            <label>Attachment (optional)
                <input type="file" name="attachment">
            </label>
        </div>

        <div class="form-row">
            <button type="submit" name="action" value="send" class="btn-sm">
                Send
            </button>
            <button type="submit" name="action" value="save_draft" class="btn-sm btn-secondary"
                    style="margin-left:8px;">
                Save as draft
            </button>
        </div>
    </form>
</div>

<!-- INBOX -->
<div class="card">
    <h2>Inbox</h2>
    <p>Messages where you are the recipient (by user account or your email address).</p>
    <table class="table">
        <thead>
            <tr>
                <th>Date</th>
                <th>From</th>
                <th>Project</th>
                <th>Subject &amp; Preview</th>
                <th>Status</th>
                <th>Read?</th>
                <th style="width: 260px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$inbox): ?>
                <tr><td colspan="7">No messages in your inbox.</td></tr>
            <?php else: ?>
                <?php foreach ($inbox as $m): ?>
                    <?php
                        $projectLabel = '';
                        if (!empty($m['project_title'])) {
                            $projectLabel = $m['project_title'];
                        } elseif (!empty($m['project_name'])) {
                            $projectLabel = $m['project_name'];
                        } elseif (!empty($m['project_id'])) {
                            $projectLabel = 'Project #' . (int)$m['project_id'];
                        }

                        $fromDisplay = $m['from_name'] ?: 'User #' . (int)$m['from_user_id'];
                        if (!empty($m['from_email'])) {
                            $fromDisplay .= ' (' . $m['from_email'] . ')';
                        }

                        $isRead = (int)$m['is_read'] === 1;
                    ?>
                    <tr class="<?php echo $isRead ? '' : 'highlight-row'; ?>">
                        <td><?php echo h($m['created_at']); ?></td>
                        <td><?php echo h($fromDisplay); ?></td>
                        <td><?php echo h($projectLabel ?: '-'); ?></td>
                        <td>
                            <strong><?php echo h($m['subject']); ?></strong><br>
                            <small><?php echo nl2br(h(mb_strimwidth($m['body'], 0, 140, '...'))); ?></small>
                            <?php if (!empty($m['attachment_path'])): ?>
                                <br>
                                <small>
                                    📎 Attachment:
                                    <a href="/<?php echo h($m['attachment_path']); ?>" target="_blank">
                                        <?php echo h($m['attachment_name'] ?: 'Download file'); ?>
                                    </a>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo h($m['status']); ?></td>
                        <td><?php echo $isRead ? 'Yes' : 'No'; ?></td>
                        <td>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="message_id" value="<?php echo (int)$m['id']; ?>">
                                <?php if (!$isRead): ?>
                                    <button type="submit" name="action" value="mark_read" class="btn-sm">
                                        Mark as read
                                    </button>
                                <?php endif; ?>
                            </form>

                            <form method="post" style="display:inline;margin-left:4px;">
                                <input type="hidden" name="message_id" value="<?php echo (int)$m['id']; ?>">
                                <button type="submit" name="action" value="delete_message"
                                        class="btn-sm btn-secondary"
                                        onclick="return confirm('Move this message to trash?');">
                                    Trash
                                </button>
                            </form>

                            <!-- Reply / Forward links -->
                            <a href="<?php echo h($_SERVER['PHP_SELF']); ?>?reply_to=<?php echo (int)$m['id']; ?>#compose"
                               style="margin-left:4px;font-size:11px;">
                                Reply
                            </a>
                            |
                            <a href="<?php echo h($_SERVER['PHP_SELF']); ?>?forward_from=<?php echo (int)$m['id']; ?>#compose"
                               style="font-size:11px;">
                                Forward
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- SENT -->
<div class="card">
    <h2>Sent Messages</h2>
    <p>Messages you have sent (with delivery status). Editing here only updates the stored record, not the email already sent.</p>
    <table class="table">
        <thead>
            <tr>
                <th>Date</th>
                <th>To</th>
                <th>Project</th>
                <th>Subject &amp; Body (editable)</th>
                <th>Status</th>
                <th style="width: 260px;">Edit / Trash</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$sent): ?>
                <tr><td colspan="6">You have not sent any messages yet.</td></tr>
            <?php else: ?>
                <?php foreach ($sent as $m): ?>
                    <?php
                        $projectLabel = '';
                        if (!empty($m['project_title'])) {
                            $projectLabel = $m['project_title'];
                        } elseif (!empty($m['project_name'])) {
                            $projectLabel = $m['project_name'];
                        } elseif (!empty($m['project_id'])) {
                            $projectLabel = 'Project #' . (int)$m['project_id'];
                        }

                        $toDisplay = '';
                        if (!empty($m['to_name'])) {
                            $toDisplay = $m['to_name'];
                            if (!empty($m['to_system_email'])) {
                                $toDisplay .= ' (' . $m['to_system_email'] . ')';
                            }
                        } elseif (!empty($m['to_email'])) {
                            $toDisplay = $m['to_email'];
                        } else {
                            $toDisplay = 'Unknown recipient';
                        }
                    ?>
                    <tr>
                        <form method="post">
                            <td><?php echo h($m['created_at']); ?></td>
                            <td><?php echo h($toDisplay); ?></td>
                            <td><?php echo h($projectLabel ?: '-'); ?></td>
                            <td>
                                <input type="text" name="subject"
                                       value="<?php echo h($m['subject']); ?>"
                                       style="width:100%;max-width:260px;">
                                <br>
                                <textarea name="body" rows="2" style="width:100%;max-width:260px;"><?php
                                    echo h($m['body']);
                                ?></textarea>
                                <?php if (!empty($m['attachment_path'])): ?>
                                    <br>
                                    <small>
                                        📎 Attachment:
                                        <a href="/<?php echo h($m['attachment_path']); ?>" target="_blank">
                                            <?php echo h($m['attachment_name'] ?: 'Download file'); ?>
                                        </a>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo h($m['status']); ?></td>
                            <td>
                                <input type="hidden" name="message_id" value="<?php echo (int)$m['id']; ?>">

                                <button type="submit" name="action" value="update_message"
                                        class="btn-sm">
                                    Save edit
                                </button>

                                <button type="submit" name="action" value="delete_message"
                                        class="btn-sm btn-secondary"
                                        onclick="return confirm('Move this sent message to trash?');">
                                    Trash
                                </button>
                            </td>
                        </form>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- DRAFTS -->
<div class="card">
    <h2>Drafts</h2>
    <p>Messages you started but have not sent yet. You can edit, send, or move them to trash.</p>
    <table class="table">
        <thead>
            <tr>
                <th>Last updated</th>
                <th>To</th>
                <th>Project</th>
                <th>Subject &amp; Body</th>
                <th style="width: 320px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$drafts): ?>
                <tr><td colspan="5">No drafts saved.</td></tr>
            <?php else: ?>
                <?php foreach ($drafts as $m): ?>
                    <?php
                        $projectLabel = '';
                        if (!empty($m['project_title'])) {
                            $projectLabel = $m['project_title'];
                        } elseif (!empty($m['project_name'])) {
                            $projectLabel = $m['project_name'];
                        } elseif (!empty($m['project_id'])) {
                            $projectLabel = 'Project #' . (int)$m['project_id'];
                        }

                        $toDisplay = '';
                        if (!empty($m['to_name'])) {
                            $toDisplay = $m['to_name'];
                            if (!empty($m['to_system_email'])) {
                                $toDisplay .= ' (' . $m['to_system_email'] . ')';
                            }
                        } elseif (!empty($m['to_email'])) {
                            $toDisplay = $m['to_email'];
                        } else {
                            $toDisplay = '(No recipient yet)';
                        }
                    ?>
                    <tr>
                        <form method="post">
                            <td><?php echo h($m['updated_at'] ?: $m['created_at']); ?></td>
                            <td><?php echo h($toDisplay); ?></td>
                            <td><?php echo h($projectLabel ?: '-'); ?></td>
                            <td>
                                <input type="text" name="subject"
                                       value="<?php echo h($m['subject']); ?>"
                                       style="width:100%;max-width:260px;">
                                <br>
                                <textarea name="body" rows="2" style="width:100%;max-width:260px;"><?php
                                    echo h($m['body']);
                                ?></textarea>
                                <?php if (!empty($m['attachment_path'])): ?>
                                    <br>
                                    <small>
                                        📎 Attachment:
                                        <a href="/<?php echo h($m['attachment_path']); ?>" target="_blank">
                                            <?php echo h($m['attachment_name'] ?: 'Download file'); ?>
                                        </a>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <input type="hidden" name="message_id" value="<?php echo (int)$m['id']; ?>">

                                <button type="submit" name="action" value="update_draft"
                                        class="btn-sm">
                                    Save draft
                                </button>

                                <button type="submit" name="action" value="send_draft"
                                        class="btn-sm"
                                        style="margin-left:4px;">
                                    Send now
                                </button>

                                <button type="submit" name="action" value="delete_message"
                                        class="btn-sm btn-secondary"
                                        style="margin-left:4px;"
                                        onclick="return confirm('Move this draft to trash?');">
                                    Trash
                                </button>

                                <a href="<?php echo h($_SERVER['PHP_SELF']); ?>?edit_draft_id=<?php echo (int)$m['id']; ?>#compose"
                                   style="margin-left:4px;font-size:11px;">
                                    Edit in composer
                                </a>
                            </td>
                        </form>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- TRASH -->
<div class="card">
    <h2>Trash</h2>
    <p>Messages you moved to trash. You can restore them to Inbox/Sent/Drafts.</p>
    <table class="table">
        <thead>
            <tr>
                <th>Date</th>
                <th>From → To</th>
                <th>Project</th>
                <th>Subject</th>
                <th>Draft?</th>
                <th style="width: 220px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$trash): ?>
                <tr><td colspan="6">Trash is empty.</td></tr>
            <?php else: ?>
                <?php foreach ($trash as $m): ?>
                    <?php
                        $projectLabel = '';
                        if (!empty($m['project_title'])) {
                            $projectLabel = $m['project_title'];
                        } elseif (!empty($m['project_name'])) {
                            $projectLabel = $m['project_name'];
                        } elseif (!empty($m['project_id'])) {
                            $projectLabel = 'Project #' . (int)$m['project_id'];
                        }

                        $fromDisplay = $m['from_name'] ?: 'User #' . (int)$m['from_user_id'];
                        if (!empty($m['from_email'])) {
                            $fromDisplay .= ' (' . $m['from_email'] . ')';
                        }

                        $toDisplay = '';
                        if (!empty($m['to_name'])) {
                            $toDisplay = $m['to_name'];
                            if (!empty($m['to_system_email'])) {
                                $toDisplay .= ' (' . $m['to_system_email'] . ')';
                            }
                        } elseif (!empty($m['to_email'])) {
                            $toDisplay = $m['to_email'];
                        } else {
                            $toDisplay = 'Unknown';
                        }
                    ?>
                    <tr>
                        <td><?php echo h($m['created_at']); ?></td>
                        <td><?php echo h($fromDisplay . ' → ' . $toDisplay); ?></td>
                        <td><?php echo h($projectLabel ?: '-'); ?></td>
                        <td>
                            <strong><?php echo h($m['subject']); ?></strong>
                            <?php if (!empty($m['attachment_path'])): ?>
                                <br>
                                <small>
                                    📎 Attachment:
                                    <a href="/<?php echo h($m['attachment_path']); ?>" target="_blank">
                                        <?php echo h($m['attachment_name'] ?: 'Download file'); ?>
                                    </a>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $m['is_draft'] ? 'Yes' : 'No'; ?></td>
                        <td>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="message_id" value="<?php echo (int)$m['id']; ?>">
                                <button type="submit" name="action" value="restore_message"
                                        class="btn-sm">
                                    Restore
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<style>
.highlight-row {
    background-color: #fff8e1; /* light yellow for unread */
}
</style>

<?php require_once __DIR__ . '/../footer.php'; ?>
