<?php
require __DIR__ . '/../src/bootstrap.php'; startApp(); $user = currentUser();
$data = readStore(); $fileId = get('file'); $shareId = get('share'); $file = $data['files'][$fileId] ?? null;
if (!$file) { http_response_code(404); exit('Không tìm thấy tệp.'); }
$allowed = userCanManageFile($user, $file);
if (!$allowed && $shareId !== '') {
    $share = $data['shares'][$shareId] ?? null;
    $allowed = $share && $share['file_id'] === $fileId && ((!$user && isPublicShare($share)) || ($user && userCanDownloadShare($user, $share))) && validShare($share, time()) && (!$share['password_hash'] || ($_SESSION['preview_access'][$share['id']] ?? 0) > time());
}
$path = safeStoredPath($file);
if (!$allowed || $path === null) { http_response_code(403); exit('Không có quyền xem tệp.'); }
header('Content-Type: '.($file['mime'] ?: 'application/octet-stream'));
header('Content-Length: '.filesize($path));
header('Content-Disposition: inline; filename="'.str_replace('"', '', basename((string)$file['original_name'])).'"');
readfile($path);
