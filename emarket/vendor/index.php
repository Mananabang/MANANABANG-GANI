<?php
require __DIR__ . '/../includes/layout.php';
$u = need('vendor');
$v = one('SELECT * FROM vendors WHERE user_id=?', [$u['id']]);
if (!$v) exit('No vendor profile is linked to this account. Please contact the administrator.');
$menu = ['overview' => ['📊', 'Overview'], 'orders' => ['📦', 'Orders'], 'products' => ['🛍️', 'Products & Stock'], 'sales' => ['💰', 'Sales Records'], 'rental' => ['🏬', 'My Stall & Rental'], 'profile' => ['⚙️', 'Store Profile']];
$p = $_GET['p'] ?? 'overview'; if (!isset($menu[$p])) $p = 'overview';

// ---------------- actions ----------------
if (post_ok()) {
    $a = $_POST['a'] ?? ''; $back = '?p=' . $p . (isset($_GET['s']) ? '&s=' . urlencode($_GET['s']) : '');
    if ($a === 'product_save') {
        $d = [trim($_POST['name']), trim($_POST['emoji']) ?: '🛍️', (int)$_POST['category_id'], max(0, (float)$_POST['price']), $_POST['unit'], max(0, (float)$_POST['stock']), max(0, (float)$_POST['low_at']), trim($_POST['description'])];
        if (!$d[0] || !in_array($d[4], ['kg', 'piece', 'pack'])) flash('Please complete the product form.', 'err');
        elseif (!empty($_POST['id'])) { q('UPDATE products SET name=?,emoji=?,category_id=?,price=?,unit=?,stock=?,low_at=?,description=? WHERE id=? AND vendor_id=?', array_merge($d, [(int)$_POST['id'], $v['id']])); flash('Product updated.'); }
        else { q('INSERT INTO products(name,emoji,category_id,price,unit,stock,low_at,description,vendor_id) VALUES(?,?,?,?,?,?,?,?,?)', array_merge($d, [$v['id']])); flash('Product added.'); }
        redirect('?p=products');
    }
    if ($a === 'product_del') { q('UPDATE products SET active=0 WHERE id=? AND vendor_id=?', [(int)$_POST['id'], $v['id']]); flash('Product removed from your listing.'); redirect('?p=products'); }
    if ($a === 'stock') { q('UPDATE products SET stock=? WHERE id=? AND vendor_id=?', [max(0, (float)$_POST['stock']), (int)$_POST['id'], $v['id']]); flash('Stock updated.'); redirect('?p=products'); }
    if ($a === 'profile') { q('UPDATE vendors SET store_name=?, emoji=?, description=? WHERE id=?', [trim($_POST['store_name']), trim($_POST['emoji']) ?: '🏪', trim($_POST['description']), $v['id']]); flash('Store profile saved.'); redirect('?p=profile'); }

    $o = one('SELECT * FROM orders WHERE id=? AND vendor_id=?', [(int)($_POST['id'] ?? 0), $v['id']]);
    if ($o) {
        $st = $o['status']; $oid = $o['id']; $cu = $o['customer_id'];
        if ($a === 'accept' && $st === 'pending') {
            $items = all('SELECT * FROM order_items WHERE order_id=?', [$oid]); $ok = true;
            foreach ($items as $i) { $s = val('SELECT stock FROM products WHERE id=?', [$i['product_id']]); if ($s < $i['qty']) { flash("Not enough stock for {$i['name']} (have " . qty($s) . ').', 'err'); $ok = false; } }
            if ($ok) {
                foreach ($items as $i) q('UPDATE products SET stock=stock-? WHERE id=?', [$i['qty'], $i['product_id']]);
                q("UPDATE orders SET status='accepted' WHERE id=?", [$oid]);
                notify($cu, "Order #$oid was accepted by {$v['store_name']}. Stock is reserved for you."); flash("Order #$oid accepted — stock reserved.");
            }
        } elseif ($a === 'reject' && in_array($st, ['pending', 'accepted', 'preparing'])) {
            if ($st !== 'pending') restore_stock($oid);
            q("UPDATE orders SET status='rejected', note=? WHERE id=?", [trim($_POST['reason'] ?? '') ?: 'Rejected by vendor', $oid]);
            notify($cu, "Order #$oid was rejected by {$v['store_name']}."); flash("Order #$oid rejected.");
        } elseif ($a === 'preparing' && $st === 'accepted') {
            q("UPDATE orders SET status='preparing' WHERE id=?", [$oid]); notify($cu, "Order #$oid is being prepared."); flash('Marked as preparing.');
        } elseif ($a === 'ready' && in_array($st, ['accepted', 'preparing'])) {
            q("UPDATE orders SET status='ready', ready_at=NOW(), pickup_deadline=DATE_ADD(NOW(), INTERVAL ? HOUR) WHERE id=?", [(int)setting('pickup_hours'), $oid]);
            notify($cu, "Order #$oid is READY for pickup at {$v['store_name']}. Please collect within " . setting('pickup_hours') . ' hours.'); flash('Customer notified: ready for pickup.');
        } elseif ($a === 'complete' && $st === 'ready') {
            $tot = 0; $bad = false; $fix = [];
            foreach (all('SELECT * FROM order_items WHERE order_id=?', [$oid]) as $i) {
                if ($i['unit'] === 'kg') { $w = (float)($_POST['w'][$i['id']] ?? 0); if ($w <= 0) { $bad = true; break; } $fp = round($w * $i['unit_price'], 2); $diff = $i['qty'] - $w; }
                else { $w = null; $fp = round($i['qty'] * $i['unit_price'], 2); $diff = 0; }
                $fix[] = [$i, $w, $fp, $diff]; $tot += $fp;
            }
            if ($bad) flash('Enter the actual weight for every wet-goods item.', 'err');
            else {
                foreach ($fix as [$i, $w, $fp, $diff]) { q('UPDATE order_items SET final_weight=?, final_price=? WHERE id=?', [$w, $fp, $i['id']]); if ($diff) q('UPDATE products SET stock=GREATEST(0,stock+?) WHERE id=?', [$diff, $i['product_id']]); }
                q("UPDATE orders SET status='completed', total=?, completed_at=NOW() WHERE id=?", [$tot, $oid]);
                notify($cu, "Order #$oid completed. Final total " . peso($tot) . '. Thank you!'); flash("Order #$oid completed — " . peso($tot) . ' recorded.');
            }
        } elseif ($a === 'uncollected' && $st === 'ready' && $o['pickup_deadline'] < date('Y-m-d H:i:s')) {
            mark_uncollected($oid); flash("Order #$oid marked uncollected; stock returned and a strike recorded.");
        } elseif ($a === 'report_customer' && trim($_POST['reason'] ?? '')) {
            q("INSERT INTO reports(reporter_id,target_type,target_id,reason) VALUES(?,'user',?,?)", [$u['id'], $cu, 'Order #' . $oid . ': ' . substr(trim($_POST['reason']), 0, 200)]); flash('Customer reported to the administrator.');
        }
    }
    redirect($back);
}

