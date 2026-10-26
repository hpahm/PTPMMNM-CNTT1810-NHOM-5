<?php
require __DIR__ . '/../src/bootstrap.php'; startApp(); $user = requireLogin(); $errors = []; $data = readStore();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf(); $upload = $_FILES['file'] ?? null;
    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) $errors[] = 'Chưa chọn tệp hoặc tải lên thất bại.';
    elseif ((int)$upload['size'] <= 0 || (int)$upload['size'] > MAX_FILE_BYTES) $errors[] = 'Tệp phải lớn hơn 0 và không quá 10 MB.';
    elseif (!allowedExtension((string)$upload['name'])) $errors[] = 'Loại tệp không được phép. Chỉ nhận PDF, TXT, JPG, PNG, ZIP, DOCX.';
    elseif (!diskHasSpace((int)$upload['size'])) $errors[] = 'Ổ đĩa không còn đủ dung lượng để nhận tệp.';
    elseif (userUsage(readStore(), $user['id']) + (int)$upload['size'] > (int)$user['quota']) $errors[] = 'Vượt dung lượng được cấp.';
    if (!$errors) {
        $id = bin2hex(random_bytes(8)); $stored = FILE_ROOT . '/' . $id . '.bin';
        if (!move_uploaded_file($upload['tmp_name'], $stored)) $errors[] = 'Không thể lưu tệp trên máy chủ.';
        elseif (!detectedMimeIsAllowed((string)$upload['name'], $stored)) { unlink($stored); $errors[] = 'Nội dung tệp không khớp với loại tệp đã chọn.'; }
        else withStore(function(array &$data) use ($id, $user, $upload, $stored): void {
            $folderId = post('folder_id');
            $defaultFolder = 'root-' . $user['id'];
            if (!isset($data['folders'][$folderId]) || $data['folders'][$folderId]['owner_id'] !== $user['id']) $folderId = $defaultFolder;
            $data['files'][$id] = ['id'=>$id,'owner_id'=>$user['id'],'folder_id'=>$folderId,'original_name'=>basename((string)$upload['name']),'stored_name'=>basename($stored),'size'=>(int)$upload['size'],'mime'=>mime_content_type($stored) ?: 'application/octet-stream','created_at'=>date(DATE_ATOM),'download_count'=>0];
            logEvent($data, 'upload', $user['id'], $id);
        });
        if (!$errors) { flash('Tải tệp thành công.'); redirect('/'); }
    }
}
layoutStart('Tải tệp lên'); echo '<section class="panel upload-panel"><h1>Tải tệp lên</h1>';
foreach ($errors as $error) echo '<div class="flash error">'.h($error).'</div>';
echo '<p class="muted">Định dạng: PDF, TXT, JPG, PNG, ZIP, DOCX · tối đa 10 MB.</p><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="'.h(csrf()).'"><label>Thư mục<select name="folder_id">';
foreach ($data['folders'] as $folder) if ($folder['owner_id'] === $user['id']) echo '<option value="'.h($folder['id']).'">'.h($folder['name']).'</option>';
echo '</select></label><label class="drop-zone" id="drop-zone"><span class="drop-icon">⇧</span><strong>Thả file vào đây</strong><small>hoặc bấm để chọn file</small><input type="file" name="file" id="file-input" required></label><div class="upload-name" id="upload-name"></div><button>Tải lên</button></form><script>const zone=document.getElementById("drop-zone"), input=document.getElementById("file-input"), name=document.getElementById("upload-name"); function showFile(file){if(file) name.textContent="Đã chọn: "+file.name;} zone.addEventListener("dragover",e=>{e.preventDefault();zone.classList.add("dragging")}); zone.addEventListener("dragleave",()=>zone.classList.remove("dragging")); zone.addEventListener("drop",e=>{e.preventDefault();zone.classList.remove("dragging");if(e.dataTransfer.files.length){input.files=e.dataTransfer.files;showFile(input.files[0])}}); input.addEventListener("change",()=>showFile(input.files[0]));</script></section>'; layoutEnd();
