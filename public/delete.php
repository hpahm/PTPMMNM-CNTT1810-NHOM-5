<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
startApp();

$user = requireLogin();
$fileId = get('file');
$db = database();
$fStmt = $db->prepare('SELECT * FROM files WHERE id = ? AND deleted_at IS NULL LIMIT 1');
$fStmt->execute([$fileId]);
$file = $fStmt->fetch(PDO::FETCH_ASSOC);

if (!$file || !userCanManageFile($user, $file)) {
    http_response_code(404);
    exit('Không tìm thấy tệp hoặc bạn không có quyền xóa tệp này.');
}

$path = FILE_ROOT . '/' . basename((string)$file['stored_name']);
$fileExists = is_file($path);
$hasActiveShare = false;
if ($fileExists) {
    $sStmt = $db->prepare('SELECT 1 FROM shares WHERE file_id = ? AND revoked_at IS NULL AND expires_at > ? AND downloads < max_downloads LIMIT 1');
    $sStmt->execute([$fileId, date(DATE_ATOM)]);
    $hasActiveShare = (bool)$sStmt->fetchColumn();
}

if ($hasActiveShare) {
    http_response_code(409);
    exit('Chỉ được xóa tệp khi không còn liên kết chia sẻ nào còn hiệu lực. Vui lòng thu hồi tất cả liên kết trước khi xóa.');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verifyCsrf();
    beginImmediate($db);
    try {
        $db->prepare('UPDATE files SET deleted_at = ? WHERE id = ?')->execute([date(DATE_ATOM), $fileId]);
        logActivity($db, 'file_trashed', $user['id'], $fileId);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    flash('Đã chuyển tệp vào Thùng rác!');
    redirect('/');
}

$ext = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION));

layoutStart('Xác nhận xóa tệp');
?>

<div class="drive-container-narrow">
    <div class="card delete-confirm-card">
        <div class="delete-icon-box"><?= uiIcon('trash', 40) ?></div>
        <h2>Xác nhận chuyển vào thùng rác</h2>
        
        <div class="file-delete-preview">
            <span class="file-badge badge-<?= h($ext) ?>"><?= h(strtoupper(substr($ext, 0, 4))) ?></span>
            <div class="delete-file-meta">
                <strong><?= h($file['original_name']) ?></strong>
                <span><?= formatBytes((int)$file['size']) ?></span>
            </div>
        </div>

        <p class="text-danger-notice">
            Tệp sẽ được chuyển vào Thùng rác và có thể khôi phục lại trong vòng 30 ngày trước khi bị dọn dẹp vĩnh viễn.
        </p>

        <form method="post" class="delete-actions-form">
            <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
            <a href="/" class="btn btn-outline">Hủy bỏ</a>
            <button type="submit" class="btn btn-danger">
                <?= uiIcon('trash', 16) ?> Chuyển vào thùng rác
            </button>
        </form>
    </div>
</div>

<?php layoutEnd(); ?>
