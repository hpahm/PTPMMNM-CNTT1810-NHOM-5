<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
startApp();

$user = requireLogin();
$data = readStore();

$q = strtolower(get('q'));
$status = get('status');
$folderId = get('folder');
$folder = null;

if ($folderId !== '') {
    $folder = $data['folders'][$folderId] ?? null;
    if (!$folder || ($user['role'] !== 'admin' && $folder['owner_id'] !== $user['id'])) {
        http_response_code(404);
        exit('Không tìm thấy thư mục.');
    }
}

$files = array_filter($data['files'], function (array $file) use ($user, $q, $status, $folderId, $data): bool {
    if ($user['role'] !== 'admin' && $file['owner_id'] !== $user['id']) {
        return false;
    }
    if ($q !== '' && !str_contains(strtolower($file['original_name']), $q)) {
        return false;
    }
    if ($folderId !== '' && ($file['folder_id'] ?? 'root-' . $file['owner_id']) !== $folderId) {
        return false;
    }
    $shares = array_filter($data['shares'], fn($s) => $s['file_id'] === $file['id'] && $s['revoked_at'] === null);
    if ($status === 'shared' && empty($shares)) {
        return false;
    }
    if ($status === 'private' && !empty($shares)) {
        return false;
    }
    return true;
});

// Sắp xếp tệp mới nhất lên đầu
usort($files, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

$pageTitle = $folder ? $folder['name'] : ($q !== '' ? 'Kết quả: "' . $q . '"' : 'Drive của tôi');
layoutStart($pageTitle);
?>

<div class="drive-container">
    <?php 
    $pendingInvites = countPendingInvitations($data, $user['id']);
    if ($pendingInvites > 0): 
    ?>
        <div class="invitation-banner">
            <div class="invitation-banner-text">
                <span class="invitation-banner-icon">📬</span>
                <div>
                    <strong>Bạn có <?= $pendingInvites ?> lời mời chia sẻ tệp mới!</strong>
                    <span style="display:block; color: var(--color-text-sub); font-size: 13px;">Người dùng khác đã chia sẻ tệp cho bạn. Vui lòng kiểm tra và xác nhận lưu vào danh sách của bạn.</span>
                </div>
            </div>
            <a href="/shared.php" class="btn btn-sm btn-primary">Xem lời mời ngay ›</a>
        </div>
    <?php endif; ?>

    <!-- Breadcrumb & Header -->
    <div class="drive-header-bar">
        <div class="breadcrumb-trail">
            <a href="/" class="crumb-link">Drive của tôi</a>
            <?php if ($folder): ?>
                <span class="crumb-sep">›</span>
                <span class="crumb-current"><?= h($folder['name']) ?></span>
            <?php elseif ($q !== ''): ?>
                <span class="crumb-sep">›</span>
                <span class="crumb-current">Tìm kiếm: "<?= h($q) ?>"</span>
            <?php endif; ?>
        </div>

        <div class="drive-header-actions">
            <?php if ($folder): ?>
                <a href="/folders.php" class="btn btn-outline btn-sm">‹ Danh sách thư mục</a>
            <?php endif; ?>
            <a href="/upload.php<?= $folder ? '?folder=' . rawurlencode($folder['id']) : '' ?>" class="btn btn-primary btn-sm">
                <span>＋</span> Tải tệp lên
            </a>
            <a href="/folders.php" class="btn btn-outline btn-sm">
                <span>📁</span> Thư mục mới
            </a>
        </div>
    </div>

    <!-- Toolbar: Filters & View Switch -->
    <div class="drive-toolbar">
        <form class="toolbar-filter" method="get">
            <?php if ($folderId !== ''): ?>
                <input type="hidden" name="folder" value="<?= h($folderId) ?>">
            <?php endif; ?>
            <?php if ($q !== ''): ?>
                <input type="hidden" name="q" value="<?= h($q) ?>">
            <?php endif; ?>
            
            <div class="filter-pill-group">
                <a href="/?<?= http_build_query(array_filter(['folder' => $folderId, 'q' => $q])) ?>" 
                   class="filter-pill <?= $status === '' ? 'active' : '' ?>">Tất cả tệp (<?= count($files) ?>)</a>
                <a href="/?<?= http_build_query(array_filter(['status' => 'shared', 'folder' => $folderId, 'q' => $q])) ?>" 
                   class="filter-pill <?= $status === 'shared' ? 'active' : '' ?>">Đang chia sẻ</a>
                <a href="/?<?= http_build_query(array_filter(['status' => 'private', 'folder' => $folderId, 'q' => $q])) ?>" 
                   class="filter-pill <?= $status === 'private' ? 'active' : '' ?>">Riêng tư</a>
            </div>
        </form>

        <div class="view-toggle">
            <button type="button" class="btn-icon" id="btn-list-view" title="Xem danh sách" onclick="setViewMode('list')">☰</button>
            <button type="button" class="btn-icon" id="btn-grid-view" title="Xem dạng lưới" onclick="setViewMode('grid')">▦</button>
        </div>
    </div>

    <!-- File View: Empty State or File Table/Grid -->
    <?php if (empty($files)): ?>
        <div class="empty-state-card">
            <div class="empty-icon-circle">📂</div>
            <h3>Chưa có tệp nào ở đây</h3>
            <p>Kéo và thả tệp vào đây hoặc nhấn nút "Tải tệp lên" để bắt đầu lưu trữ.</p>
            <a href="/upload.php<?= $folder ? '?folder=' . rawurlencode($folder['id']) : '' ?>" class="btn btn-primary">
                Tải tệp lên ngay
            </a>
        </div>
    <?php else: ?>
        <!-- List View -->
        <div id="drive-list-container" class="drive-table-wrap">
            <table class="drive-table">
                <thead>
                    <tr>
                        <th class="th-name">Tên tệp</th>
                        <th class="th-owner">Chủ sở hữu</th>
                        <th class="th-date">Ngày tạo</th>
                        <th class="th-size">Kích thước</th>
                        <th class="th-status">Chia sẻ</th>
                        <th class="th-actions">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($files as $file): 
                        $ext = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION));
                        $shares = array_filter($data['shares'], fn($s) => $s['file_id'] === $file['id'] && $s['revoked_at'] === null);
                        $activeShares = array_filter($shares, fn($s) => validShare($s, time()));
                        $isOwnerOrAdmin = ($file['owner_id'] === $user['id'] || $user['role'] === 'admin');
                    ?>
                    <tr class="file-row-item">
                        <td class="td-name">
                            <a href="/preview.php?file=<?= rawurlencode($file['id']) ?>" class="file-title-link">
                                <span class="file-badge badge-<?= h($ext) ?>"><?= h(strtoupper(substr($ext, 0, 4))) ?></span>
                                <span class="file-name-text" title="<?= h($file['original_name']) ?>"><?= h($file['original_name']) ?></span>
                            </a>
                        </td>
                        <td class="td-owner">
                            <span class="owner-pill">
                                <?= h($data['users'][$file['owner_id']]['name'] ?? 'Chủ tệp') ?>
                            </span>
                        </td>
                        <td class="td-date"><?= h(date('d/m/Y H:i', strtotime($file['created_at']))) ?></td>
                        <td class="td-size"><?= formatBytes((int)$file['size']) ?></td>
                        <td class="td-status">
                            <?php if (!empty($activeShares)): ?>
                                <span class="badge-status status-shared" title="<?= count($activeShares) ?> liên kết hợp lệ">
                                    🔗 Đang chia sẻ (<?= count($activeShares) ?>)
                                </span>
                            <?php else: ?>
                                <span class="badge-status status-private">🔒 Riêng tư</span>
                            <?php endif; ?>
                        </td>
                        <td class="td-actions">
                            <div class="row-btn-group">
                                <a href="/preview.php?file=<?= rawurlencode($file['id']) ?>" class="btn-action" title="Xem trước">👁</a>
                                <?php if ($isOwnerOrAdmin): ?>
                                    <a href="/share.php?file=<?= rawurlencode($file['id']) ?>" class="btn-action" title="Quản lý chia sẻ">🔗</a>
                                    <a href="/download.php?file=<?= rawurlencode($file['id']) ?>" class="btn-action" title="Tải xuống">⇩</a>
                                    <a href="/logs.php?file=<?= rawurlencode($file['id']) ?>" class="btn-action" title="Lịch sử tải">⏱</a>
                                    <a href="/delete.php?file=<?= rawurlencode($file['id']) ?>" class="btn-action btn-danger-action" title="Xóa tệp">🗑</a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Grid View -->
        <div id="drive-grid-container" class="drive-grid" style="display:none;">
            <?php foreach ($files as $file): 
                $ext = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION));
                $shares = array_filter($data['shares'], fn($s) => $s['file_id'] === $file['id'] && $s['revoked_at'] === null);
                $activeShares = array_filter($shares, fn($s) => validShare($s, time()));
                $isOwnerOrAdmin = ($file['owner_id'] === $user['id'] || $user['role'] === 'admin');
            ?>
            <div class="drive-card">
                <a href="/preview.php?file=<?= rawurlencode($file['id']) ?>" class="card-preview-area">
                    <div class="card-file-type badge-<?= h($ext) ?>"><?= h(strtoupper(substr($ext, 0, 4))) ?></div>
                    <div class="card-preview-symbol">📄</div>
                </a>
                <div class="card-body">
                    <a href="/preview.php?file=<?= rawurlencode($file['id']) ?>" class="card-title" title="<?= h($file['original_name']) ?>">
                        <?= h($file['original_name']) ?>
                    </a>
                    <div class="card-meta">
                        <span><?= formatBytes((int)$file['size']) ?></span> · 
                        <span><?= h(date('d/m/Y', strtotime($file['created_at']))) ?></span>
                    </div>
                </div>
                <div class="card-footer">
                    <div class="card-status-badge">
                        <?php if (!empty($activeShares)): ?>
                            <span class="badge-status status-shared">Đang chia sẻ (<?= count($activeShares) ?>)</span>
                        <?php else: ?>
                            <span class="badge-status status-private">Riêng tư</span>
                        <?php endif; ?>
                    </div>
                    <div class="card-actions">
                        <a href="/preview.php?file=<?= rawurlencode($file['id']) ?>" class="btn-action" title="Xem trước">👁</a>
                        <?php if ($isOwnerOrAdmin): ?>
                            <a href="/share.php?file=<?= rawurlencode($file['id']) ?>" class="btn-action" title="Chia sẻ">🔗</a>
                            <a href="/download.php?file=<?= rawurlencode($file['id']) ?>" class="btn-action" title="Tải xuống">⇩</a>
                            <a href="/delete.php?file=<?= rawurlencode($file['id']) ?>" class="btn-action btn-danger-action" title="Xóa">🗑</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Drag & Drop Fullscreen Overlay -->
