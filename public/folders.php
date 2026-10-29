<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
startApp();

$user = requireLogin();
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verifyCsrf();
    $name = post('name');

    if ($name === '' || mb_strlen($name) > 80 || str_contains($name, "\0") || in_array($name, ['.', '..'], true)) {
        $errors[] = 'Tên thư mục không hợp lệ (độ dài tối đa 80 ký tự).';
    }

    if (empty($errors)) {
        $created = withStore(function (array &$data) use ($user, $name): bool {
            foreach ($data['folders'] as $folder) {
                if ($folder['owner_id'] === $user['id'] && strcasecmp($folder['name'], $name) === 0) {
                    return false;
                }
            }
            $id = bin2hex(random_bytes(8));
            $data['folders'][$id] = [
                'id' => $id,
                'owner_id' => $user['id'],
                'name' => $name,
                'parent_id' => 'root-' . $user['id'],
                'created_at' => date(DATE_ATOM),
            ];
            logEvent($data, 'folder_created', $user['id']);
            return true;
        });

        if ($created) {
            flash('Tạo thư mục thành công!');
            redirect('/folders.php');
        }
        $errors[] = 'Thư mục với tên này đã tồn tại trong kho của bạn.';
    }
}

$data = readStore();
$folders = array_filter($data['folders'], fn($folder) => $folder['owner_id'] === $user['id']);

layoutStart('Quản lý Thư mục');
?>

<div class="drive-container">
    <div class="page-header-bar">
        <div>
            <h2>Quản lý Thư mục</h2>
            <p class="text-muted">Tổ chức và phân loại tệp lưu trữ trong tài khoản của bạn</p>
        </div>
        <a href="/" class="btn btn-outline btn-sm">‹ Quay lại Drive</a>
    </div>

    <?php foreach ($errors as $err): ?>
        <div class="flash error"><?= h($err) ?></div>
    <?php endforeach; ?>

    <!-- Card tạo thư mục mới -->
    <div class="card mb-4">
        <div class="card-header">
            <h3>Tạo thư mục mới</h3>
        </div>
        <form method="post" class="folder-create-form">
            <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
            <div class="inline-form-group">
                <input type="text" name="name" maxlength="80" placeholder="Nhập tên thư mục (ví dụ: Tài liệu học tập, Đồ án...)" class="form-control" required autofocus>
                <button type="submit" class="btn btn-primary">
                    <span>＋</span> Tạo thư mục
                </button>
            </div>
        </form>
    </div>

    <!-- Danh sách thư mục -->
    <div class="card">
        <div class="card-header">
            <h3>Thư mục của bạn (<?= count($folders) ?>)</h3>
        </div>

        <div class="folder-grid">
            <?php foreach ($folders as $folder): 
                $fileCount = count(array_filter($data['files'], fn($f) => ($f['folder_id'] ?? '') === $folder['id']));
            ?>
            <a href="/?folder=<?= rawurlencode($folder['id']) ?>" class="folder-card-item">
                <div class="folder-icon">📁</div>
                <div class="folder-details">
                    <strong class="folder-name" title="<?= h($folder['name']) ?>"><?= h($folder['name']) ?></strong>
                    <span class="folder-count"><?= $fileCount ?> tệp</span>
                </div>
                <div class="folder-arrow">›</div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php layoutEnd(); ?>
