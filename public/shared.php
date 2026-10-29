<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
startApp();

$user = requireLogin();
$data = readStore();

$pending = [];
$accepted = [];
$viewedShareIds = [];

foreach ($data['logs'] as $log) {
    if (($log['action'] ?? '') === 'share_view' && ($log['user_id'] ?? '') === $user['id']) {
        $viewedShareIds[$log['file_id'] ?? ''] = true;
    }
}

foreach ($data['shares'] as $share) {
    $isRecipientShare = $share['recipient_id'] === $user['id'] && $share['owner_id'] !== $user['id'];
    $isViewedPublicShare = isPublicShare($share)
        && $share['owner_id'] !== $user['id']
        && isset($viewedShareIds[$share['file_id']]);
    if ((!$isRecipientShare && !$isViewedPublicShare) || !validShare($share, time())) {
        continue;
    }
    $file = $data['files'][$share['file_id']] ?? null;
    if (!$file) {
        continue;
    }
    if (shareIsAccepted($share)) {
        $accepted[] = [$share, $file];
    } else {
        $pending[] = [$share, $file];
    }
}

layoutStart('Được chia sẻ với tôi');
?>

<div class="drive-container">
    <div class="page-header-bar">
        <div>
            <h2>Được chia sẻ với tôi</h2>
            <p class="text-muted">Các tệp người dùng khác đã cấp quyền chia sẻ cho tài khoản của bạn</p>
        </div>
    </div>

    <!-- Mục chờ xác nhận -->
    <?php if (!empty($pending)): ?>
        <div class="shared-section card mb-4">
            <div class="card-header">
                <h3>Đang chờ bạn xác nhận (<?= count($pending) ?>)</h3>
                <p class="text-muted">Mở xem trước tệp để kiểm tra và xác nhận lưu vào danh sách của bạn</p>
            </div>

            <div class="drive-grid">
                <?php foreach ($pending as [$share, $file]): 
                    $ext = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION));
                    $owner = $data['users'][$file['owner_id']]['name'] ?? 'Chủ tệp';
                ?>
                <div class="drive-card card-pending">
                    <a href="/preview.php?file=<?= rawurlencode($file['id']) ?>&share=<?= rawurlencode($share['id']) ?>" class="card-preview-area">
                        <div class="card-file-type badge-<?= h($ext) ?>"><?= h(strtoupper(substr($ext, 0, 4))) ?></div>
                        <div class="card-preview-symbol">📬</div>
                    </a>
                    <div class="card-body">
                        <a href="/preview.php?file=<?= rawurlencode($file['id']) ?>&share=<?= rawurlencode($share['id']) ?>" class="card-title" title="<?= h($file['original_name']) ?>">
                            <?= h($file['original_name']) ?>
                        </a>
                        <div class="card-meta">
                            <span>Từ: <strong><?= h($owner) ?></strong></span> · 
                            <span><?= formatBytes((int)$file['size']) ?></span>
                        </div>
                    </div>
                    <div class="card-footer">
                        <span class="badge-status status-waiting">Chờ xác nhận</span>
                        <a href="/preview.php?file=<?= rawurlencode($file['id']) ?>&share=<?= rawurlencode($share['id']) ?>" class="btn btn-sm btn-primary">
                            Xem & Lưu
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Mục tệp đã lưu -->
    <div class="shared-section card">
        <div class="card-header">
            <h3>Tệp đã lưu (<?= count($accepted) ?>)</h3>
            <p class="text-muted">Danh sách tệp bạn đã xác nhận lưu từ liên kết được chia sẻ</p>
        </div>

        <?php if (empty($accepted)): ?>
            <div class="empty-state-card small">
                <div class="empty-icon-circle">👥</div>
                <h3>Chưa có tệp nào được lưu</h3>
                <p class="text-muted">Khi có ai đó chia sẻ tệp cho bạn và bạn xác nhận lưu, tệp sẽ hiển thị ở đây.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="drive-table">
                    <thead>
                        <tr>
                            <th>Tên tệp</th>
                            <th>Người chia sẻ</th>
                            <th>Kích thước</th>
                            <th>Lượt tải còn lại</th>
                            <th>Hết hạn</th>
                            <th>Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($accepted as [$share, $file]): 
                            $ext = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION));
                            $owner = $data['users'][$file['owner_id']]['name'] ?? 'Chủ sở hữu';
                            $remaining = (int)$share['max_downloads'] - (int)$share['downloads'];
                        ?>
                        <tr>
                            <td>
                                <a href="/preview.php?file=<?= rawurlencode($file['id']) ?>&share=<?= rawurlencode($share['id']) ?>" class="file-title-link">
                                    <span class="file-badge badge-<?= h($ext) ?>"><?= h(strtoupper(substr($ext, 0, 4))) ?></span>
                                    <span class="file-name-text"><?= h($file['original_name']) ?></span>
                                </a>
                            </td>
                            <td><span class="owner-pill"><?= h($owner) ?></span></td>
                            <td><?= formatBytes((int)$file['size']) ?></td>
                            <td><strong><?= $remaining ?></strong> lượt</td>
                            <td><?= h(date('d/m/Y H:i', strtotime($share['expires_at']))) ?></td>
                            <td>
                                <div class="row-btn-group">
                                    <a href="/preview.php?file=<?= rawurlencode($file['id']) ?>&share=<?= rawurlencode($share['id']) ?>" class="btn-action" title="Xem trước">👁</a>
                                    <a href="/download.php?share=<?= rawurlencode($share['id']) ?>" class="btn-action" title="Tải xuống">⇩</a>
                                </div>
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
