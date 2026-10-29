<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
startApp();

$user = requireLogin();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    exit('Phương thức không được hỗ trợ.');
}

verifyCsrf();
$id = post('share');
$targetFileId = '';

withStore(function (array &$data) use ($id, $user, &$targetFileId): void {
    if (!isset($data['shares'][$id])) return;
    $share = &$data['shares'][$id];
    if ($user['role'] !== 'admin' && $share['owner_id'] !== $user['id']) return;
    $share['revoked_at'] = date(DATE_ATOM);
    $targetFileId = $share['file_id'];
    logEvent($data, 'share_revoked', $user['id'], $share['file_id']);
});

flash('Đã thu hồi liên kết chia sẻ thành công!');
if ($targetFileId !== '') {
    redirect('/share.php?file=' . rawurlencode($targetFileId));
} else {
    redirect('/');
}
