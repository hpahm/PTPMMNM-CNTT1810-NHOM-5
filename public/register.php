<?php
require __DIR__ . '/../src/bootstrap.php'; startApp();
if (currentUser()) redirect('/');
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = post('name');
    $email = strtolower(post('email'));
    $password = post('password');
    $confirm = post('confirm_password');
    if ($name === '' || strlen($name) > 100 || str_contains($name, "\0")) $errors[] = 'Họ tên không hợp lệ.';
    if (!isGmailAddress($email)) $errors[] = 'Email phải có đuôi @gmail.com.';
    if (strlen($password) < 6) $errors[] = 'Mật khẩu phải có ít nhất 6 ký tự.';
    if ($password !== $confirm) $errors[] = 'Mật khẩu xác nhận không khớp.';
    $data = readStore();
    foreach ($data['users'] as $existing) {
        if ($existing['email'] === $email) { $errors[] = 'Email này đã được đăng ký.'; break; }
    }
    if (!$errors) {
        $id = bin2hex(random_bytes(8));
        withStore(function (array &$store) use ($id, $name, $email, $password): void {
            $store['users'][$id] = [
                'id' => $id,
                'name' => $name,
                'email' => $email,
                'role' => 'recipient',
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'quota' => USER_QUOTA_BYTES,
            ];
        });
        flash('Đăng ký thành công. Bạn có thể đăng nhập ngay.');
        redirect('/login.php');
    }
}
layoutStart('Đăng ký');
echo '<section class="panel"><h1>Tạo tài khoản</h1>';
foreach ($errors as $error) echo '<div class="flash error">'.h($error).'</div>';
echo '<form method="post"><label>Họ tên<input type="text" name="name" value="'.h(post('name')).'" maxlength="100" required autofocus></label><label>Email<input type="email" name="email" value="'.h(post('email')).'" pattern="[a-zA-Z0-9._%+\-]+@gmail\.com" title="Email phải có đuôi @gmail.com" placeholder="tenban@gmail.com" required></label><label>Mật khẩu<input type="password" name="password" minlength="6" required></label><label>Nhập lại mật khẩu<input type="password" name="confirm_password" minlength="6" required></label><button>Đăng ký</button></form><p class="auth-switch">Đã có tài khoản? <a href="/login.php">Đăng nhập</a></p></section>';
layoutEnd();
