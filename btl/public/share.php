<?php
require __DIR__ . '/../src/bootstrap.php'; startApp(); $user = requireLogin(); $data = readStore(); $fileId = get('file'); $file = $data['files'][$fileId] ?? null;
if (!$file || !userCanManageFile($user, $file)) { http_response_code(404); exit('Không tìm thấy tệp.'); }
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf(); $public = post('access') === 'public'; $recipient = $public ? $user['id'] : post('recipient'); $days = filter_var(post('days'), FILTER_VALIDATE_INT); $max = filter_var(post('max_downloads'), FILTER_VALIDATE_INT); $password = post('password');
    if (!$public && (!isset($data['users'][$recipient]) || $data['users'][$recipient]['role'] === 'admin')) $errors[] = 'Người nhận không hợp lệ.';
    if ($days === false || $days < 1 || $days > 365) $errors[] = 'Hạn dùng phải từ 1 đến 365 ngày.';
    if ($max === false || $max < 1 || $max > 1000) $errors[] = 'Lượt tải phải từ 1 đến 1000.';
    if (!$errors) {
        $shareId = bin2hex(random_bytes(8)); $expires = date(DATE_ATOM, time() + $days * 86400);
        withStore(function(array &$store) use ($shareId,$fileId,$recipient,$max,$expires,$password,$user,$file): void {
            $store['shares'][$shareId] = ['id'=>$shareId,'file_id'=>$fileId,'owner_id'=>$file['owner_id'],'recipient_id'=>$recipient,'expires_at'=>$expires,'max_downloads'=>$max,'downloads'=>0,'password_hash'=>$password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null,'created_at'=>date(DATE_ATOM),'revoked_at'=>null,'queue'=>[]];
            logEvent($store, 'share_created', $user['id'], $fileId);
        });
        flash('Đã tạo liên kết chia sẻ.'); redirect('/share.php?file='.rawurlencode($fileId));
    }
}
$data = readStore(); $shares = array_filter($data['shares'], fn($s) => $s['file_id'] === $fileId);
layoutStart('Quản lý chia sẻ'); echo '<div class="actions"><h1>'.h($file['original_name']).'</h1><a class="button secondary" href="/">Quay lại</a></div>';
foreach ($errors as $error) echo '<div class="flash error">'.h($error).'</div>';
echo '<section class="panel"><h2>Tạo liên kết</h2><form method="post"><input type="hidden" name="csrf" value="'.h(csrf()).'"><label>Phạm vi truy cập<select name="access" id="share-access"><option value="public">Bất kỳ ai có liên kết</option><option value="recipient">Tài khoản cụ thể</option></select></label><div id="recipient-field" style="display:none"><label>Người nhận<select name="recipient" disabled>';
foreach ($data['users'] as $candidate) if ($candidate['id'] !== $user['id'] && $candidate['role'] !== 'admin') echo '<option value="'.h($candidate['id']).'">'.h($candidate['name']).' ('.h($candidate['email']).')</option>';
echo '</select></label></div><label>Số ngày hiệu lực<input type="number" name="days" value="7" min="1" max="365" required></label><label>Giới hạn lượt tải<input type="number" name="max_downloads" value="5" min="1" max="1000" required></label><label>Mật khẩu tùy chọn<input type="password" name="password"></label><button>Tạo liên kết</button></form><script>const access=document.getElementById("share-access"), recipientField=document.getElementById("recipient-field"), recipient=recipientField.querySelector("select"); function updateRecipient(){const specific=access.value==="recipient"; recipientField.style.display=specific?"block":"none"; recipient.disabled=!specific; recipient.required=specific;} access.addEventListener("change",updateRecipient); updateRecipient();</script></section><section class="panel"><h2>Lịch sử cấp quyền</h2><table><tr><th>Người nhận</th><th>Liên kết</th><th>Hiệu lực</th><th>Đã tải</th><th>Trạng thái</th><th>Đang chờ</th><th></th></tr>';
foreach ($shares as $share) { normalizeShare($share); $active = validShare($share, time()); $waiting = count(array_filter($share['queue'], fn($entry) => ($entry['status'] ?? 'waiting') === 'waiting')); $shareUrl = 'http' . (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 's' : '') . '://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8127') . '/preview.php?file=' . rawurlencode($fileId) . '&share=' . rawurlencode($share['id']); $recipientLabel = isPublicShare($share) ? 'Bất kỳ ai có link' : ($data['users'][$share['recipient_id']]['email'] ?? $share['recipient_id']); echo '<tr><td>'.h($recipientLabel).'</td><td><input readonly value="'.h($shareUrl).'" onclick="this.select()"><button type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)">Sao chép</button></td><td>'.h($share['expires_at']).'</td><td>'.(int)$share['downloads'].' / '.(int)$share['max_downloads'].'</td><td>'.($share['revoked_at'] ? 'Đã thu hồi' : ($active ? 'Còn hiệu lực' : 'Hết hạn')).'</td><td>'.$waiting.' yêu cầu chờ</td><td>'.($share['revoked_at']===null ? '<form method="post" action="/revoke.php"><input type="hidden" name="csrf" value="'.h(csrf()).'"><input type="hidden" name="share" value="'.h($share['id']).'"><button class="danger">Thu hồi</button></form>' : '').'</td></tr>'; }
echo '</table></section>'; layoutEnd();
