<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
startApp();

$user = currentUser();
$db = database();
$fileId = get('file');
$shareId = get('share');
$fStmt = $db->prepare('SELECT * FROM files WHERE id = ? AND deleted_at IS NULL LIMIT 1');
$fStmt->execute([$fileId]);
$file = $fStmt->fetch(PDO::FETCH_ASSOC);
$share = null;
if ($shareId !== '') {
    $sStmt = $db->prepare('SELECT * FROM shares WHERE id = ? LIMIT 1');
    $sStmt->execute([$shareId]);
    $share = $sStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
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
    $rateKey = 'share_pw:' . clientIp() . ':' . ($share['id'] ?? '');
    $access = !$share['password_hash'] || (($share['id'] ?? '') !== '' && ($_SESSION['preview_access'][$share['id']] ?? 0) > time());
    if (!$access && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        verifyCsrf();
        if (isRateLimited($rateKey, 5, 300)) {
            $rem = getRateLimitRemainingSeconds($rateKey, 300);
            $error = "Bạn đã nhập sai mật khẩu quá nhiều lần. Vui lòng thử lại sau {$rem} giây.";
        } else {
            $access = password_verify(post('password'), (string)$share['password_hash']);
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
    logActivity($db, 'share_view', $user['id'], $share['file_id']);
}

$ext = strtolower(pathinfo((string)$file['original_name'], PATHINFO_EXTENSION));
$previewable = in_array($ext, ['pdf', 'txt', 'md', 'csv', 'json', 'xml', 'log', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'mp3', 'wav', 'mp4', 'webm'], true);
$isTextEditable = in_array($ext, TEXT_EDITABLE_EXTENSIONS, true);
$textContent = '';
$docxHtml = '';
$docxError = '';
if ($access && $isTextEditable) {
    $rawText = @file_get_contents((string)safeStoredPath($file));
    $textContent = $rawText !== false ? $rawText : '';
}
if ($access && $ext === 'docx') {
    try {
        $docxHtml = readDocxHtml((string)safeStoredPath($file));
    } catch (RuntimeException $exception) {
        $docxError = $exception->getMessage();
    }
}
$canEditText = $access && $isTextEditable && $user && userCanManageFile($user, $file);
$canEditDocx = $access && $ext === 'docx' && $user && userCanManageFile($user, $file) && $docxError === '';
$pendingAcceptance = false;
if ($user && $share && $share['owner_id'] !== $user['id']) {
    $accStmt = $db->prepare('SELECT 1 FROM share_acceptances WHERE share_id = ? AND user_id = ? LIMIT 1');
    $accStmt->execute([$share['id'], $user['id']]);
    $pendingAcceptance = !$accStmt->fetchColumn();
}
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
                <?php if ($canEditDocx || $canEditText): ?>
                    <button type="button" class="btn-download-action docx-save-button" id="doc-save-button">Lưu thay đổi</button>
                <?php endif; ?>
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
                    <?= brandLogoSvg(24, 24) ?>
                </div>
                <div class="banner-text">
                    <strong>Bạn muốn lưu tệp này vào Drive?</strong>
                    <span>Xác nhận để tệp xuất hiện trong mục <em>"Được chia sẻ với tôi"</em> của bạn.</span>
                </div>
            </div>
            <form method="post" action="/accept-share.php" class="banner-form">
                <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                <input type="hidden" name="share" value="<?= h($share['id']) ?>">
                <button type="submit" class="btn-save-confirm">Xác nhận lưu</button>
                <a href="/shared.php" class="btn-save-dismiss">Để sau</a>
            </form>
        </div>
    <?php endif; ?>

    <!-- Main Content Area -->
    <div class="preview-body">
        <?php if (!$access): ?>
            <!-- Password Dialog -->
            <div class="preview-locked-card card">
                <div class="lock-icon"><?= uiIcon('lock', 36) ?></div>
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
        <?php elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'], true)): ?>
            <!-- Modern Responsive Image Stage -->
            <div class="preview-stage image-stage">
                <img src="/preview-content.php<?= $contentQuery ?>" 
                     alt="<?= h($file['original_name']) ?>" 
                     class="preview-image-element"
                     loading="eager">
            </div>
        <?php elseif (in_array($ext, ['mp4', 'webm'], true)): ?>
            <div class="preview-stage image-stage">
                <video controls style="max-width:100%;max-height:78vh;border-radius:10px;" src="/preview-content.php<?= $contentQuery ?>"></video>
            </div>
        <?php elseif (in_array($ext, ['mp3', 'wav'], true)): ?>
            <div class="preview-stage image-stage">
                <audio controls style="width:min(520px,90%);" src="/preview-content.php<?= $contentQuery ?>"></audio>
            </div>
        <?php elseif ($ext === 'pdf'): ?>
            <!-- PDF Viewer Stage -->
            <div class="preview-stage pdf-stage">
                <iframe src="/preview-content.php<?= $contentQuery ?>" title="Bản xem trước <?= h($file['original_name']) ?>"></iframe>
            </div>
        <?php elseif ($isTextEditable): ?>
            <div class="preview-stage docx-stage">
                <div class="docx-toolbar">
                    <span><?= $canEditText ? 'Chỉnh sửa trực tiếp tệp văn bản (Ctrl+S để lưu nhanh).' : 'Chế độ chỉ xem.' ?></span>
                    <span id="doc-save-status" role="status"></span>
                </div>
                <textarea
                    id="text-editor"
                    data-file="<?= h($fileId) ?>"
                    data-csrf="<?= h(csrf()) ?>"
                    <?= $canEditText ? '' : 'readonly' ?>
                    spellcheck="false"
                    style="width:100%;min-height:70vh;padding:18px;border:1px solid #dadce0;border-radius:10px;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:14px;line-height:1.6;resize:vertical;background:#fff;color:#1f1f1f;"><?= h($textContent) ?></textarea>
            </div>
        <?php elseif ($ext === 'docx' && $docxError === ''): ?>
            <div class="preview-stage docx-stage">
                <div class="docx-toolbar">
                    <span><?= $canEditDocx ? 'Bạn có thể chỉnh sửa trực tiếp nội dung tài liệu (Ctrl+S để lưu nhanh).' : 'Chế độ chỉ xem.' ?></span>
                    <span id="doc-save-status" role="status"></span>
                </div>
                <article
                    class="docx-editor"
                    id="docx-editor"
                    data-file="<?= h($fileId) ?>"
                    data-share="<?= h($shareId) ?>"
                    data-csrf="<?= h(csrf()) ?>"
                    contenteditable="<?= $canEditDocx ? 'true' : 'false' ?>"
                    spellcheck="true"><?= $docxHtml ?></article>
            </div>
        <?php else: ?>
            <!-- Unsupported format (ZIP or unavailable DOCX processor) -->
            <div class="preview-unsupported-card card">
                <div class="unsupported-icon">📦</div>
                <h3>Không thể xem trước định dạng .<?= h(strtoupper($ext)) ?> trực tiếp</h3>
                <p class="text-muted"><?= $docxError !== '' ? h($docxError) : 'Định dạng này cần được tải về thiết bị để mở bằng ứng dụng tương thích.' ?></p>
                <div class="action-wrap">
                    <a href="/download.php<?= $downloadQuery ?>" class="btn btn-primary">
                        <span>⇩</span> Tải xuống tệp ngay (<?= formatBytes((int)$file['size']) ?>)
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($canEditDocx || $canEditText): ?>
<script>
(() => {
    const docxEditor = document.getElementById('docx-editor');
    const textEditor = document.getElementById('text-editor');
    const saveButton = document.getElementById('doc-save-button');
    const status = document.getElementById('doc-save-status');
    const editor = docxEditor || textEditor;
    if (!editor || !saveButton) return;

    const endpoint = docxEditor ? '/save-docx.php' : '/save-text.php';
    const getContent = () => docxEditor ? docxEditor.innerText : textEditor.value;

    const markDirty = () => {
        if (status && status.textContent !== 'Chưa lưu*') {
            status.textContent = 'Chưa lưu*';
        }
    };
    editor.addEventListener('input', markDirty);

    const doSave = async () => {
        saveButton.disabled = true;
        if (status) status.textContent = 'Đang lưu...';
        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                body: JSON.stringify({
                    file: editor.dataset.file,
                    share: editor.dataset.share || '',
                    csrf: editor.dataset.csrf,
                    content: getContent()
                })
            });
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.error || 'Không thể lưu tài liệu.');
            if (status) status.textContent = '✓ Đã lưu';
            setTimeout(() => { if (status && status.textContent === '✓ Đã lưu') status.textContent = ''; }, 2500);
        } catch (error) {
            if (status) status.textContent = error.message || 'Lỗi khi lưu';
        } finally {
            saveButton.disabled = false;
        }
    };

    saveButton.addEventListener('click', doSave);
    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
            e.preventDefault();
            doSave();
        }
    });
})();
</script>
<?php endif; ?>

<?php layoutEnd(); ?>
