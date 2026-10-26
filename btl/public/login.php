<?php
require __DIR__ . '/../src/bootstrap.php'; startApp();
if (currentUser()) redirect('/');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(post('email')); $password = post('password'); $data = readStore();
    if (!isGmailAddress($email)) {
        $error = 'Chỉ chấp nhận tài khoản có đuôi @gmail.com.';
    }
    foreach ($data['users'] as $user) {
        if ($error === '' && $user['email'] === $email && password_verify($password, $user['password'])) {
            session_regenerate_id(true); $_SESSION['user_id'] = $user['id']; redirect('/');
        }
    }
    if ($error === '') $error = 'Email hoặc mật khẩu không đúng.';
}
layoutStart('Đăng nhập');
echo '<section class="panel"><h1>Đăng nhập</h1>';
if ($error) echo '<div class="flash error">'.h($error).'</div>';
echo '<form method="post"><label>Email<input type="email" name="email" pattern="[a-zA-Z0-9._%+\-]+@gmail\.com" title="Email phải có đuôi @gmail.com" placeholder="tenban@gmail.com" required autofocus></label><label>Mật khẩu<input type="password" name="password" required></label><button>Đăng nhập</button></form><p class="auth-switch">Chưa có tài khoản? <a href="/register.php">Đăng ký ngay</a></p></section>';
layoutEnd();
