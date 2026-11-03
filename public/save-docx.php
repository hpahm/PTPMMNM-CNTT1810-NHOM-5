<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
startApp();
header('Content-Type: application/json; charset=utf-8');

$user = currentUser();
$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Dữ liệu yêu cầu không hợp lệ.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$csrfToken = is_string($payload['csrf'] ?? null) ? $payload['csrf'] : '';
if ($csrfToken === '' || !hash_equals((string)($_SESSION['csrf'] ?? ''), $csrfToken)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Yêu cầu không hợp lệ (Lỗi CSRF Token).'], JSON_UNESCAPED_UNICODE);
    exit;
}

$fileId = is_string($payload['file'] ?? null) ? trim($payload['file']) : '';
$content = is_string($payload['content'] ?? null) ? $payload['content'] : '';
$data = readStore();
$file = $data['files'][$fileId] ?? null;
$path = $file ? safeStoredPath($file) : null;

if (!$user || !$file || !$path || strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION)) !== 'docx' || !userCanManageFile($user, $file)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Bạn không có quyền chỉnh sửa tệp DOCX này.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    saveDocxContent($path, $content);
    $size = filesize($path);
    withStore(function (array &$store) use ($fileId, $user, $size): void {
        $store['files'][$fileId]['size'] = $size === false ? $store['files'][$fileId]['size'] : $size;
        logEvent($store, 'docx_edit', $user['id'], $fileId);
    });
    echo json_encode(['ok' => true, 'size' => $size], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $exception) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
}
