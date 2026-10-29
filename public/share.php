<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
startApp();

$user = requireLogin();
$data = readStore();
$fileId = get('file');
$file = $data['files'][$fileId] ?? null;

if (!$file || !userCanManageFile($user, $file)) {
    http_response_code(404);
    exit('Không tìm thấy tệp hoặc bạn không có quyền chia sẻ tệp này.');
}

$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verifyCsrf();
    $public = post('access') === 'public';
    $recipient = $public ? $user['id'] : post('recipient');
    $days = filter_var(post('days'), FILTER_VALIDATE_INT);
    $max = filter_var(post('max_downloads'), FILTER_VALIDATE_INT);
    $password = post('password');

    if (!$public && (!isset($data['users'][$recipient]) || $data['users'][$recipient]['role'] === 'admin')) {
        $errors[] = 'Vui lòng chọn tài khoản người nhận hợp lệ.';
    }
    if ($days === false || $days < 1 || $days > 365) {
        $errors[] = 'Hạn dùng liên kết phải từ 1 đến 365 ngày.';
    }
    if ($max === false || $max < 1 || $max > 1000) {
        $errors[] = 'Giới hạn số lượt tải phải từ 1 đến 1000.';
    }

    if (empty($errors)) {
        $shareId = bin2hex(random_bytes(8));
        $expires = date(DATE_ATOM, time() + $days * 86400);

        withStore(function (array &$store) use ($shareId, $fileId, $recipient, $max, $expires, $password, $user, $file, $public): void {
            $store['shares'][$shareId] = [
                'id' => $shareId,
                'file_id' => $fileId,
                'owner_id' => $file['owner_id'],
                'recipient_id' => $recipient,
                'expires_at' => $expires,
                'max_downloads' => $max,
                'downloads' => 0,
                'password_hash' => $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null,
                'created_at' => date(DATE_ATOM),
                'revoked_at' => null,
                'queue' => [],
                'accepted' => $public
            ];
            logEvent($store, 'share_created', $user['id'], $fileId);
        });

        flash('Tạo liên kết chia sẻ thành công!');
        redirect('/share.php?file=' . rawurlencode($fileId));
    }
}

$data = readStore();
$shares = array_filter($data['shares'], fn($s) => $s['file_id'] === $fileId);
$ext = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION));

layoutStart('Chia sẻ: ' . $file['original_name']);
?>

