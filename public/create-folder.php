<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
startApp();
header('Content-Type: application/json; charset=utf-8');

$user = currentUser();
$payload = json_decode((string)file_get_contents('php://input'), true);
$name = is_array($payload) && is_string($payload['name'] ?? null) ? trim($payload['name']) : '';
$parentId = is_array($payload) && is_string($payload['parent'] ?? null) ? trim($payload['parent']) : '';
$csrfToken = is_array($payload) && is_string($payload['csrf'] ?? null) ? $payload['csrf'] : '';

if (!$user || $csrfToken === '' || !hash_equals((string)($_SESSION['csrf'] ?? ''), $csrfToken)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Yêu cầu không hợp lệ.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$parentId = $parentId !== '' ? $parentId : 'root-' . $user['id'];
$db = database();
$pStmt = $db->prepare('SELECT id FROM folders WHERE id = ? AND owner_id = ? LIMIT 1');
$pStmt->execute([$parentId, $user['id']]);
if (!$pStmt->fetchColumn()) {
    $result = ['ok' => false, 'error' => 'Thư mục cha không hợp lệ.'];
} elseif ($name === '' || mb_strlen($name) > 80 || str_contains($name, "\0") || in_array($name, ['.', '..'], true)) {
    $result = ['ok' => false, 'error' => 'Tên thư mục không hợp lệ (tối đa 80 ký tự).'];
} else {
    $dupStmt = $db->prepare('SELECT 1 FROM folders WHERE owner_id = ? AND parent_id = ? AND LOWER(name) = LOWER(?) LIMIT 1');
    $dupStmt->execute([$user['id'], $parentId, $name]);
    if ($dupStmt->fetchColumn()) {
        $result = ['ok' => false, 'error' => 'Thư mục với tên này đã tồn tại.'];
    } else {
        $id = bin2hex(random_bytes(8));
        $createdAt = date(DATE_ATOM);
        $folder = [
            'id' => $id,
            'owner_id' => $user['id'],
            'name' => $name,
            'parent_id' => $parentId,
            'created_at' => $createdAt,
        ];
        beginImmediate($db);
        try {
            $db->prepare('INSERT INTO folders (id, owner_id, name, parent_id, created_at) VALUES (?, ?, ?, ?, ?)')
               ->execute([$id, $user['id'], $name, $parentId, $createdAt]);
            logActivity($db, 'folder_created', $user['id']);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        $result = ['ok' => true, 'folder' => $folder];
    }
}

if (!$result['ok']) {
    http_response_code(422);
}
echo json_encode($result, JSON_UNESCAPED_UNICODE);
