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

if (!$file) {
    http_response_code(404);
    exit('Không tìm thấy tệp.');
}

$allowed = userCanManageFile($user, $file);
if (!$allowed && $shareId !== '') {
    $sStmt = $db->prepare('SELECT * FROM shares WHERE id = ? AND file_id = ? LIMIT 1');
    $sStmt->execute([$shareId, $fileId]);
    $share = $sStmt->fetch(PDO::FETCH_ASSOC);
    $allowed = $share
        && ((!$user && isPublicShare($share)) || ($user && userCanDownloadShare($user, $share)))
        && validShare($share, time())
        && (!$share['password_hash'] || ($_SESSION['preview_access'][$share['id']] ?? 0) > time());
}

$path = safeStoredPath($file);
if (!$allowed || $path === null) {
    http_response_code(403);
    exit('Bạn không có quyền xem trước tệp này hoặc liên kết không hợp lệ.');
}

streamFileResponse($path, (string)($file['mime'] ?: 'application/octet-stream'), basename((string)$file['original_name']), 'inline');
