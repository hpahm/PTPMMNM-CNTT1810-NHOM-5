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
$accepted = false;

withStore(function (array &$data) use ($shareId, $user, &$accepted): void {
    $share = $data['shares'][$shareId] ?? null;
    if (!$share || !validShare($share, time())) {
        return;
    }
    $isDirect = ($share['recipient_id'] === $user['id'] && $share['owner_id'] !== $user['id']);
    $isPublic = isPublicShare($share) && $share['owner_id'] !== $user['id'];
    if (!$isDirect && !$isPublic) {
        return;
    }
    if (!isset($data['shares'][$shareId]['acceptances'])) {
        $data['shares'][$shareId]['acceptances'] = [];
    }
    $data['shares'][$shareId]['acceptances'][$user['id']] = date(DATE_ATOM);
    $data['shares'][$shareId]['accepted'] = true;
    logEvent($data, 'share_accepted', $user['id'], $share['file_id']);
    $accepted = true;
});

if (!$accepted) {
    http_response_code(403);
    exit('Liên kết không hợp lệ, đã hết hạn hoặc bạn không có quyền nhận tệp này.');
}

flash('Đã lưu tệp vào "Được chia sẻ với tôi" thành công!');
redirect('/shared.php');
