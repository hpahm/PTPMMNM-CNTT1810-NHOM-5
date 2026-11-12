<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$delete = ($argv[1] ?? '--preview') === '--delete';
$db = database();
$nowIso = date(DATE_ATOM);

$trashCutoff = date(DATE_ATOM, time() - 30 * 86400);
$stmt = $db->prepare(
    'SELECT f.id, f.original_name, f.stored_name
     FROM files f
     WHERE (f.deleted_at IS NOT NULL AND f.deleted_at < ?)
        OR (
            EXISTS (SELECT 1 FROM shares s WHERE s.file_id = f.id)
            AND NOT EXISTS (
                SELECT 1 FROM shares s
                WHERE s.file_id = f.id
                  AND s.revoked_at IS NULL
                  AND s.expires_at > ?
                  AND s.downloads < s.max_downloads
            )
        )'
);
$stmt->execute([$trashCutoff, $nowIso]);
$expired = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "DANH SACH CO THE XOA (" . count($expired) . "):\n";
foreach ($expired as $file) echo "- {$file['id']} {$file['original_name']}\n";
if (!$delete || !$expired) { echo "Xem truoc; dung --delete de xac nhan xoa.\n"; exit(0); }

beginImmediate($db);
try {
    $delStmt = $db->prepare('DELETE FROM files WHERE id = ?');
    foreach ($expired as $file) {
        $path = FILE_ROOT . '/' . basename((string)$file['stored_name']);
        if (is_file($path)) @unlink($path);
        $delStmt->execute([$file['id']]);
        logActivity($db, 'expired_file_deleted', 'admin', $file['id']);
    }
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}
echo "DA XOA " . count($expired) . " tep.\n";
