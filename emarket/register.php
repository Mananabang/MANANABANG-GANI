<?php
require __DIR__ . '/includes/layout.php';
if (me()) redirect(home_for(me()['role']));
if (post_ok()) {
    $name = trim($_POST['name'] ?? ''); $email = strtolower(trim($_POST['email'] ?? '')); $pw = $_POST['password'] ?? '';
    if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL)) flash('Please enter your name and a valid email.', 'err');
    elseif (strlen($pw) < 6) flash('Password must be at least 6 characters.', 'err');
    elseif (one('SELECT id FROM users WHERE email=?', [$email])) flash('That email is already registered.', 'err');
    else {
        q("INSERT INTO users(name,email,phone,password,role) VALUES(?,?,?,?,'customer')", [$name, $email, trim($_POST['phone'] ?? ''), password_hash($pw, PASSWORD_DEFAULT)]);
        session_regenerate_id(true); $_SESSION['uid'] = db()->lastInsertId();
        flash('Account created. Happy shopping!'); redirect(BASE . '/index.php');
    }
}
page_start('Sign up', true); ?>
<div class="authwrap"><div class="authcard">
  <h1>Join the market 🧺</h1><p class="sub">A customer account is only needed to add items to your cart and order.</p>
  <?php show_flash(); ?>
  <form method="post"><?= csrf() ?>
    <div class="fg"><label>Full name</label><input name="name" required value="<?= e($_POST['name'] ?? '') ?>"></div>
    <div class="fg"><label>Email</label><input type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>"></div>
    <div class="fg"><label>Mobile number</label><input name="phone" placeholder="09xx xxx xxxx" value="<?= e($_POST['phone'] ?? '') ?>"></div>
    <div class="fg"><label>Password</label><input type="password" name="password" minlength="6" required></div>
    <button class="btn">Create account</button></form>
  <p class="meta" style="margin-top:14px;font-size:.78rem">By signing up you agree that your data is processed under the Data Privacy Act of 2012 (RA 10173). Vendors are registered by the market administration.</p>
</div></div>
<?php page_end();
