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

$uploads = $_FILES['files'] ?? null;
$paths = $_POST['paths'] ?? [];
$files = is_array($uploads) && isset($uploads['name'], $uploads['tmp_name'], $uploads['size'], $uploads['error'])
    ? $uploads : null;
if (!$files || !is_array($paths) || count($paths) === 0 || count($paths) !== count($files['name'])) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Không nhận được cấu trúc thư mục hợp lệ.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$totalSize = 0;
$normalizedPaths = [];
foreach ($paths as $index => $rawPath) {
    $name = is_string($files['name'][$index] ?? null) ? $files['name'][$index] : '';
    $relativePath = is_string($rawPath) ? str_replace('\\', '/', trim($rawPath)) : '';
    $segments = array_values(array_filter(explode('/', $relativePath), static fn(string $segment): bool => $segment !== ''));
    if (count($segments) < 2 || in_array('.', $segments, true) || in_array('..', $segments, true)
        || $segments[count($segments) - 1] !== basename($name)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Đường dẫn trong thư mục không hợp lệ.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (($files['error'][$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || (int)$files['size'][$index] <= 0
        || (int)$files['size'][$index] > MAX_FILE_BYTES
        || !allowedExtension($name)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Thư mục chứa tệp không hợp lệ hoặc vượt giới hạn 10 MB mỗi tệp.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $totalSize += (int)$files['size'][$index];
    $normalizedPaths[$index] = $segments;
}

if (!diskHasSpace($totalSize) || userUsageBytes($user['id']) + $totalSize > (int)$user['quota']) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Thư mục vượt quá dung lượng ổ đĩa hoặc quota tài khoản.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$storedFiles = [];
$rootFolderId = null;
$db = database();
try {
    foreach ($normalizedPaths as $index => $segments) {
        $stored = FILE_ROOT . DIRECTORY_SEPARATOR . bin2hex(random_bytes(8)) . '.bin';
        if (!move_uploaded_file($files['tmp_name'][$index], $stored)) {
            throw new RuntimeException('Không thể lưu tệp "' . basename($files['name'][$index]) . '".');
        }
        if (!detectedMimeIsAllowed($files['name'][$index], $stored)) {
            unlink($stored);
            throw new RuntimeException('Nội dung tệp "' . basename($files['name'][$index]) . '" không khớp phần mở rộng.');
        }
        $storedFiles[$index] = [
            'path' => $stored,
            'name' => basename($files['name'][$index]),
            'size' => (int)$files['size'][$index],
            'segments' => $segments,
        ];
    }

    $parentId = post('folder_id');
    $pCheck = $db->prepare('SELECT id FROM folders WHERE id = ? AND owner_id = ? LIMIT 1');
    $pCheck->execute([$parentId, $user['id']]);
    $parentId = $pCheck->fetchColumn() ? $parentId : 'root-' . $user['id'];

    beginImmediate($db);
    try {
        $folderIds = [$parentId => $parentId];
        $findFolderStmt = $db->prepare('SELECT id FROM folders WHERE owner_id = ? AND parent_id = ? AND LOWER(name) = LOWER(?) LIMIT 1');
        $insertFolderStmt = $db->prepare('INSERT INTO folders (id, owner_id, name, parent_id, created_at) VALUES (?, ?, ?, ?, ?)');
        $insertFileStmt = $db->prepare('INSERT INTO files (id, owner_id, folder_id, original_name, stored_name, size, mime, file_hash, created_at, download_count) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)');

        foreach ($storedFiles as $storedFile) {
            $currentParent = $parentId;
            $folderPath = [];
            foreach (array_slice($storedFile['segments'], 0, -1) as $segment) {
                $folderPath[] = $segment;
                $key = $currentParent . '/' . implode('/', $folderPath);
                if (!isset($folderIds[$key])) {
                    $findFolderStmt->execute([$user['id'], $currentParent, $segment]);
                    $existingId = $findFolderStmt->fetchColumn();
                    if ($existingId === false) {
                        $existingId = bin2hex(random_bytes(8));
                        $insertFolderStmt->execute([$existingId, $user['id'], $segment, $currentParent, date(DATE_ATOM)]);
                        logActivity($db, 'folder_created', $user['id']);
                    }
                    $folderIds[$key] = (string)$existingId;
                }
                $currentParent = $folderIds[$key];
                if ($rootFolderId === null) {
                    $rootFolderId = $currentParent;
                }
            }
            $id = bin2hex(random_bytes(8));
            $mime = mime_content_type($storedFile['path']) ?: 'application/octet-stream';
            $fileHash = hash_file('sha256', $storedFile['path']) ?: null;
            $insertFileStmt->execute([$id, $user['id'], $currentParent, $storedFile['name'], basename($storedFile['path']), $storedFile['size'], $mime, $fileHash, date(DATE_ATOM)]);
            logActivity($db, 'upload', $user['id'], $id);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    echo json_encode(['ok' => true, 'files' => count($storedFiles), 'folder_id' => $rootFolderId ?? $parentId], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $exception) {
    foreach ($storedFiles as $storedFile) {
        if (is_file($storedFile['path'])) {
            unlink($storedFile['path']);
        }
    }
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
}
