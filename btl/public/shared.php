<?php
require __DIR__ . '/../src/bootstrap.php';
startApp();
$user = requireLogin();
$data = readStore();
$pending = [];
$accepted = [];
foreach ($data['shares'] as $share) {
    if ($share['recipient_id'] !== $user['id'] || !validShare($share, time())) continue;
    $file = $data['files'][$share['file_id']] ?? null;
    if (!$file) continue;
    if (shareIsAccepted($share)) $accepted[] = [$share, $file];
    else $pending[] = [$share, $file];
}
layoutStart('Được chia sẻ');
echo '<div class="content-heading"><div><h1>Được chia sẻ</h1><p class="muted">Các file người khác chia sẻ cho bạn.</p></div></div>';
if ($pending) {
    echo '<section class="panel"><h2>Đang chờ xác nhận</h2><p class="muted">Mở xem trước để kiểm tra rồi xác nhận lưu file.</p><div class="shared-grid">';
    foreach ($pending as [$share, $file]) echo '<a class="shared-card pending" href="/preview.php?file='.rawurlencode($file['id']).'&share='.rawurlencode($share['id']).'"><span class="file-icon '.h(strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION))).'">↗</span><strong>'.h($file['original_name']).'</strong><small>Chờ bạn xác nhận</small></a>';
    echo '</div></section>';
}
echo '<section class="panel"><h2>File đã lưu</h2>';
if (!$accepted) echo '<div class="empty-state"><div class="empty-icon">◫</div><h2>Chưa có file được lưu</h2><p>File bạn xác nhận sẽ xuất hiện ở đây.</p></div>';
else {
    echo '<div class="shared-grid">';
    foreach ($accepted as [$share, $file]) echo '<a class="shared-card" href="/preview.php?file='.rawurlencode($file['id']).'&share='.rawurlencode($share['id']).'"><span class="file-icon '.h(strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION))).'">↗</span><strong>'.h($file['original_name']).'</strong><small>Từ '.h($data['users'][$file['owner_id']]['name'] ?? 'người dùng khác').'</small></a>';
    echo '</div>';
}
echo '</section>';
layoutEnd();
