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
$result = withStore(function (array &$store) use ($user, $name, $parentId): array {
    $parent = $store['folders'][$parentId] ?? null;
    if (!$parent || $parent['owner_id'] !== $user['id']) {
        return ['ok' => false, 'error' => 'Thư mục cha không hợp lệ.'];
    }
    if ($name === '' || mb_strlen($name) > 80 || str_contains($name, "\0") || in_array($name, ['.', '..'], true)) {
        return ['ok' => false, 'error' => 'Tên thư mục không hợp lệ (tối đa 80 ký tự).'];
    }
    foreach ($store['folders'] as $folder) {
        if ($folder['owner_id'] === $user['id']
            && ($folder['parent_id'] ?? 'root-' . $user['id']) === $parentId
            && strcasecmp($folder['name'], $name) === 0) {
            return ['ok' => false, 'error' => 'Thư mục với tên này đã tồn tại.'];
        }
    }
    $id = bin2hex(random_bytes(8));
    $store['folders'][$id] = [
        'id' => $id,
        'owner_id' => $user['id'],
        'name' => $name,
        'parent_id' => $parentId,
        'created_at' => date(DATE_ATOM),
    ];
    logEvent($store, 'folder_created', $user['id']);
    return ['ok' => true, 'folder' => $store['folders'][$id]];
});

if (!$result['ok']) {
    http_response_code(422);
}
echo json_encode($result, JSON_UNESCAPED_UNICODE);
