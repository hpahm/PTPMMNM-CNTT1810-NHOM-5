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

$db = database();
$fStmt = $db->prepare('SELECT * FROM folders WHERE id = ? AND owner_id = ? LIMIT 1');
$fStmt->execute([$folderId, $user['id']]);
$folder = $fStmt->fetch(PDO::FETCH_ASSOC);

if (!$folder) {
    $result = ['ok' => false, 'error' => 'Bạn không có quyền thao tác với thư mục này.'];
} elseif ($action === 'rename') {
    if ($name === '' || mb_strlen($name) > 80 || in_array($name, ['.', '..'], true) || str_contains($name, "\0")) {
        $result = ['ok' => false, 'error' => 'Tên thư mục không hợp lệ.'];
    } else {
        $dupStmt = $db->prepare('SELECT 1 FROM folders WHERE id <> ? AND owner_id = ? AND COALESCE(parent_id, ?) = COALESCE(?, ?) AND LOWER(name) = LOWER(?) LIMIT 1');
        $dupStmt->execute([$folderId, $user['id'], '', $folder['parent_id'], '', $name]);
        if ($dupStmt->fetchColumn()) {
            $result = ['ok' => false, 'error' => 'Tên thư mục đã tồn tại.'];
        } else {
            beginImmediate($db);
            try {
                $db->prepare('UPDATE folders SET name = ? WHERE id = ?')->execute([$name, $folderId]);
                logActivity($db, 'folder_renamed', $user['id']);
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
            $result = ['ok' => true];
        }
    }
} elseif ($action === 'delete') {
    $allStmt = $db->prepare('SELECT id, parent_id FROM folders WHERE owner_id = ?');
    $allStmt->execute([$user['id']]);
    $byParent = [];
    foreach ($allStmt->fetchAll(PDO::FETCH_ASSOC) as $cand) {
        $byParent[(string)($cand['parent_id'] ?? '')][] = $cand['id'];
    }
    $ids = [$folderId];
    $queue = [$folderId];
    while ($queue) {
        $cur = array_shift($queue);
        foreach ($byParent[$cur] ?? [] as $cid) {
            $ids[] = $cid;
            $queue[] = $cid;
        }
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $nowIso = date(DATE_ATOM);

    beginImmediate($db);
    try {
        $db->prepare("UPDATE files SET deleted_at = ? WHERE owner_id = ? AND deleted_at IS NULL AND folder_id IN ({$placeholders})")
           ->execute(array_merge([$nowIso, $user['id']], $ids));
        $db->prepare("UPDATE folders SET deleted_at = ? WHERE owner_id = ? AND deleted_at IS NULL AND id IN ({$placeholders})")
           ->execute(array_merge([$nowIso, $user['id']], $ids));
        logActivity($db, 'folder_trashed', $user['id']);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    $result = ['ok' => true];
} elseif ($action === 'move') {
    $rootId = 'root-' . $user['id'];
    $validTarget = true;
    if ($parentId !== $rootId) {
        $pStmt = $db->prepare('SELECT id FROM folders WHERE id = ? AND owner_id = ? LIMIT 1');
        $pStmt->execute([$parentId, $user['id']]);
        if (!$pStmt->fetchColumn()) {
            $validTarget = false;
        }
    }
    if (!$validTarget) {
        $result = ['ok' => false, 'error' => 'Folder đích không hợp lệ.'];
    } elseif ($parentId === $folderId) {
        $result = ['ok' => false, 'error' => 'Không thể kéo folder vào chính nó.'];
    } else {
        $allStmt = $db->prepare('SELECT id, parent_id FROM folders WHERE owner_id = ?');
        $allStmt->execute([$user['id']]);
        $parentMap = [];
        foreach ($allStmt->fetchAll(PDO::FETCH_ASSOC) as $cand) {
            $parentMap[$cand['id']] = $cand['parent_id'] ?? $rootId;
        }
        $isDescendant = false;
        $ancestor = $parentId;
        while ($ancestor !== '' && $ancestor !== $rootId) {
            if ($ancestor === $folderId) {
                $isDescendant = true;
                break;
            }
            $ancestor = $parentMap[$ancestor] ?? '';
        }
        if ($isDescendant) {
            $result = ['ok' => false, 'error' => 'Không thể kéo folder vào folder con của chính nó.'];
        } else {
            $dupStmt = $db->prepare('SELECT 1 FROM folders WHERE id <> ? AND owner_id = ? AND COALESCE(parent_id, ?) = ? AND LOWER(name) = LOWER(?) LIMIT 1');
            $dupStmt->execute([$folderId, $user['id'], $rootId, $parentId, $folder['name']]);
            if ($dupStmt->fetchColumn()) {
                $result = ['ok' => false, 'error' => 'Folder đích đã có folder cùng tên.'];
            } else {
                beginImmediate($db);
                try {
                    $db->prepare('UPDATE folders SET parent_id = ? WHERE id = ?')->execute([$parentId, $folderId]);
                    logActivity($db, 'folder_moved', $user['id']);
                    $db->commit();
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    throw $e;
                }
                $result = ['ok' => true];
            }
        }
    }
} else {
    $result = ['ok' => false, 'error' => 'Thao tác không được hỗ trợ.'];
}

if (!$result['ok']) http_response_code(422);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
