<?php
require __DIR__ . '/../includes/layout.php';
$u = need('admin');
$menu = ['overview' => ['📊', 'Overview'], 'users' => ['👥', 'Accounts'], 'listings' => ['🛍️', 'Product Listings'], 'orders' => ['📦', 'Orders & Transactions'], 'reports' => ['🚩', 'Reports & Flags'], 'summary' => ['📈', 'System Reports'], 'settings' => ['⚙️', 'Categories & Rules'], '/staff/' => ['🏬', 'Stall Rentals']];
$p = $_GET['p'] ?? 'overview'; if (!isset($menu[$p]) || $p[0] === '/') $p = 'overview';

if (post_ok()) {
    $a = $_POST['a'] ?? '';
    if (in_array($a, ['create_vendor', 'create_staff'])) {
        $email = strtolower(trim($_POST['email'])); $name = trim($_POST['name']);
        if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($_POST['password']) < 6) flash('Enter a name, valid email and a password of 6+ characters.', 'err');
        elseif (one('SELECT id FROM users WHERE email=?', [$email])) flash('That email is already registered.', 'err');
        else {
            $role = $a === 'create_vendor' ? 'vendor' : 'staff';
            q('INSERT INTO users(name,email,phone,password,role) VALUES(?,?,?,?,?)', [$name, $email, trim($_POST['phone'] ?? ''), password_hash($_POST['password'], PASSWORD_DEFAULT), $role]);
            if ($role === 'vendor') q('INSERT INTO vendors(user_id,store_name,category_id,emoji,description) VALUES(?,?,?,?,?)', [db()->lastInsertId(), trim($_POST['store_name']) ?: $name . "'s Stall", (int)$_POST['category_id'], trim($_POST['emoji']) ?: '🏪', trim($_POST['description'] ?? '')]);
            flash(ucfirst($role) . ' account created.' . ($role === 'vendor' ? ' Next: record the stall agreement and business permit under Stall Rentals so the vendor can be listed.' : ''));
        }
    } elseif ($a === 'user_status') {
        $t = one("SELECT * FROM users WHERE id=? AND role<>'admin'", [(int)$_POST['id']]);
        if ($t && in_array($_POST['status'], ['active', 'flagged', 'blocked'])) {
            q('UPDATE users SET status=?, strikes=IF(?="active",0,strikes) WHERE id=?', [$_POST['status'], $_POST['status'], $t['id']]);
            notify($t['id'], $_POST['status'] === 'blocked' ? 'Your account was blocked from ordering by the administrator.' : 'Your account status was updated to ' . $_POST['status'] . '.'); flash('Account updated.');
        }
    } elseif ($a === 'vendor_toggle') { q('UPDATE vendors SET active=1-active WHERE id=?', [(int)$_POST['id']]); flash('Vendor listing toggled.'); }
    elseif ($a === 'product_hide') { q('UPDATE products SET active=0 WHERE id=?', [(int)$_POST['id']]); flash('Listing removed.'); }
    elseif ($a === 'report_resolve') { q("UPDATE reports SET status='resolved' WHERE id=?", [(int)$_POST['id']]); flash('Report marked resolved.'); }
    elseif ($a === 'category_add' && trim($_POST['name'])) { q('INSERT INTO categories(name) VALUES(?)', [trim($_POST['name'])]); flash('Category added.'); }
    elseif ($a === 'settings') {
        foreach (['response_minutes', 'pickup_hours', 'max_strikes'] as $k) q('UPDATE settings SET v=? WHERE k=?', [max(1, (int)$_POST[$k]), $k]);
        flash('Order safeguards updated.');
    }
    redirect('?p=' . $p . (isset($_GET['r']) ? '&r=' . urlencode($_GET['r']) : '') . (isset($_GET['s']) ? '&s=' . urlencode($_GET['s']) : ''));
}

dash_start('Admin', $menu, $p, 'Administrator');

