<?php
// Run once in the browser:  http://localhost/emarket/install.php   (add ?reset=1 to wipe & reinstall)
require __DIR__ . '/config.php';
$pdo = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$exists = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='" . DB_NAME . "' AND table_name='users'")->fetchColumn();
function out($msg, $ok = true) {
    echo '<!doctype html><meta charset="utf-8"><body style="font-family:system-ui;background:#fbf6ec;display:grid;place-items:center;min-height:100vh;margin:0"><div style="background:#fff;padding:34px 40px;border-radius:22px;max-width:560px;box-shadow:0 20px 50px -20px #0d4a2f66;line-height:1.7">'
        . '<h1 style="margin-top:0">' . ($ok ? '🧺 E-Market Installer' : '⚠️ Installer') . '</h1>' . $msg . '</div></body>';
    exit;
}
if ($exists && !isset($_GET['reset'])) out('<p>The database <b>' . DB_NAME . '</b> is already installed.</p><p><a href="' . BASE . '/index.php">Open the market</a> · <a href="?reset=1" onclick="return confirm(\'This erases ALL data. Continue?\')">Reinstall (erase all data)</a></p>', false);

$pdo->exec('DROP DATABASE IF EXISTS ' . DB_NAME);
$pdo->exec('CREATE DATABASE ' . DB_NAME . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE ' . DB_NAME);

$schema = [
"CREATE TABLE settings(k VARCHAR(40) PRIMARY KEY, v VARCHAR(100) NOT NULL)",
"CREATE TABLE users(id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, email VARCHAR(120) NOT NULL UNIQUE, phone VARCHAR(30),
  password VARCHAR(255) NOT NULL, role ENUM('customer','vendor','admin','staff') NOT NULL DEFAULT 'customer',
  strikes INT NOT NULL DEFAULT 0, status ENUM('active','flagged','blocked') NOT NULL DEFAULT 'active', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
"CREATE TABLE categories(id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(60) NOT NULL)",
"CREATE TABLE sections(id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL)",
"CREATE TABLE stalls(id INT AUTO_INCREMENT PRIMARY KEY, section_id INT NOT NULL, stall_no VARCHAR(20) NOT NULL, rate DECIMAL(10,2) NOT NULL DEFAULT 0,
  status ENUM('vacant','reserved','occupied') NOT NULL DEFAULT 'vacant', FOREIGN KEY(section_id) REFERENCES sections(id))",
"CREATE TABLE vendors(id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL UNIQUE, store_name VARCHAR(100) NOT NULL, category_id INT, emoji VARCHAR(10) DEFAULT '🏪',
  description TEXT, active TINYINT NOT NULL DEFAULT 1, FOREIGN KEY(user_id) REFERENCES users(id))",
"CREATE TABLE applications(id INT AUTO_INCREMENT PRIMARY KEY, stall_id INT NOT NULL, applicant VARCHAR(100) NOT NULL, contact VARCHAR(60), vendor_id INT NULL,
  status ENUM('pending','awarded','cancelled') NOT NULL DEFAULT 'pending', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(stall_id) REFERENCES stalls(id))",
"CREATE TABLE agreements(id INT AUTO_INCREMENT PRIMARY KEY, vendor_id INT NOT NULL, stall_id INT NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL,
  monthly_rate DECIMAL(10,2) NOT NULL, status ENUM('active','expired','ended') NOT NULL DEFAULT 'active', note VARCHAR(255),
  FOREIGN KEY(vendor_id) REFERENCES vendors(id), FOREIGN KEY(stall_id) REFERENCES stalls(id))",
"CREATE TABLE permits(id INT AUTO_INCREMENT PRIMARY KEY, vendor_id INT NOT NULL, permit_no VARCHAR(60) NOT NULL, issue_date DATE NOT NULL, expiry_date DATE NOT NULL, FOREIGN KEY(vendor_id) REFERENCES vendors(id))",
"CREATE TABLE billings(id INT AUTO_INCREMENT PRIMARY KEY, agreement_id INT NOT NULL, period CHAR(7) NOT NULL, amount DECIMAL(10,2) NOT NULL, due_date DATE NOT NULL,
  UNIQUE KEY u(agreement_id,period), FOREIGN KEY(agreement_id) REFERENCES agreements(id))",
"CREATE TABLE payments(id INT AUTO_INCREMENT PRIMARY KEY, billing_id INT NOT NULL, amount DECIMAL(10,2) NOT NULL, or_number VARCHAR(40) NOT NULL, paid_on DATE NOT NULL,
  recorded_by INT, FOREIGN KEY(billing_id) REFERENCES billings(id))",
"CREATE TABLE products(id INT AUTO_INCREMENT PRIMARY KEY, vendor_id INT NOT NULL, category_id INT, name VARCHAR(100) NOT NULL, emoji VARCHAR(10) DEFAULT '🛍️', description VARCHAR(255),
  price DECIMAL(10,2) NOT NULL, unit ENUM('kg','piece','pack') NOT NULL DEFAULT 'piece', stock DECIMAL(10,2) NOT NULL DEFAULT 0, low_at DECIMAL(10,2) NOT NULL DEFAULT 5,
  active TINYINT NOT NULL DEFAULT 1, FOREIGN KEY(vendor_id) REFERENCES vendors(id))",
