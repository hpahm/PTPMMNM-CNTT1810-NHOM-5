<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
startApp();

$user = requireLogin();
$fileId = get('file');
$data = readStore();
$file = $data['files'][$fileId] ?? null;

if (!$file || !userCanManageFile($user, $file)) {
    http_response_code(404);
    exit('Không tìm thấy tệp hoặc bạn không có quyền xóa tệp này.');
}

$path = FILE_ROOT . '/' . basename((string)$file['stored_name']);
$fileExists = is_file($path);
$active = $fileExists
    ? array_filter($data['shares'], fn($s) => $s['file_id'] === $fileId && validShare($s, time()))
    : [];

if (!empty($active)) {
    http_response_code(409);
    exit('Chỉ được xóa tệp khi không còn liên kết chia sẻ nào còn hiệu lực. Vui lòng thu hồi tất cả liên kết trước khi xóa.');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verifyCsrf();
    withStore(function (array &$store) use ($fileId, $user): void {
        if (isset($store['files'][$fileId])) {
            unset($store['files'][$fileId]);
            logEvent($store, 'file_deleted', $user['id'], $fileId);
        }
    });

    if ($fileExists && !unlink($path)) {
        throw new RuntimeException('Không thể xóa tệp vật lý trên máy chủ.');
    }

    flash('Đã xóa tệp thành công!');
    redirect('/');
}

$ext = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION));

layoutStart('Xác nhận xóa tệp');
?>

<div class="drive-container-narrow">
    <div class="card delete-confirm-card">
        <div class="delete-icon-box">🗑</div>
        <h2>Xác nhận xóa tệp</h2>
        
        <div class="file-delete-preview">
            <span class="file-badge badge-<?= h($ext) ?>"><?= h(strtoupper(substr($ext, 0, 4))) ?></span>
            <div class="delete-file-meta">
                <strong><?= h($file['original_name']) ?></strong>
                <span><?= formatBytes((int)$file['size']) ?></span>
            </div>
        </div>

        <?php if ($fileExists): ?>
            <p class="text-danger-notice">
                Hành động này sẽ xóa vĩnh viễn tệp vật lý khỏi máy chủ và xóa mọi lịch sử liên kết chia sẻ. Thao tác không thể hoàn tác!
            </p>
        <?php else: ?>
            <p class="text-danger-notice">
                Tệp vật lý không còn tồn tại trên ổ đĩa. Bạn có muốn dọn dẹp bản ghi tệp này khỏi cơ sở dữ liệu?
            </p>
        <?php endif; ?>

        <form method="post" class="delete-actions-form">
            <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
            <a href="/" class="btn btn-outline">Hủy bỏ</a>
            <button type="submit" class="btn btn-danger">
                <span>🗑</span> Xác nhận xóa vĩnh viễn
            </button>
        </form>
    </div>
</div>

<?php layoutEnd(); ?>
