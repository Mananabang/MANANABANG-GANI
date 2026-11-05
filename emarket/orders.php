<?php
require __DIR__ . '/includes/layout.php';
$u = need('customer');
if (post_ok()) {
    $o = one('SELECT o.*, v.user_id vuser FROM orders o JOIN vendors v ON v.id=o.vendor_id WHERE o.id=? AND o.customer_id=?', [(int)$_POST['id'], $u['id']]);
    if ($o && ($_POST['a'] ?? '') === 'cancel' && $o['status'] === 'pending') {
        q("UPDATE orders SET status='cancelled', note='Cancelled by customer' WHERE id=?", [$o['id']]);
        notify($o['vuser'], "Order #{$o['id']} was cancelled by the customer."); flash('Order cancelled.');
    }
    if ($o && ($_POST['a'] ?? '') === 'report' && trim($_POST['reason'] ?? '')) {
        q("INSERT INTO reports(reporter_id,target_type,target_id,reason) VALUES(?,'vendor',?,?)", [$u['id'], $o['vendor_id'], 'Order #' . $o['id'] . ': ' . substr(trim($_POST['reason']), 0, 200)]);
        flash('Thanks — your report was sent to the administrator.');
    }
    redirect('orders.php');
}
$orders = all('SELECT o.*, v.store_name, v.emoji, ' . LOC . ' AS location FROM orders o JOIN vendors v ON v.id=o.vendor_id WHERE o.customer_id=? ORDER BY o.id DESC', [$u['id']]);
page_start('My Orders'); ?>
<h1 style="margin-bottom:6px">📦 My orders</h1>
<?php if ($u['strikes'] > 0): ?><div class="flash f-warn">⚠️ You have <?= $u['strikes'] ?> uncollected-order strike(s). At <?= setting('max_strikes') ?> your ordering is blocked pending admin review.</div><?php endif; ?>
<?php if (!$orders): ?><div class="empty"><span>📭</span>No orders yet.<br><br><a class="btn" href="<?= BASE ?>/index.php">Browse the market</a></div><?php endif; ?>
<?php foreach ($orders as $o): $items = all('SELECT * FROM order_items WHERE order_id=?', [$o['id']]); ?>
<div class="order"><div class="ohead"><h3><?= e($o['emoji']) ?> <?= e($o['store_name']) ?> <span class="meta">· Order #<?= $o['id'] ?></span></h3><?= status_badge($o['status']) ?></div>
<div class="meta">📍 <?= e($o['location'] ?: 'Stall location unavailable') ?> · <?= date('M j, g:i A', strtotime($o['created_at'])) ?></div>
<?= steps_html($o['status']) ?>
<?php if ($o['status'] === 'ready'): ?><div class="flash f-ok">✅ Ready for pickup! Please collect before <b><?= date('g:i A, M j', strtotime($o['pickup_deadline'])) ?></b> and pay in cash.</div><?php endif; ?>
<?php if ($o['status'] === 'pending'): ?><div class="meta">Waiting for the vendor to respond…</div><?php endif; ?>
<?php if ($o['note'] && in_array($o['status'], ['cancelled', 'rejected'])): ?><div class="meta">ℹ️ <?= e($o['note']) ?></div><?php endif; ?>
<div class="oitems"><?php foreach ($items as $i): ?><div><span><?= e($i['name']) ?> × <?= qty($i['qty']) ?> <?= $i['unit'] ?><?= $i['final_weight'] !== null ? ' → <b>' . qty($i['final_weight']) . ' kg actual</b>' : '' ?></span><span><?= peso($i['final_price'] ?? $i['unit_price'] * $i['qty']) ?></span></div><?php endforeach; ?></div>
<div class="ohead"><div class="total"><?= peso($o['total']) ?> <small class="meta"><?= $o['status'] === 'completed' ? 'final' : 'estimated' ?></small></div>
<div class="acts">
<?php if ($o['status'] === 'pending'): ?><form method="post" data-confirm="Cancel this order?"><?= csrf() ?><input type="hidden" name="a" value="cancel"><input type="hidden" name="id" value="<?= $o['id'] ?>"><button class="btn sm red">Cancel order</button></form><?php endif; ?>
<details><summary class="btn sm ghost" style="list-style:none;cursor:pointer">🚩 Report</summary><form method="post" style="margin-top:8px"><?= csrf() ?><input type="hidden" name="a" value="report"><input type="hidden" name="id" value="<?= $o['id'] ?>"><input name="reason" placeholder="What went wrong?" required><button class="btn sm" style="margin-top:6px">Send report</button></form></details>
</div></div></div>
<?php endforeach; page_end();
