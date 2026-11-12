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
$db = database();
$fStmt = $db->prepare('SELECT * FROM files WHERE id = ? LIMIT 1');
$fStmt->execute([$fileId]);
$file = $fStmt->fetch(PDO::FETCH_ASSOC);
$path = $file ? safeStoredPath($file) : null;

if (!$user || !$file || !$path || strtolower(pathinfo((string)$file['original_name'], PATHINFO_EXTENSION)) !== 'docx' || !userCanManageFile($user, $file)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Bạn không có quyền chỉnh sửa tệp DOCX này.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    saveDocxContent($path, $content);
    $size = filesize($path);
    beginImmediate($db);
    try {
        if ($size !== false) {
            $db->prepare('UPDATE files SET size = ? WHERE id = ?')->execute([(int)$size, $fileId]);
        }
        logActivity($db, 'docx_edit', $user['id'], $fileId);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    echo json_encode(['ok' => true, 'size' => $size], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $exception) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
}
