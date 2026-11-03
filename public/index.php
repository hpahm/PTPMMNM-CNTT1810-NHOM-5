<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
startApp();

$user = requireLogin();
$data = readStore();

$q = strtolower(get('q'));
$status = get('status');
$folderId = get('folder');
$currentFolderId = $folderId !== '' ? $folderId : 'root-' . $user['id'];
$folder = null;

if ($folderId !== '') {
    $folder = $data['folders'][$folderId] ?? null;
    if (!$folder || ($user['role'] !== 'admin' && $folder['owner_id'] !== $user['id'])) {
        http_response_code(404);
        exit('Không tìm thấy thư mục.');
    }
}

$breadcrumbFolders = [];
$breadcrumbCursor = $folder;
while ($breadcrumbCursor && ($breadcrumbCursor['id'] ?? '') !== 'root-' . $user['id']) {
    array_unshift($breadcrumbFolders, $breadcrumbCursor);
    $parentId = $breadcrumbCursor['parent_id'] ?? '';
    $breadcrumbCursor = $parentId !== '' ? ($data['folders'][$parentId] ?? null) : null;
}

$files = array_filter($data['files'], function (array $file) use ($user, $q, $status, $currentFolderId, $data): bool {
    if ($user['role'] !== 'admin' && $file['owner_id'] !== $user['id']) {
        return false;
    }
    if ($q !== '' && !str_contains(strtolower($file['original_name']), $q)) {
        return false;
    }
    if (($file['folder_id'] ?? 'root-' . $file['owner_id']) !== $currentFolderId) {
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

$parentFolderId = $folderId !== '' ? $folderId : 'root-' . $user['id'];
$folders = array_values(array_filter($data['folders'], function (array $candidate) use ($user, $parentFolderId): bool {
    return $candidate['owner_id'] === $user['id']
        && strcasecmp((string)($candidate['name'] ?? ''), 'Tệp của tôi') !== 0
        && ($candidate['parent_id'] ?? 'root-' . $user['id']) === $parentFolderId;
}));
usort($folders, fn($a, $b) => strcasecmp($a['name'], $b['name']));

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
            <a href="/" class="crumb-link drop-target" data-drop-folder="root-<?= h($user['id']) ?>">Drive của tôi</a>
            <?php foreach ($breadcrumbFolders as $breadcrumbFolder): ?>
                <span class="crumb-sep">›</span>
                <a href="/?folder=<?= rawurlencode($breadcrumbFolder['id']) ?>"
                   class="crumb-link drop-target<?= $breadcrumbFolder['id'] === $currentFolderId ? ' crumb-current' : '' ?>"
                   data-drop-folder="<?= h($breadcrumbFolder['id']) ?>"><?= h($breadcrumbFolder['name']) ?></a>
            <?php endforeach; ?>
            <?php if (!$folder && $q !== ''): ?>
                <span class="crumb-sep">›</span>
                <span class="crumb-current">Tìm kiếm: "<?= h($q) ?>"</span>
            <?php endif; ?>
        </div>

    </div>
    <div id="drive-upload-drop-zone" class="drive-upload-drop-zone drop-target" data-drop-folder="<?= h($currentFolderId) ?>" hidden>
        <span class="drive-upload-drop-icon">⬆</span>
        <span>Thả tệp để tải lên</span>
        <small>Drive của tôi</small>
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
            <button type="button" class="btn-icon" id="btn-list-view" title="Xem dạng danh sách" aria-label="Xem dạng danh sách" onclick="setViewMode('list')">☰</button>
            <button type="button" class="btn-icon" id="btn-grid-view" title="Xem dạng lưới" aria-label="Xem dạng lưới" onclick="setViewMode('grid')">▦</button>
        </div>
    </div>

    <?php if (!empty($folders)): ?>
        <section class="drive-folders-section" aria-labelledby="folders-heading">
            <div class="drive-section-heading">
                <h2 id="folders-heading"><?= $folder ? 'Thư mục con' : 'Thư mục' ?></h2>
                <span><?= count($folders) ?> thư mục</span>
            </div>
            <div class="drive-folder-grid">
                <?php foreach ($folders as $driveFolder):
                ?>
                    <div class="drive-folder-card" role="link" tabindex="0" draggable="true" data-folder-link="/?folder=<?= rawurlencode($driveFolder['id']) ?>" data-folder-id="<?= h($driveFolder['id']) ?>" data-drop-folder="<?= h($driveFolder['id']) ?>" data-created="<?= h($driveFolder['created_at']) ?>">
                        <span class="drive-folder-icon">📁</span>
                        <span class="drive-folder-name" title="<?= h($driveFolder['name']) ?>"><?= h($driveFolder['name']) ?></span>
                        <span class="folder-menu-anchor">
                            <button type="button" class="folder-more-button" data-folder-menu-button="<?= h($driveFolder['id']) ?>" aria-label="Tùy chọn thư mục">⋮</button>
                            <div class="folder-more-menu" data-folder-menu="<?= h($driveFolder['id']) ?>" hidden>
                                <a href="/download-folder.php?folder=<?= rawurlencode($driveFolder['id']) ?>">⇩ <span>Tải xuống</span></a>
                                <button type="button" data-folder-action="rename" data-folder-id="<?= h($driveFolder['id']) ?>">✎ <span>Đổi tên</span></button>
                                <button type="button" class="folder-sort-trigger" data-folder-action="sort">⇅ <span>Sắp xếp</span><b>›</b></button>
                                <div class="folder-sort-menu" hidden>
                                    <button type="button" data-sort-folders="name-asc">Tên (A-Z)</button>
                                    <button type="button" data-sort-folders="name-desc">Tên (Z-A)</button>
                                    <button type="button" data-sort-folders="created-desc">Mới nhất</button>
                                </div>
                                <button type="button" data-folder-action="info" data-folder-id="<?= h($driveFolder['id']) ?>">ⓘ <span>Chi tiết</span></button>
                                <button type="button" class="folder-danger" data-folder-action="delete" data-folder-id="<?= h($driveFolder['id']) ?>">🗑 <span>Chuyển vào thùng rác</span></button>
                            </div>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- File View: Empty State or File Table/Grid -->
    <?php if (empty($files) && empty($folders)): ?>
        <div class="empty-state-card">
            <div class="empty-icon-circle">📂</div>
            <h3>Chưa có tệp nào ở đây</h3>
            <p>Kéo và thả tệp vào đây hoặc nhấn nút "Tải tệp lên" để bắt đầu lưu trữ.</p>
        </div>
    <?php elseif (!empty($files)): ?>
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
                    <tr class="file-row-item" draggable="<?= $isOwnerOrAdmin ? 'true' : 'false' ?>" data-file-id="<?= h($file['id']) ?>">
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
            <div class="drive-card" draggable="<?= $isOwnerOrAdmin ? 'true' : 'false' ?>" data-file-id="<?= h($file['id']) ?>">
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

<div class="folder-modal" id="folder-modal" aria-hidden="true">
    <div class="folder-modal-backdrop" data-close-folder-modal></div>
    <section class="folder-modal-card" role="dialog" aria-modal="true" aria-labelledby="folder-modal-title">
        <h2 id="folder-modal-title">Tạo thư mục mới</h2>
        <p class="text-muted">Nhập tên thư mục để tạo trong vị trí hiện tại.</p>
        <form id="folder-create-form">
            <label for="folder-name">Tên thư mục</label>
            <input id="folder-name" name="name" class="form-control" maxlength="80" required autocomplete="off" placeholder="Ví dụ: Tài liệu học tập">
            <div class="folder-modal-error" id="folder-modal-error" role="alert"></div>
            <div class="folder-modal-actions">
                <button type="button" class="btn btn-outline" data-close-folder-modal>Hủy</button>
                <button type="submit" class="btn btn-primary" id="folder-create-submit">Tạo</button>
            </div>
        </form>
    </section>
</div>

<!-- Drag & Drop Fullscreen Overlay -->
<div class="drag-overlay" id="drag-overlay" aria-hidden="true">
    <div class="drag-overlay-card">
        <div class="drag-overlay-icon">⬆</div>
        <h3>Thả tệp vào đây để tải lên</h3>
        <p>Tệp sẽ được lưu vào: <strong><?= h($folder ? $folder['name'] : 'Tệp của tôi') ?></strong></p>
    </div>
</div>
<aside class="upload-status-panel" id="upload-status-panel" hidden aria-live="polite">
    <div class="upload-status-heading">
        <strong id="upload-status-title">Đang tải lên...</strong>
        <button type="button" id="upload-status-close" aria-label="Đóng">×</button>
    </div>
    <div class="upload-progress-track"><span id="upload-progress-bar"></span></div>
    <div class="upload-status-detail" id="upload-status-detail">Đang chuẩn bị...</div>
    <div class="upload-complete-links" id="upload-complete-links"></div>
</aside>

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
    const folderModal = document.getElementById('folder-modal');
    const folderForm = document.getElementById('folder-create-form');
    const folderName = document.getElementById('folder-name');
    const folderError = document.getElementById('folder-modal-error');
    const currentFolder = '<?= h($folder ? $folder['id'] : 'root-' . $user['id']) ?>';
    const newButton = document.getElementById('new-menu-button');
    const newMenu = document.getElementById('new-menu');
    const newFileInput = document.getElementById('new-file-input');
    const newFolderInput = document.getElementById('new-folder-input');
    const dropTargets = Array.from(document.querySelectorAll('[data-drop-folder]'));
    const fileItems = Array.from(document.querySelectorAll('[data-file-id]'));
    const folderItems = Array.from(document.querySelectorAll('[data-folder-id]'));
    const uploadDropZone = document.getElementById('drive-upload-drop-zone');
    const uploadStatusPanel = document.getElementById('upload-status-panel');
    const uploadProgressBar = document.getElementById('upload-progress-bar');
    const uploadStatusTitle = document.getElementById('upload-status-title');
    const uploadStatusDetail = document.getElementById('upload-status-detail');
    const uploadCompleteLinks = document.getElementById('upload-complete-links');
    document.getElementById('upload-status-close')?.addEventListener('click', () => {
        uploadStatusPanel.hidden = true;
    });
    let dragCounter = 0;
    let internalDrag = false;
    const folderMenus = Array.from(document.querySelectorAll('[data-folder-menu]'));

    document.querySelectorAll('[data-folder-link]').forEach((card) => {
        card.addEventListener('click', (event) => {
            if (event.target.closest('.folder-menu-anchor')) return;
            window.location.href = card.dataset.folderLink;
        });
        card.addEventListener('keydown', (event) => {
            if ((event.key === 'Enter' || event.key === ' ') && !event.target.closest('.folder-menu-anchor')) {
                event.preventDefault();
                window.location.href = card.dataset.folderLink;
            }
        });
    });

    document.querySelectorAll('[data-folder-menu-button]').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            const menu = document.querySelector(`[data-folder-menu="${button.dataset.folderMenuButton}"]`);
            folderMenus.forEach((candidate) => {
                if (candidate !== menu) candidate.hidden = true;
            });
            menu.hidden = !menu.hidden;
        });
    });

    document.querySelectorAll('[data-folder-action="rename"], [data-folder-action="delete"], [data-folder-action="info"]').forEach((action) => {
        action.addEventListener('click', async (event) => {
            event.preventDefault();
            event.stopPropagation();
            const card = action.closest('.drive-folder-card');
            const folderId = action.dataset.folderId;
            const type = action.dataset.folderAction;
            if (type === 'info') {
                alert(`Thư mục: ${card.querySelector('.drive-folder-name').textContent.trim()}`);
                return;
            }
            if (type === 'delete' && !confirm('Chuyển thư mục và toàn bộ nội dung vào thùng rác?')) return;
            const name = type === 'rename'
                ? prompt('Nhập tên mới:', card.querySelector('.drive-folder-name').textContent.trim())
                : '';
            if (type === 'rename' && name === null) return;
            try {
                const response = await fetch('/folder-action.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                    body: JSON.stringify({folder: folderId, action: type, name, csrf: csrfToken})
                });
                const result = await response.json();
                if (!response.ok || !result.ok) throw new Error(result.error || 'Không thể thực hiện thao tác.');
                window.location.reload();
            } catch (error) {
                alert(error.message);
            }
        });
    });

    document.querySelectorAll('.folder-sort-trigger').forEach((trigger) => {
        trigger.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            trigger.nextElementSibling.hidden = !trigger.nextElementSibling.hidden;
        });
    });
    document.querySelectorAll('[data-sort-folders]').forEach((sortButton) => {
        sortButton.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            const grid = sortButton.closest('.drive-folder-grid') || document.querySelector('.drive-folder-grid');
            const cards = Array.from(grid.querySelectorAll('.drive-folder-card'));
            const mode = sortButton.dataset.sortFolders;
            cards.sort((a, b) => {
                if (mode === 'created-desc') {
                    return (b.dataset.created || '').localeCompare(a.dataset.created || '');
                }
                const comparison = a.querySelector('.drive-folder-name').textContent.localeCompare(
                    b.querySelector('.drive-folder-name').textContent, 'vi', {sensitivity: 'base'}
                );
                return mode === 'name-desc' ? -comparison : comparison;
            });
            cards.forEach((card) => grid.appendChild(card));
            sortButton.closest('.folder-sort-menu').hidden = true;
        });
    });

    function closeFolderModal() {
        if (!folderModal) return;
        folderModal.classList.remove('visible');
        folderModal.setAttribute('aria-hidden', 'true');
        folderForm.reset();
        folderError.textContent = '';
    }

    function openFolderModal() {
        folderModal.classList.add('visible');
        folderModal.setAttribute('aria-hidden', 'false');
        folderName.focus();
    }

    newButton?.addEventListener('click', () => {
        const isOpen = !newMenu.hidden;
        newMenu.hidden = isOpen;
        newButton.setAttribute('aria-expanded', String(!isOpen));
    });
    document.querySelectorAll('[data-new-action]').forEach((action) => {
        action.addEventListener('click', () => {
            newMenu.hidden = true;
            newButton.setAttribute('aria-expanded', 'false');
            if (action.dataset.newAction === 'folder') {
                openFolderModal();
            } else if (action.dataset.newAction === 'file') {
                newFileInput.click();
            } else {
                newFolderInput.click();
            }
        });
    });
    newFileInput?.addEventListener('change', () => {
        uploadFiles(Array.from(newFileInput.files || []), currentFolder);
        newFileInput.value = '';
    });
    newFolderInput?.addEventListener('change', () => {
        const records = Array.from(newFolderInput.files || []).map((file) => ({
            file,
            path: file.webkitRelativePath || file.name
        }));
        uploadDirectory(records, currentFolder);
        newFolderInput.value = '';
    });
    document.addEventListener('click', (event) => {
        if (newMenu && newButton && !newMenu.contains(event.target) && !newButton.contains(event.target)) {
            newMenu.hidden = true;
            newButton.setAttribute('aria-expanded', 'false');
        }
    });
    document.querySelectorAll('[data-close-folder-modal]').forEach((element) => {
        element.addEventListener('click', closeFolderModal);
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeFolderModal();
    });
    folderForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const submit = document.getElementById('folder-create-submit');
        submit.disabled = true;
        folderError.textContent = '';
        try {
            const response = await fetch('/create-folder.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                body: JSON.stringify({name: folderName.value, parent: currentFolder, csrf: csrfToken})
            });
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.error || 'Không thể tạo thư mục.');
            window.location.reload();
        } catch (error) {
            folderError.textContent = error.message;
            submit.disabled = false;
        }
    });

    function showUploadStatus(title, detail, percent = 0) {
        uploadStatusPanel.hidden = false;
        uploadStatusTitle.textContent = title;
        uploadStatusDetail.textContent = detail;
        uploadProgressBar.style.width = `${percent}%`;
        uploadCompleteLinks.replaceChildren();
    }

    function uploadWithProgress(url, formData, detail) {
        return new Promise((resolve, reject) => {
            const request = new XMLHttpRequest();
            request.open('POST', url);
            request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            request.upload.addEventListener('progress', (event) => {
                if (event.lengthComputable) {
                    uploadProgressBar.style.width = `${Math.round((event.loaded / event.total) * 100)}%`;
                }
                uploadStatusDetail.textContent = detail;
            });
            request.addEventListener('load', () => {
                let result;
                try { result = JSON.parse(request.responseText); } catch { reject(new Error('Phản hồi máy chủ không hợp lệ.')); return; }
                if (request.status < 200 || request.status >= 300 || !result.ok) {
                    reject(new Error(result.error || 'Tải lên thất bại.'));
                    return;
                }
                resolve(result);
            });
            request.addEventListener('error', () => reject(new Error('Không thể kết nối máy chủ.')));
            request.send(formData);
        });
    }

    function showUploadComplete(links) {
        uploadStatusTitle.textContent = 'Tải lên thành công';
        uploadStatusDetail.textContent = 'Bạn có thể mở nội dung vừa tải lên:';
        uploadProgressBar.style.width = '100%';
        uploadCompleteLinks.replaceChildren();
        links.forEach((link) => {
            const anchor = document.createElement('a');
            anchor.href = link.href;
            anchor.textContent = link.label;
            uploadCompleteLinks.appendChild(anchor);
        });
    }

    async function uploadFiles(files, folderId) {
        if (!files.length) return;
        showUploadStatus('Đang tải tệp lên...', `0/${files.length} tệp`, 0);
        const links = [];
        for (const [index, file] of files.entries()) {
            const formData = new FormData();
            formData.append('csrf', csrfToken);
            formData.append('folder_id', folderId);
            formData.append('file', file, file.name);
            try {
                const result = await uploadWithProgress('/upload-file.php', formData, `${index + 1}/${files.length} tệp: ${file.name}`);
                links.push({label: file.name, href: `/?folder=${encodeURIComponent(result.folder_id)}`});
            } catch (err) {
                uploadStatusTitle.textContent = 'Tải lên thất bại';
                uploadStatusDetail.textContent = err.message + ` (${file.name})`;
                return;
            }
        }
        showUploadComplete(links);
    }

    async function uploadDirectory(records, folderId) {
        if (!records.length) return;
        showUploadStatus('Đang tải thư mục lên...', `0/${records.length} tệp`, 0);
        const formData = new FormData();
        formData.append('csrf', csrfToken);
        formData.append('folder_id', folderId);
        records.forEach((record) => {
            formData.append('files[]', record.file, record.file.name);
            formData.append('paths[]', record.path);
        });
        try {
            const result = await uploadWithProgress('/upload-directory.php', formData, `Đang tải ${records.length} tệp...`);
            showUploadComplete([{label: 'Mở thư mục vừa tải lên', href: `/?folder=${encodeURIComponent(result.folder_id)}`}]);
        } catch (error) {
            uploadStatusTitle.textContent = 'Tải lên thất bại';
            uploadStatusDetail.textContent = error.message;
        }
    }

    function readDirectoryEntries(reader) {
        return new Promise((resolve, reject) => {
            const entries = [];
            const readBatch = () => reader.readEntries((batch) => {
                if (!batch.length) {
                    resolve(entries);
                    return;
                }
                entries.push(...batch);
                readBatch();
            }, reject);
            readBatch();
        });
    }

    function readEntryFile(entry) {
        return new Promise((resolve, reject) => entry.file(resolve, reject));
    }

    async function collectDroppedEntry(entry, parentPath = '') {
        const path = parentPath ? parentPath + '/' + entry.name : entry.name;
        if (entry.isFile) {
            return [{file: await readEntryFile(entry), path}];
        }
        if (!entry.isDirectory) return [];
        const entries = await readDirectoryEntries(entry.createReader());
        const records = [];
        for (const child of entries) {
            records.push(...await collectDroppedEntry(child, path));
        }
        return records;
    }

    async function collectDroppedItems(dataTransfer) {
        const items = Array.from(dataTransfer.items || []);
        const handles = [];
        for (const item of items) {
            if (typeof item.getAsFileSystemHandle !== 'function') continue;
            const handle = await item.getAsFileSystemHandle();
            if (handle) handles.push(handle);
        }
        if (handles.length) {
            const records = [];
            for (const handle of handles) {
                if (handle.kind === 'file') {
                    records.push({file: await handle.getFile(), path: handle.name});
                    continue;
                }
                for await (const [name, child] of handle.entries()) {
                    const childRecords = await collectFileSystemHandle(child, handle.name + '/' + name);
                    records.push(...childRecords);
                }
            }
            if (records.length) return records;
        }
        const entries = items
            .map((item) => typeof item.webkitGetAsEntry === 'function' ? item.webkitGetAsEntry() : null)
            .filter(Boolean);
        if (entries.length) {
            const records = [];
            for (const entry of entries) {
                records.push(...await collectDroppedEntry(entry));
            }
            if (records.length) return records;
        }
        return Array.from(dataTransfer.files || []).map((file) => ({
            file,
            path: file.webkitRelativePath || file.name
        }));
    }

    async function collectFileSystemHandle(handle, parentPath) {
        if (handle.kind === 'file') {
            return [{file: await handle.getFile(), path: parentPath}];
        }
        const records = [];
        for await (const [name, child] of handle.entries()) {
            records.push(...await collectFileSystemHandle(child, parentPath + '/' + name));
        }
        return records;
    }

    function hasExternalFiles(dataTransfer) {
        if (!dataTransfer) return false;
        const types = Array.from(dataTransfer.types || []);
        if (types.includes('application/x-openshare-file') || types.includes('application/x-openshare-folder')) {
            return false;
        }
        return types.includes('Files');
    }

    function isInternalTransfer(dataTransfer) {
        const types = Array.from(dataTransfer?.types || []);
        return internalDrag
            || types.includes('application/x-openshare-file')
            || types.includes('application/x-openshare-folder');
    }

    async function uploadDroppedItems(dataTransfer, folderId) {
        const records = await collectDroppedItems(dataTransfer);
        if (!records.length) {
            showUploadStatus('Không thể tải lên', 'Không đọc được tệp từ thao tác kéo thả.');
            return;
        }
        const hasDirectory = records.some((record) => record.path.includes('/'));
        if (hasDirectory) {
            await uploadDirectory(records, folderId);
        } else {
            await uploadFiles(records.map((record) => record.file), folderId);
        }
    }

    function clearFolderDropState() {
        dropTargets.forEach((target) => target.classList.remove('drag-target'));
    }

    fileItems.forEach((item) => {
        item.addEventListener('dragstart', (event) => {
            internalDrag = true;
            if (uploadDropZone) uploadDropZone.hidden = true;
            dragCounter = 0;
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('application/x-openshare-file', item.dataset.fileId);
            item.classList.add('dragging');
        });

        item.addEventListener('dragend', () => {
            internalDrag = false;
            item.classList.remove('dragging');
            clearFolderDropState();
        });
    });

    folderItems.forEach((item) => {
        item.addEventListener('dragstart', (event) => {
            internalDrag = true;
            if (uploadDropZone) uploadDropZone.hidden = true;
            if (uploadStatusPanel) uploadStatusPanel.hidden = true;
            dragCounter = 0;
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('application/x-openshare-folder', item.dataset.folderId);
            item.classList.add('dragging');
        });
        item.addEventListener('dragend', () => {
            internalDrag = false;
            item.classList.remove('dragging');
            clearFolderDropState();
        });
    });

    dropTargets.forEach((target) => {
        target.addEventListener('dragover', (event) => {
            event.preventDefault();
            target.classList.add('drag-target');
            if (uploadDropZone && target !== uploadDropZone) {
                uploadDropZone.hidden = true;
            }
            event.dataTransfer.dropEffect = internalDrag ? 'move' : 'copy';
        });
        target.addEventListener('dragleave', () => {
            target.classList.remove('drag-target');
            if (uploadDropZone && !internalDrag && target !== uploadDropZone) {
                uploadDropZone.hidden = false;
            }
        });
        target.addEventListener('drop', async (event) => {
            event.preventDefault();
            event.stopPropagation();
            target.classList.remove('drag-target');
            if (uploadDropZone) uploadDropZone.hidden = true;
            const fileId = event.dataTransfer.getData('application/x-openshare-file');
            const draggedFolderId = event.dataTransfer.getData('application/x-openshare-folder');
            if (draggedFolderId) {
                try {
                    const response = await fetch('/folder-action.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                        body: JSON.stringify({folder: draggedFolderId, action: 'move', parent: target.dataset.dropFolder, csrf: csrfToken})
                    });
                    const result = await response.json();
                    if (!response.ok || !result.ok) throw new Error(result.error || 'Không thể di chuyển folder.');
                    window.location.reload();
                } catch (error) {
                    alert(error.message);
                }
                return;
            }
            if (fileId) {
                if (uploadStatusPanel) uploadStatusPanel.hidden = true;
                try {
                    const response = await fetch('/move-file.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                        body: JSON.stringify({file: fileId, folder: target.dataset.dropFolder, csrf: csrfToken})
                    });
                    const result = await response.json();
                    if (!response.ok || !result.ok) throw new Error(result.error || 'Không thể di chuyển tệp.');
                    window.location.reload();
                } catch (error) {
                    alert(error.message);
                }
                return;
            }
            await uploadDroppedItems(event.dataTransfer, target.dataset.dropFolder);
        });
    });

    window.addEventListener('dragenter', (e) => {
        if (isInternalTransfer(e.dataTransfer)) {
            if (uploadDropZone) uploadDropZone.hidden = true;
            return;
        }
        if (hasExternalFiles(e.dataTransfer)) {
            dragCounter++;
            if (uploadDropZone) uploadDropZone.hidden = false;
        }
    });

    window.addEventListener('dragleave', () => {
        if (internalDrag) return;
        dragCounter--;
        if (dragCounter <= 0) {
            if (uploadDropZone) uploadDropZone.hidden = true;
            dragCounter = 0;
        }
    });

    window.addEventListener('dragover', (e) => {
        if (!isInternalTransfer(e.dataTransfer) && hasExternalFiles(e.dataTransfer)) {
            e.preventDefault();
        }
    });

    window.addEventListener('drop', async (e) => {
        if (isInternalTransfer(e.dataTransfer) || !hasExternalFiles(e.dataTransfer)) return;
        e.preventDefault();
        if (uploadDropZone) uploadDropZone.hidden = true;
        dragCounter = 0;

        await uploadDroppedItems(e.dataTransfer, targetFolder);
    });
})();
</script>

<?php layoutEnd(); ?>
