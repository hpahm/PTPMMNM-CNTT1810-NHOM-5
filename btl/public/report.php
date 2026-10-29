<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
startApp();

$user = requireLogin();
$data = readStore();

$rows = array_filter($data['files'], fn($f) => $user['role'] === 'admin' || $f['owner_id'] === $user['id']);

if (get('format') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=openshare-report.csv');
    $out = fopen('php://output', 'w');
    // Xuất BOM UTF-8 để Excel đọc tiếng Việt không bị lỗi font
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['Mã tệp', 'Tên tệp', 'Kích thước (Bytes)', 'Dung lượng định dạng', 'Lượt tải', 'Ngày tạo']);
    foreach ($rows as $f) {
        fputcsv($out, [
            $f['id'],
            $f['original_name'],
            $f['size'],
            formatBytes((int)$f['size']),
            $f['download_count'],
            $f['created_at']
        ]);
    }
    fclose($out);
    exit;
}

$usage = userUsage($data, $user['id']);
$quota = (int)($user['quota'] ?? USER_QUOTA_BYTES);
$percent = $quota > 0 ? min(100, round(($usage / $quota) * 100)) : 0;
$diskFree = disk_free_space(FILE_ROOT);
$missing = array_filter($rows, fn($file) => !is_file(FILE_ROOT . '/' . basename($file['stored_name'])));

layoutStart('Báo cáo lưu trữ & Quota');
?>

<div class="drive-container">
    <div class="page-header-bar">
        <div>
            <h2>Báo cáo lưu trữ & Hoạt động</h2>
            <p class="text-muted">Tổng quan dung lượng sử dụng, hạn mức quota và thống kê lượt tải tệp</p>
        </div>
        <div class="header-actions">
            <a href="/report.php?format=csv" class="btn btn-outline btn-sm">
                <span>📊</span> Xuất báo cáo CSV
            </a>
            <a href="/" class="btn btn-primary btn-sm">‹ Quay lại Drive</a>
        </div>
    </div>

    <?php if (!empty($missing)): ?>
        <div class="flash error">
            ⚠️ <strong>Cảnh báo tệp vật lý:</strong> Phát hiện <?= count($missing) ?> tệp có bản ghi dữ liệu nhưng không còn tồn tại trên ổ đĩa máy chủ.
        </div>
    <?php endif; ?>

    <?php if ($diskFree !== false && $diskFree < MAX_FILE_BYTES): ?>
        <div class="flash error">
            ⚠️ <strong>Cảnh báo dung lượng máy chủ:</strong> Ổ đĩa máy chủ còn ít hơn 10 MB, có thể không tiếp nhận được tệp mới.
        </div>
    <?php endif; ?>

    <!-- Thống kê Quota Dashboard -->
    <div class="dashboard-stats-grid">
        <div class="stat-card">
            <div class="stat-icon">☁️</div>
            <div class="stat-content">
                <span class="stat-title">Dung lượng tài khoản đã dùng</span>
                <span class="stat-value"><?= formatBytes($usage) ?> <small>/ <?= formatBytes($quota) ?></small></span>
                <div class="stat-progress-bar">
                    <div class="stat-progress-fill <?= $percent >= 80 ? 'danger' : '' ?>" style="width:<?= $percent ?>%"></div>
                </div>
                <span class="stat-hint">
                    <?= $percent >= 80 ? '⚠️ Đã đạt trên 80% hạn mức!' : 'Dung lượng còn dồi dào (' . (100 - $percent) . '% còn trống).' ?>
                </span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">📁</div>
            <div class="stat-content">
                <span class="stat-title">Tổng số tệp quản lý</span>
                <span class="stat-value"><?= count($rows) ?> <small>tệp</small></span>
                <span class="stat-hint">
                    <?php 
                    $totalDownloads = array_sum(array_column($rows, 'download_count'));
                    echo "Tổng cộng {$totalDownloads} lượt tải thành công";
                    ?>
                </span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">💾</div>
            <div class="stat-content">
                <span class="stat-title">Ổ đĩa máy chủ còn trống</span>
                <span class="stat-value"><?= $diskFree === false ? 'Không xác định' : formatBytes((int)$diskFree) ?></span>
                <span class="stat-hint">Được lưu trữ an toàn tại <code>storage/files/</code></span>
            </div>
        </div>
    </div>

    <!-- Bảng chi tiết tệp -->
    <div class="card mt-4">
        <div class="card-header">
            <h3>Chi tiết từng tệp (<?= count($rows) ?>)</h3>
            <p class="text-muted">Danh sách tệp và số lượt tải đã phát sinh</p>
        </div>

        <div class="table-responsive">
            <table class="drive-table">
                <thead>
                    <tr>
                        <th>Tên tệp</th>
                        <th>Dung lượng</th>
                        <th>Lượt tải</th>
                        <th>Thời gian tạo</th>
                        <th>Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $f): 
                        $ext = strtolower(pathinfo($f['original_name'], PATHINFO_EXTENSION));
                    ?>
                    <tr>
                        <td>
                            <a href="/preview.php?file=<?= rawurlencode($f['id']) ?>" class="file-title-link">
                                <span class="file-badge badge-<?= h($ext) ?>"><?= h(strtoupper(substr($ext, 0, 4))) ?></span>
                                <span class="file-name-text"><?= h($f['original_name']) ?></span>
                            </a>
                        </td>
                        <td><?= formatBytes((int)$f['size']) ?></td>
                        <td><strong><?= (int)$f['download_count'] ?></strong> lượt</td>
                        <td><?= h(date('d/m/Y H:i', strtotime($f['created_at']))) ?></td>
                        <td>
                            <div class="row-btn-group">
                                <a href="/preview.php?file=<?= rawurlencode($f['id']) ?>" class="btn-action" title="Xem trước">👁</a>
                                <a href="/logs.php?file=<?= rawurlencode($f['id']) ?>" class="btn-action" title="Nhật ký">⏱</a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php layoutEnd(); ?>
