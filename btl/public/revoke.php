<?php
require __DIR__ . '/../src/bootstrap.php'; startApp(); $user = requireLogin(); if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method Not Allowed'); } verifyCsrf(); $id = post('share');
withStore(function(array &$data) use ($id,$user): void {
    if (!isset($data['shares'][$id])) return; $share = &$data['shares'][$id];
    if ($user['role'] !== 'admin' && $share['owner_id'] !== $user['id']) return;
    $share['revoked_at'] = date(DATE_ATOM); logEvent($data, 'share_revoked', $user['id'], $share['file_id']);
});
flash('Đã thu hồi liên kết.'); redirect('/'); 
