<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
startApp();

$user = requireLogin();
$db = database();
$nowIso = date(DATE_ATOM);

$stmt = $db->prepare(
    'SELECT s.*, f.id AS f_id, f.owner_id AS f_owner_id, f.original_name, f.stored_name, f.size, f.mime, f.created_at AS f_created_at, f.download_count,
            u.name AS owner_name, u.email AS owner_email,
            CASE WHEN sa.user_id IS NOT NULL THEN 1 ELSE 0 END AS is_accepted
     FROM shares s
     INNER JOIN files f ON f.id = s.file_id
     LEFT JOIN users u ON u.id = f.owner_id
     LEFT JOIN share_acceptances sa ON sa.share_id = s.id AND sa.user_id = ?
     WHERE s.owner_id <> ?
       AND f.deleted_at IS NULL
       AND s.revoked_at IS NULL
       AND s.expires_at > ?
       AND s.downloads < s.max_downloads
       AND (sa.user_id IS NOT NULL OR s.recipient_id = ?)
     ORDER BY s.created_at DESC'
);
$stmt->execute([$user['id'], $user['id'], $nowIso, $user['id']]);

$pending = [];
$accepted = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $file = [
        'id' => $row['f_id'],
        'owner_id' => $row['f_owner_id'],
        'original_name' => $row['original_name'],
        'stored_name' => $row['stored_name'],
        'size' => $row['size'],
        'mime' => $row['mime'],
        'created_at' => $row['f_created_at'],
        'download_count' => $row['download_count'],
        'owner_name' => $row['owner_name'] ?? 'Chủ tệp',
        'owner_email' => $row['owner_email'] ?? '',
    ];
    if ((int)$row['is_accepted'] === 1) {
        $accepted[] = [$row, $file];
    } else {
        $pending[] = [$row, $file];
    }
}

layoutStart('Được chia sẻ với tôi');
?>

<div class="drive-container">
    <div class="page-header-bar">
        <div>
            <h2>Được chia sẻ với tôi</h2>
            <p class="text-muted">Các tệp và lời mời truy cập được người dùng khác chia sẻ trực tiếp cho bạn</p>
        </div>
    </div>

    <!-- Mục lời mời đang chờ xác nhận -->
    <?php if (!empty($pending)): ?>
        <div class="shared-section card mb-4" style="border: 1px solid #c2e7ff; background: #fafcff;">
            <div class="card-header" style="border-bottom: 1px solid #e8f0fe;">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <div>
                        <h3 style="color: var(--color-primary); display: flex; align-items: center; gap: 8px;">
                            <span><?= uiIcon('bell', 20) ?></span> Lời mời chia sẻ mới (<?= count($pending) ?>)
                        </h3>
                        <p class="text-muted" style="margin-top: 4px;">Bạn nhận được lời mời chia sẻ tệp. Hãy chọn Chấp nhận để lưu vào Drive của bạn hoặc Từ chối.</p>
                    </div>
                </div>
            </div>

            <div class="drive-grid" style="padding: 16px;">
                <?php foreach ($pending as [$share, $file]): 
                    $ext = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION));
                    $ownerName = $file['owner_name'] ?? 'Chủ tệp';
                    $ownerEmail = $file['owner_email'] ?? '';
                    $remaining = (int)$share['max_downloads'] - (int)$share['downloads'];
                ?>
                <div class="invitation-card-item">
                    <div class="invitation-card-top">
                        <div class="card-file-type badge-<?= h($ext) ?>" style="font-size: 11px; padding: 6px 10px; border-radius: 6px;">
                            <?= h(strtoupper(substr($ext, 0, 4))) ?>
                        </div>
                        <div class="invitation-card-info">
                            <span class="invitation-file-name" title="<?= h($file['original_name']) ?>">
                                <?= h($file['original_name']) ?>
                            </span>
                            <div class="invitation-from-text">
                                Từ: <strong><?= h($ownerName) ?></strong>
                                <?php if ($ownerEmail !== ''): ?>
                                    <span style="color: var(--color-text-muted);">(<?= h($ownerEmail) ?>)</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="invitation-card-meta">
                        <span><?= formatBytes((int)$file['size']) ?></span>
                        <span>Hết hạn: <?= h(date('d/m/Y', strtotime($share['expires_at']))) ?></span>
                        <span>Còn <?= $remaining ?> lượt tải</span>
                    </div>

                    <div class="invitation-actions">
                        <form method="post" action="/accept-share.php" style="display:inline; margin: 0;">
                            <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                            <input type="hidden" name="share" value="<?= h($share['id']) ?>">
                            <button type="submit" class="btn btn-sm btn-success" title="Chấp nhận và lưu vào Drive của tôi">
                                <?= uiIcon('check', 16) ?> Chấp nhận
                            </button>
                        </form>

                        <form method="post" action="/decline-share.php" onsubmit="return confirm('Bạn có chắc muốn từ chối lời mời chia sẻ này không?');" style="display:inline; margin: 0;">
                            <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                            <input type="hidden" name="share" value="<?= h($share['id']) ?>">
                            <button type="submit" class="btn btn-sm btn-outline" title="Từ chối lời mời">
                                <?= uiIcon('close', 16) ?> Từ chối
                            </button>
                        </form>

                        <a href="/preview.php?file=<?= rawurlencode($file['id']) ?>&share=<?= rawurlencode($share['id']) ?>" class="btn btn-sm btn-outline" style="margin-left: auto;" title="Xem trước nội dung tệp">
                            <?= uiIcon('eye', 14) ?> Xem
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
                <div class="empty-icon-circle"><?= uiIcon('share', 48) ?></div>
                <h3>Chưa có tệp nào được lưu</h3>
                <p class="text-muted">Khi có ai đó chia sẻ tệp cho email của bạn và bạn bấm Chấp nhận, tệp sẽ xuất hiện tại đây.</p>
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
                            $ownerUser = $data['users'][$file['owner_id']] ?? null;
                            $owner = $ownerUser['name'] ?? 'Chủ sở hữu';
                            $ownerEmail = $ownerUser['email'] ?? '';
                            $remaining = (int)$share['max_downloads'] - (int)$share['downloads'];
                        ?>
                        <tr>
                            <td>
                                <a href="/preview.php?file=<?= rawurlencode($file['id']) ?>&share=<?= rawurlencode($share['id']) ?>" class="file-title-link">
                                    <span class="file-badge badge-<?= h($ext) ?>"><?= h(strtoupper(substr($ext, 0, 4))) ?></span>
                                    <span class="file-name-text"><?= h($file['original_name']) ?></span>
                                </a>
                            </td>
                            <td>
                                <span class="owner-pill" title="<?= h($ownerEmail) ?>">
                                    <?= h($owner) ?>
                                </span>
                            </td>
                            <td><?= formatBytes((int)$file['size']) ?></td>
                            <td><strong><?= $remaining ?></strong> lượt</td>
                            <td><?= h(date('d/m/Y H:i', strtotime($share['expires_at']))) ?></td>
                            <td>
                                <div class="row-btn-group">
                                    <a href="/preview.php?file=<?= rawurlencode($file['id']) ?>&share=<?= rawurlencode($share['id']) ?>" class="btn-action" title="Xem trước"><?= uiIcon('eye', 14) ?></a>
                                    <a href="/download.php?share=<?= rawurlencode($share['id']) ?>" class="btn-action" title="Tải xuống"><?= uiIcon('download', 14) ?></a>
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
