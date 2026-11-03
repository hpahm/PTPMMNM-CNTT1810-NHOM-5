<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
startApp();
header('Content-Type: application/json; charset=utf-8');

$user = currentUser();
$payload = json_decode((string)file_get_contents('php://input'), true);
$fileId = is_array($payload) && is_string($payload['file'] ?? null) ? trim($payload['file']) : '';
$folderId = is_array($payload) && is_string($payload['folder'] ?? null) ? trim($payload['folder']) : '';
$csrfToken = is_array($payload) && is_string($payload['csrf'] ?? null) ? $payload['csrf'] : '';

if (!$user || $csrfToken === '' || !hash_equals((string)($_SESSION['csrf'] ?? ''), $csrfToken)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Yêu cầu không hợp lệ.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = withStore(function (array &$store) use ($user, $fileId, $folderId): array {
    $file = $store['files'][$fileId] ?? null;
    $folder = $store['folders'][$folderId] ?? null;
    if (!$file || !$folder || !userCanManageFile($user, $file) || $folder['owner_id'] !== $user['id']) {
        return ['ok' => false, 'error' => 'Bạn không có quyền di chuyển tệp vào thư mục này.'];
    }
    if ($file['owner_id'] !== $folder['owner_id']) {
        return ['ok' => false, 'error' => 'Tệp và thư mục phải thuộc cùng một tài khoản.'];
    }
    if (($file['folder_id'] ?? '') === $folderId) {
        return ['ok' => true, 'unchanged' => true];
    }
    $store['files'][$fileId]['folder_id'] = $folderId;
    logEvent($store, 'file_moved', $user['id'], $fileId);
    return ['ok' => true, 'unchanged' => false];
});

if (!$result['ok']) {
    http_response_code(403);
}
echo json_encode($result, JSON_UNESCAPED_UNICODE);
