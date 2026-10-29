<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
startApp();

$user = currentUser();
$id = get('share');
$fileId = get('file');
$password = get('password');
$result = ['status' => 'invalid'];
$file = null;

if ($fileId !== '') {
    // Tải trực tiếp tệp bởi chủ sở hữu hoặc quản trị viên
    withStore(function (array &$data) use (&$result, &$file, $fileId, $user): void {
        $candidate = $data['files'][$fileId] ?? null;
        if (!$candidate || !userCanManageFile($user, $candidate) || safeStoredPath($candidate) === null) {
            logEvent($data, 'download_denied', $user['id'] ?? 'guest', $fileId, false);
            return;
        }
        $file = $candidate;
        $data['files'][$fileId]['download_count']++;
        logEvent($data, 'download', $user['id'] ?? 'guest', $fileId);
        $result = ['status' => 'granted'];
    });
} elseif ($id !== '') {
    // Tải qua liên kết chia sẻ
    withStore(function (array &$data) use (&$result, &$file, $id, $user, $password): void {
        $share = $data['shares'][$id] ?? null;
        if (!$share || !isset($data['files'][$share['file_id']])) {
            logEvent($data, 'download_denied', $user['id'] ?? 'guest', $share['file_id'] ?? '', false);
            return;
        }
        $file = $data['files'][$share['file_id']];
        $sameOwner = ($share['owner_id'] ?? '') === ($file['owner_id'] ?? '');
        $actor = $user ?: ['id' => $share['owner_id'], 'role' => 'recipient'];
        $allowedUser = (!$user && isPublicShare($share)) || ($user && userCanDownloadShare($user, $share));
        $safePath = safeStoredPath($file);
        $notExpiredOrRevoked = $share['revoked_at'] === null && strtotime($share['expires_at']) > time() && (int)$share['downloads'] <= (int)$share['max_downloads'];
        $sessionAccess = $share['password_hash'] && ($_SESSION['preview_access'][$share['id']] ?? 0) > time();
        
        if (!$sameOwner || !$allowedUser || !$safePath || !$notExpiredOrRevoked || ($share['password_hash'] && !$sessionAccess && !password_verify($password, $share['password_hash']))) {
            logEvent($data, 'download_denied', $actor['id'], $share['file_id'], false);
            return;
        }

        $result = requestDownloadSlot($data['shares'][$id], $actor['id'], time());
        if ($result['status'] === 'granted') {
            $data['files'][$share['file_id']]['download_count']++;
            logEvent($data, 'download', $actor['id'], $share['file_id']);
        } elseif ($result['status'] === 'queued') {
            logEvent($data, 'download_queued', $actor['id'], $share['file_id']);
        }
    });
}

if ($result['status'] === 'queued') {
    http_response_code(202);
    exit('Yêu cầu đã vào hàng đợi, vị trí số ' . (int)$result['position'] . '. Người yêu cầu sớm nhất được cấp lượt tải trước.');
}

if ($result['status'] !== 'granted' || !$file) {
    http_response_code(403);
    exit('Liên kết không hợp lệ, đã hết hạn, hết lượt hoặc sai mật khẩu.');
}

$path = safeStoredPath($file);
if ($path === null) {
    http_response_code(404);
    exit('Tệp không còn trên máy chủ.');
}

$downloadName = basename((string)$file['original_name']);
$cleanAsciiName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $downloadName);

header('Content-Type: ' . ($file['mime'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . $cleanAsciiName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
readfile($path);
exit;
