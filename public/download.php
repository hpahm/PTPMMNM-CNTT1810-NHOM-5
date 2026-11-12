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

$db = database();
if ($fileId !== '') {
    beginImmediate($db);
    try {
        $stmt = $db->prepare('SELECT * FROM files WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$fileId]);
        $candidate = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$candidate || !userCanManageFile($user, $candidate) || safeStoredPath($candidate) === null) {
            logActivity($db, 'download_denied', $user['id'] ?? 'guest', $fileId, false);
            $db->commit();
        } else {
            $file = $candidate;
            $db->prepare('UPDATE files SET download_count = download_count + 1 WHERE id = ?')->execute([$fileId]);
            logActivity($db, 'download', $user['id'] ?? 'guest', $fileId);
            $db->commit();
            $result = ['status' => 'granted'];
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
} elseif ($id !== '') {
    beginImmediate($db);
    try {
        $sStmt = $db->prepare('SELECT * FROM shares WHERE id = ? LIMIT 1');
        $sStmt->execute([$id]);
        $share = $sStmt->fetch(PDO::FETCH_ASSOC);
        $candidate = null;
        if ($share) {
            $fStmt = $db->prepare('SELECT * FROM files WHERE id = ? AND deleted_at IS NULL LIMIT 1');
            $fStmt->execute([$share['file_id']]);
            $candidate = $fStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$share || !$candidate) {
            logActivity($db, 'download_denied', $user['id'] ?? 'guest', $share['file_id'] ?? '', false);
            $db->commit();
        } else {
            $file = $candidate;
            $sameOwner = ($share['owner_id'] ?? '') === ($file['owner_id'] ?? '');
            $actor = $user ?: ['id' => $share['owner_id'], 'role' => 'recipient'];
            $allowedUser = (!$user && isPublicShare($share)) || ($user && userCanDownloadShare($user, $share));
            $safePath = safeStoredPath($file);
            $notExpiredOrRevoked = $share['revoked_at'] === null && strtotime((string)$share['expires_at']) > time() && (int)$share['downloads'] <= (int)$share['max_downloads'];
            $sessionAccess = $share['password_hash'] && ($_SESSION['preview_access'][$share['id']] ?? 0) > time();

            if (!$sameOwner || !$allowedUser || !$safePath || !$notExpiredOrRevoked || ($share['password_hash'] && !$sessionAccess && !password_verify($password, (string)$share['password_hash']))) {
                logActivity($db, 'download_denied', $actor['id'], $share['file_id'], false);
                $db->commit();
            } else {
                $result = requestDownloadSlotSql($db, $id, $actor['id'], time());
                if ($result['status'] === 'granted') {
                    $db->prepare('UPDATE files SET download_count = download_count + 1 WHERE id = ?')->execute([$share['file_id']]);
                    logActivity($db, 'download', $actor['id'], $share['file_id']);
                } elseif ($result['status'] === 'queued') {
                    logActivity($db, 'download_queued', $actor['id'], $share['file_id']);
                }
                $db->commit();
            }
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

if ($result['status'] === 'queued') {
    http_response_code(202);
    $pos = (int)$result['position'];
    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    if (str_contains($accept, 'text/html')) {
        echo '<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta http-equiv="refresh" content="3"><title>Đang xếp hàng tải · OpenShare</title><style>body{font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f8fafd;color:#1f1f1f}.box{background:#fff;border:1px solid #e0e4eb;border-radius:14px;padding:32px;max-width:420px;text-align:center;box-shadow:0 6px 24px rgba(0,0,0,.06)}h2{margin:0 0 8px}p{color:#5f6368;line-height:1.5}</style></head><body><div class="box"><h2>⏳ Đang xếp hàng tải xuống</h2><p>Liên kết đang ở lượt tải cuối. Vị trí hàng đợi FIFO của bạn là <strong>#' . $pos . '</strong>.</p><p>Trang sẽ tự động kiểm tra lại sau 3 giây...</p><p><a href="/">Quay lại Drive</a></p></div></body></html>';
        exit;
    }
    exit('Yêu cầu đã vào hàng đợi, vị trí số ' . $pos . '. Người yêu cầu sớm nhất được cấp lượt tải trước.');
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

streamFileResponse($path, (string)($file['mime'] ?: 'application/octet-stream'), basename((string)$file['original_name']), 'attachment');
