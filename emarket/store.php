<?php
require __DIR__ . '/includes/layout.php';
$id = (int)($_GET['id'] ?? 0);
$v = one('SELECT v.*, c.name cat, ' . LOC . ' AS location FROM vendors v LEFT JOIN categories c ON c.id=v.category_id WHERE v.id=? AND ' . LISTED, [$id]);
page_start($v ? $v['store_name'] : 'Vendor');
if (!$v) { echo '<div class="empty"><span>🏪</span>This vendor is not available.<br><a class="btn sm" href="' . BASE . '/index.php">Back to market</a></div>'; page_end(); exit; }
$products = all('SELECT p.*, ? AS store_name, ? AS vid, ? AS location FROM products p WHERE p.vendor_id=? AND p.active=1 ORDER BY (p.stock>0) DESC, p.name', [$v['store_name'], $v['id'], $v['location'], $v['id']]);
?>
<div class="storehead"><div class="vav"><?= e($v['emoji']) ?></div>
  <div><h1><?= e($v['store_name']) ?></h1><p class="meta"><?= e($v['description']) ?></p>
  <p style="margin-top:8px"><?= badge('📍 ' . $v['location'], 'green') ?> <?= badge($v['cat'] ?: 'General', 'gold') ?> <?= badge('Pickup at stall', 'blue') ?></p></div></div>
<?php if (!$products): ?><div class="empty"><span>🛒</span>No products yet.</div><?php else: ?>
<div class="grid s"><?php foreach ($products as $p) echo product_card($p); ?></div><?php endif;
page_end();
