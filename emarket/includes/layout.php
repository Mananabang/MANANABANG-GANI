<?php
require_once __DIR__ . '/../config.php';

function boot() { static $d; if ($d) return; $d = 1; housekeeping(); }

function badge($text, $cls = 'gray') { return '<span class="badge b-' . $cls . '">' . e($text) . '</span>'; }
function status_badge($s) {
    $m = ['pending' => 'gold', 'accepted' => 'blue', 'preparing' => 'blue', 'ready' => 'green', 'completed' => 'green',
          'rejected' => 'red', 'cancelled' => 'gray', 'uncollected' => 'red', 'vacant' => 'green', 'reserved' => 'gold',
          'occupied' => 'blue', 'active' => 'green', 'flagged' => 'gold', 'blocked' => 'red', 'paid' => 'green',
          'unpaid' => 'gold', 'overdue' => 'red', 'expired' => 'red', 'ended' => 'gray', 'open' => 'gold', 'resolved' => 'green',
          'valid' => 'green', 'due' => 'gold', 'pending_app' => 'gold', 'awarded' => 'green'];
    return badge(ucfirst($s), $m[$s] ?? 'gray');
}
function stock_label($p) {
    if ($p['stock'] <= 0) return ['Out of stock', 'red'];
    if ($p['stock'] <= $p['low_at']) return ['Low stock', 'gold'];
    return ['In stock', 'green'];
}
function steps_html($status) {
    $flow = ['pending', 'accepted', 'preparing', 'ready', 'completed'];
    if (!in_array($status, $flow, true)) return '<div class="end-state">' . status_badge($status) . '</div>';
    $at = array_search($status, $flow); $h = '<div class="steps">';
    foreach ($flow as $i => $s) $h .= '<div class="step ' . ($i < $at ? 'done' : ($i == $at ? 'now' : '')) . '"><i>' . ($i < $at ? '✓' : $i + 1) . '</i><span>' . ucfirst($s) . '</span></div>';
    return $h . '</div>';
}
function show_flash() {
    foreach (flash() as [$t, $m]) echo '<div class="flash f-' . $t . '">' . e($m) . '</div>';
}
function product_card($p) {
    [$lbl, $cls] = stock_label($p); $u = me(); $wet = $p['unit'] === 'kg';
    $h = '<article class="pcard"><div class="pimg"><span>' . e($p['emoji']) . '</span>' . badge($lbl, $cls) . '</div><div class="pbody">';
    $h .= '<h3>' . e($p['name']) . '</h3><a class="pvendor" href="' . BASE . '/store.php?id=' . $p['vid'] . '">🏪 ' . e($p['store_name']) . '</a>';
    $h .= '<div class="ploc">📍 ' . e($p['location']) . '</div>';
    $h .= '<div class="pprice">' . peso($p['price']) . '<small> / ' . e($p['unit']) . '</small></div>';
    if ($wet) $h .= '<div class="hint">⚖️ Final price depends on actual weight.</div>';
    if ($p['stock'] > 0) {
        if ($u && $u['role'] === 'customer') {
            $step = $wet ? '0.25' : '1';
            $h .= '<form method="post" action="' . BASE . '/cart.php" class="addform">' . csrf() . '<input type="hidden" name="a" value="add"><input type="hidden" name="id" value="' . $p['id'] . '">
                   <input type="number" name="qty" min="' . $step . '" step="' . $step . '" value="' . ($wet ? '0.5' : '1') . '"><button class="btn sm">Add to cart</button></form>';
        } elseif (!$u) $h .= '<a class="btn sm ghost" href="' . BASE . '/login.php">Log in to order</a>';
    }
    return $h . '</div></article>';
}

