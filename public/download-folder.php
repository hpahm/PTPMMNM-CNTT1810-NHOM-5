<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
startApp();

$user = requireLogin();
$db = database();
$folderId = get('folder');

$fStmt = $db->prepare('SELECT * FROM folders WHERE id = ? AND owner_id = ? LIMIT 1');
$fStmt->execute([$folderId, $user['id']]);
$folder = $fStmt->fetch(PDO::FETCH_ASSOC);
if (!$folder) {
    http_response_code(404);
    exit('Không tìm thấy thư mục.');
}

// Tải cây thư mục của user để giữ đúng cấu trúc thư mục con trong file ZIP
$allFoldersStmt = $db->prepare('SELECT id, name, parent_id FROM folders WHERE owner_id = ?');
$allFoldersStmt->execute([$user['id']]);
$byParent = [];
foreach ($allFoldersStmt->fetchAll(PDO::FETCH_ASSOC) as $cand) {
    $byParent[(string)($cand['parent_id'] ?? '')][] = $cand;
}

$folderRelPaths = [$folderId => ''];
$queue = [$folderId];
while ($queue) {
    $cur = array_shift($queue);
    $prefix = $folderRelPaths[$cur];
    foreach ($byParent[$cur] ?? [] as $child) {
        $cid = $child['id'];
        $safeFolderName = str_replace(['/', '\\', "\0"], '_', (string)$child['name']);
        $folderRelPaths[$cid] = ($prefix !== '' ? $prefix . '/' : '') . $safeFolderName;
        $queue[] = $cid;
    }
}

$folderIds = array_keys($folderRelPaths);
$placeholders = implode(',', array_fill(0, count($folderIds), '?'));
$filesStmt = $db->prepare("SELECT folder_id, original_name, stored_name FROM files WHERE owner_id = ? AND folder_id IN ({$placeholders})");
$filesStmt->execute(array_merge([$user['id']], $folderIds));

$items = [];
$usedNames = [];
foreach ($filesStmt->fetchAll(PDO::FETCH_ASSOC) as $file) {
    $path = safeStoredPath($file);
    if ($path === null) {
        continue;
    }
    $relDir = $folderRelPaths[$file['folder_id']] ?? '';
    $origName = basename((string)$file['original_name']);
    $entryName = ($relDir !== '' ? $relDir . '/' : '') . $origName;
    if (isset($usedNames[$entryName])) {
        $ext = pathinfo($origName, PATHINFO_EXTENSION);
        $base = pathinfo($origName, PATHINFO_FILENAME);
        $n = 2;
        do {
            $candidate = ($relDir !== '' ? $relDir . '/' : '') . $base . " ({$n})" . ($ext !== '' ? '.' . $ext : '');
            $n++;
        } while (isset($usedNames[$candidate]));
        $entryName = $candidate;
    }
    $usedNames[$entryName] = true;
    $items[] = ['name' => $entryName, 'path' => $path];
}

if (!$items) {
    http_response_code(404);
    exit('Thư mục không có tệp.');
}

$archive = tempnam(sys_get_temp_dir(), 'openshare-zip-');
if ($archive === false) {
    http_response_code(500);
    exit('Không thể tạo tệp nén tạm.');
}
try {
    zipWriteArchive($archive, $items);
    $zipName = (preg_replace('/[^a-zA-Z0-9._-]/', '_', (string)$folder['name']) ?: 'folder') . '.zip';
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipName . '"');
    header('Content-Length: ' . filesize($archive));
    $fh = fopen($archive, 'rb');
    if ($fh !== false) {
        while (!feof($fh)) {
            echo fread($fh, 65536);
            flush();
        }
        fclose($fh);
    }
    exit;
} finally {
    @unlink($archive);
}
