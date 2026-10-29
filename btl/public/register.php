<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
startApp();

if (currentUser()) {
    redirect('/');
}

$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verifyCsrf();
    $name = post('name');
    $email = strtolower(post('email'));
    $password = post('password');
    $confirm = post('confirm_password');

    if ($name === '' || mb_strlen($name) > 100 || str_contains($name, "\0")) {
        $errors[] = 'Họ và tên không hợp lệ.';
    }
    if (!isGmailAddress($email)) {
        $errors[] = 'Email phải có định dạng @gmail.com.';
    }
    if (strlen($password) < 6) {
        $errors[] = 'Mật khẩu phải có ít nhất 6 ký tự.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Mật khẩu xác nhận không khớp.';
    }

    $data = readStore();
    foreach ($data['users'] as $existing) {
        if ($existing['email'] === $email) {
            $errors[] = 'Email này đã tồn tại trong hệ thống.';
            break;
        }
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
            $store['folders']['root-' . $id] = [
                'id' => 'root-' . $id,
                'owner_id' => $id,
                'name' => 'Tệp của tôi',
                'parent_id' => null,
                'created_at' => date(DATE_ATOM),
            ];
            logEvent($store, 'register', $id);
        });

        flash('Đăng ký tài khoản thành công! Bạn có thể đăng nhập ngay.');
        redirect('/login.php');
    }
}

layoutStart('Đăng ký');
?>
<div class="auth-card">
    <div class="auth-card-header">
        <div class="auth-brand-badge">
            <svg viewBox="0 0 87.3 78" width="36" height="32">
                <path d="m6.6 66.85 3.85 6.65c.8 1.4 1.95 2.5 3.3 3.3l13.75-23.8h-27.5c0 1.55.4 3.1 1.2 4.5z" fill="#0066da"/>
                <path d="m43.65 25-13.75-23.8c-1.35.8-2.5 1.9-3.3 3.3l-25.4 44c-.8 1.4-1.2 2.95-1.2 4.5h27.5z" fill="#00ac47"/>
                <path d="m73.55 76.8c1.35-.8 2.5-1.9 3.3-3.3l1.6-2.75 7.65-13.25c.8-1.4 1.2-2.95 1.2-4.5h-27.502l5.852 11.5z" fill="#ea4335"/>
                <path d="m43.65 25 13.75-23.8c-1.35-.8-2.9-1.2-4.5-1.2h-18.5c-1.6 0-3.15.45-4.5 1.25z" fill="#00832d"/>
                <path d="m59.8 53h-32.3l-13.75 23.8c1.35.8 2.9 1.2 4.5 1.2h50.8c1.6 0 3.15-.45 4.5-1.25z" fill="#2684fc"/>
                <path d="m73.4 26.5-12.7-22c-.8-1.4-1.95-2.5-3.3-3.3l-13.75 23.8 16.15 28h27.45c0-1.55-.4-3.1-1.2-4.5z" fill="#ffba00"/>
            </svg>
        </div>
        <h1>Tạo tài khoản OpenShare</h1>
        <p class="auth-sub">Đăng ký để nhận và chia sẻ tệp có kiểm soát quyền truy cập</p>
    </div>

    <?php foreach ($errors as $err): ?>
        <div class="flash error"><?= h($err) ?></div>
    <?php endforeach; ?>

    <form method="post" class="auth-form" autocomplete="on">
        <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">

        <div class="form-group">
            <label for="name">Họ và tên</label>
            <input type="text" id="name" name="name" value="<?= h(post('name')) ?>" 
                   placeholder="Nguyễn Văn A" maxlength="100" required autofocus>
        </div>

        <div class="form-group">
            <label for="email">Địa chỉ Gmail</label>
            <input type="email" id="email" name="email" value="<?= h(post('email')) ?>" 
                   placeholder="tenban@gmail.com" 
                   pattern="[a-zA-Z0-9._%+\-]+@gmail\.com" 
                   title="Bắt buộc sử dụng đuôi @gmail.com" required>
        </div>

        <div class="form-group">
            <label for="password">Mật khẩu (tối thiểu 6 ký tự)</label>
            <input type="password" id="password" name="password" minlength="6" placeholder="Nhập mật khẩu" required>
        </div>

        <div class="form-group">
            <label for="confirm_password">Xác nhận mật khẩu</label>
            <input type="password" id="confirm_password" name="confirm_password" minlength="6" placeholder="Nhập lại mật khẩu" required>
        </div>

        <button type="submit" class="btn btn-primary btn-block">Đăng ký</button>
    </form>

    <div class="auth-card-footer">
        <span>Đã có tài khoản?</span>
        <a href="/login.php">Đăng nhập</a>
    </div>
</div>

<?php layoutEnd(); ?>
