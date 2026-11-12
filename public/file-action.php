<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
startApp();
header('Content-Type: application/json; charset=utf-8');

$user = currentUser();
$input = json_decode((string)file_get_contents('php://input'), true);
$fileId = is_array($input) && is_string($input['file'] ?? null) ? trim($input['file']) : '';
$action = is_array($input) && is_string($input['action'] ?? null) ? trim($input['action']) : '';
$name = is_array($input) && is_string($input['name'] ?? null) ? trim($input['name']) : '';
$folderId = is_array($input) && is_string($input['folder'] ?? null) ? trim($input['folder']) : '';
$csrfToken = is_array($input) && is_string($input['csrf'] ?? null) ? $input['csrf'] : '';

if (!$user || $csrfToken === '' || !hash_equals((string)($_SESSION['csrf'] ?? ''), $csrfToken)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Yêu cầu không hợp lệ.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = database();
$fStmt = $db->prepare('SELECT * FROM files WHERE id = ? AND deleted_at IS NULL LIMIT 1');
$fStmt->execute([$fileId]);
$file = $fStmt->fetch(PDO::FETCH_ASSOC);

if (!$file || !userCanManageFile($user, $file)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Bạn không có quyền thao tác với tệp này.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'rename') {
    $oldExt = strtolower(pathinfo((string)$file['original_name'], PATHINFO_EXTENSION));
    $cleanName = basename(str_replace(['\\', '/', "\0"], '', $name));
    if ($cleanName === '' || mb_strlen($cleanName) > 180) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Tên tệp không hợp lệ.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $newExt = strtolower(pathinfo($cleanName, PATHINFO_EXTENSION));
    if ($newExt === '' && $oldExt !== '') {
        $cleanName .= '.' . $oldExt;
        $newExt = $oldExt;
    }
    if ($newExt !== $oldExt && !allowedExtension($cleanName)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Phần mở rộng tệp mới không được phép.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    beginImmediate($db);
    try {
        $db->prepare('UPDATE files SET original_name = ? WHERE id = ?')->execute([$cleanName, $fileId]);
        logActivity($db, 'file_renamed', $user['id'], $fileId);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    echo json_encode(['ok' => true, 'name' => $cleanName], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'move') {
    $dStmt = $db->prepare('SELECT * FROM folders WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $dStmt->execute([$folderId]);
    $folder = $dStmt->fetch(PDO::FETCH_ASSOC);
    if (!$folder || $folder['owner_id'] !== $file['owner_id']) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Thư mục đích không hợp lệ.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    beginImmediate($db);
    try {
        $db->prepare('UPDATE files SET folder_id = ? WHERE id = ?')->execute([$folderId, $fileId]);
        logActivity($db, 'file_moved', $user['id'], $fileId);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(422);
echo json_encode(['ok' => false, 'error' => 'Thao tác không được hỗ trợ.'], JSON_UNESCAPED_UNICODE);

