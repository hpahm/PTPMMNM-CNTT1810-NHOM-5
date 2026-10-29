<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
startApp();

$user = requireLogin();
$errors = [];
$data = readStore();
$selectedFolder = get('folder');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verifyCsrf();
    $upload = $_FILES['file'] ?? null;

    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errors[] = 'Chưa chọn tệp hoặc quá trình tải lên gặp sự cố.';
    } elseif ((int)$upload['size'] <= 0 || (int)$upload['size'] > MAX_FILE_BYTES) {
        $errors[] = 'Kích thước tệp không hợp lệ (phải lớn hơn 0 và không vượt quá 10 MB).';
    } elseif (!allowedExtension((string)$upload['name'])) {
        $errors[] = 'Loại tệp không được phép. Hệ thống chỉ nhận định dạng PDF, TXT, JPG, JPEG, PNG, ZIP, DOCX.';
    } elseif (!diskHasSpace((int)$upload['size'])) {
        $errors[] = 'Dung lượng ổ đĩa máy chủ không còn đủ để tiếp nhận tệp.';
    } elseif (userUsage(readStore(), $user['id']) + (int)$upload['size'] > (int)$user['quota']) {
        $errors[] = 'Dung lượng tệp vượt quá hạn mức bộ nhớ (Quota) còn lại của tài khoản.';
    }

    if (empty($errors)) {
        $id = bin2hex(random_bytes(8));
        $stored = FILE_ROOT . '/' . $id . '.bin';

        if (!move_uploaded_file($upload['tmp_name'], $stored)) {
            $errors[] = 'Không thể lưu trữ tệp vào thư mục an toàn trên máy chủ.';
        } elseif (!detectedMimeIsAllowed((string)$upload['name'], $stored)) {
            unlink($stored);
            $errors[] = 'Nội dung tệp thực tế (MIME) không khớp với phần mở rộng tệp.';
        } else {
            withStore(function (array &$store) use ($id, $user, $upload, $stored): void {
                $folderId = post('folder_id');
                $defaultFolder = 'root-' . $user['id'];
                if (!isset($store['folders'][$folderId]) || $store['folders'][$folderId]['owner_id'] !== $user['id']) {
                    $folderId = $defaultFolder;
                }
                $store['files'][$id] = [
                    'id' => $id,
                    'owner_id' => $user['id'],
                    'folder_id' => $folderId,
                    'original_name' => basename((string)$upload['name']),
                    'stored_name' => basename($stored),
                    'size' => (int)$upload['size'],
                    'mime' => mime_content_type($stored) ?: 'application/octet-stream',
                    'created_at' => date(DATE_ATOM),
                    'download_count' => 0,
                ];
                logEvent($store, 'upload', $user['id'], $id);
            });

            flash('Tải tệp lên thành công!');
            redirect($selectedFolder !== '' ? '/?folder=' . rawurlencode($selectedFolder) : '/');
        }
    }
}

layoutStart('Tải tệp lên');
?>

<div class="drive-container-narrow">
    <div class="page-header-bar">
        <div>
            <h2>Tải tệp lên Drive</h2>
            <p class="text-muted">Lưu trữ tệp có kiểm soát quyền truy cập và chia sẻ an toàn</p>
        </div>
        <a href="/" class="btn btn-outline btn-sm">‹ Quay lại Drive</a>
    </div>

    <?php foreach ($errors as $err): ?>
        <div class="flash error"><?= h($err) ?></div>
    <?php endforeach; ?>

    <div class="card upload-card">
        <form method="post" enctype="multipart/form-data" id="upload-form">
            <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">

            <div class="form-group">
                <label for="folder_id">Lưu vào thư mục</label>
                <select name="folder_id" id="folder_id" class="form-control">
                    <?php 
                    $userFolders = array_filter($data['folders'], fn($f) => $f['owner_id'] === $user['id']);
                    foreach ($userFolders as $f): 
                    ?>
                        <option value="<?= h($f['id']) ?>" <?= ($selectedFolder === $f['id']) ? 'selected' : '' ?>>
                            📁 <?= h($f['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="upload-dropzone" id="dropzone">
                <input type="file" name="file" id="file-input" required 
                       accept=".pdf,.txt,.jpg,.jpeg,.png,.zip,.docx">
                <div class="dropzone-content">
                    <div class="dropzone-icon">☁️</div>
                    <div class="dropzone-title">Kéo thả tệp vào đây hoặc <span class="text-primary">chọn từ thiết bị</span></div>
                    <div class="dropzone-hint">Định dạng hỗ trợ: PDF, TXT, JPG, PNG, ZIP, DOCX · Tối đa 10 MB</div>
                    <div class="file-chosen-info" id="file-info" style="display:none;">
                        <span class="chosen-icon">📄</span>
                        <span class="chosen-name" id="chosen-name"></span>
                        <span class="chosen-size" id="chosen-size"></span>
                    </div>
                </div>
            </div>

            <div class="upload-specs-card">
                <div class="spec-item">
                    <span class="spec-label">Hạn mức tệp:</span>
                    <span class="spec-val">Tối đa 10 MB / tệp</span>
                </div>
                <div class="spec-item">
                    <span class="spec-label">Bộ nhớ tài khoản:</span>
                    <span class="spec-val"><?= formatBytes(userUsage($data, $user['id'])) ?> / <?= formatBytes((int)$user['quota']) ?></span>
                </div>
            </div>

            <div class="form-actions">
                <a href="/" class="btn btn-outline">Hủy</a>
                <button type="submit" class="btn btn-primary" id="btn-submit">
                    <span>⬆</span> Tải lên ngay
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    const dropzone = document.getElementById('dropzone');
    const input = document.getElementById('file-input');
    const fileInfo = document.getElementById('file-info');
    const chosenName = document.getElementById('chosen-name');
    const chosenSize = document.getElementById('chosen-size');

    function formatBytes(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(2) + ' MB';
    }

    function updatePreview(file) {
        if (!file) {
            fileInfo.style.display = 'none';
            return;
        }
        chosenName.textContent = file.name;
        chosenSize.textContent = '(' + formatBytes(file.size) + ')';
        fileInfo.style.display = 'inline-flex';
    }

    input.addEventListener('change', () => {
        if (input.files && input.files[0]) {
            updatePreview(input.files[0]);
        }
    });

    ['dragover', 'dragenter'].forEach(evt => {
        dropzone.addEventListener(evt, (e) => {
            e.preventDefault();
            dropzone.classList.add('is-dragover');
        });
    });

    ['dragleave', 'dragend', 'drop'].forEach(evt => {
        dropzone.addEventListener(evt, (e) => {
            e.preventDefault();
            dropzone.classList.remove('is-dragover');
        });
    });

    dropzone.addEventListener('drop', (e) => {
        if (e.dataTransfer && e.dataTransfer.files.length) {
            input.files = e.dataTransfer.files;
            updatePreview(input.files[0]);
        }
    });
})();
</script>

<?php layoutEnd(); ?>
