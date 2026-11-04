<?php
require __DIR__ . '/includes/layout.php';
$u = need(['customer', 'vendor', 'admin', 'staff']);
$list = all('SELECT * FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 60', [$u['id']]);
q('UPDATE notifications SET is_read=1 WHERE user_id=?', [$u['id']]);
page_start('Notifications'); ?>
<h1 style="margin-bottom:16px">🔔 Notifications</h1>
<?php if (!$list): ?><div class="empty"><span>📭</span>Nothing here yet.</div><?php endif; ?>
<?php foreach ($list as $n): ?><div class="order" style="padding:14px 18px;<?= $n['is_read'] ? '' : 'border-left:5px solid var(--green)' ?>">
  <?= e($n['message']) ?><div class="meta"><?= date('M j, g:i A', strtotime($n['created_at'])) ?></div></div><?php endforeach;
page_end();