// ---------------- data ----------------
$cats = all('SELECT * FROM categories ORDER BY name');
$listed = (bool)one('SELECT v.id FROM vendors v WHERE v.id=? AND ' . LISTED, [$v['id']]);
$ag = one("SELECT a.*, s.stall_no, sc.name section FROM agreements a JOIN stalls s ON s.id=a.stall_id JOIN sections sc ON sc.id=s.section_id WHERE a.vendor_id=? AND a.status='active' ORDER BY a.id DESC LIMIT 1", [$v['id']]);
$pm = one('SELECT * FROM permits WHERE vendor_id=? ORDER BY expiry_date DESC LIMIT 1', [$v['id']]);
$bills = all(BILL_SQL . ' WHERE a.vendor_id=? ORDER BY b.period DESC', [$v['id']]);
$overdue = 0; $owed = 0; foreach ($bills as $b) if (bill_status($b) === 'overdue') { $overdue++; $owed += $b['amount'] - $b['paid']; }

dash_start('Vendor', $menu, $p, 'Vendor · ' . $v['store_name']);
if (!$listed): ?><div class="flash f-warn">⚠️ Your store is currently <b>not listed</b> in the e-market. A vendor needs an active stall rental agreement and a valid business permit — please see the market office.</div><?php endif;

if ($p === 'overview'):
    $pend = (int)val("SELECT COUNT(*) FROM orders WHERE vendor_id=? AND status='pending'", [$v['id']]);
    $work = (int)val("SELECT COUNT(*) FROM orders WHERE vendor_id=? AND status IN('accepted','preparing','ready')", [$v['id']]);
    $today = (float)val("SELECT COALESCE(SUM(total),0) FROM orders WHERE vendor_id=? AND status='completed' AND DATE(completed_at)=CURDATE()", [$v['id']]);
    $low = all('SELECT * FROM products WHERE vendor_id=? AND active=1 AND stock<=low_at ORDER BY stock', [$v['id']]);
    $pl = all("SELECT o.*, u.name cname FROM orders o JOIN users u ON u.id=o.customer_id WHERE o.vendor_id=? AND o.status='pending' ORDER BY o.id", [$v['id']]); ?>
