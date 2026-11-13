<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
startApp();

$user = requireLogin();
$db = database();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verifyCsrf();
    $action = post('action');
    $targetType = post('type');
    $targetId = post('id');

    if ($action === 'empty_trash') {
        $fStmt = $db->prepare('SELECT id, stored_name FROM files WHERE owner_id = ? AND deleted_at IS NOT NULL');
        $fStmt->execute([$user['id']]);
        $trashedFiles = $fStmt->fetchAll(PDO::FETCH_ASSOC);

        beginImmediate($db);
        try {
            $db->prepare('DELETE FROM files WHERE owner_id = ? AND deleted_at IS NOT NULL')->execute([$user['id']]);
            $db->prepare('DELETE FROM folders WHERE owner_id = ? AND deleted_at IS NOT NULL')->execute([$user['id']]);
            logActivity($db, 'trash_emptied', $user['id']);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        foreach ($trashedFiles as $f) {
            $path = safeStoredPath($f);
            if ($path !== null) @unlink($path);
        }
        flash('Đã dọn sạch thùng rác.');
        redirect('/trash');
    } elseif ($targetType === 'file' && $targetId !== '') {
        $fStmt = $db->prepare('SELECT * FROM files WHERE id = ? AND owner_id = ? AND deleted_at IS NOT NULL LIMIT 1');
        $fStmt->execute([$targetId, $user['id']]);
        $file = $fStmt->fetch(PDO::FETCH_ASSOC);
        if ($file) {
            if ($action === 'restore') {
                $rootId = 'root-' . $user['id'];
                $pStmt = $db->prepare('SELECT id FROM folders WHERE id = ? AND owner_id = ? AND deleted_at IS NULL LIMIT 1');
                $pStmt->execute([$file['folder_id'], $user['id']]);
                $restoreFolder = $pStmt->fetchColumn() ? $file['folder_id'] : $rootId;
                $db->prepare('UPDATE files SET deleted_at = NULL, folder_id = ? WHERE id = ?')->execute([$restoreFolder, $targetId]);
                logActivity($db, 'file_restored', $user['id'], $targetId);
                flash('Đã khôi phục tệp "' . $file['original_name'] . '".');
            } elseif ($action === 'destroy') {
                $path = safeStoredPath($file);
                beginImmediate($db);
                try {
                    $db->prepare('DELETE FROM files WHERE id = ?')->execute([$targetId]);
                    logActivity($db, 'file_deleted', $user['id'], $targetId);
                    $db->commit();
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    throw $e;
                }
                if ($path !== null) @unlink($path);
                flash('Đã xóa vĩnh viễn tệp "' . $file['original_name'] . '".');
            }
        }
        redirect('/trash');
    } elseif ($targetType === 'folder' && $targetId !== '') {
        $dStmt = $db->prepare('SELECT * FROM folders WHERE id = ? AND owner_id = ? AND deleted_at IS NOT NULL LIMIT 1');
        $dStmt->execute([$targetId, $user['id']]);
        $folder = $dStmt->fetch(PDO::FETCH_ASSOC);
        if ($folder) {
            $allStmt = $db->prepare('SELECT id, parent_id FROM folders WHERE owner_id = ?');
            $allStmt->execute([$user['id']]);
            $byParent = [];
            foreach ($allStmt->fetchAll(PDO::FETCH_ASSOC) as $cand) {
                $byParent[(string)($cand['parent_id'] ?? '')][] = $cand['id'];
            }
            $ids = [$targetId];
            $queue = [$targetId];
            while ($queue) {
                $cur = array_shift($queue);
                foreach ($byParent[$cur] ?? [] as $cid) {
                    $ids[] = $cid;
                    $queue[] = $cid;
                }
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            if ($action === 'restore') {
                $rootId = 'root-' . $user['id'];
                $pStmt = $db->prepare('SELECT id FROM folders WHERE id = ? AND owner_id = ? AND deleted_at IS NULL LIMIT 1');
                $pStmt->execute([$folder['parent_id'], $user['id']]);
                $newParent = $pStmt->fetchColumn() ? $folder['parent_id'] : $rootId;
                beginImmediate($db);
                try {
                    $db->prepare("UPDATE folders SET deleted_at = NULL WHERE owner_id = ? AND id IN ({$placeholders})")
                       ->execute(array_merge([$user['id']], $ids));
                    $db->prepare('UPDATE folders SET parent_id = ? WHERE id = ?')->execute([$newParent, $targetId]);
                    $db->prepare("UPDATE files SET deleted_at = NULL WHERE owner_id = ? AND folder_id IN ({$placeholders})")
                       ->execute(array_merge([$user['id']], $ids));
                    logActivity($db, 'folder_restored', $user['id']);
                    $db->commit();
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    throw $e;
                }
                flash('Đã khôi phục thư mục "' . $folder['name'] . '".');
            } elseif ($action === 'destroy') {
                $fStmt = $db->prepare("SELECT id, stored_name FROM files WHERE owner_id = ? AND folder_id IN ({$placeholders})");
                $fStmt->execute(array_merge([$user['id']], $ids));
                $filesToDestroy = $fStmt->fetchAll(PDO::FETCH_ASSOC);
                beginImmediate($db);
                try {
                    $db->prepare("DELETE FROM files WHERE owner_id = ? AND folder_id IN ({$placeholders})")
                       ->execute(array_merge([$user['id']], $ids));
                    $db->prepare("DELETE FROM folders WHERE owner_id = ? AND id IN ({$placeholders})")
                       ->execute(array_merge([$user['id']], $ids));
                    logActivity($db, 'folder_deleted', $user['id']);
                    $db->commit();
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    throw $e;
                }
                foreach ($filesToDestroy as $f) {
                    $path = safeStoredPath($f);
                    if ($path !== null) @unlink($path);
                }
                flash('Đã xóa vĩnh viễn thư mục "' . $folder['name'] . '".');
            }
        }
        redirect('/trash');
    }
}

$trashedFoldersStmt = $db->prepare('SELECT * FROM folders WHERE owner_id = ? AND deleted_at IS NOT NULL ORDER BY deleted_at DESC');
$trashedFoldersStmt->execute([$user['id']]);
$trashedFolders = $trashedFoldersStmt->fetchAll(PDO::FETCH_ASSOC);

$trashedFilesStmt = $db->prepare('SELECT * FROM files WHERE owner_id = ? AND deleted_at IS NOT NULL ORDER BY deleted_at DESC');
$trashedFilesStmt->execute([$user['id']]);
$trashedFiles = $trashedFilesStmt->fetchAll(PDO::FETCH_ASSOC);

layoutStart('Thùng rác');
?>

<div class="drive-container">
    <div class="page-header-bar">
        <div>
            <h2>Thùng rác</h2>
            <p class="text-muted">Các tệp và thư mục đã xóa tạm thời. Tệp trong thùng rác sẽ tự động bị xóa vĩnh viễn sau 30 ngày.</p>
        </div>
        <div class="header-actions">
            <?php if (!empty($trashedFiles) || !empty($trashedFolders)): ?>
                <form method="post" onsubmit="return confirm('Xóa vĩnh viễn toàn bộ mục trong thùng rác? Thao tác này không thể hoàn tác.');">
                    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                    <input type="hidden" name="action" value="empty_trash">
                    <button type="submit" class="btn btn-danger btn-sm"><?= uiIcon('trash', 14) ?> Dọn sạch thùng rác</button>
                </form>
            <?php endif; ?>
            <a href="/" class="btn btn-outline btn-sm">‹ Quay lại Drive</a>
        </div>
    </div>

    <?php if (empty($trashedFiles) && empty($trashedFolders)): ?>
        <div class="empty-state-card">
            <div class="empty-icon-circle"><?= uiIcon('trash', 48) ?></div>
            <h3>Thùng rác đang trống</h3>
            <p class="text-muted">Không có tệp hay thư mục nào đã xóa gần đây.</p>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="table-responsive">
                <table class="drive-table">
                    <thead>
                        <tr>
                            <th>Tên mục</th>
                            <th>Loại</th>
                            <th>Kích thước</th>
                            <th>Ngày xóa</th>
                            <th>Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($trashedFolders as $tf): ?>
                            <tr>
                                <td><span style="display:inline-flex;align-items:center;gap:6px;"><?= uiIcon('folder', 16) ?> <strong><?= h($tf['name']) ?></strong></span></td>
                                <td>Thư mục</td>
                                <td>—</td>
                                <td><?= h(date('d/m/Y H:i', strtotime((string)$tf['deleted_at']))) ?></td>
                                <td class="td-actions">
                                    <div class="row-btn-group">
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                                            <input type="hidden" name="type" value="folder">
                                            <input type="hidden" name="id" value="<?= h($tf['id']) ?>">
                                            <input type="hidden" name="action" value="restore">
                                            <button type="submit" class="btn btn-sm btn-outline">Khôi phục</button>
                                        </form>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Xóa vĩnh viễn thư mục này và toàn bộ tệp bên trong?');">
                                            <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                                            <input type="hidden" name="type" value="folder">
                                            <input type="hidden" name="id" value="<?= h($tf['id']) ?>">
                                            <input type="hidden" name="action" value="destroy">
                                            <button type="submit" class="btn btn-sm btn-danger">Xóa vĩnh viễn</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php foreach ($trashedFiles as $tf):
                            $ext = strtolower(pathinfo((string)$tf['original_name'], PATHINFO_EXTENSION));
                        ?>
                            <tr>
                                <td>
                                    <span class="file-badge badge-<?= h($ext) ?>"><?= h(strtoupper(substr($ext, 0, 4))) ?></span>
                                    <strong><?= h($tf['original_name']) ?></strong>
                                </td>
                                <td>Tệp</td>
                                <td><?= formatBytes((int)$tf['size']) ?></td>
                                <td><?= h(date('d/m/Y H:i', strtotime((string)$tf['deleted_at']))) ?></td>
                                <td class="td-actions">
                                    <div class="row-btn-group">
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                                            <input type="hidden" name="type" value="file">
                                            <input type="hidden" name="id" value="<?= h($tf['id']) ?>">
                                            <input type="hidden" name="action" value="restore">
                                            <button type="submit" class="btn btn-sm btn-outline">Khôi phục</button>
                                        </form>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Xóa vĩnh viễn tệp này?');">
                                            <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                                            <input type="hidden" name="type" value="file">
                                            <input type="hidden" name="id" value="<?= h($tf['id']) ?>">
                                            <input type="hidden" name="action" value="destroy">
                                            <button type="submit" class="btn btn-sm btn-danger">Xóa vĩnh viễn</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php layoutEnd(); ?>

