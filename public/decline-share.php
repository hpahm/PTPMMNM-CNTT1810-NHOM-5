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
$db = database();

beginImmediate($db);
try {
    $sStmt = $db->prepare('SELECT * FROM shares WHERE id = ? LIMIT 1');
    $sStmt->execute([$shareId]);
    $share = $sStmt->fetch(PDO::FETCH_ASSOC);
    if ($share && $share['recipient_id'] === $user['id'] && $share['owner_id'] !== $user['id']) {
        $db->prepare('UPDATE shares SET revoked_at = ? WHERE id = ?')->execute([date(DATE_ATOM), $shareId]);
        logActivity($db, 'share_declined', $user['id'], $share['file_id']);
        $declined = true;
    }
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}

if (!$declined) {
    http_response_code(403);
    exit('Yêu cầu từ chối không hợp lệ hoặc liên kết không tồn tại.');
}

flash('Bạn đã từ chối lời mời chia sẻ.');
redirect('/shared');
