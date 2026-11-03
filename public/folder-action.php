<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
startApp();
header('Content-Type: application/json; charset=utf-8');
$user = currentUser();
$input = json_decode((string)file_get_contents('php://input'), true);
$folderId = is_array($input) && is_string($input['folder'] ?? null) ? trim($input['folder']) : '';
$action = is_array($input) && is_string($input['action'] ?? null) ? trim($input['action']) : '';
$name = is_array($input) && is_string($input['name'] ?? null) ? trim($input['name']) : '';
$parentId = is_array($input) && is_string($input['parent'] ?? null) ? trim($input['parent']) : '';
$csrfToken = is_array($input) && is_string($input['csrf'] ?? null) ? $input['csrf'] : '';
if (!$user || $csrfToken === '' || !hash_equals((string)($_SESSION['csrf'] ?? ''), $csrfToken)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Yêu cầu không hợp lệ.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$result = withStore(function (array &$store) use ($user, $folderId, $action, $name, $parentId): array {
    $folder = $store['folders'][$folderId] ?? null;
    if (!$folder || $folder['owner_id'] !== $user['id']) {
        return ['ok' => false, 'error' => 'Bạn không có quyền thao tác với thư mục này.'];
    }
    if ($action === 'rename') {
        if ($name === '' || mb_strlen($name) > 80 || in_array($name, ['.', '..'], true) || str_contains($name, "\0")) {
            return ['ok' => false, 'error' => 'Tên thư mục không hợp lệ.'];
        }
        foreach ($store['folders'] as $candidate) {
            if ($candidate['id'] !== $folderId && $candidate['owner_id'] === $user['id']
                && ($candidate['parent_id'] ?? '') === ($folder['parent_id'] ?? '')
                && strcasecmp($candidate['name'], $name) === 0) {
                return ['ok' => false, 'error' => 'Tên thư mục đã tồn tại.'];
            }
        }
        $store['folders'][$folderId]['name'] = $name;
        logEvent($store, 'folder_renamed', $user['id']);
        return ['ok' => true];
    }
    if ($action === 'delete') {
        $ids = [$folderId => true];
        do {
            $changed = false;
            foreach ($store['folders'] as $candidate) {
                if (isset($ids[$candidate['parent_id'] ?? ''])) {
                    $ids[$candidate['id']] = true;
                    $changed = true;
                }
            }
        } while ($changed);
        foreach ($store['files'] as $fileId => $file) {
            if (isset($ids[$file['folder_id'] ?? ''])) {
                $path = safeStoredPath($file);
                if ($path !== null) unlink($path);
                unset($store['files'][$fileId]);
            }
        }
        foreach (array_keys($ids) as $id) unset($store['folders'][$id]);
        logEvent($store, 'folder_deleted', $user['id']);
        return ['ok' => true];
    }
    if ($action === 'move') {
        $rootId = 'root-' . $user['id'];
        if ($parentId !== $rootId) {
            $parent = $store['folders'][$parentId] ?? null;
            if (!$parent || $parent['owner_id'] !== $user['id']) {
                return ['ok' => false, 'error' => 'Folder đích không hợp lệ.'];
            }
        }
        if ($parentId === $folderId) {
            return ['ok' => false, 'error' => 'Không thể kéo folder vào chính nó.'];
        }
        $ancestor = $parentId;
        while ($ancestor !== '' && $ancestor !== $rootId) {
            if ($ancestor === $folderId) {
                return ['ok' => false, 'error' => 'Không thể kéo folder vào folder con của chính nó.'];
            }
            $ancestorFolder = $store['folders'][$ancestor] ?? null;
            if (!$ancestorFolder) break;
            $ancestor = $ancestorFolder['parent_id'] ?? $rootId;
        }
        foreach ($store['folders'] as $candidate) {
            if ($candidate['id'] !== $folderId && $candidate['owner_id'] === $user['id']
                && ($candidate['parent_id'] ?? $rootId) === $parentId
                && strcasecmp($candidate['name'], $folder['name']) === 0) {
                return ['ok' => false, 'error' => 'Folder đích đã có folder cùng tên.'];
            }
        }
        $store['folders'][$folderId]['parent_id'] = $parentId;
        logEvent($store, 'folder_moved', $user['id']);
        return ['ok' => true];
    }
    return ['ok' => false, 'error' => 'Thao tác không được hỗ trợ.'];
});
if (!$result['ok']) http_response_code(422);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