"CREATE TABLE orders(id INT AUTO_INCREMENT PRIMARY KEY, customer_id INT NOT NULL, vendor_id INT NOT NULL,
  status ENUM('pending','accepted','preparing','ready','completed','rejected','cancelled','uncollected') NOT NULL DEFAULT 'pending',
  total DECIMAL(10,2) NOT NULL DEFAULT 0, note VARCHAR(255), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, ready_at DATETIME NULL, pickup_deadline DATETIME NULL, completed_at DATETIME NULL,
  FOREIGN KEY(customer_id) REFERENCES users(id), FOREIGN KEY(vendor_id) REFERENCES vendors(id))",
"CREATE TABLE order_items(id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, product_id INT NOT NULL, name VARCHAR(100) NOT NULL, unit VARCHAR(10) NOT NULL,
  unit_price DECIMAL(10,2) NOT NULL, qty DECIMAL(10,2) NOT NULL, final_weight DECIMAL(10,3) NULL, final_price DECIMAL(10,2) NULL, FOREIGN KEY(order_id) REFERENCES orders(id))",
"CREATE TABLE notifications(id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, message VARCHAR(255) NOT NULL, is_read TINYINT NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
"CREATE TABLE reports(id INT AUTO_INCREMENT PRIMARY KEY, reporter_id INT NOT NULL, target_type ENUM('user','vendor','product') NOT NULL, target_id INT NOT NULL,
  reason VARCHAR(255) NOT NULL, status ENUM('open','resolved') NOT NULL DEFAULT 'open', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
];
foreach ($schema as $sql) $pdo->exec($sql);

// ---------- demo data ----------
$ins = function ($sql, $p = []) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($p); return $pdo->lastInsertId(); };
foreach ([['response_minutes', 15], ['pickup_hours', 3], ['max_strikes', 3]] as $s) $ins('INSERT INTO settings VALUES(?,?)', $s);
foreach (['Meat', 'Fish & Seafood', 'Vegetables', 'Fruits', 'Dry Goods', 'Cooked Food'] as $c) $ins('INSERT INTO categories(name) VALUES(?)', [$c]);
$sec = []; foreach (['Meat & Fish Section', 'Vegetable & Fruit Section', 'Dry Goods Section'] as $n) $sec[] = $ins('INSERT INTO sections(name) VALUES(?)', [$n]);
$stall = [];
foreach ([[0, 'M', 5, 2500], [1, 'V', 4, 1800], [2, 'D', 3, 2200]] as [$i, $pre, $n, $rate])
    for ($k = 1; $k <= $n; $k++) $stall[$pre . $k] = $ins('INSERT INTO stalls(section_id,stall_no,rate) VALUES(?,?,?)', [$sec[$i], sprintf('%s-%02d', $pre, $k), $rate]);
$pw = fn($p) => password_hash($p, PASSWORD_DEFAULT);
$ins("INSERT INTO users(name,email,phone,password,role) VALUES(?,?,?,?,?)", ['System Administrator', 'admin@cogon.test', '', $pw('admin123'), 'admin']);
$ins("INSERT INTO users(name,email,phone,password,role) VALUES(?,?,?,?,?)", ['Market Office (CEED) Staff', 'staff@cogon.test', '', $pw('staff123'), 'staff']);
$cust = $ins("INSERT INTO users(name,email,phone,password,role) VALUES(?,?,?,?,?)", ['Juan Dela Cruz', 'juan@cogon.test', '0917 000 1111', $pw('customer123'), 'customer']);
$V = [['Mang Ben Meat Stall', 'ben@cogon.test', 'Mang Ben Santos', 1, '🥩', 'Fresh pork & beef cuts every morning.', 'M1', 2],
      ['Aling Rosa Seafoods', 'rosa@cogon.test', 'Rosa Villanueva', 2, '🐟', 'Daily catch straight from the Macajalar Bay.', 'M2', 2],
      ['Nanay Linda Veggies & Fruits', 'linda@cogon.test', 'Linda Ramos', 3, '🥬', 'Highland vegetables and seasonal fruits.', 'V1', 3]];
