<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
startApp();
$user = requireLogin();
$data = readStore();
$folderId = get('folder');
$folder = $data['folders'][$folderId] ?? null;
if (!$folder || $folder['owner_id'] !== $user['id']) {
    http_response_code(404);
    exit('Không tìm thấy thư mục.');
}
$temporary = docxTempDirectory();
$archive = $temporary . DIRECTORY_SEPARATOR . 'folder.zip';
try {
    $ids = [$folderId => true];
    do {
        $changed = false;
        foreach ($data['folders'] as $candidate) {
            if (isset($ids[$candidate['parent_id'] ?? ''])) {
                $ids[$candidate['id']] = true;
                $changed = true;
            }
        }
    } while ($changed);
    $paths = [];
    foreach ($data['files'] as $file) {
        if (isset($ids[$file['folder_id'] ?? '']) && ($path = safeStoredPath($file)) !== null) {
            copy($path, $temporary . DIRECTORY_SEPARATOR . basename($file['stored_name']));
            $paths[] = $temporary . DIRECTORY_SEPARATOR . basename($file['stored_name']);
        }
    }
    if (!$paths) {
        http_response_code(404);
        exit('Thư mục không có tệp.');
    }
    docxCommand('a -tzip ' . escapeshellarg($archive) . ' ' . implode(' ', array_map('escapeshellarg', $paths)), $temporary);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $folder['name']) . '.zip"');
    header('Content-Length: ' . filesize($archive));
    readfile($archive);
} finally {
    removeDocxTempDirectory($temporary);
}
