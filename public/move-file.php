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

$db = database();
$fStmt = $db->prepare('SELECT * FROM files WHERE id = ? LIMIT 1');
$fStmt->execute([$fileId]);
$file = $fStmt->fetch(PDO::FETCH_ASSOC);

$dStmt = $db->prepare('SELECT * FROM folders WHERE id = ? LIMIT 1');
$dStmt->execute([$folderId]);
$folder = $dStmt->fetch(PDO::FETCH_ASSOC);

if (!$file || !$folder || !userCanManageFile($user, $file) || $folder['owner_id'] !== $user['id']) {
    $result = ['ok' => false, 'error' => 'Bạn không có quyền di chuyển tệp vào thư mục này.'];
} elseif ($file['owner_id'] !== $folder['owner_id']) {
    $result = ['ok' => false, 'error' => 'Tệp và thư mục phải thuộc cùng một tài khoản.'];
} elseif (($file['folder_id'] ?? '') === $folderId) {
    $result = ['ok' => true, 'unchanged' => true];
} else {
    beginImmediate($db);
    try {
        $db->prepare('UPDATE files SET folder_id = ? WHERE id = ?')->execute([$folderId, $fileId]);
        logActivity($db, 'file_moved', $user['id'], $fileId);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    $result = ['ok' => true, 'unchanged' => false];
}

if (!$result['ok']) {
    http_response_code(403);
}
echo json_encode($result, JSON_UNESCAPED_UNICODE);