if ($p === 'overview'):
    $cn = (int)val("SELECT COUNT(*) FROM users WHERE role='customer'");
    $vt = (int)val('SELECT COUNT(*) FROM vendors'); $vl = (int)val('SELECT COUNT(*) FROM vendors v WHERE ' . LISTED);
    $ot = (int)val('SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE()');
    $rev = (float)val("SELECT COALESCE(SUM(total),0) FROM orders WHERE status='completed'");
    $rep = (int)val("SELECT COUNT(*) FROM reports WHERE status='open'"); $blk = (int)val("SELECT COUNT(*) FROM users WHERE status IN('flagged','blocked')");
    $od = 0; foreach (all(BILL_SQL) as $b) if (bill_status($b) === 'overdue') $od++; ?>
<div class="stats"><?= stat_card('🧑‍🤝‍🧑', 'Customers', $cn, 'blue') ?><?= stat_card('🏪', 'Vendors listed / total', "$vl / $vt", 'green') ?><?= stat_card('📦', 'Orders today', $ot, 'gold') ?><?= stat_card('💰', 'Completed sales (all)', peso($rev), 'green') ?><?= stat_card('🚩', 'Open reports', $rep, $rep ? 'red' : 'green') ?><?= stat_card('⛔', 'Flagged / blocked buyers', $blk, $blk ? 'gold' : 'green') ?><?= stat_card('⏰', 'Overdue rentals', $od, $od ? 'red' : 'green') ?></div>
<h3 style="margin-bottom:10px">Latest orders</h3><div class="tw"><table><tr><th>#</th><th>Customer</th><th>Vendor</th><th class="num">Total</th><th>Status</th><th>Placed</th></tr>
<?php foreach (all('SELECT o.*, u.name cname, v.store_name FROM orders o JOIN users u ON u.id=o.customer_id JOIN vendors v ON v.id=o.vendor_id ORDER BY o.id DESC LIMIT 8') as $o): ?><tr><td><?= $o['id'] ?></td><td><?= e($o['cname']) ?></td><td><?= e($o['store_name']) ?></td><td class="num"><?= peso($o['total']) ?></td><td><?= status_badge($o['status']) ?></td><td><?= date('M j, g:i A', strtotime($o['created_at'])) ?></td></tr><?php endforeach; ?></table></div>

<?php elseif ($p === 'users'):
    $cats = all('SELECT * FROM categories ORDER BY name'); $r = $_GET['r'] ?? 'all';
    $users = all('SELECT u.*, v.id vid, v.store_name, v.active vactive, (SELECT COUNT(*) FROM vendors v2 WHERE v2.id=v.id AND ' . str_replace('v.', 'v2.', LISTED) . ') listed FROM users u LEFT JOIN vendors v ON v.user_id=u.id' . ($r !== 'all' ? ' WHERE u.role=?' : '') . ' ORDER BY u.role, u.name', $r !== 'all' ? [$r] : []); ?>
