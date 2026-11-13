<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
startApp();

if (currentUser()) {
    redirect('/');
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verifyCsrf();
    $rateKey = 'login:' . clientIp();
    if (isRateLimited($rateKey, 5, 300)) {
        $remaining = getRateLimitRemainingSeconds($rateKey, 300);
        $error = "Bạn đã đăng nhập sai quá nhiều lần. Vui lòng thử lại sau {$remaining} giây.";
    } else {
        $email = strtolower(post('email'));
        $password = post('password');

        if (!isGmailAddress($email)) {
            $error = 'Chỉ chấp nhận tài khoản có đuôi @gmail.com.';
        } else {
            $user = findUserByEmailSql($email);
            if ($user && password_verify($password, (string)$user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                currentUser(true);
                clearRateLimit($rateKey);
                redirect('/');
            }
            recordFailedAttempt($rateKey);
            $error = 'Email hoặc mật khẩu không chính xác.';
        }
    }
}

layoutStart('Đăng nhập');
?>
<div class="auth-card">
    <div class="auth-card-header">
        <div class="auth-brand-badge">
            <?= brandLogoSvg(44, 44) ?>
        </div>
        <h1>Đăng nhập OpenShare</h1>
        <p class="auth-sub">Truy cập kho lưu trữ và chia sẻ tệp an toàn của bạn</p>
    </div>

    <?php if ($error !== ''): ?>
        <div class="flash error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" class="auth-form" autocomplete="on">
        <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
        
        <div class="form-group">
            <label for="email">
                <span>Địa chỉ Gmail</span>
                <span class="auth-label-hint">Đuôi @gmail.com</span>
            </label>
            <div class="input-with-icon">
                <span class="input-icon-left"><?= uiIcon('mail', 18) ?></span>
                <input type="email" id="email" name="email" value="<?= h(post('email')) ?>" 
                       placeholder="example@gmail.com" 
                       pattern="[a-zA-Z0-9._%+\-]+@gmail\.com" 
                       title="Tài khoản phải có đuôi @gmail.com" required autofocus>
            </div>
        </div>

        <div class="form-group">
            <label for="password">
                <span>Mật khẩu</span>
            </label>
            <div class="input-with-icon">
                <span class="input-icon-left"><?= uiIcon('lock', 18) ?></span>
                <input type="password" id="password" name="password" placeholder="Nhập mật khẩu của bạn" required>
                <button type="button" class="btn-toggle-password" onclick="togglePassword('password', this)" title="Hiện/ẩn mật khẩu">
                    <span class="eye-open"><?= uiIcon('eye', 18) ?></span>
                    <span class="eye-closed" style="display:none;"><?= uiIcon('eye-off', 18) ?></span>
                </button>
            </div>
        </div>

        <button type="submit" class="btn-submit-auth">
            <span>Đăng nhập vào OpenShare</span>
            <span>→</span>
        </button>
    </form>

    <div class="auth-card-footer">
        <span>Chưa có tài khoản?</span>
        <a href="/register.php">Đăng ký tài khoản mới</a>
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
