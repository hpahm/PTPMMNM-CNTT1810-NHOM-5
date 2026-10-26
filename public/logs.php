<?php
require __DIR__ . '/../src/bootstrap.php'; startApp(); $user = requireLogin(); $data = readStore(); $fileId = get('file'); $logs = array_reverse(array_filter($data['logs'], function (array $log) use ($user, $data, $fileId): bool {
    if ($user['role'] === 'admin' || $log['user_id'] === $user['id']) return true;
    return $fileId !== '' && isset($data['files'][$fileId]) && $data['files'][$fileId]['owner_id'] === $user['id'] && $log['file_id'] === $fileId;
}));
layoutStart('Lịch sử truy cập'); echo '<h1>Lịch sử cấp quyền và tải</h1><div class="panel"><table><tr><th>Thời gian</th><th>Thao tác</th><th>Tài khoản</th><th>Tệp</th><th>Kết quả</th></tr>';
foreach (array_slice($logs,0,100) as $log) echo '<tr><td>'.h($log['at']).'</td><td>'.h($log['action']).'</td><td>'.h($data['users'][$log['user_id']]['email'] ?? $log['user_id']).'</td><td>'.h($data['files'][$log['file_id']]['original_name'] ?? '-').'</td><td>'.($log['success']?'OK':'Từ chối').'</td></tr>';
echo '</table></div>'; layoutEnd();
