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
$fStmt = $db->prepare('SELECT * FROM files WHERE id = ? AND deleted_at IS NULL LIMIT 1');
$fStmt->execute([$fileId]);
$file = $fStmt->fetch(PDO::FETCH_ASSOC);
$path = $file ? safeStoredPath($file) : null;
$ext = $file ? strtolower(pathinfo((string)$file['original_name'], PATHINFO_EXTENSION)) : '';

if (!$user || !$file || !$path || !in_array($ext, TEXT_EDITABLE_EXTENSIONS, true) || !userCanManageFile($user, $file)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Bạn không có quyền chỉnh sửa tệp văn bản này.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$newBytes = strlen($content);
if ($newBytes > MAX_FILE_BYTES) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Nội dung vượt quá giới hạn 10 MB.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ownerQuotaStmt = $db->prepare('SELECT quota FROM users WHERE id = ? LIMIT 1');
$ownerQuotaStmt->execute([$file['owner_id']]);
$ownerQuota = (int)$ownerQuotaStmt->fetchColumn();
$delta = $newBytes - (int)$file['size'];
if ($delta > 0 && (userUsageBytes($file['owner_id']) + $delta) > $ownerQuota) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Tệp sau khi sửa vượt quá quota bộ nhớ.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (file_put_contents($path, $content, LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Không thể lưu nội dung tệp.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$newHash = hash_file('sha256', $path) ?: null;
beginImmediate($db);
try {
    $db->prepare('UPDATE files SET size = ?, file_hash = ? WHERE id = ?')->execute([$newBytes, $newHash, $fileId]);
    logActivity($db, 'text_edit', $user['id'], $fileId);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}

echo json_encode(['ok' => true, 'size' => $newBytes, 'formatted_size' => formatBytes($newBytes)], JSON_UNESCAPED_UNICODE);

