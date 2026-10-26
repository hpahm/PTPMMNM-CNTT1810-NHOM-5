<?php
require __DIR__ . '/../src/bootstrap.php'; startApp(); $user = currentUser();
$data = readStore(); $fileId = get('file'); $shareId = get('share'); $file = $data['files'][$fileId] ?? null; $share = $shareId !== '' ? ($data['shares'][$shareId] ?? null) : null; $error = '';

if (!$file) {
    http_response_code(404); exit('Không tìm thấy tệp.');
}
if ($user && userCanManageFile($user, $file)) {
    $access = true;
} elseif (!$share || $share['file_id'] !== $fileId || (!$user && !isPublicShare($share)) || ($user && !userCanDownloadShare($user, $share)) || !validShare($share, time())) {
    $access = false;
    $error = 'Bạn không có quyền xem tệp này hoặc liên kết đã hết hiệu lực.';
} else {
    $access = !$share['password_hash'] || (($share['id'] ?? '') !== '' && ($_SESSION['preview_access'][$share['id']] ?? 0) > time());
    if (!$access && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        $access = password_verify(post('password'), $share['password_hash']);
        if ($access) $_SESSION['preview_access'][$share['id']] = time() + 900;
    }
    if (!$access) $error = 'Nhập mật khẩu liên kết để xem trước tệp.';
}
if ((!$access && !($share && $share['password_hash'])) || safeStoredPath($file) === null) {
    http_response_code(403); exit($error !== '' ? $error : 'Tệp không còn trên máy chủ.');
}

$extension = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION));
$previewable = in_array($extension, ['pdf', 'txt', 'jpg', 'jpeg', 'png'], true);
$pendingAcceptance = $user && $share && !isPublicShare($share) && $share['recipient_id'] === $user['id'] && !shareIsAccepted($share);
$downloadQuery = $share ? '?share='.rawurlencode($share['id']) : '?file='.rawurlencode($fileId);
$contentQuery = '?file='.rawurlencode($fileId).($share ? '&share='.rawurlencode($share['id']) : '');
layoutStart('Xem trước · '.$file['original_name']);
echo '<div class="preview-shell"><div class="preview-toolbar"><a class="button secondary" href="/">← Quay lại</a><div class="preview-title"><span class="file-icon '.h($extension).'">'.h(strtoupper(substr($extension, 0, 1))).'</span><strong>'.h($file['original_name']).'</strong><small>'.number_format((int)$file['size']).' bytes</small></div>'.($access ? '<a class="button" href="/download.php'.$downloadQuery.'">⇩ Tải xuống</a>' : '').'</div>';
if (!$access) {
    echo '<section class="panel"><h2>Nhập mật khẩu liên kết</h2><p class="muted">'.h($error).'</p><form method="post"><input type="hidden" name="csrf" value="'.h(csrf()).'"><label>Mật khẩu<input type="password" name="password" required autofocus></label><button>Xác nhận</button></form></section>';
} elseif ($previewable) {
    echo '<div class="preview-frame"><iframe src="/preview-content.php'.$contentQuery.'" title="Bản xem trước '.h($file['original_name']).'"></iframe></div>';
} else {
    echo '<div class="preview-empty"><div class="empty-icon">□</div><h2>Chưa hỗ trợ xem trước định dạng này</h2><p>Định dạng '.h(strtoupper($extension)).' không thể hiển thị trực tiếp trên trình duyệt.</p><a class="button" href="/download.php'.$downloadQuery.'">⇩ Tải xuống tệp</a></div>';
}
if ($pendingAcceptance) {
    echo '<section class="panel share-confirm"><h2>Lưu vào Tệp được chia sẻ?</h2><p class="muted">Bạn đã xem trước file. Xác nhận để lưu file này vào khu vực Được chia sẻ của bạn.</p><form method="post" action="/accept-share.php"><input type="hidden" name="csrf" value="'.h(csrf()).'"><input type="hidden" name="share" value="'.h($share['id']).'"><button>✓ Xác nhận lưu</button><a class="button secondary" href="/shared.php">Để sau</a></form></section>';
}
echo '</div>';
layoutEnd();
