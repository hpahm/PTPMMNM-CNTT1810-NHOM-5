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
} elseif (userUsage(readStore(), $user['id']) + (int)$upload['size'] > (int)$user['quota']) {
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
 $savedFolderId = 'root-' . $user['id'];
withStore(function (array &$store) use ($id, $user, $upload, $stored, $folderId, &$savedFolderId): void {
    $defaultFolder = 'root-' . $user['id'];
    $targetFolder = isset($store['folders'][$folderId]) && $store['folders'][$folderId]['owner_id'] === $user['id']
        ? $folderId : $defaultFolder;
    $savedFolderId = $targetFolder;
    $store['files'][$id] = [
        'id' => $id, 'owner_id' => $user['id'], 'folder_id' => $targetFolder,
        'original_name' => basename((string)$upload['name']), 'stored_name' => basename($stored),
        'size' => (int)$upload['size'], 'mime' => mime_content_type($stored) ?: 'application/octet-stream',
        'created_at' => date(DATE_ATOM), 'download_count' => 0,
    ];
    logEvent($store, 'upload', $user['id'], $id);
});
echo json_encode(['ok' => true, 'folder_id' => $savedFolderId], JSON_UNESCAPED_UNICODE);
