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
    $rateKey = 'share_pw_' . ($share['id'] ?? '');
    $access = !$share['password_hash'] || (($share['id'] ?? '') !== '' && ($_SESSION['preview_access'][$share['id']] ?? 0) > time());
    if (!$access && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        verifyCsrf();
        if (isRateLimited($rateKey, 5, 300)) {
            $rem = getRateLimitRemainingSeconds($rateKey, 300);
            $error = "Bạn đã nhập sai mật khẩu quá nhiều lần. Vui lòng thử lại sau {$rem} giây.";
        } else {
            $access = password_verify(post('password'), $share['password_hash']);
            if ($access) {
                $_SESSION['preview_access'][$share['id']] = time() + 900;
                clearRateLimit($rateKey);
            } else {
                recordFailedAttempt($rateKey);
                $error = 'Mật khẩu truy cập không chính xác.';
            }
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

if ($access && $user && $share && $share['owner_id'] !== $user['id']) {
    withStore(function (array &$store) use ($user, $share): void {
        logEvent($store, 'share_view', $user['id'], $share['file_id']);
    });
}

$ext = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION));
$previewable = in_array($ext, ['pdf', 'txt', 'jpg', 'jpeg', 'png'], true);
$pendingAcceptance = $user && $share && $share['owner_id'] !== $user['id'] && !isShareAcceptedByUser($share, $user['id']);
$downloadQuery = $share ? '?share=' . rawurlencode($share['id']) : '?file=' . rawurlencode($fileId);
$contentQuery = '?file=' . rawurlencode($fileId) . ($share ? '&share=' . rawurlencode($share['id']) : '');

layoutStart('Xem trước · ' . $file['original_name']);
?>

<div class="preview-workspace">
    <!-- Top Viewer Bar -->
    <div class="preview-header-bar">
        <div class="preview-file-info">
            <a href="/" class="btn-back-nav" title="Quay lại Drive">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor">
                    <path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/>
                </svg>
            </a>
            <span class="file-badge badge-<?= h($ext) ?>"><?= h(strtoupper(substr($ext, 0, 4))) ?></span>
            <div class="preview-title-block">
                <span class="preview-filename" title="<?= h($file['original_name']) ?>"><?= h($file['original_name']) ?></span>
                <span class="preview-meta"><?= formatBytes((int)$file['size']) ?> · Tạo ngày <?= h(date('d/m/Y', strtotime($file['created_at']))) ?></span>
            </div>
        </div>

        <div class="preview-actions">
            <?php if ($access): ?>
                <a href="/download.php<?= $downloadQuery ?>" class="btn-download-action" title="Tải tệp về máy">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor">
                        <path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM17 13l-5 5-5-5h3V9h4v4h3z"/>
                    </svg>
                    <span>Tải xuống</span>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Banner xác nhận lưu vào "Được chia sẻ với tôi" (đặt phía trên, không đè lên nội dung xem) -->
    <?php if ($pendingAcceptance): ?>
        <div class="preview-accept-banner">
            <div class="banner-left">
                <div class="banner-icon-badge">
                    <svg viewBox="0 0 87.3 78" width="22" height="20">
                        <path d="m6.6 66.85 3.85 6.65c.8 1.4 1.95 2.5 3.3 3.3l13.75-23.8h-27.5c0 1.55.4 3.1 1.2 4.5z" fill="#0066da"/>
                        <path d="m43.65 25-13.75-23.8c-1.35.8-2.5 1.9-3.3 3.3l-25.4 44c-.8 1.4-1.2 2.95-1.2 4.5h27.5z" fill="#00ac47"/>
                        <path d="m73.55 76.8c1.35-.8 2.5-1.9 3.3-3.3l1.6-2.75 7.65-13.25c.8-1.4 1.2-2.95 1.2-4.5h-27.502l5.852 11.5z" fill="#ea4335"/>
                        <path d="m43.65 25 13.75-23.8c-1.35-.8-2.9-1.2-4.5-1.2h-18.5c-1.6 0-3.15.45-4.5 1.25z" fill="#00832d"/>
                        <path d="m59.8 53h-32.3l-13.75 23.8c1.35.8 2.9 1.2 4.5 1.2h50.8c1.6 0 3.15-.45 4.5-1.25z" fill="#2684fc"/>
                        <path d="m73.4 26.5-12.7-22c-.8-1.4-1.95-2.5-3.3-3.3l-13.75 23.8 16.15 28h27.45c0-1.55-.4-3.1-1.2-4.5z" fill="#ffba00"/>
                    </svg>
                </div>
                <div class="banner-text">
                    <strong>Bạn muốn lưu tệp này vào Drive?</strong>
                    <span>Xác nhận để tệp xuất hiện trong mục <em>"Được chia sẻ với tôi"</em> của bạn.</span>
                </div>
            </div>
            <form method="post" action="/accept-share.php" class="banner-form">
                <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                <input type="hidden" name="share" value="<?= h($share['id']) ?>">
                <button type="submit" class="btn-save-confirm">✓ Xác nhận lưu</button>
                <a href="/shared.php" class="btn-save-dismiss">Để sau</a>
            </form>
        </div>
    <?php endif; ?>

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
        <?php elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)): ?>
            <!-- Modern Responsive Image Stage -->
            <div class="preview-stage image-stage">
                <img src="/preview-content.php<?= $contentQuery ?>" 
                     alt="<?= h($file['original_name']) ?>" 
                     class="preview-image-element"
                     loading="eager">
            </div>
        <?php elseif ($ext === 'pdf'): ?>
            <!-- PDF Viewer Stage -->
            <div class="preview-stage pdf-stage">
                <iframe src="/preview-content.php<?= $contentQuery ?>" title="Bản xem trước <?= h($file['original_name']) ?>"></iframe>
            </div>
        <?php elseif ($ext === 'txt'): ?>
            <!-- Text Viewer Stage -->
            <div class="preview-stage text-stage">
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
</div>

<?php layoutEnd(); ?>