<div class="stats"><?= stat_card('🔔', 'Awaiting your response', $pend, $pend ? 'red' : 'green') ?><?= stat_card('🧑‍🍳', 'In progress', $work, 'blue') ?><?= stat_card('💵', "Today's sales", peso($today), 'gold') ?><?= stat_card('📉', 'Low / out of stock', count($low), $low ? 'red' : 'green') ?></div>
<div class="two">
<div class="card"><h3>⏳ Orders needing action</h3>
<?php if (!$pl) echo '<p class="meta" style="margin-bottom:10px">All caught up 🎉</p>'; foreach ($pl as $o): ?>
<div class="order" style="padding:12px"><b>#<?= $o['id'] ?></b> <?= e($o['cname']) ?> · <?= peso($o['total']) ?><div class="meta">Auto-cancels in <?= max(0, (int)setting('response_minutes') - floor((time() - strtotime($o['created_at'])) / 60)) ?> min</div></div><?php endforeach; ?>
<a class="btn sm" href="?p=orders">Open orders</a></div>
<div class="card"><h3>🏬 Stall & rental</h3><?php if ($ag): ?>
<p><b><?= e($ag['section']) ?> · Stall <?= e($ag['stall_no']) ?></b></p><p class="meta">Lease ends <?= date('M j, Y', strtotime($ag['end_date'])) ?></p>
<p style="margin:10px 0"><?= $overdue ? badge($overdue . ' overdue · ' . peso($owed), 'red') : badge('Rental up to date', 'green') ?> <?= $pm ? status_badge(permit_status($pm['expiry_date'])) . ' permit' : badge('No permit', 'red') ?></p><?php else: ?><p class="meta">No active stall agreement.</p><?php endif; ?>
<a class="btn sm ghost" href="?p=rental">View details</a></div>
<?php if ($low): ?><div class="card"><h3>📉 Restock soon</h3><?php foreach ($low as $l): ?><div style="display:flex;justify-content:space-between;padding:5px 0"><span><?= e($l['emoji'] . ' ' . $l['name']) ?></span><?= badge($l['stock'] <= 0 ? 'Out' : qty($l['stock']) . ' ' . $l['unit'] . ' left', $l['stock'] <= 0 ? 'red' : 'gold') ?></div><?php endforeach; ?></div><?php endif; ?></div>

<?php elseif ($p === 'products'):
    $edit = !empty($_GET['edit']) ? one('SELECT * FROM products WHERE id=? AND vendor_id=? AND active=1', [(int)$_GET['edit'], $v['id']]) : null;
    $prods = all('SELECT p.*, c.name cat FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.vendor_id=? AND p.active=1 ORDER BY p.name', [$v['id']]); ?>
