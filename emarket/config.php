<?php
// ---------- E-Market Cogon : configuration ----------
session_start();
date_default_timezone_set('Asia/Manila');

const DB_HOST = 'localhost';
const DB_NAME = 'cogon_emarket';
const DB_USER = 'root';
const DB_PASS = '';
const BASE    = '/emarket';   // folder name under htdocs (change if you rename it)
const BILL_DUE_DAY = 10;      // monthly rental due day (follows lease terms in practice)

// A vendor is listed only with an ACTIVE stall agreement AND a VALID business permit
const LISTED = "v.active=1
 AND EXISTS(SELECT 1 FROM agreements a WHERE a.vendor_id=v.id AND a.status='active' AND a.end_date>=CURDATE())
 AND EXISTS(SELECT 1 FROM permits pm WHERE pm.vendor_id=v.id AND pm.expiry_date>=CURDATE())";
// Stall location text of a vendor (alias v)
const LOC = "(SELECT CONCAT(sc.name,' · Stall ',st.stall_no) FROM agreements ag
 JOIN stalls st ON st.id=ag.stall_id JOIN sections sc ON sc.id=st.section_id
 WHERE ag.vendor_id=v.id AND ag.status='active' ORDER BY ag.id DESC LIMIT 1)";

function db(): PDO {
    static $pdo;
    if (!$pdo) {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("SET time_zone='+08:00'");
    }
    return $pdo;
}
function q($sql, $p = []) { $s = db()->prepare($sql); $s->execute($p); return $s; }
function all($sql, $p = []) { return q($sql, $p)->fetchAll(); }
function one($sql, $p = []) { $r = q($sql, $p)->fetch(); return $r ?: null; }
function val($sql, $p = []) { return q($sql, $p)->fetchColumn(); }

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function peso($n) { return '₱' . number_format((float)$n, 2); }
function qty($n) { return rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.'); }
function redirect($u) { header('Location: ' . $u); exit; }
function flash($m = null, $t = 'ok') {
    if ($m !== null) { $_SESSION['flash'][] = [$t, $m]; return; }
    $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f;
}
function me() {
    static $u = false;
    if ($u === false) $u = isset($_SESSION['uid']) ? one('SELECT * FROM users WHERE id=?', [$_SESSION['uid']]) : null;
    return $u;
}
function need($roles) {
    $u = me();
    if (!$u) {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') $_SESSION['back'] = $_SERVER['REQUEST_URI'];
        flash('Please log in to continue.', 'warn');
        redirect(BASE . '/login.php');
    }
    if (!in_array($u['role'], (array)$roles, true)) { http_response_code(403); exit('Access denied for your role.'); }
    return $u;
}
function home_for($role) {
    return ['admin' => BASE . '/admin/', 'staff' => BASE . '/staff/', 'vendor' => BASE . '/vendor/', 'customer' => BASE . '/index.php'][$role] ?? BASE . '/';
}
function csrf() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return '<input type="hidden" name="_t" value="' . $_SESSION['csrf'] . '">';
}
function post_ok() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return false;
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['_t'] ?? '')) { http_response_code(400); exit('Invalid request token. Go back and retry.'); }
    return true;
}
function setting($k) { return val('SELECT v FROM settings WHERE k=?', [$k]); }
function notify($uid, $msg) { q('INSERT INTO notifications(user_id,message) VALUES(?,?)', [$uid, $msg]); }

// ---------- order helpers ----------
function restore_stock($orderId) {
    foreach (all('SELECT product_id,qty FROM order_items WHERE order_id=?', [$orderId]) as $i)
        q('UPDATE products SET stock=stock+? WHERE id=?', [$i['qty'], $i['product_id']]);
}
function mark_uncollected($orderId) {
    $o = one('SELECT * FROM orders WHERE id=?', [$orderId]);
    if (!$o || !in_array($o['status'], ['ready', 'accepted', 'preparing'], true)) return;
    restore_stock($orderId);
    q("UPDATE orders SET status='uncollected' WHERE id=?", [$orderId]);
    q('UPDATE users SET strikes=strikes+1 WHERE id=?', [$o['customer_id']]);
    $c = one('SELECT * FROM users WHERE id=?', [$o['customer_id']]);
    $max = (int)setting('max_strikes');
    if ($c['strikes'] >= $max) q("UPDATE users SET status='blocked' WHERE id=?", [$c['id']]);
    elseif ($c['strikes'] >= $max - 1) q("UPDATE users SET status='flagged' WHERE id=?", [$c['id']]);
    notify($c['id'], "Order #$orderId was not collected in time and recorded as a strike ({$c['strikes']}/$max).");
}
function housekeeping() {
    $mins = (int)setting('response_minutes');
    foreach (all("SELECT id,customer_id FROM orders WHERE status='pending' AND created_at < (NOW() - INTERVAL $mins MINUTE)") as $o) {
        q("UPDATE orders SET status='cancelled', note='Auto-cancelled: vendor did not respond in time' WHERE id=?", [$o['id']]);
        notify($o['customer_id'], "Order #{$o['id']} was auto-cancelled because the vendor did not respond in time.");
    }
    foreach (all("SELECT id FROM orders WHERE status='ready' AND pickup_deadline < NOW()") as $o) mark_uncollected($o['id']);
    q("UPDATE agreements SET status='expired' WHERE status='active' AND end_date < CURDATE()");
}
