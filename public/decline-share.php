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
$shareId = post('share');
$declined = false;

withStore(function (array &$data) use ($shareId, $user, &$declined): void {
    $share = $data['shares'][$shareId] ?? null;
    if (!$share || $share['recipient_id'] !== $user['id'] || $share['owner_id'] === $user['id']) {
        return;
    }
    // Thu hồi liên kết riêng này
    $data['shares'][$shareId]['revoked_at'] = date(DATE_ATOM);
    logEvent($data, 'share_declined', $user['id'], $share['file_id']);
    $declined = true;
});

if (!$declined) {
    http_response_code(403);
    exit('Yêu cầu từ chối không hợp lệ hoặc liên kết không tồn tại.');
}

flash('Bạn đã từ chối lời mời chia sẻ.');
redirect('/shared.php');