<div class="two"><div class="card"><h3>🏪 Create vendor account</h3><p class="meta" style="margin-bottom:10px">Vendors cannot self-register. Then record their stall agreement & business permit to list them.</p>
<form method="post"><?= csrf() ?><input type="hidden" name="a" value="create_vendor"><div class="row"><div class="fg"><label>Owner name</label><input name="name" required></div><div class="fg"><label>Email</label><input type="email" name="email" required></div>
<div class="fg"><label>Phone</label><input name="phone"></div><div class="fg"><label>Temp. password</label><input name="password" minlength="6" required></div><div class="fg"><label>Store name</label><input name="store_name" required></div>
<div class="fg"><label>Category</label><select name="category_id"><?php foreach ($cats as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div><div class="fg"><label>Emoji</label><input name="emoji" maxlength="4" placeholder="🥩"></div></div><button class="btn">Create vendor</button></form></div>
<div class="card"><h3>🏛️ Create Market Staff / LGU account</h3><p class="meta" style="margin-bottom:10px">Limited to stall rental functions.</p>
<form method="post"><?= csrf() ?><input type="hidden" name="a" value="create_staff"><div class="row"><div class="fg"><label>Full name</label><input name="name" required></div><div class="fg"><label>Email</label><input type="email" name="email" required></div><div class="fg"><label>Temp. password</label><input name="password" minlength="6" required></div></div><button class="btn">Create staff</button></form></div></div>
<div class="tabs" style="margin-top:20px"><?php foreach (['all' => 'All', 'customer' => 'Customers', 'vendor' => 'Vendors', 'staff' => 'Staff / LGU', 'admin' => 'Admins'] as $k => $l): ?><a class="<?= $r === $k ? 'on' : '' ?>" href="?p=users&r=<?= $k ?>"><?= $l ?></a><?php endforeach; ?></div>
<div class="tw"><table><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Strikes</th><th>Details</th><th>Actions</th></tr>
<?php foreach ($users as $x): ?><tr><td><b><?= e($x['name']) ?></b></td><td><?= e($x['email']) ?></td><td><?= badge(ucfirst($x['role']), 'blue') ?></td><td><?= status_badge($x['status']) ?></td><td><?= $x['strikes'] ?></td>
<td><?php if ($x['vid']): ?><?= e($x['store_name']) ?> <?= $x['listed'] ? badge('Listed', 'green') : badge('Not eligible', 'red') ?><?php endif; ?></td>
<td><div class="acts"><?php if ($x['role'] === 'customer'): foreach (($x['status'] === 'active' ? ['blocked' => 'Block'] : ['active' => 'Clear & unblock'] + ($x['status'] === 'flagged' ? ['blocked' => 'Block'] : [])) as $st => $lb): ?>
<form method="post"><?= csrf() ?><input type="hidden" name="a" value="user_status"><input type="hidden" name="id" value="<?= $x['id'] ?>"><input type="hidden" name="status" value="<?= $st ?>"><button class="btn xs <?= $st === 'blocked' ? 'red' : 'ghost' ?>"><?= $lb ?></button></form><?php endforeach; endif; ?>
<?php if ($x['vid']): ?><form method="post"><?= csrf() ?><input type="hidden" name="a" value="vendor_toggle"><input type="hidden" name="id" value="<?= $x['vid'] ?>"><button class="btn xs ghost"><?= $x['vactive'] ? 'Deactivate' : 'Activate' ?></button></form><?php endif; ?></div></td></tr><?php endforeach; ?></table></div>

<?php elseif ($p === 'listings'):
    $rows = all('SELECT p.*, v.store_name, c.name cat FROM products p JOIN vendors v ON v.id=p.vendor_id LEFT JOIN categories c ON c.id=p.category_id WHERE p.active=1 ORDER BY v.store_name, p.name'); ?>
<div class="tw"><table><tr><th>Product</th><th>Vendor</th><th>Category</th><th>Price</th><th>Stock</th><th></th></tr>
<?php foreach ($rows as $r): [$lb, $cl] = stock_label($r); ?><tr><td><?= e($r['emoji']) ?> <b><?= e($r['name']) ?></b></td><td><?= e($r['store_name']) ?></td><td><?= e($r['cat']) ?></td><td><?= peso($r['price']) ?>/<?= $r['unit'] ?></td><td><?= badge(qty($r['stock']) . ' · ' . $lb, $cl) ?></td>
<td><form method="post" data-confirm="Remove this listing from the market?"><?= csrf() ?><input type="hidden" name="a" value="product_hide"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn xs red">Remove</button></form></td></tr><?php endforeach; ?></table></div>

<?php elseif ($p === 'orders'):
    $s = $_GET['s'] ?? 'all'; $sts = ['pending', 'accepted', 'preparing', 'ready', 'completed', 'rejected', 'cancelled', 'uncollected'];
    $rows = all('SELECT o.*, u.name cname, v.store_name FROM orders o JOIN users u ON u.id=o.customer_id JOIN vendors v ON v.id=o.vendor_id' . (in_array($s, $sts) ? ' WHERE o.status=?' : '') . ' ORDER BY o.id DESC LIMIT 150', in_array($s, $sts) ? [$s] : []); ?>
<div class="tabs"><a class="<?= $s === 'all' ? 'on' : '' ?>" href="?p=orders">All</a><?php foreach ($sts as $x): ?><a class="<?= $s === $x ? 'on' : '' ?>" href="?p=orders&s=<?= $x ?>"><?= ucfirst($x) ?></a><?php endforeach; ?></div>
<div class="tw"><table><tr><th>#</th><th>Customer</th><th>Vendor</th><th class="num">Total</th><th>Status</th><th>Placed</th><th>Note</th></tr>
<?php foreach ($rows as $o): ?><tr><td><?= $o['id'] ?></td><td><?= e($o['cname']) ?></td><td><?= e($o['store_name']) ?></td><td class="num"><?= peso($o['total']) ?></td><td><?= status_badge($o['status']) ?></td><td><?= date('M j, g:i A', strtotime($o['created_at'])) ?></td><td class="meta"><?= e($o['note']) ?></td></tr><?php endforeach; ?></table></div>

<?php elseif ($p === 'reports'):
    $rows = all("SELECT r.*, u.name rname FROM reports r JOIN users u ON u.id=r.reporter_id ORDER BY (r.status='open') DESC, r.id DESC LIMIT 100");
    $flag = all("SELECT * FROM users WHERE status IN('flagged','blocked') ORDER BY strikes DESC"); ?>
<div class="two"><div class="card"><h3>⛔ Flagged & blocked customers</h3><?php if (!$flag) echo '<p class="meta">None 🎉</p>'; foreach ($flag as $f): ?><div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px dashed var(--line)"><span><b><?= e($f['name']) ?></b> <small class="meta"><?= $f['strikes'] ?> strikes</small></span>
<span class="acts"><?= status_badge($f['status']) ?><form method="post"><?= csrf() ?><input type="hidden" name="a" value="user_status"><input type="hidden" name="id" value="<?= $f['id'] ?>"><input type="hidden" name="status" value="active"><button class="btn xs ghost">Clear</button></form><?php if ($f['status'] !== 'blocked'): ?><form method="post"><?= csrf() ?><input type="hidden" name="a" value="user_status"><input type="hidden" name="id" value="<?= $f['id'] ?>"><input type="hidden" name="status" value="blocked"><button class="btn xs red">Block</button></form><?php endif; ?></span></div><?php endforeach; ?></div></div>
<h3 style="margin:22px 0 10px">User reports</h3><div class="tw"><table><tr><th>When</th><th>Reporter</th><th>Target</th><th>Reason</th><th>Status</th><th></th></tr>
<?php foreach ($rows as $r): $tn = $r['target_type'] === 'user' ? val('SELECT name FROM users WHERE id=?', [$r['target_id']]) : val('SELECT store_name FROM vendors WHERE id=?', [$r['target_id']]); ?><tr><td><?= date('M j', strtotime($r['created_at'])) ?></td><td><?= e($r['rname']) ?></td><td><?= badge($r['target_type'], 'gray') ?> <?= e($tn) ?></td><td><?= e($r['reason']) ?></td><td><?= status_badge($r['status']) ?></td>
<td><?php if ($r['status'] === 'open'): ?><form method="post"><?= csrf() ?><input type="hidden" name="a" value="report_resolve"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn xs">Resolve</button></form><?php endif; ?></td></tr><?php endforeach; if (!$rows) echo '<tr><td colspan="6" class="meta">No reports.</td></tr>'; ?></table></div>

<?php elseif ($p === 'summary'):
    $byStatus = all('SELECT status, COUNT(*) n, COALESCE(SUM(total),0) amt FROM orders GROUP BY status');
    $byVendor = all("SELECT v.store_name, COUNT(o.id) n, COALESCE(SUM(o.total),0) amt FROM vendors v LEFT JOIN orders o ON o.vendor_id=v.id AND o.status='completed' GROUP BY v.id ORDER BY amt DESC");
    $prices = all('SELECT p.name, p.unit, COUNT(DISTINCT p.vendor_id) vn, MIN(p.price) lo, AVG(p.price) av, MAX(p.price) hi FROM products p JOIN vendors v ON v.id=p.vendor_id WHERE p.active=1 AND ' . LISTED . ' GROUP BY p.name, p.unit ORDER BY p.name');
    $rent = ['billed' => (float)val('SELECT COALESCE(SUM(amount),0) FROM billings'), 'paid' => (float)val('SELECT COALESCE(SUM(amount),0) FROM payments')]; ?>
<button class="btn sm noprint" onclick="window.print()" style="margin-bottom:16px">🖨️ Print</button>
<div class="two"><div class="card"><h3>Orders by status</h3><div class="tw"><table><tr><th>Status</th><th class="num">Orders</th><th class="num">Value</th></tr><?php foreach ($byStatus as $r): ?><tr><td><?= status_badge($r['status']) ?></td><td class="num"><?= $r['n'] ?></td><td class="num"><?= peso($r['amt']) ?></td></tr><?php endforeach; ?></table></div></div>
<div class="card"><h3>Rental summary</h3><p>Total billed: <b><?= peso($rent['billed']) ?></b></p><p>Total collected: <b><?= peso($rent['paid']) ?></b></p><p>Outstanding: <b style="color:var(--tom)"><?= peso($rent['billed'] - $rent['paid']) ?></b></p><p class="meta" style="margin-top:8px">Details under Stall Rentals → Rental Reports.</p></div></div>
<h3 style="margin:22px 0 10px">Completed sales per vendor <small class="meta">(admin-only · sensitive)</small></h3><div class="tw"><table><tr><th>Vendor</th><th class="num">Completed orders</th><th class="num">Sales</th></tr><?php foreach ($byVendor as $r): ?><tr><td><?= e($r['store_name']) ?></td><td class="num"><?= $r['n'] ?></td><td class="num"><?= peso($r['amt']) ?></td></tr><?php endforeach; ?></table></div>
<h3 style="margin:22px 0 10px">Market price summary <small class="meta">(aggregated listed prices)</small></h3><div class="tw"><table><tr><th>Product</th><th>Unit</th><th class="num">Vendors</th><th class="num">Lowest</th><th class="num">Average</th><th class="num">Highest</th></tr><?php foreach ($prices as $r): ?><tr><td><?= e($r['name']) ?></td><td><?= $r['unit'] ?></td><td class="num"><?= $r['vn'] ?></td><td class="num"><?= peso($r['lo']) ?></td><td class="num"><?= peso($r['av']) ?></td><td class="num"><?= peso($r['hi']) ?></td></tr><?php endforeach; ?></table></div>

<?php else:
    $cats = all('SELECT c.*, (SELECT COUNT(*) FROM products WHERE category_id=c.id AND active=1) n FROM categories c ORDER BY name'); ?>
<div class="two"><div class="card"><h3>🛡️ Order safeguards</h3><form method="post"><?= csrf() ?><input type="hidden" name="a" value="settings">
<div class="fg"><label>Vendor response time (minutes) — unanswered orders auto-cancel</label><input type="number" min="1" name="response_minutes" value="<?= e(setting('response_minutes')) ?>"></div>
<div class="fg"><label>Pickup window (hours after "ready")</label><input type="number" min="1" name="pickup_hours" value="<?= e(setting('pickup_hours')) ?>"></div>
<div class="fg"><label>Allowed uncollected-order strikes before blocking</label><input type="number" min="1" name="max_strikes" value="<?= e(setting('max_strikes')) ?>"></div>
<button class="btn">Save rules</button><p class="meta" style="margin-top:8px">Set these together with the participating vendors.</p></form></div>
<div class="card"><h3>🏷️ Product categories</h3><form method="post" class="acts" style="margin-bottom:12px"><?= csrf() ?><input type="hidden" name="a" value="category_add"><input name="name" placeholder="New category" required style="flex:1"><button class="btn sm">Add</button></form>
<?php foreach ($cats as $c): ?><div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px dashed var(--line)"><span><?= e($c['name']) ?></span><?= badge($c['n'] . ' products', 'gray') ?></div><?php endforeach; ?></div></div>
<?php endif; dash_end();
