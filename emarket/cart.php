<?php
require __DIR__ . '/includes/layout.php';
$u = need('customer');
$cart = &$_SESSION['cart']; if (!is_array($cart)) $cart = [];
function norm_qty($unit, $qty) { $qty = (float)$qty; return $unit === 'kg' ? max(0.25, min(99, round($qty * 4) / 4)) : max(1, min(99, round($qty))); }

if (post_ok()) {
    $a = $_POST['a'] ?? '';
    if ($a === 'add') {
        $p = one('SELECT p.*, v.id vid FROM products p JOIN vendors v ON v.id=p.vendor_id WHERE p.id=? AND p.active=1 AND ' . LISTED, [(int)$_POST['id']]);
        if ($p) { $cart[$p['id']] = norm_qty($p['unit'], ($cart[$p['id']] ?? 0) + norm_qty($p['unit'], $_POST['qty'] ?? 1)); flash($p['name'] . ' added to your cart.'); }
        else flash('That product is no longer available.', 'err');
        redirect($_SERVER['HTTP_REFERER'] ?? BASE . '/index.php');
    }
    if ($a === 'update') {
        foreach ($_POST['q'] ?? [] as $id => $qt) {
            $p = one('SELECT unit FROM products WHERE id=?', [(int)$id]);
            if ($p && isset($cart[$id])) { if ((float)$qt <= 0) unset($cart[$id]); else $cart[$id] = norm_qty($p['unit'], $qt); }
        }
        flash('Cart updated.'); redirect('cart.php');
    }
    if ($a === 'remove') { unset($cart[(int)$_POST['id']]); redirect('cart.php'); }
    if ($a === 'checkout') {
        if ($u['status'] === 'blocked') { flash('Your account is blocked from ordering due to repeated uncollected orders. Please contact the market administration.', 'err'); redirect('cart.php'); }
        $groups = [];
        foreach ($cart as $id => $qt) {
            $p = one('SELECT p.*, v.user_id vuser FROM products p JOIN vendors v ON v.id=p.vendor_id WHERE p.id=? AND p.active=1 AND ' . LISTED, [$id]);
            if (!$p) { flash('An item was removed because it is no longer available.', 'warn'); unset($cart[$id]); continue; }
            if ($p['stock'] < $qt) { flash($p['name'] . ': only ' . qty($p['stock']) . ' ' . $p['unit'] . ' left. Adjust your quantity.', 'err'); redirect('cart.php'); }
            $groups[$p['vendor_id']][] = [$p, $qt];
        }
        $made = 0;
        foreach ($groups as $vid => $items) {
            $total = 0; foreach ($items as [$p, $qt]) $total += $p['price'] * $qt;
            q("INSERT INTO orders(customer_id,vendor_id,total,note) VALUES(?,?,?,?)", [$u['id'], $vid, $total, substr(trim($_POST['note'] ?? ''), 0, 250)]);
            $oid = db()->lastInsertId();
            foreach ($items as [$p, $qt]) { q('INSERT INTO order_items(order_id,product_id,name,unit,unit_price,qty) VALUES(?,?,?,?,?,?)', [$oid, $p['id'], $p['name'], $p['unit'], $p['price'], $qt]); unset($cart[$p['id']]); }
            notify($items[0][0]['vuser'], "New order #$oid from {$u['name']} — respond within " . setting('response_minutes') . ' minutes.');
            $made++;
        }
        if ($made) { flash("$made order(s) placed! Each vendor will accept or reject. You pay in cash at pickup."); redirect('orders.php'); }
        redirect('cart.php');
    }
}
$rows = [];
if ($cart) {
    $in = implode(',', array_map('intval', array_keys($cart)));
    foreach (all("SELECT p.*, v.store_name, v.id vid, " . LOC . " AS location FROM products p JOIN vendors v ON v.id=p.vendor_id WHERE p.id IN ($in)") as $p) $rows[$p['vid']][] = $p;
}
page_start('My Cart'); $grand = 0; ?>
<h1 style="margin-bottom:6px">🛒 Your cart</h1><p class="meta" style="margin-bottom:18px">Items from different vendors become separate orders — one pickup per stall.</p>
<?php if (!$rows): ?><div class="empty"><span>🧺</span>Your cart is empty.<br><br><a class="btn" href="<?= BASE ?>/index.php">Start shopping</a></div>
<?php else: ?>
<form method="post"><?= csrf() ?><input type="hidden" name="a" value="update">
<?php foreach ($rows as $vid => $items): $sub = 0; ?>
<div class="card"><h3>🏪 <?= e($items[0]['store_name']) ?> <small class="meta" style="font-family:var(--body)">· 📍 <?= e($items[0]['location']) ?></small></h3>
<div class="tw"><table><tr><th>Item</th><th>Price</th><th style="width:130px">Qty</th><th class="num">Subtotal</th><th></th></tr>
<?php foreach ($items as $p): $qt = $cart[$p['id']]; $sub += $p['price'] * $qt; ?>
<tr><td><?= e($p['emoji']) ?> <b><?= e($p['name']) ?></b><?= $p['unit'] === 'kg' ? '<div class="hint">⚖️ estimate — final price follows actual weight</div>' : '' ?></td>
<td><?= peso($p['price']) ?>/<?= $p['unit'] ?></td>
<td><input type="number" name="q[<?= $p['id'] ?>]" value="<?= qty($qt) ?>" min="0" step="<?= $p['unit'] === 'kg' ? '0.25' : '1' ?>"></td>
<td class="num"><?= peso($p['price'] * $qt) ?></td>
<td><button class="btn xs ghost" form="rm<?= $p['id'] ?>">✕</button></td></tr><?php endforeach; $grand += $sub; ?>
<tr><td colspan="3" class="num"><b>Vendor subtotal</b></td><td class="num"><b><?= peso($sub) ?></b></td><td></td></tr></table></div></div>
<?php endforeach; ?>
<div style="margin-top:14px"><button class="btn ghost">Update quantities</button></div></form>
<?php foreach ($cart as $id => $_): ?><form id="rm<?= $id ?>" method="post"><?= csrf() ?><input type="hidden" name="a" value="remove"><input type="hidden" name="id" value="<?= $id ?>"></form><?php endforeach; ?>
<form method="post" class="card" style="margin-top:18px"><?= csrf() ?><input type="hidden" name="a" value="checkout">
  <div class="fg"><label>Note to vendor (optional)</label><input name="note" maxlength="250" placeholder="e.g. Please slice thinly"></div>
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px"><div><div class="meta">Estimated total · pay in cash on pickup</div><div class="total" style="font-size:1.8rem"><?= peso($grand) ?></div></div>
  <button class="btn gold">Place order for pickup →</button></div></form>
<?php endif; page_end();
