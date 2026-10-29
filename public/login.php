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
    if (isRateLimited('login', 5, 300)) {
        $remaining = getRateLimitRemainingSeconds('login', 300);
        $error = "Bạn đã đăng nhập sai quá nhiều lần. Vui lòng thử lại sau {$remaining} giây.";
    } else {
        $email = strtolower(post('email'));
        $password = post('password');
        $data = readStore();

        if (!isGmailAddress($email)) {
            $error = 'Chỉ chấp nhận tài khoản có đuôi @gmail.com.';
        } else {
            $loggedIn = false;
            foreach ($data['users'] as $user) {
                if ($user['email'] === $email && password_verify($password, $user['password'])) {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    clearRateLimit('login');
                    $loggedIn = true;
                    redirect('/');
                }
            }
            if (!$loggedIn) {
                recordFailedAttempt('login');
                $error = 'Email hoặc mật khẩu không chính xác.';
            }
        }
    }
}

layoutStart('Đăng nhập');
?>
<div class="auth-card">
    <div class="auth-card-header">
        <div class="auth-brand-badge">
            <svg viewBox="0 0 87.3 78">
                <path d="m6.6 66.85 3.85 6.65c.8 1.4 1.95 2.5 3.3 3.3l13.75-23.8h-27.5c0 1.55.4 3.1 1.2 4.5z" fill="#0066da"/>
                <path d="m43.65 25-13.75-23.8c-1.35.8-2.5 1.9-3.3 3.3l-25.4 44c-.8 1.4-1.2 2.95-1.2 4.5h27.5z" fill="#00ac47"/>
                <path d="m73.55 76.8c1.35-.8 2.5-1.9 3.3-3.3l1.6-2.75 7.65-13.25c.8-1.4 1.2-2.95 1.2-4.5h-27.502l5.852 11.5z" fill="#ea4335"/>
                <path d="m43.65 25 13.75-23.8c-1.35-.8-2.9-1.2-4.5-1.2h-18.5c-1.6 0-3.15.45-4.5 1.25z" fill="#00832d"/>
                <path d="m59.8 53h-32.3l-13.75 23.8c1.35.8 2.9 1.2 4.5 1.2h50.8c1.6 0 3.15-.45 4.5-1.25z" fill="#2684fc"/>
                <path d="m73.4 26.5-12.7-22c-.8-1.4-1.95-2.5-3.3-3.3l-13.75 23.8 16.15 28h27.45c0-1.55-.4-3.1-1.2-4.5z" fill="#ffba00"/>
            </svg>
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
                <span class="input-icon-left">✉️</span>
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
                <span class="input-icon-left">🔒</span>
                <input type="password" id="password" name="password" placeholder="Nhập mật khẩu của bạn" required>
                <button type="button" class="btn-toggle-password" onclick="togglePassword('password', this)" title="Hiện/ẩn mật khẩu">👁️</button>
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
    if (input.type === 'password') {
        input.type = 'text';
        btn.textContent = '🙈';
    } else {
        input.type = 'password';
        btn.textContent = '👁️';
    }
}
</script>

<?php layoutEnd(); ?>