function page_start($title, $hero = false) {
    boot(); $u = me();
    $unread = $u ? (int)val('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0', [$u['id']]) : 0;
    $cart = count($_SESSION['cart'] ?? []);
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?> · E-Market Cogon</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE ?>/assets/style.css"></head><body>
<header class="nav"><div class="wrap navin">
  <a class="brand" href="<?= BASE ?>/index.php"><span class="logo">🧺</span><b>E-Market</b><em>Cogon</em></a>
  <nav>
    <a href="<?= BASE ?>/index.php">Market</a><a href="<?= BASE ?>/index.php#vendors">Vendors</a>
    <?php if ($u && $u['role'] === 'customer'): ?><a href="<?= BASE ?>/orders.php">My Orders</a><?php endif; ?>
    <?php if ($u && $u['role'] !== 'customer'): ?><a href="<?= home_for($u['role']) ?>">Dashboard</a><?php endif; ?>
  </nav>
  <div class="navr">
    <?php if ($u): ?>
      <?php if ($u['role'] === 'customer'): ?><a class="iconbtn" href="<?= BASE ?>/cart.php" title="Cart">🛒<?php if ($cart): ?><i><?= $cart ?></i><?php endif; ?></a><?php endif; ?>
      <a class="iconbtn" href="<?= BASE ?>/notifications.php" title="Notifications">🔔<?php if ($unread): ?><i><?= $unread ?></i><?php endif; ?></a>
      <span class="who"><?= e(explode(' ', $u['name'])[0]) ?></span><a class="btn sm ghost" href="<?= BASE ?>/logout.php">Log out</a>
    <?php else: ?><a class="btn sm ghost" href="<?= BASE ?>/login.php">Log in</a><a class="btn sm" href="<?= BASE ?>/register.php">Sign up</a><?php endif; ?>
  </div></div></header>
<main class="<?= $hero ? '' : 'wrap pad' ?>"><?php if (!$hero) show_flash();
}
function page_end() {
    ?></main><footer class="foot"><div class="wrap"><b>🧺 E-Market Cogon</b> — the digital counterpart of Cogon Market, Cagayan de Oro City.
<p>Pickup only · Cash on pickup · Your data is handled in line with the Data Privacy Act of 2012 (RA 10173).</p></div></footer>
<script src="<?= BASE ?>/assets/app.js"></script></body></html><?php
}

// ---------- dashboard layout (admin / staff / vendor) ----------
function dash_start($title, $menu, $cur, $roleLabel) {
    boot(); $u = me();
    $unread = (int)val('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0', [$u['id']]);
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?> · E-Market Cogon</title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE ?>/assets/style.css"></head><body class="dashbody">
<button class="burger" onclick="document.body.classList.toggle('menu')">☰</button>
<aside class="side"><a class="brand light" href="<?= BASE ?>/index.php"><span class="logo">🧺</span><b>E-Market</b><em>Cogon</em></a>
<div class="role"><?= e($roleLabel) ?></div>
<nav><?php foreach ($menu as $k => [$ico, $lbl]): ?><a class="<?= $k === $cur ? 'on' : '' ?>" href="<?= $k[0] === '/' ? BASE . $k : '?p=' . $k ?>"><span><?= $ico ?></span><?= e($lbl) ?></a><?php endforeach; ?></nav>
<div class="sidefoot"><a href="<?= BASE ?>/notifications.php">🔔 Notifications <?= $unread ? '<i class="dot">' . $unread . '</i>' : '' ?></a>
<a href="<?= BASE ?>/index.php">🌐 View storefront</a><a href="<?= BASE ?>/logout.php">🚪 Log out</a>
<div class="me"><b><?= e($u['name']) ?></b><small><?= e($u['email']) ?></small></div></div></aside>
<main class="dmain"><div class="dtop"><h1><?= e($menu[$cur][1] ?? $title) ?></h1><span><?= date('l, F j, Y') ?></span></div><?php show_flash();
}
function dash_end() { ?></main><script src="<?= BASE ?>/assets/app.js"></script></body></html><?php }

function stat_card($ico, $label, $value, $tone = 'green') {
    return '<div class="stat t-' . $tone . '"><div class="sico">' . $ico . '</div><div><small>' . e($label) . '</small><b>' . $value . '</b></div></div>';
}

// ---------- rental helpers (shared by staff / vendor / admin) ----------
function bill_status($b) { // needs amount, paid, due_date
    if ((float)$b['paid'] >= (float)$b['amount']) return 'paid';
    return $b['due_date'] < date('Y-m-d') ? 'overdue' : 'unpaid';
}
function permit_status($expiry) {
    if ($expiry < date('Y-m-d')) return 'expired';
    return $expiry <= date('Y-m-d', strtotime('+30 days')) ? 'due' : 'valid';
}
const BILL_SQL = "SELECT b.*, a.vendor_id, (SELECT COALESCE(SUM(amount),0) FROM payments WHERE billing_id=b.id) paid,
  (SELECT GROUP_CONCAT(or_number SEPARATOR ', ') FROM payments WHERE billing_id=b.id) ors,
  (SELECT MAX(paid_on) FROM payments WHERE billing_id=b.id) paid_on FROM billings b JOIN agreements a ON a.id=b.agreement_id";
