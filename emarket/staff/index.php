<?php
require __DIR__ . '/../includes/layout.php';
$u = need(['staff', 'admin']);
$menu = ['overview' => ['📊', 'Overview'], 'stalls' => ['🧱', 'Stalls & Reservations'], 'agreements' => ['📜', 'Rental Agreements'], 'permits' => ['🪪', 'Business Permits'], 'billing' => ['🧾', 'Billing & Payments'], 'reports' => ['📈', 'Rental Reports']];
if ($u['role'] === 'admin') $menu = ['/admin/' => ['⬅️', 'Back to Admin']] + $menu;
$p = $_GET['p'] ?? 'overview'; if (!isset($menu[$p])) $p = 'overview';

if (post_ok()) {
    $a = $_POST['a'] ?? '';
    if ($a === 'section_add' && trim($_POST['name'])) { q('INSERT INTO sections(name) VALUES(?)', [trim($_POST['name'])]); flash('Market section added.'); }
    elseif ($a === 'stall_add') {
        if (one('SELECT id FROM stalls WHERE section_id=? AND stall_no=?', [(int)$_POST['section_id'], trim($_POST['stall_no'])])) flash('That stall number already exists in the section.', 'err');
        else { q('INSERT INTO stalls(section_id,stall_no,rate) VALUES(?,?,?)', [(int)$_POST['section_id'], trim($_POST['stall_no']), max(0, (float)$_POST['rate'])]); flash('Stall registered as vacant.'); }
    } elseif ($a === 'reserve') {
        $s = one("SELECT * FROM stalls WHERE id=? AND status='vacant'", [(int)$_POST['stall_id']]);
        if ($s && trim($_POST['applicant'])) {
            q('INSERT INTO applications(stall_id,applicant,contact,vendor_id) VALUES(?,?,?,?)', [$s['id'], trim($_POST['applicant']), trim($_POST['contact']), ((int)$_POST['vendor_id']) ?: null]);
            q("UPDATE stalls SET status='reserved' WHERE id=?", [$s['id']]); flash('Application recorded — stall marked reserved.');
        } else flash('Choose a vacant stall and enter the applicant name.', 'err');
    } elseif ($a === 'app_cancel') {
        $ap = one("SELECT * FROM applications WHERE id=? AND status='pending'", [(int)$_POST['id']]);
        if ($ap) { q("UPDATE applications SET status='cancelled' WHERE id=?", [$ap['id']]);
            if (!val("SELECT COUNT(*) FROM applications WHERE stall_id=? AND status='pending'", [$ap['stall_id']]) && !val("SELECT COUNT(*) FROM agreements WHERE stall_id=? AND status='active'", [$ap['stall_id']])) q("UPDATE stalls SET status='vacant' WHERE id=?", [$ap['stall_id']]);
            flash('Reservation cancelled.'); }
    } elseif ($a === 'agreement_add') {
        $vid = (int)$_POST['vendor_id']; $sid = (int)$_POST['stall_id'];
        $vu = one('SELECT user_id FROM vendors WHERE id=?', [$vid]);
        if (!$vu || !one('SELECT id FROM stalls WHERE id=?', [$sid])) flash('Select a vendor and a stall.', 'err');
        elseif ($_POST['end_date'] <= $_POST['start_date']) flash('The end date must be after the start date.', 'err');
        else {
            q("UPDATE agreements SET status='ended' WHERE stall_id=? AND status='active'", [$sid]); // renewal / transfer keeps the old one as history
            q('INSERT INTO agreements(vendor_id,stall_id,start_date,end_date,monthly_rate,note) VALUES(?,?,?,?,?,?)', [$vid, $sid, $_POST['start_date'], $_POST['end_date'], max(0, (float)$_POST['monthly_rate']), trim($_POST['note'])]);
            q("UPDATE stalls SET status='occupied' WHERE id=?", [$sid]);
            q("UPDATE applications SET status='awarded', vendor_id=? WHERE stall_id=? AND status='pending'", [$vid, $sid]);
            notify($vu['user_id'], 'A stall rental agreement was recorded for you. You can view it under My Stall & Rental.'); flash('Rental agreement recorded; stall marked occupied.');
        }
    } elseif ($a === 'agreement_end') {
        $ag = one("SELECT * FROM agreements WHERE id=? AND status='active'", [(int)$_POST['id']]);
        if ($ag) { q("UPDATE agreements SET status='ended' WHERE id=?", [$ag['id']]); q("UPDATE stalls SET status='vacant' WHERE id=?", [$ag['stall_id']]); flash('Agreement ended — vendor is no longer listed and the stall is vacant.'); }
    } elseif ($a === 'permit_save') {
        if (one('SELECT id FROM vendors WHERE id=?', [(int)$_POST['vendor_id']]) && trim($_POST['permit_no']) && $_POST['expiry_date'] > $_POST['issue_date']) {
            q('INSERT INTO permits(vendor_id,permit_no,issue_date,expiry_date) VALUES(?,?,?,?)', [(int)$_POST['vendor_id'], trim($_POST['permit_no']), $_POST['issue_date'], $_POST['expiry_date']]); flash('Business permit recorded.');
        } else flash('Complete the permit form (expiry must be after issue date).', 'err');
    } elseif ($a === 'bill_generate') {
        $per = preg_match('/^\d{4}-\d{2}$/', $_POST['period'] ?? '') ? $_POST['period'] : date('Y-m'); $n = 0;
        foreach (all("SELECT * FROM agreements WHERE status='active' AND start_date<=LAST_DAY(?) AND end_date>=?", [$per . '-01', $per . '-01']) as $ag)
            $n += q('INSERT IGNORE INTO billings(agreement_id,period,amount,due_date) VALUES(?,?,?,?)', [$ag['id'], $per, $ag['monthly_rate'], $per . '-' . sprintf('%02d', BILL_DUE_DAY)])->rowCount();
        flash("$n billing(s) generated for " . date('F Y', strtotime($per . '-01')) . '.');
    } elseif ($a === 'pay') {
        $b = one(BILL_SQL . ' WHERE b.id=?', [(int)$_POST['id']]); $amt = (float)$_POST['amount'];
        if (!$b || $amt <= 0 || !trim($_POST['or_number'])) flash('Enter the amount and the official receipt number.', 'err');
        elseif (one('SELECT id FROM payments WHERE or_number=?', [trim($_POST['or_number'])])) flash('That OR number has already been recorded.', 'err');
        else {
            q('INSERT INTO payments(billing_id,amount,or_number,paid_on,recorded_by) VALUES(?,?,?,?,?)', [$b['id'], $amt, trim($_POST['or_number']), $_POST['paid_on'] ?: date('Y-m-d'), $u['id']]);
            $vu = val('SELECT user_id FROM vendors WHERE id=?', [$b['vendor_id']]); notify($vu, 'Rental payment of ' . peso($amt) . ' for ' . date('F Y', strtotime($b['period'] . '-01')) . ' was recorded (OR ' . trim($_POST['or_number']) . ').');
            flash('Payment recorded.');
        }
    }
    redirect('?p=' . $p . (isset($_GET['f']) ? '&f=' . urlencode($_GET['f']) : ''));
}

