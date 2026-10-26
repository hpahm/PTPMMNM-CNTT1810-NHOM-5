<?php
require __DIR__ . '/../src/bootstrap.php'; startApp(); $user = currentUser(); $id = get('share'); $fileId = get('file'); $password = get('password'); $result = ['status'=>'invalid']; $file = null;
if ($fileId !== '') {
    withStore(function(array &$data) use (&$result,&$file,$fileId,$user): void {
        $candidate = $data['files'][$fileId] ?? null;
        if (!$candidate || !userCanManageFile($user, $candidate) || safeStoredPath($candidate) === null) {
            logEvent($data, 'download_denied', $user['id'] ?? 'admin', $fileId, false);
            return;
        }
        $file = $candidate;
        $data['files'][$fileId]['download_count']++;
        logEvent($data, 'download', $user['id'], $fileId);
        $result = ['status'=>'granted'];
    });
} else withStore(function(array &$data) use (&$result,&$file,$id,$user,$password): void {
    $share = $data['shares'][$id] ?? null;
    if (!$share || !isset($data['files'][$share['file_id']])) {
        logEvent($data,'download_denied',$user['id'] ?? 'admin',$share['file_id']??'',false); return;
    }
    $file = $data['files'][$share['file_id']];
    $sameOwner = ($share['owner_id'] ?? '') === ($file['owner_id'] ?? '');
    $actor = $user ?: ['id' => $share['owner_id'], 'role' => 'recipient'];
    $allowedUser = (!$user && isPublicShare($share)) || ($user && userCanDownloadShare($user, $share));
    $safePath = safeStoredPath($file);
    $notExpiredOrRevoked = $share['revoked_at'] === null && strtotime($share['expires_at']) > time() && (int)$share['downloads'] <= (int)$share['max_downloads'];
    $sessionAccess = $share['password_hash'] && ($_SESSION['preview_access'][$share['id']] ?? 0) > time();
    if (!$sameOwner || !$allowedUser || !$safePath || !$notExpiredOrRevoked || ($share['password_hash'] && !$sessionAccess && !password_verify($password,$share['password_hash']))) {
        logEvent($data,'download_denied',$actor['id'],$share['file_id'],false); return;
    }
    $result = requestDownloadSlot($data['shares'][$id], $actor['id'], time());
    if ($result['status'] === 'granted') {
        $data['files'][$share['file_id']]['download_count']++;
        logEvent($data,'download',$actor['id'],$share['file_id']);
    } elseif ($result['status'] === 'queued') {
        logEvent($data,'download_queued',$actor['id'],$share['file_id']);
    }
});
if ($fileId !== '') {
    withStore(function(array &$data) use (&$result,&$file,$fileId,$user): void {
        if (!isset($data['files'][$fileId])) {
            logEvent($data,'download_denied',$user['id'] ?? 'admin',$fileId,false);
            return;
        }
        $candidate = $data['files'][$fileId];
        if (safeStoredPath($candidate) === null) {
            logEvent($data,'download_denied',$user['id'] ?? 'admin',$fileId,false);
            return;
        }
        $file = $candidate;
        $data['files'][$fileId]['download_count']++;
        logEvent($data,'download',$user['id'] ?? 'admin',$fileId);
        $result = ['status'=>'granted'];
    });
}
if ($result['status'] === 'queued') { http_response_code(202); exit('Yêu cầu đã vào hàng đợi, vị trí số '.(int)$result['position'].'. Người yêu cầu sớm nhất được cấp lượt tải trước.'); }
if ($result['status'] !== 'granted' || !$file) { http_response_code(403); exit('Liên kết không hợp lệ, đã hết hạn, hết lượt hoặc sai mật khẩu.'); }
$path = safeStoredPath($file); if ($path === null) { http_response_code(404); exit('Tệp không còn trên máy chủ.'); }
$downloadName = basename((string)$file['original_name']);
header('Content-Type: '.$file['mime']); header('Content-Length: '.filesize($path)); header('Content-Disposition: attachment; filename="'.str_replace('"','',$downloadName).'"'); readfile($path);
