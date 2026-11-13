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

    if (findUserByEmailSql($email) !== null) {
        $errors[] = 'Email này đã tồn tại trong hệ thống.';
    }

    if (!$errors) {
        $id = bin2hex(random_bytes(8));
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $db = database();
        beginImmediate($db);
        $assignedRole = 'recipient';
        try {
            $hasAdmin = (bool)$db->query("SELECT 1 FROM users WHERE role = 'admin' LIMIT 1")->fetchColumn();
            $assignedRole = !$hasAdmin ? 'admin' : 'recipient';
            $db->prepare('INSERT INTO users (id, name, email, role, password, quota) VALUES (?, ?, ?, ?, ?, ?)')
               ->execute([$id, $name, $email, $assignedRole, $hash, USER_QUOTA_BYTES]);
            $db->prepare('INSERT INTO folders (id, owner_id, name, parent_id, created_at) VALUES (?, ?, ?, ?, ?)')
               ->execute(['root-' . $id, $id, 'Tệp của tôi', null, date(DATE_ATOM)]);
            logActivity($db, 'register', $id);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        if ($assignedRole === 'admin') {
            flash('Đăng ký tài khoản Quản trị viên thành công! Bạn có thể đăng nhập ngay.');
        } else {
            flash('Đăng ký tài khoản thành công! Bạn có thể đăng nhập ngay.');
        }
        redirect('/login.php');
    }
}

$systemHasAdmin = systemHasAdmin();

layoutStart('Đăng ký');
?>
<div class="auth-card">
    <div class="auth-card-header">
        <div class="auth-brand-badge">
            <?= brandLogoSvg(44, 44) ?>
        </div>
        <h1>Tạo tài khoản OpenShare</h1>
        <p class="auth-sub">Đăng ký để nhận và chia sẻ tệp có kiểm soát quyền truy cập</p>
    </div>

    <?php if (!$systemHasAdmin): ?>
        <div class="auth-role-notice">
            <span class="auth-notice-icon"><?= uiIcon('alert', 18) ?></span>
            <span><strong>Hệ thống chưa có Admin:</strong> Tài khoản đầu tiên đăng ký sẽ được tự động kích hoạt quyền <strong>Quản trị viên</strong>.</span>
        </div>
    <?php endif; ?>

    <?php foreach ($errors as $err): ?>
        <div class="flash error"><?= h($err) ?></div>
    <?php endforeach; ?>

    <form method="post" class="auth-form" autocomplete="on" id="registerForm">
        <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">

        <div class="form-group">
            <label for="name">
                <span>Họ và tên</span>
            </label>
            <div class="input-with-icon">
                <span class="input-icon-left"><?= uiIcon('user', 18) ?></span>
                <input type="text" id="name" name="name" value="<?= h(post('name')) ?>" 
                       placeholder="Nguyễn Văn A" maxlength="100" required autofocus>
            </div>
        </div>

        <div class="form-group">
            <label for="email">
                <span>Địa chỉ Gmail</span>
                <span class="auth-label-hint">Đuôi @gmail.com</span>
            </label>
            <div class="input-with-icon">
                <span class="input-icon-left"><?= uiIcon('mail', 18) ?></span>
                <input type="email" id="email" name="email" value="<?= h(post('email')) ?>" 
                       placeholder="tenban@gmail.com" 
                       pattern="[a-zA-Z0-9._%+\-]+@gmail\.com" 
                       title="Bắt buộc sử dụng đuôi @gmail.com" required>
            </div>
        </div>

        <div class="form-group">
            <label for="password">
                <span>Mật khẩu</span>
                <span class="auth-label-hint">Tối thiểu 6 ký tự</span>
            </label>
            <div class="input-with-icon">
                <span class="input-icon-left"><?= uiIcon('lock', 18) ?></span>
                <input type="password" id="password" name="password" minlength="6" placeholder="Tạo mật khẩu an toàn" required>
                <button type="button" class="btn-toggle-password" onclick="togglePassword('password', this)" title="Hiện/ẩn mật khẩu">
                    <span class="eye-open"><?= uiIcon('eye', 18) ?></span>
                    <span class="eye-closed" style="display:none;"><?= uiIcon('eye-off', 18) ?></span>
                </button>
            </div>
        </div>

        <div class="form-group">
            <label for="confirm_password">
                <span>Xác nhận mật khẩu</span>
            </label>
            <div class="input-with-icon">
                <span class="input-icon-left"><?= uiIcon('lock', 18) ?></span>
                <input type="password" id="confirm_password" name="confirm_password" minlength="6" placeholder="Nhập lại mật khẩu" required>
                <button type="button" class="btn-toggle-password" onclick="togglePassword('confirm_password', this)" title="Hiện/ẩn mật khẩu">
                    <span class="eye-open"><?= uiIcon('eye', 18) ?></span>
                    <span class="eye-closed" style="display:none;"><?= uiIcon('eye-off', 18) ?></span>
                </button>
            </div>
        </div>

        <button type="submit" class="btn-submit-auth">
            <span>Tạo tài khoản OpenShare</span>
            <span>→</span>
        </button>
    </form>

    <div class="auth-card-footer">
        <span>Đã có tài khoản?</span>
        <a href="/login.php">Đăng nhập ngay</a>
    </div>
</div>

<script>
function togglePassword(inputId, btn) {
    const input = document.getElementById(inputId);
    if (!input) return;
    const eyeOpen = btn.querySelector('.eye-open');
    const eyeClosed = btn.querySelector('.eye-closed');
    if (input.type === 'password') {
        input.type = 'text';
        if (eyeOpen && eyeClosed) {
            eyeOpen.style.display = 'none';
            eyeClosed.style.display = 'inline-flex';
        }
    } else {
        input.type = 'password';
        if (eyeOpen && eyeClosed) {
            eyeOpen.style.display = 'inline-flex';
            eyeClosed.style.display = 'none';
        }
    }
}
</script>

<?php layoutEnd(); ?>
