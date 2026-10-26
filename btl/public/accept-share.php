<?php
require __DIR__ . '/../src/bootstrap.php';
startApp();
$user = requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Phương thức không được hỗ trợ.');
}
verifyCsrf();
$shareId = post('share');
$accepted = false;
withStore(function (array &$data) use ($shareId, $user, &$accepted): void {
    $share = $data['shares'][$shareId] ?? null;
    if (!$share || isPublicShare($share) || $share['recipient_id'] !== $user['id'] || !validShare($share, time())) return;
    $data['shares'][$shareId]['accepted'] = true;
    $data['shares'][$shareId]['accepted_at'] = date(DATE_ATOM);
    logEvent($data, 'share_accepted', $user['id'], $share['file_id']);
    $accepted = true;
});
if (!$accepted) {
    http_response_code(403);
    exit('Liên kết không hợp lệ hoặc đã hết hạn.');
}
flash('Đã lưu vào Tệp được chia sẻ.');
redirect('/shared.php');
