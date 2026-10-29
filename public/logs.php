<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
startApp();

$user = requireLogin();
$data = readStore();
$fileId = get('file');
$targetFile = $fileId !== '' ? ($data['files'][$fileId] ?? null) : null;

$logs = array_reverse(array_filter($data['logs'], function (array $log) use ($user, $data, $fileId): bool {
    if ($user['role'] === 'admin') {
        return $fileId === '' || $log['file_id'] === $fileId;
    }
    if ($fileId !== '') {
        return isset($data['files'][$fileId]) && $data['files'][$fileId]['owner_id'] === $user['id'] && $log['file_id'] === $fileId;
    }
    return $log['user_id'] === $user['id'];
}));

function translateAction(string $action): string {
    return match ($action) {
        'upload' => '⬆ Tải tệp lên',
        'download' => '⇩ Tải tệp xuống',
        'download_denied' => '⛔ Bị từ chối tải',
        'download_queued' => '⏳ Xếp hàng tải (FIFO)',
        'share_created' => '🔗 Tạo liên kết chia sẻ',
        'share_revoked' => '🚫 Thu hồi liên kết',
        'share_accepted' => '✓ Xác nhận lưu tệp',
        'share_declined' => '✕ Từ chối lời mời',
        'file_deleted' => '🗑 Xóa tệp',
        'expired_file_deleted' => '🧹 Dọn tệp hết hạn',
        'folder_created' => '📁 Tạo thư mục',
        'register' => '👤 Đăng ký tài khoản',
        default => $action,
    };
}

layoutStart('Nhật ký hoạt động');
?>

<div class="drive-container">
    <div class="page-header-bar">
        <div>
            <h2>Nhật ký hoạt động & Truy cập</h2>
            <p class="text-muted">
                <?= $targetFile ? 'Lịch sử thao tác liên quan đến tệp: <strong>' . h($targetFile['original_name']) . '</strong>' : 'Toàn bộ thao tác truy cập, cấp quyền và tải tệp' ?>
            </p>
        </div>
        <div class="header-actions">
            <?php if ($fileId !== ''): ?>
                <a href="/logs.php" class="btn btn-outline btn-sm">Xem tất cả hoạt động</a>
            <?php endif; ?>
            <a href="/" class="btn btn-primary btn-sm">‹ Quay lại Drive</a>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3>Hoạt động gần đây (<?= min(100, count($logs)) ?> / <?= count($logs) ?>)</h3>
        </div>

        <?php if (empty($logs)): ?>
            <div class="empty-state-card small">
                <div class="empty-icon-circle">⏱</div>
                <p class="text-muted">Chưa ghi nhận hoạt động nào trong hệ thống.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="drive-table">
                    <thead>
                        <tr>
                            <th>Thời gian</th>
                            <th>Hành động</th>
                            <th>Tài khoản thực hiện</th>
                            <th>Tệp liên quan</th>
                            <th>Trạng thái</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($logs, 0, 100) as $log): 
                            $account = $data['users'][$log['user_id']]['name'] ?? ($data['users'][$log['user_id']]['email'] ?? $log['user_id']);
                            $fileName = $data['files'][$log['file_id']]['original_name'] ?? ($log['file_id'] !== '' ? $log['file_id'] : '—');
                            $isSuccess = (bool)$log['success'];
                        ?>
                        <tr>
                            <td class="text-nowrap"><?= h(date('d/m/Y H:i:s', strtotime($log['at']))) ?></td>
                            <td>
                                <span class="action-tag action-<?= h(strtolower($log['action'])) ?>">
                                    <?= h(translateAction($log['action'])) ?>
                                </span>
                            </td>
                            <td>
                                <span class="owner-pill"><?= h($account) ?></span>
                            </td>
                            <td>
                                <?php if ($log['file_id'] !== '' && isset($data['files'][$log['file_id']])): ?>
                                    <a href="/preview.php?file=<?= rawurlencode($log['file_id']) ?>" class="table-link">
                                        <?= h($fileName) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted"><?= h($fileName) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isSuccess): ?>
                                    <span class="badge-status status-active">✓ Thành công</span>
                                <?php else: ?>
                                    <span class="badge-status status-revoked">✕ Bị từ chối</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php layoutEnd(); ?>
