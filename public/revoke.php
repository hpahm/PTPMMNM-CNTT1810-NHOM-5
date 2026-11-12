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
$db = database();

beginImmediate($db);
try {
    $sStmt = $db->prepare('SELECT * FROM shares WHERE id = ? LIMIT 1');
    $sStmt->execute([$id]);
    $share = $sStmt->fetch(PDO::FETCH_ASSOC);
    if ($share && ($user['role'] === 'admin' || $share['owner_id'] === $user['id'])) {
        $db->prepare('UPDATE shares SET revoked_at = ? WHERE id = ?')->execute([date(DATE_ATOM), $id]);
        $targetFileId = $share['file_id'];
        logActivity($db, 'share_revoked', $user['id'], $share['file_id']);
    }
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}

flash('Đã thu hồi liên kết chia sẻ thành công!');
if ($targetFileId !== '') {
    redirect('/share.php?file=' . rawurlencode($targetFileId));
} else {
    redirect('/');
}
