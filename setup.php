<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }

$msg = ''; $done = false; $locked = false;
try { $locked = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0; } catch (Throwable $e) { $locked = false; }

if (!$locked && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $c = cfg();
        $key = (string)($_POST['key'] ?? '');
        $sk = (string)($c['setup_key'] ?? '');
        if (strlen($sk) < 20 || !hash_equals($sk, $key)) throw new Exception('The setup key is wrong, or the setup_key in config.php is shorter than 20 characters.');
        $u = trim((string)($_POST['u'] ?? ''));
        $p = (string)($_POST['p'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $u)) throw new Exception('Username must be 3 to 50 letters, numbers, dot, dash or underscore.');
        if (strlen($p) < 10) throw new Exception('Password must be at least 10 characters.');
        if ($p !== (string)($_POST['p2'] ?? '')) throw new Exception('The two passwords do not match.');
        $pdo = db();
        create_tables($pdo);
        if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) throw new Exception('Setup is already done.');
        $pdo->prepare('INSERT INTO users (username, pass_hash, role) VALUES (?, ?, "admin")')->execute([$u, password_hash($p, PASSWORD_DEFAULT)]);
        $done = true;
    } catch (PDOException $e) {
        error_log($e->getMessage());
        $msg = 'Database error. Check the database name, user and password in config.php.';
    } catch (Throwable $e) {
        $msg = $e->getMessage();
    }
}
?><!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex">
<meta name="viewport" content="width=device-width, initial-scale=1"><title>Setup</title>
<style>body{font:16px/1.5 system-ui,sans-serif;background:#edf3ee;color:#17302a;margin:0;padding:24px}
.c{max-width:430px;margin:30px auto;background:#fff;border:1px solid #d8e4dc;border-radius:14px;padding:24px}
input{width:100%;padding:9px;margin:4px 0 14px;border:1px solid #c9d6cd;border-radius:8px;font:inherit;box-sizing:border-box}
button{background:#1d6b57;color:#fff;border:0;border-radius:999px;padding:10px 22px;font:inherit;font-weight:600;cursor:pointer}.e{color:#b6402e}</style></head><body><div class="c">
<h2>Healthy Meal Manager setup</h2>
<?php if ($locked || $done): ?>
  <p><?= $done ? 'The admin account was created.' : 'Setup is already done. The admin account exists.' ?></p>
  <p><a href="./">Open the app</a></p>
  <?php if ($done): ?><p>For safety, delete <b>setup.php</b> from the server folder (File Manager) when you are ready. It already refuses to run again.</p><?php endif; ?>
<?php else: ?>
  <?php if ($msg): ?><p class="e"><?= h($msg) ?></p><?php endif; ?>
  <form method="post" autocomplete="off">
    <label>Setup key (from config.php)<input type="password" name="key" required></label>
    <label>Admin username<input name="u" value="admin" required></label>
    <label>Admin password (10 or more characters)<input type="password" name="p" required></label>
    <label>Repeat password<input type="password" name="p2" required></label>
    <button>Create admin and tables</button>
  </form>
<?php endif; ?>
</div></body></html>
