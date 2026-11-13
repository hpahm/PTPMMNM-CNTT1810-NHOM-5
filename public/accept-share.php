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
$db = database();

beginImmediate($db);
try {
    $sStmt = $db->prepare('SELECT * FROM shares WHERE id = ? LIMIT 1');
    $sStmt->execute([$shareId]);
    $share = $sStmt->fetch(PDO::FETCH_ASSOC);
    if ($share && validShare($share, time())) {
        $isDirect = ($share['recipient_id'] === $user['id'] && $share['owner_id'] !== $user['id']);
        $isPublic = isPublicShare($share) && $share['owner_id'] !== $user['id'];
        if ($isDirect || $isPublic) {
            $accStmt = $db->prepare('SELECT 1 FROM share_acceptances WHERE share_id = ? AND user_id = ? LIMIT 1');
            $accStmt->execute([$shareId, $user['id']]);
            if (!$accStmt->fetchColumn()) {
                $db->prepare('INSERT INTO share_acceptances (share_id, user_id, accepted_at) VALUES (?, ?, ?)')
                   ->execute([$shareId, $user['id'], date(DATE_ATOM)]);
            }
            logActivity($db, 'share_accepted', $user['id'], $share['file_id']);
            $accepted = true;
        }
    }
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}

if (!$accepted) {
    http_response_code(403);
    exit('Liên kết không hợp lệ, đã hết hạn hoặc bạn không có quyền nhận tệp này.');
}

flash('Đã lưu tệp vào "Được chia sẻ với tôi" thành công!');
redirect('/shared');