<div class="card"><h3><?= $edit ? '✏️ Edit product' : '➕ Add a product' ?></h3>
<form method="post"><?= csrf() ?><input type="hidden" name="a" value="product_save"><?php if ($edit): ?><input type="hidden" name="id" value="<?= $edit['id'] ?>"><?php endif; ?>
<div class="row"><div class="fg"><label>Name</label><input name="name" required value="<?= e($edit['name'] ?? '') ?>"></div>
<div class="fg"><label>Emoji icon</label><input name="emoji" maxlength="4" value="<?= e($edit['emoji'] ?? '') ?>" placeholder="🐟"></div>
<div class="fg"><label>Category</label><select name="category_id"><?php foreach ($cats as $c): ?><option value="<?= $c['id'] ?>" <?= ($edit['category_id'] ?? $v['category_id']) == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
<div class="fg"><label>Price (₱)</label><input type="number" step="0.01" min="0" name="price" required value="<?= e($edit['price'] ?? '') ?>"></div>
<div class="fg"><label>Unit of measure</label><select name="unit"><?php foreach (['kg' => 'per kilogram (sold by weight)', 'piece' => 'per piece', 'pack' => 'per pack'] as $k => $l): ?><option value="<?= $k ?>" <?= ($edit['unit'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
<div class="fg"><label>Stock on hand</label><input type="number" step="0.01" min="0" name="stock" required value="<?= e($edit['stock'] ?? 0) ?>"></div>
<div class="fg"><label>"Low stock" at or below</label><input type="number" step="0.01" min="0" name="low_at" value="<?= e($edit['low_at'] ?? 5) ?>"></div></div>
<div class="fg"><label>Description</label><input name="description" maxlength="250" value="<?= e($edit['description'] ?? '') ?>"></div>
<button class="btn"><?= $edit ? 'Save changes' : 'Add product' ?></button> <?php if ($edit): ?><a class="btn ghost" href="?p=products">Cancel</a><?php endif; ?></form></div>
<div class="tw" style="margin-top:18px"><table><tr><th>Product</th><th>Category</th><th>Price</th><th>Status</th><th>Stock (quick update)</th><th></th></tr>
<?php foreach ($prods as $r): [$lb, $cl] = stock_label($r); ?><tr><td><?= e($r['emoji']) ?> <b><?= e($r['name']) ?></b></td><td><?= e($r['cat']) ?></td><td><?= peso($r['price']) ?>/<?= $r['unit'] ?></td><td><?= badge($lb, $cl) ?></td>
<td><form method="post" class="acts"><?= csrf() ?><input type="hidden" name="a" value="stock"><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="number" step="0.01" min="0" name="stock" value="<?= qty($r['stock']) ?>" style="width:90px"><button class="btn xs ghost">Set</button></form></td>
<td><div class="acts"><a class="btn xs ghost" href="?p=products&edit=<?= $r['id'] ?>">Edit</a><form method="post" data-confirm="Remove this product?"><?= csrf() ?><input type="hidden" name="a" value="product_del"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn xs red">Remove</button></form></div></td></tr><?php endforeach; ?></table></div>

<?php elseif ($p === 'orders'):
    $s = ($_GET['s'] ?? 'active') === 'history' ? 'history' : 'active';
    $in = $s === 'active' ? "('pending','accepted','preparing','ready')" : "('completed','rejected','cancelled','uncollected')";
    $orders = all("SELECT o.*, u.name cname, u.phone, u.status cstatus, u.strikes FROM orders o JOIN users u ON u.id=o.customer_id WHERE o.vendor_id=? AND o.status IN $in ORDER BY o.id DESC LIMIT 80", [$v['id']]); ?>
<div class="tabs"><a class="<?= $s === 'active' ? 'on' : '' ?>" href="?p=orders&s=active">Active</a><a class="<?= $s === 'history' ? 'on' : '' ?>" href="?p=orders&s=history">History</a></div>
<?php if (!$orders): ?><div class="empty"><span>📭</span>No orders here.</div><?php endif;
foreach ($orders as $o): $items = all('SELECT * FROM order_items WHERE order_id=?', [$o['id']]); $fm = '?p=orders&s=' . $s; ?>
<div class="order"><div class="ohead"><h3>Order #<?= $o['id'] ?> · <?= e($o['cname']) ?> <?= $o['cstatus'] !== 'active' ? status_badge($o['cstatus']) . ' ' . badge($o['strikes'] . ' strikes', 'red') : '' ?></h3><?= status_badge($o['status']) ?></div>
<div class="meta">📞 <?= e($o['phone'] ?: 'n/a') ?> · <?= date('M j, g:i A', strtotime($o['created_at'])) ?><?= $o['note'] ? ' · 📝 ' . e($o['note']) : '' ?></div><?= steps_html($o['status']) ?>
<?php if ($o['status'] === 'ready'): ?><p class="meta">Pickup deadline: <b><?= date('g:i A, M j', strtotime($o['pickup_deadline'])) ?></b></p>
<form method="post" action="<?= $fm ?>"><?= csrf() ?><input type="hidden" name="a" value="complete"><input type="hidden" name="id" value="<?= $o['id'] ?>">
<div class="oitems"><?php foreach ($items as $i): ?><div><span><?= e($i['name']) ?> × <?= qty($i['qty']) ?> <?= $i['unit'] ?> @ <?= peso($i['unit_price']) ?></span>
<?php if ($i['unit'] === 'kg'): ?><span>⚖️ Actual kg <input type="number" step="0.001" min="0.001" name="w[<?= $i['id'] ?>]" value="<?= qty($i['qty']) ?>" style="width:100px" required></span><?php else: ?><span><?= peso($i['qty'] * $i['unit_price']) ?></span><?php endif; ?></div><?php endforeach; ?></div>
<button class="btn sm gold">Enter weights & complete (cash received)</button></form>
<?php else: ?><div class="oitems"><?php foreach ($items as $i): ?><div><span><?= e($i['name']) ?> × <?= qty($i['qty']) ?> <?= $i['unit'] ?><?= $i['final_weight'] !== null ? ' → ' . qty($i['final_weight']) . ' kg actual' : '' ?></span><span><?= peso($i['final_price'] ?? $i['unit_price'] * $i['qty']) ?></span></div><?php endforeach; ?></div><?php endif; ?>
<div class="ohead"><div class="total"><?= peso($o['total']) ?></div><div class="acts">
<?php foreach ([['pending', 'accept', 'Accept order', ''], ['accepted', 'preparing', 'Start preparing', 'ghost'], ['accepted', 'ready', 'Mark ready', ''], ['preparing', 'ready', 'Mark ready for pickup', '']] as [$need, $act, $lbl, $cls]):
    if ($o['status'] === $need): ?><form method="post" action="<?= $fm ?>"><?= csrf() ?><input type="hidden" name="a" value="<?= $act ?>"><input type="hidden" name="id" value="<?= $o['id'] ?>"><button class="btn sm <?= $cls ?>"><?= $lbl ?></button></form><?php endif; endforeach; ?>
<?php if ($o['status'] === 'ready' && $o['pickup_deadline'] < date('Y-m-d H:i:s')): ?><form method="post" action="<?= $fm ?>" data-confirm="Mark as uncollected? A strike will be recorded."><?= csrf() ?><input type="hidden" name="a" value="uncollected"><input type="hidden" name="id" value="<?= $o['id'] ?>"><button class="btn sm red">Mark uncollected</button></form><?php endif; ?>
<?php if (in_array($o['status'], ['pending', 'accepted', 'preparing'])): ?><form method="post" action="<?= $fm ?>" class="acts"><?= csrf() ?><input type="hidden" name="a" value="reject"><input type="hidden" name="id" value="<?= $o['id'] ?>"><input name="reason" placeholder="Reason (optional)" style="width:150px"><button class="btn sm red">Reject</button></form><?php endif; ?>
<details><summary class="btn sm ghost" style="list-style:none;cursor:pointer">🚩</summary><form method="post" action="<?= $fm ?>" style="margin-top:8px"><?= csrf() ?><input type="hidden" name="a" value="report_customer"><input type="hidden" name="id" value="<?= $o['id'] ?>"><input name="reason" placeholder="Report this customer…" required><button class="btn xs" style="margin-top:6px">Send</button></form></details>
</div></div></div><?php endforeach; ?>

<?php elseif ($p === 'sales'):
    $days = []; for ($d = 6; $d >= 0; $d--) { $dt = date('Y-m-d', strtotime("-$d days")); $days[$dt] = (float)val("SELECT COALESCE(SUM(total),0) FROM orders WHERE vendor_id=? AND status='completed' AND DATE(completed_at)=?", [$v['id'], $dt]); }
    $max = max($days) ?: 1; $week = array_sum($days);
    $allS = (float)val("SELECT COALESCE(SUM(total),0) FROM orders WHERE vendor_id=? AND status='completed'", [$v['id']]);
    $cnt = (int)val("SELECT COUNT(*) FROM orders WHERE vendor_id=? AND status='completed'", [$v['id']]);
    $unc = (int)val("SELECT COUNT(*) FROM orders WHERE vendor_id=? AND status='uncollected'", [$v['id']]);
    $top = all("SELECT i.name, SUM(COALESCE(i.final_weight,i.qty)) q, i.unit, SUM(COALESCE(i.final_price,i.unit_price*i.qty)) rev FROM order_items i JOIN orders o ON o.id=i.order_id WHERE o.vendor_id=? AND o.status='completed' GROUP BY i.name,i.unit ORDER BY rev DESC LIMIT 6", [$v['id']]);
    $hist = all("SELECT o.*, u.name cname FROM orders o JOIN users u ON u.id=o.customer_id WHERE o.vendor_id=? AND o.status='completed' ORDER BY o.completed_at DESC LIMIT 30", [$v['id']]); ?>
<div class="stats"><?= stat_card('📅', 'Last 7 days', peso($week), 'gold') ?><?= stat_card('💰', 'All-time sales', peso($allS), 'green') ?><?= stat_card('✅', 'Completed orders', $cnt, 'blue') ?><?= stat_card('🚫', 'Uncollected orders', $unc, $unc ? 'red' : 'green') ?></div>
<div class="two"><div class="card"><h3>Sales — last 7 days</h3><div class="bars"><?php foreach ($days as $d => $amt): ?><div class="bar"><b><?= $amt ? '₱' . number_format($amt) : '' ?></b><div style="height:<?= max(2, round($amt / $max * 100)) ?>%"></div><?= date('D', strtotime($d)) ?></div><?php endforeach; ?></div></div>
<div class="card"><h3>🏆 Top products</h3><?php if (!$top) echo '<p class="meta">No completed sales yet.</p>'; foreach ($top as $t): ?><div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px dashed var(--line)"><span><?= e($t['name']) ?> <small class="meta"><?= qty($t['q']) ?> <?= $t['unit'] ?></small></span><b><?= peso($t['rev']) ?></b></div><?php endforeach; ?></div></div>
<h3 style="margin:22px 0 10px">Completed transactions</h3><div class="tw"><table><tr><th>Order</th><th>Customer</th><th>Completed</th><th class="num">Amount</th></tr>
<?php foreach ($hist as $h): ?><tr><td>#<?= $h['id'] ?></td><td><?= e($h['cname']) ?></td><td><?= date('M j, g:i A', strtotime($h['completed_at'])) ?></td><td class="num"><?= peso($h['total']) ?></td></tr><?php endforeach; ?></table></div>

<?php elseif ($p === 'rental'): ?>
<div class="two"><div class="card"><h3>🏬 Assigned stall & lease</h3><?php if ($ag): $left = (int)((strtotime($ag['end_date']) - time()) / 86400); ?>
<p style="font-size:1.3rem;font-family:var(--head)"><b><?= e($ag['section']) ?> · Stall <?= e($ag['stall_no']) ?></b></p>
<p class="meta">Monthly rate <b><?= peso($ag['monthly_rate']) ?></b> · <?= date('M j, Y', strtotime($ag['start_date'])) ?> – <?= date('M j, Y', strtotime($ag['end_date'])) ?></p>
<p style="margin-top:8px"><?= status_badge($ag['status']) ?> <?= badge($left . ' days left', $left < 60 ? 'gold' : 'green') ?></p><?php else: ?><p class="meta">No active rental agreement recorded. Please see the market office.</p><?php endif; ?></div>
<div class="card"><h3>📄 Business permit</h3><?php if ($pm): ?><p><b><?= e($pm['permit_no']) ?></b></p><p class="meta">Issued <?= date('M j, Y', strtotime($pm['issue_date'])) ?> · Expires <?= date('M j, Y', strtotime($pm['expiry_date'])) ?></p><p style="margin-top:8px"><?= status_badge(permit_status($pm['expiry_date'])) ?></p><?php else: ?><p class="meta">No permit details recorded.</p><?php endif; ?></div></div>
<h3 style="margin:22px 0 6px">Monthly dues & payment history</h3><p class="meta" style="margin-bottom:10px">Pay at the market office / City Treasurer's Office. This page only shows your records.</p>
<div class="tw"><table><tr><th>Period</th><th>Due date</th><th class="num">Amount</th><th>Status</th><th>OR number</th><th>Paid on</th></tr>
<?php foreach ($bills as $b): ?><tr><td><b><?= date('F Y', strtotime($b['period'] . '-01')) ?></b></td><td><?= date('M j, Y', strtotime($b['due_date'])) ?></td><td class="num"><?= peso($b['amount']) ?></td><td><?= status_badge(bill_status($b)) ?></td><td><?= e($b['ors'] ?: '—') ?></td><td><?= $b['paid_on'] ? date('M j, Y', strtotime($b['paid_on'])) : '—' ?></td></tr><?php endforeach; if (!$bills) echo '<tr><td colspan="6" class="meta">No billings yet.</td></tr>'; ?></table></div>

<?php else: ?>
<div class="card" style="max-width:640px"><h3>Store profile</h3><form method="post"><?= csrf() ?><input type="hidden" name="a" value="profile">
<div class="row"><div class="fg"><label>Store name</label><input name="store_name" required value="<?= e($v['store_name']) ?>"></div><div class="fg"><label>Store emoji</label><input name="emoji" maxlength="4" value="<?= e($v['emoji']) ?>"></div></div>
<div class="fg"><label>Description</label><textarea name="description" rows="3"><?= e($v['description']) ?></textarea></div><button class="btn">Save profile</button></form></div>
<?php endif; dash_end();
