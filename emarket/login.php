<?php
require __DIR__ . '/includes/layout.php';
if (me()) redirect(home_for(me()['role']));
if (post_ok()) {
    $u = one('SELECT * FROM users WHERE email=?', [strtolower(trim($_POST['email'] ?? ''))]);
    if ($u && password_verify($_POST['password'] ?? '', $u['password'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = $u['id'];
        $back = $_SESSION['back'] ?? null; unset($_SESSION['back']);
        redirect($u['role'] === 'customer' ? ($back ?: BASE . '/index.php') : home_for($u['role']));
    }
    flash('Incorrect email or password.', 'err');
}
page_start('Log in', true); ?>
<div class="authwrap"><div class="authcard">
  <h1>Welcome back 👋</h1><p class="sub">Log in to order, manage your stall, or run the market.</p>
  <?php show_flash(); ?>
  <form method="post"><?= csrf() ?>
    <div class="fg"><label>Email</label><input type="email" name="email" required autofocus></div>
    <div class="fg"><label>Password</label><input type="password" name="password" required></div>
    <button class="btn">Log in</button></form>
  <p class="meta" style="margin-top:16px;text-align:center">New customer? <a href="register.php" style="color:var(--green);font-weight:700">Create an account</a></p>
  <div class="demo"><b>Demo logins</b> (from install.php)<br>admin@cogon.test / admin123 · staff@cogon.test / staff123<br>ben@cogon.test / vendor123 · juan@cogon.test / customer123</div>
</div></div>
<?php page_end();
