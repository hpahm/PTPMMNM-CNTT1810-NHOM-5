<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
startApp();

$user = currentUser();
$data = readStore();
$fileId = get('file');
$shareId = get('share');
$file = $data['files'][$fileId] ?? null;
$share = $shareId !== '' ? ($data['shares'][$shareId] ?? null) : null;
$error = '';

if (!$file) {
    http_response_code(404);
    exit('Không tìm thấy tệp.');
}

if ($user && userCanManageFile($user, $file)) {
    $access = true;
} elseif (!$share || $share['file_id'] !== $fileId || (!$user && !isPublicShare($share)) || ($user && !userCanDownloadShare($user, $share)) || !validShare($share, time())) {
    $access = false;
    $error = 'Bạn không có quyền xem tệp này hoặc liên kết đã hết hiệu lực / hết lượt tải.';
} else {
    $access = !$share['password_hash'] || (($share['id'] ?? '') !== '' && ($_SESSION['preview_access'][$share['id']] ?? 0) > time());
    if (!$access && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        verifyCsrf();
        $access = password_verify(post('password'), $share['password_hash']);
        if ($access) {
            $_SESSION['preview_access'][$share['id']] = time() + 900;
        } else {
            $error = 'Mật khẩu truy cập không chính xác.';
        }
    }
    if (!$access && $error === '') {
        $error = 'Tệp này được bảo vệ bằng mật khẩu. Vui lòng nhập mật khẩu để xem trước.';
    }
}

if ((!$access && !($share && $share['password_hash'])) || safeStoredPath($file) === null) {
    http_response_code(403);
    exit($error !== '' ? $error : 'Tệp không còn tồn tại trên máy chủ.');
}

$ext = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION));
$previewable = in_array($ext, ['pdf', 'txt', 'jpg', 'jpeg', 'png'], true);
$pendingAcceptance = $user && $share && !isPublicShare($share) && $share['recipient_id'] === $user['id'] && !shareIsAccepted($share);
$downloadQuery = $share ? '?share=' . rawurlencode($share['id']) : '?file=' . rawurlencode($fileId);
$contentQuery = '?file=' . rawurlencode($fileId) . ($share ? '&share=' . rawurlencode($share['id']) : '');

layoutStart('Xem trước · ' . $file['original_name']);
?>

<div class="preview-workspace">
    <!-- Top Viewer Bar -->
    <div class="preview-header-bar">
        <div class="preview-file-info">
            <a href="/" class="btn-icon" title="Quay lại Drive">‹</a>
            <span class="file-badge badge-<?= h($ext) ?>"><?= h(strtoupper(substr($ext, 0, 4))) ?></span>
            <div class="preview-title-block">
                <span class="preview-filename" title="<?= h($file['original_name']) ?>"><?= h($file['original_name']) ?></span>
                <span class="preview-meta"><?= formatBytes((int)$file['size']) ?> · Tạo ngày <?= h(date('d/m/Y', strtotime($file['created_at']))) ?></span>
            </div>
        </div>

        <div class="preview-actions">
            <?php if ($access): ?>
                <a href="/download.php<?= $downloadQuery ?>" class="btn btn-primary btn-sm">
                    <span>⇩</span> Tải xuống
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Main Content Area -->
    <div class="preview-body">
        <?php if (!$access): ?>
            <!-- Password Dialog -->
            <div class="preview-locked-card card">
                <div class="lock-icon">🔒</div>
                <h3>Liên kết được bảo vệ</h3>
                <p class="text-muted"><?= h($error) ?></p>

                <form method="post" class="preview-auth-form">
                    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                    <div class="form-group">
                        <label for="password">Mật khẩu truy cập</label>
                        <input type="password" id="password" name="password" placeholder="Nhập mật khẩu" class="form-control" required autofocus>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Mở khóa xem tệp</button>
                </form>
            </div>
        <?php elseif ($previewable): ?>
            <!-- Interactive Iframe Previewer -->
            <div class="preview-frame-container">
                <iframe src="/preview-content.php<?= $contentQuery ?>" title="Bản xem trước <?= h($file['original_name']) ?>"></iframe>
            </div>
        <?php else: ?>
            <!-- Unsupported format (DOCX, ZIP) -->
            <div class="preview-unsupported-card card">
                <div class="unsupported-icon">📦</div>
                <h3>Không thể xem trước định dạng .<?= h(strtoupper($ext)) ?> trực tiếp</h3>
                <p class="text-muted">Định dạng này cần được tải về thiết bị để mở bằng ứng dụng tương thích.</p>
                <div class="action-wrap">
                    <a href="/download.php<?= $downloadQuery ?>" class="btn btn-primary">
                        <span>⇩</span> Tải xuống tệp ngay (<?= formatBytes((int)$file['size']) ?>)
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Banner xác nhận lưu vào "Được chia sẻ với tôi" -->
    <?php if ($pendingAcceptance): ?>
        <div class="preview-accept-banner">
            <div class="banner-text">
                <strong>Bạn muốn lưu tệp này vào Drive?</strong>
                <span>Xác nhận để tệp xuất hiện trong mục "Được chia sẻ với tôi" của bạn.</span>
            </div>
            <form method="post" action="/accept-share.php" class="banner-form">
                <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                <input type="hidden" name="share" value="<?= h($share['id']) ?>">
                <button type="submit" class="btn btn-primary btn-sm">✓ Xác nhận lưu</button>
                <a href="/shared.php" class="btn btn-outline btn-sm">Để sau</a>
            </form>
        </div>
    <?php endif; ?>
</div>

<?php layoutEnd(); ?>
