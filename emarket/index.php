<?php
require __DIR__ . '/includes/layout.php';
$qs = trim($_GET['q'] ?? ''); $cat = (int)($_GET['cat'] ?? 0);
$cats = all('SELECT * FROM categories ORDER BY id');
$vendors = all('SELECT v.*, ' . LOC . ' AS location, (SELECT COUNT(*) FROM products p WHERE p.vendor_id=v.id AND p.active=1) n FROM vendors v WHERE ' . LISTED . ' ORDER BY v.store_name');
$sql = 'SELECT p.*, v.store_name, v.id vid, ' . LOC . ' AS location FROM products p JOIN vendors v ON v.id=p.vendor_id WHERE ' . LISTED . ' AND p.active=1';
$par = [];
if ($qs !== '') { $sql .= ' AND (p.name LIKE ? OR v.store_name LIKE ?)'; $par[] = "%$qs%"; $par[] = "%$qs%"; }
if ($cat) { $sql .= ' AND p.category_id=?'; $par[] = $cat; }
$products = all($sql . ' ORDER BY (p.stock>0) DESC, p.name', $par);
$total = (int)val('SELECT COUNT(*) FROM products p JOIN vendors v ON v.id=p.vendor_id WHERE ' . LISTED . ' AND p.active=1');

page_start('Market', true); ?>
<section class="hero"><div class="wrap">
  <div>
    <h1>Fresh from the stalls of <span>Cogon Market</span>, ordered from your phone.</h1>
    <p>Browse verified Cogon vendors, reserve your meat, fish, vegetables and more, then pick them up at the stall. Pay in cash — no delivery, no hassle.</p>
    <form class="searchbar" method="get"><input name="q" value="<?= e($qs) ?>" placeholder="Search bangus, liempo, tomatoes…"><button class="btn">Search</button></form>
    <div class="herostats"><div><b><?= count($vendors) ?></b><small>Verified vendors</small></div><div><b><?= $total ?></b><small>Products</small></div><div><b>₱0</b><small>Delivery fee</small></div></div>
  </div>
  <div class="floaty"><span>🥩</span><span>🐟</span><span>🥬</span><span>🍅</span><span>🍌</span></div>
</div>
<svg class="wave" viewBox="0 0 1440 60" preserveAspectRatio="none"><path fill="#fbf6ec" d="M0 40 C240 90 480 0 720 30 S1200 80 1440 20 V60 H0Z"/></svg></section>
<div class="wrap"><?php show_flash(); ?></div>

<section class="section" id="vendors"><h2>Meet the vendors</h2><p class="sub">Only stallholders with an active lease and a valid business permit are listed.</p>
<?php if (!$vendors): ?><div class="empty"><span>🏪</span>No vendors are listed yet.</div><?php else: ?>
<div class="grid"><?php foreach ($vendors as $v): ?>
  <a class="vcard" href="<?= BASE ?>/store.php?id=<?= $v['id'] ?>"><div class="vav"><?= e($v['emoji']) ?></div><h3><?= e($v['store_name']) ?></h3>
  <small>📍 <?= e($v['location']) ?></small><small><?= $v['n'] ?> products</small></a><?php endforeach; ?></div><?php endif; ?></section>

<section class="section" id="products"><h2>What's fresh today</h2>
<div class="chips"><a class="chip <?= !$cat ? 'on' : '' ?>" href="?q=<?= urlencode($qs) ?>#products">All</a>
<?php foreach ($cats as $c): ?><a class="chip <?= $cat == $c['id'] ? 'on' : '' ?>" href="?cat=<?= $c['id'] ?>&q=<?= urlencode($qs) ?>#products"><?= e($c['name']) ?></a><?php endforeach; ?></div>
<?php if (!$products): ?><div class="empty"><span>🔍</span>No products match your search.</div><?php else: ?>
<div class="grid s"><?php foreach ($products as $p) echo product_card($p); ?></div><?php endif; ?></section>
<div style="height:30px"></div>
<?php page_end();
