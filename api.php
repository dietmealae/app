<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
start_session();

$a = $_GET['a'] ?? '';
$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
if ($post && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) out(['ok' => false], 415);
$body = json_decode(file_get_contents('php://input') ?: '', true);
$authed = !empty($_SESSION['uid']);
$isAdmin = $authed && ($_SESSION['role'] ?? '') === 'admin';

try {
    switch ($a) {
        case 'me':
            out($authed ? ['ok' => true, 'user' => $_SESSION['user'], 'role' => $_SESSION['role'] ?? 'editor'] : ['ok' => false]);

        case 'login':
            if (!$post) out(['ok' => false], 405);
            $pdo = db();
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $st = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND at_time > (NOW() - INTERVAL 15 MINUTE)');
            $st->execute([$ip]);
            if ((int)$st->fetchColumn() >= 8) out(['ok' => false, 'error' => 'Too many attempts. Try again in 15 minutes.'], 429);
            $u = trim((string)($body['u'] ?? ''));
            $p = (string)($body['p'] ?? '');
            $st = $pdo->prepare('SELECT id, username, pass_hash, role FROM users WHERE username = ?');
            $st->execute([$u]);
            $r = $st->fetch();
            if ($r && password_verify($p, $r['pass_hash'])) {
                session_regenerate_id(true);
                $_SESSION['uid'] = (int)$r['id'];
                $_SESSION['user'] = $r['username'];
                $_SESSION['role'] = $r['role'];
                $pdo->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
                out(['ok' => true, 'role' => $r['role'], 'user' => $r['username']]);
            }
            $pdo->prepare('INSERT INTO login_attempts (ip) VALUES (?)')->execute([$ip]);
            usleep(700000);
            out(['ok' => false, 'error' => 'Wrong username or password.'], 401);

        case 'logout':
            $_SESSION = [];
            session_destroy();
            out(['ok' => true]);

        case 'ver':
            if (!$authed) out(['ok' => false], 401);
            $pdo = db(); ensure_ver($pdo);
            out(['ok' => true, 'ver' => (int)$pdo->query('SELECT COALESCE(MAX(ver), 0) FROM app_state')->fetchColumn()]);

        case 'load':
            if (!$authed) out(['ok' => false], 401);
            $pdo = db(); ensure_ver($pdo);
            $r = $pdo->query('SELECT data, ver FROM app_state WHERE id = 1')->fetch();
            out(['ok' => true, 'json' => $r ? $r['data'] : null, 'ver' => $r ? (int)$r['ver'] : 0]);

        case 'save':
            if (!$authed || !$post) out(['ok' => false], $authed ? 405 : 401);
            $j = (string)($body['json'] ?? '');
            $d = json_decode($j, true);
            if (!is_array($d) || !isset($d['items']) || !is_array($d['items'])) out(['ok' => false, 'error' => 'Invalid data'], 400);
            if (strlen($j) > 8000000) out(['ok' => false, 'error' => 'Too large'], 413);
            $pdo = db(); ensure_ver($pdo);
            $base = (int)($body['base'] ?? 0);
            $pdo->beginTransaction();
            $row = $pdo->query('SELECT data, ver FROM app_state WHERE id = 1 FOR UPDATE')->fetch();
            if ($row && (int)$row['ver'] !== $base) {
                $pdo->rollBack();
                out(['ok' => false, 'conflict' => true, 'error' => 'Someone else changed the data at the same time. The latest version was loaded. Please repeat your last change.'], 409);
            }
            if (!$isAdmin && $row && lost(json_decode((string)$row['data'], true), $d)) {
                $pdo->rollBack();
                out(['ok' => false, 'error' => 'Your account cannot delete content. The change was not saved.'], 403);
            }
            if ($row) {
                $pdo->prepare('UPDATE app_state SET data = ?, ver = ver + 1 WHERE id = 1')->execute([$j]);
                $newVer = (int)$row['ver'] + 1;
            } else {
                $pdo->prepare('INSERT INTO app_state (id, data, ver) VALUES (1, ?, 1)')->execute([$j]);
                $newVer = 1;
            }
            $pdo->commit();
            $recent = (int)$pdo->query('SELECT COUNT(*) FROM state_history WHERE saved_at > (NOW() - INTERVAL 30 MINUTE)')->fetchColumn();
            if ($recent === 0) {
                $pdo->prepare('INSERT INTO state_history (data) VALUES (?)')->execute([$j]);
                $pdo->exec('DELETE FROM state_history WHERE id <= (SELECT m FROM (SELECT COALESCE(MAX(id), 0) - 200 AS m FROM state_history) t)');
            }
            out(['ok' => true, 'ver' => $newVer]);

        case 'users':
            if (!$isAdmin) out(['ok' => false], 403);
            out(['ok' => true, 'users' => db()->query('SELECT id, username, role FROM users ORDER BY id')->fetchAll()]);

        case 'user_add':
            if (!$isAdmin || !$post) out(['ok' => false], 403);
            $u = trim((string)($body['u'] ?? ''));
            $p = (string)($body['p'] ?? '');
            $role = in_array($body['role'] ?? '', ['admin', 'editor'], true) ? $body['role'] : 'editor';
            if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $u)) out(['ok' => false, 'error' => 'Username must be 3 to 50 letters, numbers, dot, dash or underscore.'], 400);
            if (strlen($p) < 10) out(['ok' => false, 'error' => 'Password must be at least 10 characters.'], 400);
            try {
                db()->prepare('INSERT INTO users (username, pass_hash, role) VALUES (?, ?, ?)')->execute([$u, password_hash($p, PASSWORD_DEFAULT), $role]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') out(['ok' => false, 'error' => 'That username already exists.'], 409);
                throw $e;
            }
            out(['ok' => true]);

        case 'user_pass':
            if (!$isAdmin || !$post) out(['ok' => false], 403);
            $p = (string)($body['p'] ?? '');
            if (strlen($p) < 10) out(['ok' => false, 'error' => 'Password must be at least 10 characters.'], 400);
            db()->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')->execute([password_hash($p, PASSWORD_DEFAULT), (int)($body['id'] ?? 0)]);
            out(['ok' => true]);

        case 'user_del':
            if (!$isAdmin || !$post) out(['ok' => false], 403);
            $id = (int)($body['id'] ?? 0);
            if ($id === (int)$_SESSION['uid']) out(['ok' => false, 'error' => 'You cannot remove your own account.'], 400);
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            out(['ok' => true]);

        default:
            out(['ok' => false], 404);
    }
} catch (Throwable $e) {
    error_log($e->getMessage());
    out(['ok' => false, 'error' => 'Server error'], 500);
}
