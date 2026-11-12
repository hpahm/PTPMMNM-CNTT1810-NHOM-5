<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
startApp();
header('Content-Type: application/json; charset=utf-8');

$user = currentUser();
if (!$user || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Yêu cầu không hợp lệ.'], JSON_UNESCAPED_UNICODE);
    exit;
}
verifyCsrf();
$upload = $_FILES['file'] ?? null;
$error = '';
if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $error = 'Chưa chọn tệp hoặc quá trình tải lên gặp sự cố.';
} elseif ((int)$upload['size'] <= 0 || (int)$upload['size'] > MAX_FILE_BYTES) {
    $error = 'Kích thước tệp không hợp lệ (tối đa 10 MB).';
} elseif (!allowedExtension((string)$upload['name'])) {
    $error = 'Loại tệp không được phép.';
} elseif (!diskHasSpace((int)$upload['size'])) {
    $error = 'Dung lượng ổ đĩa máy chủ không còn đủ.';
} elseif (userUsageBytes($user['id']) + (int)$upload['size'] > (int)$user['quota']) {
    $error = 'Tệp vượt quá hạn mức bộ nhớ của tài khoản.';
}
if ($error !== '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = bin2hex(random_bytes(8));
$stored = FILE_ROOT . '/' . $id . '.bin';
if (!move_uploaded_file($upload['tmp_name'], $stored)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Không thể lưu tệp trên máy chủ.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!detectedMimeIsAllowed((string)$upload['name'], $stored)) {
    unlink($stored);
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Nội dung tệp không khớp phần mở rộng.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$folderId = post('folder_id');
$defaultFolder = 'root-' . $user['id'];
$db = database();
$targetFolder = $defaultFolder;
if ($folderId !== '') {
    $fStmt = $db->prepare('SELECT id FROM folders WHERE id = ? AND owner_id = ? LIMIT 1');
    $fStmt->execute([$folderId, $user['id']]);
    if ($fStmt->fetchColumn()) {
        $targetFolder = $folderId;
    }
}
$mime = mime_content_type($stored) ?: 'application/octet-stream';
$fileHash = hash_file('sha256', $stored) ?: null;
$dupStmt = $db->prepare('SELECT original_name FROM files WHERE owner_id = ? AND file_hash = ? AND deleted_at IS NULL LIMIT 1');
$dupStmt->execute([$user['id'], $fileHash]);
$duplicateOf = $dupStmt->fetchColumn();

beginImmediate($db);
try {
    $db->prepare('INSERT INTO files (id, owner_id, folder_id, original_name, stored_name, size, mime, file_hash, created_at, download_count) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)')
       ->execute([$id, $user['id'], $targetFolder, basename((string)$upload['name']), basename($stored), (int)$upload['size'], $mime, $fileHash, date(DATE_ATOM)]);
    logActivity($db, 'upload', $user['id'], $id);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    @unlink($stored);
    throw $e;
}
echo json_encode([
    'ok' => true,
    'folder_id' => $targetFolder,
    'duplicate_of' => $duplicateOf !== false ? (string)$duplicateOf : null,
], JSON_UNESCAPED_UNICODE);