$vendors = all('SELECT v.id, v.store_name, u.name FROM vendors v JOIN users u ON u.id=v.user_id ORDER BY v.store_name');
$sections = all('SELECT * FROM sections ORDER BY name');
dash_start('Market Staff', $menu, $p, $u['role'] === 'admin' ? 'Administrator · Rentals' : 'Market Staff / LGU');

if ($p === 'overview'):
    $sc = []; foreach (all('SELECT status, COUNT(*) n FROM stalls GROUP BY status') as $r) $sc[$r['status']] = $r['n'];
    $od = 0; $odAmt = 0; foreach (all(BILL_SQL) as $b) if (bill_status($b) === 'overdue') { $od++; $odAmt += $b['amount'] - $b['paid']; }
    $mon = (float)val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE DATE_FORMAT(paid_on,'%Y-%m')=?", [date('Y-m')]);
    $perm = all('SELECT v.store_name, pm.* FROM permits pm JOIN vendors v ON v.id=pm.vendor_id WHERE pm.id=(SELECT id FROM permits WHERE vendor_id=v.id ORDER BY expiry_date DESC LIMIT 1) AND pm.expiry_date<=DATE_ADD(CURDATE(), INTERVAL 30 DAY) ORDER BY pm.expiry_date'); ?>
<div class="stats"><?= stat_card('🟢', 'Vacant stalls', $sc['vacant'] ?? 0, 'green') ?><?= stat_card('🟡', 'Reserved', $sc['reserved'] ?? 0, 'gold') ?><?= stat_card('🔵', 'Occupied', $sc['occupied'] ?? 0, 'blue') ?><?= stat_card('⏰', 'Overdue dues', $od . ' · ' . peso($odAmt), $od ? 'red' : 'green') ?><?= stat_card('💵', 'Collected this month', peso($mon), 'gold') ?></div>
<div class="two"><div class="card"><h3>🪪 Permits due or expired</h3><?php if (!$perm) echo '<p class="meta">All permits are valid for 30+ days.</p>'; foreach ($perm as $r): ?><div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px dashed var(--line)"><span><b><?= e($r['store_name']) ?></b> <small class="meta"><?= e($r['permit_no']) ?> · <?= date('M j, Y', strtotime($r['expiry_date'])) ?></small></span><?= status_badge(permit_status($r['expiry_date'])) ?></div><?php endforeach; ?></div>
<div class="card"><h3>🧾 Recent payments</h3><?php foreach (all('SELECT p.*, b.period, v.store_name FROM payments p JOIN billings b ON b.id=p.billing_id JOIN agreements a ON a.id=b.agreement_id JOIN vendors v ON v.id=a.vendor_id ORDER BY p.id DESC LIMIT 6') as $r): ?><div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px dashed var(--line)"><span><?= e($r['store_name']) ?> <small class="meta"><?= e($r['or_number']) ?></small></span><b><?= peso($r['amount']) ?></b></div><?php endforeach; ?></div></div>

