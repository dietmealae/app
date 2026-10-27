<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
start_session();

$a = $_GET['a'] ?? '';
$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
if ($post && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) out(['ok' => false], 415);
if (empty($_SESSION['uid'])) out(['ok' => false], 401);
$b = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($b)) $b = [];
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$user = (string)($_SESSION['user'] ?? '');

function need_admin(bool $isAdmin): void {
    if (!$isAdmin) out(['ok' => false, 'error' => 'Your account cannot delete. Please ask an admin.'], 403);
}
function valid_date(string $d): bool {
    $x = DateTime::createFromFormat('Y-m-d', $d);
    return $x !== false && $x->format('Y-m-d') === $d;
}
function txt(array $b, string $k, int $max): string {
    return mb_substr(trim((string)($b[$k] ?? '')), 0, $max);
}
function is_serving(DateTimeImmutable $d, array $hol): bool {
    return $d->format('N') !== '7' && !in_array($d->format('Y-m-d'), $hol, true);
}
function next_serving(DateTimeImmutable $d, array $hol): DateTimeImmutable {
    do { $d = $d->modify('+1 day'); } while (!is_serving($d, $hol));
    return $d;
}

try {
    if (!$post && $a !== 'boot') out(['ok' => false], 405);
    $pdo = db();
    ensure_crm($pdo);
    $hol = $pdo->query('SELECT hdate FROM holidays ORDER BY hdate')->fetchAll(PDO::FETCH_COLUMN);

    switch ($a) {
        case 'boot':
            out([
                'ok' => true,
                'customers' => $pdo->query('SELECT id, name, phone, notes FROM customers ORDER BY id DESC')->fetchAll(),
                'addresses' => $pdo->query('SELECT id, customer_id, label, area, address, map_url, notes FROM addresses ORDER BY id')->fetchAll(),
                'orders' => $pdo->query("SELECT o.id, o.customer_id, o.address_id, o.category, o.meal_type, o.plan_days, o.start_date, o.price, o.status, o.notes,
                    (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.order_id = o.id) AS paid,
                    (SELECT COUNT(*) FROM order_meals m WHERE m.order_id = o.id AND m.status IN ('scheduled','delivered')) AS total_meals,
                    (SELECT COUNT(*) FROM order_meals m WHERE m.order_id = o.id AND m.status = 'delivered') AS done_meals,
                    (SELECT MAX(m.serve_date) FROM order_meals m WHERE m.order_id = o.id AND m.status IN ('scheduled','delivered')) AS end_date
                    FROM orders o ORDER BY o.id DESC")->fetchAll(),
                'settings' => $pdo->query('SELECT skey, sval FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR),
                'holidays' => $hol,
            ]);

        case 'customer_save':
            $id = (int)($b['id'] ?? 0);
            $name = txt($b, 'name', 120);
            if ($name === '') out(['ok' => false, 'error' => 'Customer name is required.'], 400);
            $phone = txt($b, 'phone', 40);
            $notes = txt($b, 'notes', 2000);
            if ($id > 0) {
                $pdo->prepare('UPDATE customers SET name = ?, phone = ?, notes = ? WHERE id = ?')->execute([$name, $phone, $notes, $id]);
            } else {
                $pdo->prepare('INSERT INTO customers (name, phone, notes) VALUES (?, ?, ?)')->execute([$name, $phone, $notes]);
                $id = (int)$pdo->lastInsertId();
            }
            out(['ok' => true, 'id' => $id]);

        case 'customer_delete':
            need_admin($isAdmin);
            $id = (int)($b['id'] ?? 0);
            $st = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE customer_id = ?');
            $st->execute([$id]);
            if ((int)$st->fetchColumn() > 0) out(['ok' => false, 'error' => 'This customer has orders, so it cannot be deleted.'], 409);
            $pdo->prepare('DELETE FROM addresses WHERE customer_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);
            out(['ok' => true]);

        case 'address_save':
            $id = (int)($b['id'] ?? 0);
            $cid = (int)($b['customer_id'] ?? 0);
            $st = $pdo->prepare('SELECT COUNT(*) FROM customers WHERE id = ?');
            $st->execute([$cid]);
            if ((int)$st->fetchColumn() === 0) out(['ok' => false, 'error' => 'Save the customer first.'], 400);
            $vals = [txt($b, 'label', 60), txt($b, 'area', 80), txt($b, 'address', 255), txt($b, 'map_url', 500), txt($b, 'notes', 255)];
            if ($vals[2] === '' && $vals[3] === '') out(['ok' => false, 'error' => 'Enter an address or a map link.'], 400);
            if ($vals[3] !== '' && !preg_match('#^https?://#i', $vals[3])) out(['ok' => false, 'error' => 'The map link must start with http:// or https://'], 400);
            if ($id > 0) {
                $pdo->prepare('UPDATE addresses SET label = ?, area = ?, address = ?, map_url = ?, notes = ? WHERE id = ? AND customer_id = ?')->execute([...$vals, $id, $cid]);
            } else {
                $pdo->prepare('INSERT INTO addresses (label, area, address, map_url, notes, customer_id) VALUES (?, ?, ?, ?, ?, ?)')->execute([...$vals, $cid]);
                $id = (int)$pdo->lastInsertId();
            }
            out(['ok' => true, 'id' => $id]);

        case 'address_delete':
            need_admin($isAdmin);
            $id = (int)($b['id'] ?? 0);
            $st = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE address_id = ?');
            $st->execute([$id]);
            if ((int)$st->fetchColumn() > 0) out(['ok' => false, 'error' => 'An order uses this address, so it cannot be deleted.'], 409);
            $pdo->prepare('DELETE FROM addresses WHERE id = ?')->execute([$id]);
            out(['ok' => true]);

        case 'order_create':
            $cid = (int)($b['customer_id'] ?? 0);
            $aid = (int)($b['address_id'] ?? 0);
            $cat = txt($b, 'category', 2);
            $mt = txt($b, 'meal_type', 1);
            $days = (int)($b['plan_days'] ?? 0);
            $start = txt($b, 'start_date', 10);
            $price = round((float)($b['price'] ?? 0), 2);
            if (!in_array($cat, ['WL', 'WB', 'WG'], true) || !in_array($mt, ['L', 'D', 'B'], true) || !in_array($days, [1, 6, 24], true)) out(['ok' => false, 'error' => 'Invalid plan.'], 400);
            if (!valid_date($start)) out(['ok' => false, 'error' => 'Invalid start date.'], 400);
            if ($price < 0 || $price > 1000000) out(['ok' => false, 'error' => 'Invalid price.'], 400);
            $st = $pdo->prepare('SELECT COUNT(*) FROM customers WHERE id = ?');
            $st->execute([$cid]);
            if ((int)$st->fetchColumn() === 0) out(['ok' => false, 'error' => 'Choose a customer.'], 400);
            if ($aid > 0) {
                $st = $pdo->prepare('SELECT COUNT(*) FROM addresses WHERE id = ? AND customer_id = ?');
                $st->execute([$aid, $cid]);
                if ((int)$st->fetchColumn() === 0) out(['ok' => false, 'error' => 'That address belongs to another customer.'], 400);
            }
            $d = new DateTimeImmutable($start);
            if (!is_serving($d, $hol)) $d = next_serving($d, $hol);
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO orders (customer_id, address_id, category, meal_type, plan_days, start_date, price, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$cid, $aid > 0 ? $aid : null, $cat, $mt, $days, $d->format('Y-m-d'), $price, txt($b, 'notes', 500), $user]);
            $oid = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO order_meals (order_id, serve_date, meal) VALUES (?, ?, ?)');
            for ($i = 0; $i < $days; $i++) {
                foreach (($mt === 'B' ? ['L', 'D'] : [$mt]) as $m) $ins->execute([$oid, $d->format('Y-m-d'), $m]);
                $d = next_serving($d, $hol);
            }
            $pdo->commit();
            out(['ok' => true, 'id' => $oid]);

        case 'order_get':
            $id = (int)($b['id'] ?? 0);
            $st = $pdo->prepare('SELECT o.*, c.name AS customer_name, c.phone AS customer_phone FROM orders o JOIN customers c ON c.id = o.customer_id WHERE o.id = ?');
            $st->execute([$id]);
            $o = $st->fetch();
            if (!$o) out(['ok' => false, 'error' => 'Order not found.'], 404);
            $st = $pdo->prepare('SELECT id, serve_date, meal, status, moved_from FROM order_meals WHERE order_id = ? ORDER BY serve_date, meal');
            $st->execute([$id]);
            $meals = $st->fetchAll();
            $st = $pdo->prepare('SELECT id, paid_on, amount, method, note, created_by FROM payments WHERE order_id = ? ORDER BY paid_on, id');
            $st->execute([$id]);
            out(['ok' => true, 'order' => $o, 'meals' => $meals, 'payments' => $st->fetchAll()]);

        case 'order_update':
            $id = (int)($b['id'] ?? 0);
            $aid = (int)($b['address_id'] ?? 0);
            $st = $pdo->prepare('SELECT customer_id FROM orders WHERE id = ?');
            $st->execute([$id]);
            $cid = $st->fetchColumn();
            if ($cid === false) out(['ok' => false, 'error' => 'Order not found.'], 404);
            if ($aid > 0) {
                $st = $pdo->prepare('SELECT COUNT(*) FROM addresses WHERE id = ? AND customer_id = ?');
                $st->execute([$aid, (int)$cid]);
                if ((int)$st->fetchColumn() === 0) out(['ok' => false, 'error' => 'That address belongs to another customer.'], 400);
            }
            $pdo->prepare('UPDATE orders SET address_id = ?, notes = ? WHERE id = ?')->execute([$aid > 0 ? $aid : null, txt($b, 'notes', 500), $id]);
            out(['ok' => true]);

        case 'order_cancel':
            need_admin($isAdmin);
            $id = (int)($b['id'] ?? 0);
            $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?")->execute([$id]);
            $pdo->prepare("UPDATE order_meals SET status = 'cancelled' WHERE order_id = ? AND status = 'scheduled'")->execute([$id]);
            out(['ok' => true]);

        case 'postpone':
            $ids = array_values(array_unique(array_map('intval', is_array($b['ids'] ?? null) ? $b['ids'] : [])));
            if (!$ids || count($ids) > 2) out(['ok' => false, 'error' => 'Choose the meal to postpone.'], 400);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT m.id, m.order_id, m.serve_date, m.meal, m.status, o.status AS ostatus FROM order_meals m JOIN orders o ON o.id = m.order_id WHERE m.id IN ($in)");
            $st->execute($ids);
            $rows = $st->fetchAll();
            if (count($rows) !== count($ids)) out(['ok' => false, 'error' => 'Meal not found.'], 404);
            $oid = (int)$rows[0]['order_id'];
            foreach ($rows as $r) {
                if ((int)$r['order_id'] !== $oid || $r['status'] !== 'scheduled' || $r['ostatus'] !== 'active') out(['ok' => false, 'error' => 'Only scheduled meals of an active order can be postponed.'], 400);
            }
            $st = $pdo->prepare("SELECT MAX(serve_date) FROM order_meals WHERE order_id = ? AND status IN ('scheduled','delivered')");
            $st->execute([$oid]);
            $new = next_serving(new DateTimeImmutable((string)$st->fetchColumn()), $hol)->format('Y-m-d');
            $pdo->beginTransaction();
            $upd = $pdo->prepare("UPDATE order_meals SET status = 'postponed' WHERE id = ?");
            $ins = $pdo->prepare('INSERT INTO order_meals (order_id, serve_date, meal, moved_from) VALUES (?, ?, ?, ?)');
            foreach ($rows as $r) {
                $upd->execute([(int)$r['id']]);
                $ins->execute([$oid, $new, $r['meal'], $r['serve_date']]);
            }
            $pdo->commit();
            out(['ok' => true, 'new_date' => $new]);

        case 'deliver':
            $id = (int)($b['meal_id'] ?? 0);
            $status = (string)($b['status'] ?? '');
            if (!in_array($status, ['delivered', 'scheduled'], true)) out(['ok' => false], 400);
            $pdo->prepare("UPDATE order_meals SET status = ? WHERE id = ? AND status IN ('scheduled','delivered')")->execute([$status, $id]);
            out(['ok' => true]);

        case 'payment_add':
            $oid = (int)($b['order_id'] ?? 0);
            $amount = round((float)($b['amount'] ?? 0), 2);
            $on = txt($b, 'paid_on', 10);
            $method = txt($b, 'method', 20);
            if ($amount <= 0 || $amount > 1000000) out(['ok' => false, 'error' => 'Enter an amount above zero.'], 400);
            if (!valid_date($on)) out(['ok' => false, 'error' => 'Invalid payment date.'], 400);
            if (!in_array($method, ['cash', 'card', 'transfer', 'online'], true)) $method = 'cash';
            $st = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE id = ?');
            $st->execute([$oid]);
            if ((int)$st->fetchColumn() === 0) out(['ok' => false, 'error' => 'Order not found.'], 404);
            $pdo->prepare('INSERT INTO payments (order_id, paid_on, amount, method, note, created_by) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$oid, $on, $amount, $method, txt($b, 'note', 255), $user]);
            out(['ok' => true]);

        case 'payment_delete':
            need_admin($isAdmin);
            $pdo->prepare('DELETE FROM payments WHERE id = ?')->execute([(int)($b['id'] ?? 0)]);
            out(['ok' => true]);

        case 'sheet':
            $date = txt($b, 'date', 10);
            if (!valid_date($date)) out(['ok' => false, 'error' => 'Invalid date.'], 400);
            $st = $pdo->prepare("SELECT m.id, m.meal, m.status, o.id AS order_id, o.category, o.notes AS order_notes, o.price,
                    (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.order_id = o.id) AS paid,
                    c.name, c.phone, a.label, a.area, a.address, a.map_url, a.notes AS address_notes
                FROM order_meals m
                JOIN orders o ON o.id = m.order_id
                JOIN customers c ON c.id = o.customer_id
                LEFT JOIN addresses a ON a.id = o.address_id
                WHERE m.serve_date = ? AND m.status IN ('scheduled','delivered') AND o.status = 'active'
                ORDER BY a.area, c.name, m.meal");
            $st->execute([$date]);
            out(['ok' => true, 'rows' => $st->fetchAll()]);

        case 'settings_save':
            $cs = txt($b, 'cycle_start', 10);
            if (!valid_date($cs)) out(['ok' => false, 'error' => 'Invalid date.'], 400);
            $pdo->prepare('INSERT INTO settings (skey, sval) VALUES ("cycle_start", ?) ON DUPLICATE KEY UPDATE sval = VALUES(sval)')->execute([$cs]);
            out(['ok' => true]);

        case 'holiday_add':
            $d = txt($b, 'date', 10);
            if (!valid_date($d)) out(['ok' => false, 'error' => 'Invalid date.'], 400);
            $pdo->prepare('INSERT IGNORE INTO holidays (hdate, note) VALUES (?, ?)')->execute([$d, txt($b, 'note', 100)]);
            out(['ok' => true]);

        case 'holiday_delete':
            need_admin($isAdmin);
            $d = txt($b, 'date', 10);
            $pdo->prepare('DELETE FROM holidays WHERE hdate = ?')->execute([$d]);
            out(['ok' => true]);

        default:
            out(['ok' => false], 404);
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log($e->getMessage());
    out(['ok' => false, 'error' => 'Server error'], 500);
}