<div class="drag-overlay" id="drag-overlay" aria-hidden="true">
    <div class="drag-overlay-card">
        <div class="drag-overlay-icon">⬆</div>
        <h3>Thả tệp vào đây để tải lên</h3>
        <p>Tệp sẽ được lưu vào: <strong><?= h($folder ? $folder['name'] : 'Tệp của tôi') ?></strong></p>
    </div>
</div>

<script>
(function() {
    const listContainer = document.getElementById('drive-list-container');
    const gridContainer = document.getElementById('drive-grid-container');
    const btnList = document.getElementById('btn-list-view');
    const btnGrid = document.getElementById('btn-grid-view');

    window.setViewMode = function(mode) {
        if (!listContainer || !gridContainer) return;
        if (mode === 'grid') {
            listContainer.style.display = 'none';
            gridContainer.style.display = 'grid';
            if (btnGrid) btnGrid.classList.add('active');
            if (btnList) btnList.classList.remove('active');
            localStorage.setItem('openshare_view', 'grid');
        } else {
            listContainer.style.display = 'block';
            gridContainer.style.display = 'none';
            if (btnList) btnList.classList.add('active');
            if (btnGrid) btnGrid.classList.remove('active');
            localStorage.setItem('openshare_view', 'list');
        }
    };

    const savedMode = localStorage.getItem('openshare_view') || 'list';
    window.setViewMode(savedMode);

    // Drag and drop overlay handler
    const overlay = document.getElementById('drag-overlay');
    const targetFolder = '<?= h($folder ? $folder['id'] : 'root-' . $user['id']) ?>';
    const csrfToken = '<?= h(csrf()) ?>';
    let dragCounter = 0;

    window.addEventListener('dragenter', (e) => {
        if (e.dataTransfer && e.dataTransfer.types.includes('Files')) {
            dragCounter++;
            overlay.classList.add('visible');
        }
    });

    window.addEventListener('dragleave', () => {
        dragCounter--;
        if (dragCounter <= 0) {
            overlay.classList.remove('visible');
            dragCounter = 0;
        }
    });

    window.addEventListener('dragover', (e) => {
        if (e.dataTransfer && e.dataTransfer.types.includes('Files')) {
            e.preventDefault();
        }
    });

    window.addEventListener('drop', async (e) => {
        if (!e.dataTransfer || !e.dataTransfer.types.includes('Files')) return;
        e.preventDefault();
        overlay.classList.remove('visible');
        dragCounter = 0;

        const files = Array.from(e.dataTransfer.files || []);
        if (!files.length) return;

        overlay.classList.add('uploading');
        overlay.querySelector('h3').textContent = 'Đang tải lên ' + files.length + ' tệp...';

        for (const file of files) {
            const formData = new FormData();
            formData.append('csrf', csrfToken);
            formData.append('folder_id', targetFolder);
            formData.append('file', file, file.name);

            try {
                const res = await fetch('/upload.php', { method: 'POST', body: formData });
                if (!res.ok) {
                    alert('Lỗi tải tệp: ' + file.name);
                }
            } catch (err) {
                alert('Không thể kết nối máy chủ khi tải ' + file.name);
            }
        }
        window.location.reload();
    });
})();
</script>

<?php layoutEnd(); ?>