$vid = [];
foreach ($V as [$store, $mail, $name, $cat, $emo, $desc, $st]) {
    $u = $ins("INSERT INTO users(name,email,phone,password,role) VALUES(?,?,?,?,'vendor')", [$name, $mail, '0918 222 3333', $pw('vendor123')]);
    $v = $ins('INSERT INTO vendors(user_id,store_name,category_id,emoji,description) VALUES(?,?,?,?,?)', [$u, $store, $cat, $emo, $desc]);
    $vid[] = $v;
    $rate = $pdo->query('SELECT rate FROM stalls WHERE id=' . $stall[$st])->fetchColumn();
    $a = $ins("INSERT INTO agreements(vendor_id,stall_id,start_date,end_date,monthly_rate,note) VALUES(?,?,?,?,?,'Stall lease contract')", [$v, $stall[$st], '2026-01-01', '2026-12-31', $rate]);
    $pdo->exec("UPDATE stalls SET status='occupied' WHERE id=" . $stall[$st]);
    $isLinda = ($mail === 'linda@cogon.test');
    $exp = $isLinda ? date('Y-m-d', strtotime('+20 days')) : '2026-12-31';
    $ins('INSERT INTO permits(vendor_id,permit_no,issue_date,expiry_date) VALUES(?,?,?,?)', [$v, 'BP-2026-' . str_pad($v, 4, '0', STR_PAD_LEFT), '2026-01-10', $exp]);
    foreach (['2026-08', '2026-09', '2026-10'] as $per) {
        $b = $ins('INSERT INTO billings(agreement_id,period,amount,due_date) VALUES(?,?,?,?)', [$a, $per, $rate, $per . '-' . BILL_DUE_DAY]);
        $paid = $per === '2026-08' || ($per === '2026-09' && !$isLinda);
        if ($paid) $ins("INSERT INTO payments(billing_id,amount,or_number,paid_on) VALUES(?,?,?,?)", [$b, $rate, 'OR-' . rand(100000, 999999), $per . '-08']);
    }
}
$ins("INSERT INTO stalls(section_id,stall_no,rate,status) VALUES(?,?,?,?)", [$sec[0], 'M-09', 2500, 'vacant']);
$P = [[0, 1, 'Pork Liempo', '🥓', 330, 'kg', 25], [0, 1, 'Beef Brisket', '🥩', 480, 'kg', 12], [0, 1, 'Whole Chicken', '🍗', 210, 'kg', 3], [0, 1, 'Pork Chop', '🍖', 310, 'kg', 18],
      [1, 2, 'Bangus (Milkfish)', '🐟', 190, 'kg', 20], [1, 2, 'Tilapia', '🐠', 150, 'kg', 15], [1, 2, 'Fresh Squid', '🦑', 260, 'kg', 8], [1, 2, 'Shrimp', '🦐', 420, 'kg', 4],
      [2, 3, 'Tomatoes', '🍅', 60, 'kg', 40], [2, 3, 'Native Garlic', '🧄', 140, 'kg', 10], [2, 3, 'Carrots', '🥕', 80, 'kg', 30], [2, 3, 'Bananas (Lakatan)', '🍌', 90, 'pack', 25], [2, 3, 'Mangoes', '🥭', 130, 'kg', 0]];
$pid = [];
foreach ($P as [$vi, $cat, $nm, $emo, $pr, $un, $stk]) $pid[$nm] = $ins('INSERT INTO products(vendor_id,category_id,name,emoji,price,unit,stock) VALUES(?,?,?,?,?,?,?)', [$vid[$vi], $cat, $nm, $emo, $pr, $un, $stk]);
// a few completed orders so charts/reports have data
$samples = [[0, 'Pork Liempo', 1.5, 1], [0, 'Pork Chop', 1.0, 2], [1, 'Bangus (Milkfish)', 2.0, 3], [1, 'Fresh Squid', 0.75, 4], [2, 'Tomatoes', 2.0, 5], [2, 'Carrots', 1.5, 6], [0, 'Beef Brisket', 1.0, 1], [1, 'Tilapia', 1.25, 2]];
foreach ($samples as [$vi, $nm, $w, $d]) {
    $price = round($w * $pdo->query('SELECT price FROM products WHERE id=' . $pid[$nm])->fetchColumn(), 2);
    $dt = date('Y-m-d H:i:s', strtotime("-$d days 10:00"));
    $o = $ins("INSERT INTO orders(customer_id,vendor_id,status,total,created_at,completed_at) VALUES(?,?,'completed',?,?,?)", [$cust, $vid[$vi], $price, $dt, $dt]);
    $ins('INSERT INTO order_items(order_id,product_id,name,unit,unit_price,qty,final_weight,final_price) SELECT ?,id,name,unit,price,?,?,? FROM products WHERE id=?', [$o, $w, $w, $price, $pid[$nm]]);
}
out('<p>✅ Database <b>' . DB_NAME . '</b> created with demo data.</p><table cellpadding="6"><tr><td><b>Admin</b></td><td>admin@cogon.test / admin123</td></tr><tr><td><b>Market Staff / LGU</b></td><td>staff@cogon.test / staff123</td></tr><tr><td><b>Vendor</b></td><td>ben@cogon.test / vendor123 (also rosa@, linda@)</td></tr><tr><td><b>Customer</b></td><td>juan@cogon.test / customer123</td></tr></table><p><a href="' . BASE . '/index.php">→ Open the market</a></p><p style="color:#a02a17;font-size:.85rem">Delete install.php after setup.</p>');