<?php elseif ($p === 'stalls'):
    $stalls = all("SELECT s.*, sc.name section, (SELECT v.store_name FROM agreements a JOIN vendors v ON v.id=a.vendor_id WHERE a.stall_id=s.id AND a.status='active' LIMIT 1) vendor FROM stalls s JOIN sections sc ON sc.id=s.section_id ORDER BY sc.name, s.stall_no");
    $apps = all("SELECT ap.*, s.stall_no, sc.name section FROM applications ap JOIN stalls s ON s.id=ap.stall_id JOIN sections sc ON sc.id=s.section_id WHERE ap.status='pending' ORDER BY ap.id DESC");
    $vacant = array_filter($stalls, fn($s) => $s['status'] === 'vacant'); ?>
<div class="two">
<div class="card"><h3>📝 Record stall application / reservation</h3><form method="post"><?= csrf() ?><input type="hidden" name="a" value="reserve">
<div class="fg"><label>Vacant stall</label><select name="stall_id" required><?php foreach ($vacant as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['section'] . ' · ' . $s['stall_no'] . ' (' . peso($s['rate']) . '/mo)') ?></option><?php endforeach; ?></select></div>
<div class="row"><div class="fg"><label>Applicant name</label><input name="applicant" required></div><div class="fg"><label>Contact</label><input name="contact"></div></div>
<div class="fg"><label>Existing vendor account (optional)</label><select name="vendor_id"><option value="0">— none —</option><?php foreach ($vendors as $v): ?><option value="<?= $v['id'] ?>"><?= e($v['store_name']) ?></option><?php endforeach; ?></select></div>
<button class="btn" <?= $vacant ? '' : 'disabled' ?>>Reserve stall</button> <span class="meta">Award stays a decision of the market office.</span></form></div>
<div class="card"><h3>🧱 Register section / stall</h3>
<form method="post" class="acts" style="margin-bottom:14px"><?= csrf() ?><input type="hidden" name="a" value="section_add"><input name="name" placeholder="New section name" required style="flex:1"><button class="btn sm">Add section</button></form>
<form method="post"><?= csrf() ?><input type="hidden" name="a" value="stall_add"><div class="row"><div class="fg"><label>Section</label><select name="section_id"><?php foreach ($sections as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
<div class="fg"><label>Stall no.</label><input name="stall_no" required placeholder="M-10"></div><div class="fg"><label>Monthly rate (₱)</label><input type="number" step="0.01" name="rate" required></div></div><button class="btn sm">Add stall</button></form></div></div>
<?php if ($apps): ?><h3 style="margin:22px 0 10px">⏳ Pending applications</h3><div class="tw"><table><tr><th>Applicant</th><th>Stall</th><th>Contact</th><th>Filed</th><th></th></tr>
<?php foreach ($apps as $r): ?><tr><td><b><?= e($r['applicant']) ?></b></td><td><?= e($r['section'] . ' · ' . $r['stall_no']) ?></td><td><?= e($r['contact']) ?></td><td><?= date('M j', strtotime($r['created_at'])) ?></td>
<td><div class="acts"><a class="btn xs" href="?p=agreements&stall=<?= $r['stall_id'] ?><?= $r['vendor_id'] ? '&vendor=' . $r['vendor_id'] : '' ?>">Award → record agreement</a><form method="post"><?= csrf() ?><input type="hidden" name="a" value="app_cancel"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn xs red">Cancel</button></form></div></td></tr><?php endforeach; ?></table></div><?php endif; ?>
<h3 style="margin:22px 0 10px">All stalls</h3><div class="tw"><table><tr><th>Section</th><th>Stall</th><th class="num">Rate / month</th><th>Status</th><th>Current vendor</th></tr>
<?php foreach ($stalls as $s): ?><tr><td><?= e($s['section']) ?></td><td><b><?= e($s['stall_no']) ?></b></td><td class="num"><?= peso($s['rate']) ?></td><td><?= status_badge($s['status']) ?></td><td><?= e($s['vendor'] ?: '—') ?></td></tr><?php endforeach; ?></table></div>

<?php elseif ($p === 'agreements'):
    $allStalls = all('SELECT s.*, sc.name section FROM stalls s JOIN sections sc ON sc.id=s.section_id ORDER BY sc.name, s.stall_no');
    $selS = (int)($_GET['stall'] ?? 0); $selV = (int)($_GET['vendor'] ?? 0);
    $ags = all('SELECT a.*, v.store_name, s.stall_no, sc.name section FROM agreements a JOIN vendors v ON v.id=a.vendor_id JOIN stalls s ON s.id=a.stall_id JOIN sections sc ON sc.id=s.section_id ORDER BY a.id DESC'); ?>
<div class="card"><h3>📜 Record rental agreement (award / renewal / transfer)</h3><p class="meta" style="margin-bottom:12px">Recording a new agreement for a stall that already has an active one keeps the old agreement as history (renewal or transfer).</p>
<form method="post"><?= csrf() ?><input type="hidden" name="a" value="agreement_add"><div class="row">
<div class="fg"><label>Vendor</label><select name="vendor_id" required><?php foreach ($vendors as $v): ?><option value="<?= $v['id'] ?>" <?= $selV == $v['id'] ? 'selected' : '' ?>><?= e($v['store_name'] . ' — ' . $v['name']) ?></option><?php endforeach; ?></select></div>
<div class="fg"><label>Stall</label><select name="stall_id" required><?php foreach ($allStalls as $s): ?><option value="<?= $s['id'] ?>" <?= $selS == $s['id'] ? 'selected' : '' ?>><?= e($s['section'] . ' · ' . $s['stall_no'] . ' [' . $s['status'] . ']') ?></option><?php endforeach; ?></select></div>
<div class="fg"><label>Start date</label><input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required></div>
<div class="fg"><label>End date</label><input type="date" name="end_date" value="<?= date('Y-m-d', strtotime('+1 year')) ?>" required></div>
<div class="fg"><label>Monthly rate (₱)</label><input type="number" step="0.01" name="monthly_rate" required></div>
<div class="fg"><label>Note (lease terms, etc.)</label><input name="note" maxlength="250"></div></div><button class="btn">Save agreement</button></form></div>
<h3 style="margin:22px 0 10px">Agreement history</h3><div class="tw"><table><tr><th>#</th><th>Vendor</th><th>Stall</th><th>Term</th><th class="num">Rate</th><th>Status</th><th></th></tr>
<?php foreach ($ags as $r): ?><tr><td><?= $r['id'] ?></td><td><b><?= e($r['store_name']) ?></b></td><td><?= e($r['section'] . ' · ' . $r['stall_no']) ?></td><td><?= date('M j, Y', strtotime($r['start_date'])) ?> – <?= date('M j, Y', strtotime($r['end_date'])) ?></td><td class="num"><?= peso($r['monthly_rate']) ?></td><td><?= status_badge($r['status']) ?></td>
<td><?php if ($r['status'] === 'active'): ?><form method="post" data-confirm="End this agreement? The vendor will be unlisted."><?= csrf() ?><input type="hidden" name="a" value="agreement_end"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn xs red">Terminate</button></form><?php endif; ?></td></tr><?php endforeach; ?></table></div>

<?php elseif ($p === 'permits'):
    $rows = all('SELECT v.id vid, v.store_name, pm.* FROM vendors v LEFT JOIN permits pm ON pm.id=(SELECT id FROM permits WHERE vendor_id=v.id ORDER BY expiry_date DESC LIMIT 1) ORDER BY v.store_name'); ?>
<div class="card"><h3>🪪 Record business permit</h3><p class="meta" style="margin-bottom:12px">The permit is separate from the stall lease. Renewals are added as new records; the latest expiry is what counts.</p><form method="post"><?= csrf() ?><input type="hidden" name="a" value="permit_save"><div class="row">
<div class="fg"><label>Vendor</label><select name="vendor_id"><?php foreach ($vendors as $v): ?><option value="<?= $v['id'] ?>"><?= e($v['store_name']) ?></option><?php endforeach; ?></select></div>
<div class="fg"><label>Permit number</label><input name="permit_no" required></div><div class="fg"><label>Issue date</label><input type="date" name="issue_date" value="<?= date('Y-m-d') ?>" required></div>
<div class="fg"><label>Expiry date</label><input type="date" name="expiry_date" value="<?= date('Y-12-31') ?>" required></div></div><button class="btn">Save permit</button></form></div>
<div class="tw" style="margin-top:18px"><table><tr><th>Vendor</th><th>Permit no.</th><th>Issued</th><th>Expires</th><th>Status</th></tr>
<?php foreach ($rows as $r): ?><tr><td><b><?= e($r['store_name']) ?></b></td><td><?= e($r['permit_no'] ?: '—') ?></td><td><?= $r['issue_date'] ? date('M j, Y', strtotime($r['issue_date'])) : '—' ?></td><td><?= $r['expiry_date'] ? date('M j, Y', strtotime($r['expiry_date'])) : '—' ?></td><td><?= $r['expiry_date'] ? status_badge(permit_status($r['expiry_date'])) : badge('No permit', 'red') ?></td></tr><?php endforeach; ?></table></div>

<?php elseif ($p === 'billing'):
    $f = $_GET['f'] ?? 'all';
    $bills = all("SELECT x.*, v.store_name, s.stall_no FROM (" . BILL_SQL . ") x JOIN agreements a ON a.id=x.agreement_id JOIN vendors v ON v.id=a.vendor_id JOIN stalls s ON s.id=a.stall_id ORDER BY x.period DESC, v.store_name");
    $bills = array_values(array_filter($bills, fn($b) => $f === 'all' || bill_status($b) === $f)); ?>
<div class="two"><div class="card"><h3>🧾 Generate monthly billings</h3><form method="post" class="acts"><?= csrf() ?><input type="hidden" name="a" value="bill_generate"><input type="month" name="period" value="<?= date('Y-m') ?>" style="width:auto"><button class="btn sm">Generate for all active agreements</button></form><p class="meta" style="margin-top:8px">Due on day <?= BILL_DUE_DAY ?> of the month. Existing billings are skipped.</p></div></div>
<div class="tabs" style="margin-top:18px"><?php foreach (['all' => 'All', 'unpaid' => 'Unpaid', 'overdue' => 'Overdue', 'paid' => 'Paid'] as $k => $l): ?><a class="<?= $f === $k ? 'on' : '' ?>" href="?p=billing&f=<?= $k ?>"><?= $l ?></a><?php endforeach; ?></div>
<div class="tw"><table><tr><th>Period</th><th>Vendor / stall</th><th>Due</th><th class="num">Amount</th><th class="num">Paid</th><th>Status</th><th>Record payment</th></tr>
<?php foreach ($bills as $b): $bs = bill_status($b); ?><tr><td><b><?= date('M Y', strtotime($b['period'] . '-01')) ?></b></td><td><?= e($b['store_name']) ?> <small class="meta"><?= e($b['stall_no']) ?></small></td><td><?= date('M j', strtotime($b['due_date'])) ?></td><td class="num"><?= peso($b['amount']) ?></td><td class="num"><?= peso($b['paid']) ?></td><td><?= status_badge($bs) ?></td>
<td><?php if ($bs !== 'paid'): ?><form method="post" class="acts"><?= csrf() ?><input type="hidden" name="a" value="pay"><input type="hidden" name="id" value="<?= $b['id'] ?>"><input type="number" step="0.01" name="amount" value="<?= $b['amount'] - $b['paid'] ?>" style="width:100px"><input name="or_number" placeholder="OR no." required style="width:110px"><input type="date" name="paid_on" value="<?= date('Y-m-d') ?>" style="width:140px"><button class="btn xs">Save</button></form><?php else: ?><small class="meta">OR <?= e($b['ors']) ?></small><?php endif; ?></td></tr><?php endforeach; if (!$bills) echo '<tr><td colspan="7" class="meta">Nothing to show.</td></tr>'; ?></table></div>

<?php else:
    $months = all('SELECT period, SUM(amount) billed, SUM(paid) paid FROM (' . BILL_SQL . ') t GROUP BY period ORDER BY period DESC LIMIT 6');
    $stat = [];
    foreach (all('SELECT v.id, v.store_name, ' . LOC . ' AS location FROM vendors v ORDER BY v.store_name') as $v) {
        $n = 0; $amt = 0; foreach (all(BILL_SQL . ' WHERE a.vendor_id=?', [$v['id']]) as $b) if (bill_status($b) === 'overdue') { $n++; $amt += $b['amount'] - $b['paid']; }
        $stat[] = $v + ['n' => $n, 'amt' => $amt];
    } ?>
<div class="two"><div class="card"><h3>Collection summary (last 6 billing months)</h3><div class="tw"><table><tr><th>Month</th><th class="num">Billed</th><th class="num">Collected</th><th class="num">Balance</th></tr>
<?php foreach ($months as $m): ?><tr><td><?= date('F Y', strtotime($m['period'] . '-01')) ?></td><td class="num"><?= peso($m['billed']) ?></td><td class="num"><?= peso($m['paid']) ?></td><td class="num"><?= peso($m['billed'] - $m['paid']) ?></td></tr><?php endforeach; ?></table></div></div>
<div class="card"><h3>Print</h3><p class="meta" style="margin-bottom:12px">Use the rental status summary below to support market certifications.</p><button class="btn noprint" onclick="window.print()">🖨️ Print rental status summary</button></div></div>
<h3 style="margin:22px 0 10px">Rental status per vendor — as of <?= date('F j, Y') ?></h3><div class="tw"><table><tr><th>Vendor</th><th>Stall</th><th>Status</th><th>Overdue months</th><th class="num">Arrears</th></tr>
<?php foreach ($stat as $r): ?><tr><td><b><?= e($r['store_name']) ?></b></td><td><?= e($r['location'] ?: '—') ?></td><td><?= $r['n'] ? badge('With arrears', 'red') : badge('Paid / up to date', 'green') ?></td><td><?= $r['n'] ?></td><td class="num"><?= peso($r['amt']) ?></td></tr><?php endforeach; ?></table></div>
<?php endif; dash_end();
