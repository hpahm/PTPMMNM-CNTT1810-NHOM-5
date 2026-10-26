<?php
require __DIR__ . '/../src/bootstrap.php'; startApp(); $user = requireLogin(); $data = readStore();
$q = strtolower(get('q')); $status = get('status'); $folderId = get('folder');
$folder = null;
if ($folderId !== '') {
    $folder = $data['folders'][$folderId] ?? null;
    if (!$folder || ($user['role'] !== 'admin' && $folder['owner_id'] !== $user['id'])) {
        http_response_code(404);
        exit('Không tìm thấy thư mục.');
    }
}
$files = array_filter($data['files'], function(array $file) use ($user, $q, $status, $folderId, $data): bool {
    $visibleShares = array_filter($data['shares'], fn($s) => $s['file_id'] === $file['id'] && $s['revoked_at'] === null && strtotime($s['expires_at']) > time() && $s['downloads'] < $s['max_downloads']);
    if ($user['role'] !== 'admin' && $file['owner_id'] !== $user['id']) return false;
    if ($q !== '' && !str_contains(strtolower($file['original_name']), $q)) return false;
    if ($folderId !== '' && ($file['folder_id'] ?? 'root') !== $folderId) return false;
    $shares = array_filter($data['shares'], fn($s) => $s['file_id'] === $file['id'] && $s['revoked_at'] === null);
    if ($status === 'shared' && !$shares) return false; if ($status === 'private' && $shares) return false;
    return true;
});
layoutStart($folder ? $folder['name'] : 'Tệp của tôi');
$heading = $folder ? h($folder['name']) : 'Tệp của tôi';
$back = $folder ? '<a class="button secondary" href="/folders.php">Quay lại thư mục</a>' : '';
echo '<div class="content-heading"><div><h1>'.$heading.'</h1><p class="muted">Quản lý và chia sẻ tệp của bạn</p></div>'.$back.'<a class="button" href="/upload.php">＋ Tải tệp lên</a></div>';
echo '<div class="drag-overlay" id="drag-overlay" aria-hidden="true"><div class="drag-overlay-card"><span class="drag-overlay-icon">⇧</span><strong>Thả để tải lên</strong><small>Ảnh, tệp hoặc thư mục</small></div></div><script>(function(){const overlay=document.getElementById("drag-overlay"),folderId="'.h($folder ? $folder['id'] : 'root-'.$user['id']).'",csrf="'.h(csrf()).'";let dragDepth=0,uploading=false;function show(){if(!uploading)overlay.classList.add("visible")}function hide(){overlay.classList.remove("visible");dragDepth=0}async function readEntry(entry){if(entry.isFile)return await new Promise(resolve=>entry.file(resolve));if(entry.isDirectory){const reader=entry.createReader(),files=[];async function readBatch(){const batch=await new Promise(resolve=>reader.readEntries(resolve));for(const child of batch)files.push(...await readEntry(child));if(batch.length)await readBatch();}await readBatch();return files}return []}async function collect(items,files){for(const item of items){const entry=item.webkitGetAsEntry?item.webkitGetAsEntry():null;if(entry)files.push(...await readEntry(entry));else if(item.getAsFile())files.push(item.getAsFile())}}async function upload(files){if(!files.length)return;uploading=true;overlay.classList.add("uploading");overlay.querySelector("strong").textContent="Đang tải lên...";for(const file of files){const body=new FormData();body.append("csrf",csrf);body.append("folder_id",folderId);body.append("file",file,file.name);const response=await fetch("/upload.php",{method:"POST",body});if(!response.ok){uploading=false;overlay.querySelector("strong").textContent="Tải lên thất bại";setTimeout(hide,1800);return}}window.location.reload()}window.addEventListener("dragenter",function(event){if(!event.dataTransfer||!event.dataTransfer.types.includes("Files"))return;dragDepth++;show()});window.addEventListener("dragover",function(event){if(event.dataTransfer&&event.dataTransfer.types.includes("Files")){event.preventDefault();show()}});window.addEventListener("dragleave",function(){dragDepth--;if(dragDepth<=0&&!uploading)hide()});window.addEventListener("drop",async function(event){if(!event.dataTransfer||!event.dataTransfer.types.includes("Files"))return;event.preventDefault();hide();const files=[];await collect(Array.from(event.dataTransfer.items||[]),files);if(!files.length)Array.from(event.dataTransfer.files||[]).forEach(file=>files.push(file));await upload(files)});})();</script>';
echo '<div class="toolbar"><form class="filter-form" method="get"><select name="status"><option value="">Tất cả tệp</option><option value="shared" '.($status==='shared'?'selected':'').'>Đang chia sẻ</option><option value="private" '.($status==='private'?'selected':'').'>Riêng tư</option></select><button class="secondary">Lọc</button></form><span class="view-switch active">☷</span><span class="view-switch">▦</span></div>';
if (!$files) echo '<div class="empty-state"><div class="empty-icon">□</div><h2>Chưa có tệp</h2><p>Tải tệp đầu tiên lên để bắt đầu.</p><a class="button" href="/upload.php">Tải tệp lên</a></div>';
else echo '<div class="file-table"><div class="file-row file-head"><span>Tên</span><span>Chủ sở hữu</span><span>Ngày tải lên</span><span>Kích thước</span><span></span></div>';
foreach ($files as $file) {
    $shares = array_filter($data['shares'], fn($s) => $s['file_id'] === $file['id'] && $s['revoked_at'] === null);
    $ext = strtoupper(pathinfo($file['original_name'], PATHINFO_EXTENSION));
    $userShare = null;
    foreach ($shares as $share) {
        if ($share['recipient_id'] === $user['id'] && validShare($share, time())) {
            $userShare = $share;
            break;
        }
    }
    $previewQuery = $userShare ? '?file='.rawurlencode($file['id']).'&share='.rawurlencode($userShare['id']) : '?file='.rawurlencode($file['id']);
    $previewLink = '<a class="file-name" href="/preview.php'.$previewQuery.'"><span class="file-icon '.strtolower($ext).'">'.h(substr($ext,0,1)).'</span><strong>'.h($file['original_name']).'</strong></a>';
    echo '<div class="file-row">'.$previewLink.'<span>'.h($data['users'][$file['owner_id']]['name']).'</span><span>'.h(date('d/m/Y', strtotime($file['created_at']))).'</span><span>'.number_format((int)$file['size']).' bytes</span><span class="row-actions">⋮<div class="row-menu">'.($file['owner_id'] === $user['id'] || $user['role'] === 'admin' ? '<a href="/preview.php?file='.h($file['id']).'">Xem trước</a><a href="/share.php?file='.h($file['id']).'">Chia sẻ</a><a href="/logs.php?file='.h($file['id']).'">Lịch sử</a><a href="/delete.php?file='.h($file['id']).'">Xóa</a>' : '').'</div></span></div>';
    if ($file['owner_id'] === $user['id'] || $user['role'] === 'admin') echo '<div class="file-meta">Đang chia sẻ với '.count($shares).' người · '.(int)$file['download_count'].' lượt tải</div>';
    foreach ($shares as $share) if ($share['recipient_id'] === $user['id'] && validShare($share,time())) echo '<a class="preview-link" href="/preview.php?file='.h($file['id']).'&share='.h($share['id']).'">Mở bản xem trước</a>';
}
if ($files) echo '</div>';
layoutEnd();
