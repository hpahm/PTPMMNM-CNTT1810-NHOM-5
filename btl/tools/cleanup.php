<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$delete = ($argv[1] ?? '--preview') === '--delete';
$data = readStore();
$expired = [];
foreach ($data['files'] as $file) {
    $hasActiveShare = false;
    foreach ($data['shares'] as $share) {
        if ($share['file_id'] === $file['id'] && validShare($share, time())) { $hasActiveShare = true; break; }
    }
    if (!$hasActiveShare && array_filter($data['shares'], fn($share) => $share['file_id'] === $file['id'])) $expired[] = $file;
}
echo "DANH SACH CO THE XOA (" . count($expired) . "):\n";
foreach ($expired as $file) echo "- {$file['id']} {$file['original_name']}\n";
if (!$delete || !$expired) { echo "Xem truoc; dung --delete de xac nhan xoa.\n"; exit(0); }
withStore(function (array &$store) use ($expired): void {
    foreach ($expired as $file) {
        unset($store['files'][$file['id']]);
        foreach ($store['shares'] as $id => $share) if ($share['file_id'] === $file['id']) unset($store['shares'][$id]);
        logEvent($store, 'expired_file_deleted', 'admin', $file['id']);
        $path = FILE_ROOT . '/' . basename($file['stored_name']);
        if (is_file($path)) unlink($path);
    }
});
echo "DA XOA " . count($expired) . " tep.\n";
