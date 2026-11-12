<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
startApp();

$user = requireLogin();
$db = database();
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verifyCsrf();
    $action = post('action');

    if ($action === 'update_profile') {
        $name = post('name');
        if ($name === '' || mb_strlen($name) > 100 || str_contains($name, "\0")) {
            $errors[] = 'Họ và tên không hợp lệ (1–100 ký tự).';
        } else {
            $db->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([$name, $user['id']]);
            currentUser(true);
            logActivity($db, 'profile_updated', $user['id']);
            flash('Đã cập nhật tên hiển thị thành công!');
            redirect('/profile.php');
        }
    } elseif ($action === 'change_password') {
        $currentPassword = post('current_password');
        $newPassword = post('new_password');
        $confirmPassword = post('confirm_password');

        $uStmt = $db->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
        $uStmt->execute([$user['id']]);
        $currentHash = (string)$uStmt->fetchColumn();

        if (!password_verify($currentPassword, $currentHash)) {
            $errors[] = 'Mật khẩu hiện tại không chính xác.';
        } elseif (strlen($newPassword) < 6) {
            $errors[] = 'Mật khẩu mới phải có ít nhất 6 ký tự.';
        } elseif ($newPassword !== $confirmPassword) {
            $errors[] = 'Mật khẩu xác nhận không khớp.';
        } else {
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $db->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([$newHash, $user['id']]);
            session_regenerate_id(true);
            logActivity($db, 'password_changed', $user['id']);
            flash('Đã đổi mật khẩu thành công!');
            redirect('/profile.php');
        }
    }
}

layoutStart('Hồ sơ & Mật khẩu');
?>

<div class="drive-container-narrow">
    <div class="page-header-bar">
        <div>
            <h2>Hồ sơ &amp; Bảo mật tài khoản</h2>
            <p class="text-muted">Quản lý thông tin hiển thị và mật khẩu đăng nhập của bạn</p>
        </div>
        <a href="/" class="btn btn-outline btn-sm">‹ Quay lại Drive</a>
    </div>

    <?php foreach ($errors as $err): ?>
        <div class="flash error"><?= h($err) ?></div>
    <?php endforeach; ?>

    <div class="card mb-4">
        <div class="card-header">
            <h3>Thông tin cá nhân</h3>
        </div>
        <form method="post" class="card-body">
            <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
            <input type="hidden" name="action" value="update_profile">
            <div class="form-group">
                <label>Email tài khoản</label>
                <input type="email" class="form-control" value="<?= h($user['email']) ?>" disabled>
            </div>
            <div class="form-group">
                <label for="profile-name">Họ và tên hiển thị</label>
                <input type="text" id="profile-name" name="name" class="form-control" value="<?= h($user['name']) ?>" maxlength="100" required>
            </div>
            <button type="submit" class="btn btn-primary">Lưu thông tin</button>
        </form>
    </div>

    <div class="card">
        <div class="card-header">
            <h3>Đổi mật khẩu</h3>
        </div>
        <form method="post" class="card-body">
            <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
            <input type="hidden" name="action" value="change_password">
            <div class="form-group">
                <label for="current-password">Mật khẩu hiện tại</label>
                <input type="password" id="current-password" name="current_password" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="new-password">Mật khẩu mới (tối thiểu 6 ký tự)</label>
                <input type="password" id="new-password" name="new_password" class="form-control" minlength="6" required>
            </div>
            <div class="form-group">
                <label for="confirm-password">Xác nhận mật khẩu mới</label>
                <input type="password" id="confirm-password" name="confirm_password" class="form-control" minlength="6" required>
            </div>
            <button type="submit" class="btn btn-primary">Cập nhật mật khẩu</button>
        </form>
    </div>
</div>

<?php layoutEnd(); ?>

