<?php
require __DIR__ . '/../src/bootstrap.php'; startApp(); $user = requireLogin(); $fileId = get('file'); $data = readStore(); $file = $data['files'][$fileId] ?? null;
if (!$file || !userCanManageFile($user, $file)) { http_response_code(404); exit('Không tìm thấy tệp.'); }
$path = FILE_ROOT . '/' . basename((string)$file['stored_name']);
$fileExists = is_file($path);
$active = $fileExists
    ? array_filter($data['shares'], fn($s) => $s['file_id'] === $fileId && validShare($s, time()))
    : [];
if ($active) { http_response_code(409); exit('Chỉ được xóa tệp không còn liên kết hợp lệ.'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    withStore(function(array &$store) use ($fileId,$user): void { if (isset($store['files'][$fileId])) { unset($store['files'][$fileId]); logEvent($store,'file_deleted',$user['id'],$fileId); } });
    if ($fileExists && !unlink($path)) {
        throw new RuntimeException('Không thể xóa tệp vật lý.');
    }
    flash('Đã xóa tệp sau khi xác nhận.'); redirect('/');
}
$message = $fileExists
    ? 'Bạn có chắc muốn xóa <strong>'.h($file['original_name']).'</strong>? Hành động này không thể hoàn tác.'
    : 'Tệp vật lý không còn trên máy chủ. Bạn có chắc muốn xóa bản ghi <strong>'.h($file['original_name']).'</strong> khỏi hệ thống?';
layoutStart('Xác nhận xóa'); echo '<section class="panel"><h1>Xóa tệp hết hạn</h1><p>'.$message.'</p><form method="post"><input type="hidden" name="csrf" value="'.h(csrf()).'"><button class="danger">Xác nhận xóa</button> <a class="button secondary" href="/">Hủy</a></form></section>'; layoutEnd();