<div class="drive-container-narrow">
    <div class="page-header-bar">
        <div class="header-with-badge">
            <span class="file-badge badge-<?= h($ext) ?>"><?= h(strtoupper(substr($ext, 0, 4))) ?></span>
            <div>
                <h2>Chia sẻ "<?= h($file['original_name']) ?>"</h2>
                <p class="text-muted"><?= formatBytes((int)$file['size']) ?> · Quản lý quyền truy cập và hạn dùng</p>
            </div>
        </div>
        <a href="/" class="btn btn-outline btn-sm">‹ Quay lại Drive</a>
    </div>

    <?php foreach ($errors as $err): ?>
        <div class="flash error"><?= h($err) ?></div>
    <?php endforeach; ?>

    <!-- Form tạo liên kết mới -->
    <div class="card share-create-card">
        <div class="card-header">
            <h3>Tạo liên kết chia sẻ mới</h3>
            <p class="text-muted">Đặt quyền truy cập, thời hạn hiệu lực, lượt tải và mật khẩu bảo vệ</p>
        </div>

        <form method="post" class="share-form">
            <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">

            <div class="form-row">
                <div class="form-group col-12">
                    <label for="share-access">Phạm vi truy cập</label>
                    <select name="access" id="share-access" class="form-control">
                        <option value="public">🌐 Bất kỳ ai có liên kết (Công khai)</option>
                        <option value="recipient">👤 Tài khoản cụ thể (Người nhận riêng)</option>
                    </select>
                </div>
            </div>

            <div class="form-group" id="recipient-field" style="display:none;">
                <label for="recipient-select">Chọn người nhận</label>
                <select name="recipient" id="recipient-select" class="form-control" disabled>
                    <?php 
                    foreach ($data['users'] as $cand): 
                        if ($cand['id'] !== $user['id'] && $cand['role'] !== 'admin'):
                    ?>
                        <option value="<?= h($cand['id']) ?>">
                            <?= h($cand['name']) ?> (<?= h($cand['email']) ?>)
                        </option>
                    <?php 
                        endif;
                    endforeach; 
                    ?>
                </select>
            </div>

            <div class="form-row">
                <div class="form-group col-6">
                    <label for="days">Thời hạn (ngày)</label>
                    <input type="number" id="days" name="days" value="7" min="1" max="365" class="form-control" required>
                </div>
                <div class="form-group col-6">
                    <label for="max_downloads">Số lượt tải tối đa</label>
                    <input type="number" id="max_downloads" name="max_downloads" value="5" min="1" max="1000" class="form-control" required>
                </div>
            </div>

            <div class="form-group">
                <label for="password">Mật khẩu bảo vệ (Tùy chọn)</label>
                <input type="password" id="password" name="password" placeholder="Để trống nếu không đặt mật khẩu" class="form-control">
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <span>🔗</span> Tạo liên kết chia sẻ
                </button>
            </div>
        </form>
    </div>

    <!-- Danh sách liên kết đã cấp -->
    <div class="card share-list-card">
        <div class="card-header">
            <h3>Các liên kết đã tạo (<?= count($shares) ?>)</h3>
            <p class="text-muted">Theo dõi lượt tải, trạng thái hạn dùng và thu hồi liên kết khi cần</p>
        </div>

        <?php if (empty($shares)): ?>
            <div class="empty-state-card small">
                <p class="text-muted">Chưa có liên kết chia sẻ nào cho tệp này.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="drive-table">
                    <thead>
                        <tr>
                            <th>Đối tượng</th>
                            <th>Liên kết</th>
                            <th>Hết hạn</th>
                            <th>Lượt tải</th>
                            <th>Hàng đợi</th>
                            <th>Trạng thái</th>
                            <th>Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        foreach ($shares as $share): 
                            normalizeShare($share);
                            $active = validShare($share, time());
                            $waiting = count(array_filter($share['queue'], fn($entry) => ($entry['status'] ?? 'waiting') === 'waiting'));
                            $shareUrl = 'http' . (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 's' : '') . '://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8127') . '/preview.php?file=' . rawurlencode($fileId) . '&share=' . rawurlencode($share['id']);
                            $recipientLabel = isPublicShare($share) ? '🌐 Công khai' : ($data['users'][$share['recipient_id']]['email'] ?? $share['recipient_id']);
                        ?>
                        <tr>
                            <td><strong><?= h($recipientLabel) ?></strong></td>
                            <td>
                                <div class="copy-input-group">
                                    <input type="text" readonly value="<?= h($shareUrl) ?>" class="input-copy" onclick="this.select()">
                                    <button type="button" class="btn btn-sm btn-outline" onclick="copyLink('<?= h($shareUrl) ?>', this)">
                                        Sao chép
                                    </button>
                                </div>
                            </td>
                            <td><?= h(date('d/m/Y H:i', strtotime($share['expires_at']))) ?></td>
                            <td><strong><?= (int)$share['downloads'] ?></strong> / <?= (int)$share['max_downloads'] ?></td>
                            <td>
                                <?php if ($waiting > 0): ?>
                                    <span class="badge-status status-waiting"><?= $waiting ?> chờ</span>
                                <?php else: ?>
                                    <span class="text-muted">0</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($share['revoked_at']): ?>
                                    <span class="badge-status status-revoked">Đã thu hồi</span>
                                <?php elseif ($active): ?>
                                    <span class="badge-status status-active">Còn hạn</span>
                                <?php else: ?>
                                    <span class="badge-status status-expired">Hết hạn</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($share['revoked_at'] === null): ?>
                                    <form method="post" action="/revoke.php" onsubmit="return confirm('Bạn có chắc muốn thu hồi liên kết này không?');">
                                        <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                                        <input type="hidden" name="share" value="<?= h($share['id']) ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">Thu hồi</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
const accessSelect = document.getElementById('share-access');
const recipientField = document.getElementById('recipient-field');
const recipientSelect = document.getElementById('recipient-select');

function updateRecipientVisibility() {
    const isSpecific = accessSelect.value === 'recipient';
    recipientField.style.display = isSpecific ? 'block' : 'none';
    recipientSelect.disabled = !isSpecific;
    recipientSelect.required = isSpecific;
}

accessSelect.addEventListener('change', updateRecipientVisibility);
updateRecipientVisibility();

function copyLink(text, btn) {
    navigator.clipboard.writeText(text).then(() => {
        const orig = btn.textContent;
        btn.textContent = '✓ Đã chép!';
        btn.classList.add('btn-success');
        setTimeout(() => {
            btn.textContent = orig;
            btn.classList.remove('btn-success');
        }, 1800);
    }).catch(() => {
        alert('Liên kết: ' + text);
    });
}
</script>

<?php layoutEnd(); ?>
