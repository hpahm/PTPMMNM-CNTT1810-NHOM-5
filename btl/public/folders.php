<?php
require __DIR__ . '/../src/bootstrap.php'; startApp(); $user = requireLogin(); $errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf(); $name = post('name');
    if ($name === '' || strlen($name) > 80 || str_contains($name, "\0") || in_array($name, ['.','..'], true)) $errors[] = 'Tên thư mục không hợp lệ.';
    if (!$errors) {
        $created = withStore(function (array &$data) use ($user, $name): bool {
            foreach ($data['folders'] as $folder) if ($folder['owner_id'] === $user['id'] && strcasecmp($folder['name'], $name) === 0) return false;
            $id = bin2hex(random_bytes(8));
            $data['folders'][$id] = ['id'=>$id,'owner_id'=>$user['id'],'name'=>$name,'parent_id'=>'root-' . $user['id'],'created_at'=>date(DATE_ATOM)];
            logEvent($data, 'folder_created', $user['id']);
            return true;
        });
        if ($created) {
            flash('Đã tạo thư mục.'); redirect('/folders.php');
        }
        $errors[] = 'Thư mục đã tồn tại.';
    }
}
$data = readStore(); $folders = array_filter($data['folders'], fn($folder) => $folder['owner_id'] === $user['id']);
layoutStart('Thư mục');
echo '<div class="content-heading"><div><h1>Thư mục</h1><p class="muted">Sắp xếp tệp trong kho của bạn.</p></div><a class="button" href="/">Quay lại tệp</a></div><section class="panel"><h2>Tạo thư mục</h2>';
foreach ($errors as $error) echo '<div class="flash error">'.h($error).'</div>';
echo '<form class="actions" method="post"><input type="hidden" name="csrf" value="'.h(csrf()).'"><input name="name" maxlength="80" placeholder="Tên thư mục" required><button>Tạo thư mục</button></form></section><section class="folder-grid">';
foreach ($folders as $folder) {
    $count = count(array_filter($data['files'], fn($file) => ($file['folder_id'] ?? '') === $folder['id']));
    echo '<a class="folder-card" href="/?folder='.rawurlencode($folder['id']).'"><span>▰</span><strong>'.h($folder['name']).'</strong><small>'.$count.' tệp</small></a>';
}
echo '</section>'; layoutEnd();
