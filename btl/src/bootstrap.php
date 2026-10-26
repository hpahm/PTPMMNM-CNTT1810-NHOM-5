<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';
const DB_FILE = APP_ROOT . '/storage/openshare.sqlite';
const DB_SERVER = 'DESKTOP-L3KA80E\\SQLEXPRESS';
const DB_NAME = 'OpenShare';
const FILE_ROOT = APP_ROOT . '/storage/files';
const MAX_FILE_BYTES = 10_000_000;
const USER_QUOTA_BYTES = 50_000_000;
const ALLOWED_MIME_TYPES = [
    'pdf' => ['application/pdf'],
    'txt' => ['text/plain'],
    'jpg' => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png' => ['image/png'],
    'zip' => ['application/zip', 'application/x-zip-compressed'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
];
function dbDriver(): string {
    $configured = strtolower(trim((string)(getenv('OPENSHARE_DB_DRIVER') ?: 'sqlite')));
    if (!in_array($configured, ['mysql', 'sqlsrv', 'sqlite'], true)) {
        throw new RuntimeException('OPENSHARE_DB_DRIVER phải là mysql, sqlsrv hoặc sqlite.');
    }
    return $configured;
}

function startApp(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}
function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function post(string $key): string {
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}
function get(string $key): string {
    $value = $_GET[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}
function isGmailAddress(string $email): bool {
    return (bool)preg_match('/^[a-z0-9][a-z0-9._%+\-]*@gmail\.com$/i', $email);
}
function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function verifyCsrf(): void {
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), post('csrf'))) {
        http_response_code(400); exit('Yêu cầu không hợp lệ.');
    }
}
function flash(string $message, string $type = 'ok'): void { $_SESSION['flash'] = [$type, $message]; }
function takeFlash(): ?array { $value = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $value; }
function redirect(string $path): never { header('Location: ' . $path); exit; }
function currentUser(): ?array {
    $id = $_SESSION['user_id'] ?? null;
    if (!is_string($id)) return null;
    $data = readStore();
    return $data['users'][$id] ?? null;
}
function requireLogin(): array {
    $user = currentUser();
    if (!$user) redirect('/login.php');
    return $user;
}
function requireRole(array $roles): array {
    $user = requireLogin();
    if (!in_array($user['role'], $roles, true)) { http_response_code(403); exit('Bạn không có quyền thực hiện thao tác này.'); }
    return $user;
}
function layoutStart(string $title): void {
    $user = currentUser(); $flash = takeFlash();
    $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $isFolders = $currentPath === '/folders.php';
    $isFiles = $currentPath === '/';
    $isLogs = $currentPath === '/logs.php';
    $isReport = $currentPath === '/report.php';
    $isShared = $currentPath === '/shared.php';
    $navClass = static fn(bool $active): string => $active ? ' class="active"' : '';
    echo '<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.h($title).' · OpenShare</title><link rel="stylesheet" href="/assets/app.css"></head><body>';
    if ($user) {
        echo '<header class="topbar"><a class="brand" href="/"><span class="brand-mark">O</span><span>OpenShare</span></a><form class="global-search" method="get" action="/"><span>⌕</span><input name="q" placeholder="Tìm trong Drive" aria-label="Tìm trong Drive"></form><div class="topbar-actions"><span class="help">?</span><details class="account-menu"><summary class="avatar" aria-label="Mở menu tài khoản">'.h(strtoupper(substr($user['name'], 0, 1))).'</summary><div class="account-dropdown"><strong>'.h($user['name']).'</strong><span>'.h($user['email']).'</span><a href="/logout.php">Đổi tài khoản</a><a href="/logout.php">Đăng xuất</a></div></details></div></header><div class="app-shell"><aside class="sidebar"><a class="new-button" href="/upload.php"><span>＋</span> Mới</a><nav class="side-nav"><a href="/"'.$navClass($isFiles).'><span>▣</span> Tệp của tôi</a><a href="/shared.php"'.$navClass($isShared).'><span>◫</span> Được chia sẻ</a><a href="/folders.php"'.$navClass($isFolders).'><span>▰</span> Thư mục</a><a href="/logs.php"'.$navClass($isLogs).'><span>◷</span> Hoạt động</a><a href="/report.php"'.$navClass($isReport).'><span>▤</span> Báo cáo</a></nav><div class="sidebar-bottom"><a href="/logout.php"><span>↪</span> Đăng xuất</a></div></aside><main>';
    } else {
        echo '<header class="login-header"><a class="brand" href="/login.php"><span class="brand-mark">O</span><span>OpenShare</span></a><div class="login-links"><a href="/login.php">Đăng nhập</a><a href="/register.php">Đăng ký</a></div></header><main class="login-main">';
    }
    if ($flash) echo '<div class="flash '.h($flash[0]).'">'.h($flash[1]).'</div>';
}
function layoutEnd(): void {
    echo currentUser() ? '</main></div>' : '</main>';
    echo '<footer>OpenShare · Dữ liệu học tập, không dùng dữ liệu cá nhân thật.</footer></body></html>';
}
function seedData(): array {
    $password = password_hash('123', PASSWORD_DEFAULT);
    $adminPassword = password_hash('123', PASSWORD_DEFAULT);
    $users = [
        'owner'=>['id'=>'owner','name'=>'Chủ tệp','email'=>'owner@gmail.com','role'=>'owner','password'=>$password,'quota'=>USER_QUOTA_BYTES],
        'recipient'=>['id'=>'recipient','name'=>'Người nhận','email'=>'recipient@gmail.com','role'=>'recipient','password'=>$password,'quota'=>USER_QUOTA_BYTES],
        'admin'=>['id'=>'admin','name'=>'Quản trị viên','email'=>'admin@gmail.com','role'=>'admin','password'=>$adminPassword,'quota'=>USER_QUOTA_BYTES],
    ];
    $folders = [
        'root-owner'=>['id'=>'root-owner','owner_id'=>'owner','name'=>'Tệp của tôi','parent_id'=>null,'created_at'=>date(DATE_ATOM)],
        'root-recipient'=>['id'=>'root-recipient','owner_id'=>'recipient','name'=>'Tệp của tôi','parent_id'=>null,'created_at'=>date(DATE_ATOM)],
        'root-admin'=>['id'=>'root-admin','owner_id'=>'admin','name'=>'Tệp của tôi','parent_id'=>null,'created_at'=>date(DATE_ATOM)],
    ];
    $extensions = ['pdf', 'txt', 'jpg', 'png', 'zip', 'docx'];
    $files = [];
    for ($number = 1; $number <= 30; $number++) {
        $ownerId = 'owner';
        $extension = $extensions[($number - 1) % count($extensions)];
        $id = 'demo-file-' . str_pad((string)$number, 2, '0', STR_PAD_LEFT);
        $content = "OpenShare demo file {$number}\n";
        $files[$id] = [
            'id' => $id,
            'owner_id' => $ownerId,
            'folder_id' => 'root-' . $ownerId,
            'original_name' => "tai-lieu-demo-{$number}.{$extension}",
            'stored_name' => "{$id}.bin",
            'size' => strlen($content),
            'mime' => ALLOWED_MIME_TYPES[$extension][0],
            'created_at' => date(DATE_ATOM, time() - (30 - $number) * 3600),
            'download_count' => 0,
        ];
    }
    if (!function_exists('database')) {
    function database(): PDO {
        if (!class_exists('PDO')) throw new RuntimeException('PHP PDO chưa được bật.');
        if (dbDriver() === 'mysql') {
            if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
                throw new RuntimeException('PHP cần bật pdo_mysql để chạy OpenShare với MySQL.');
            }
            $host = getenv('OPENSHARE_DB_HOST') ?: '127.0.0.1';
            $port = getenv('OPENSHARE_DB_PORT') ?: '3306';
            $name = getenv('OPENSHARE_DB_NAME') ?: 'openshare';
            $user = getenv('OPENSHARE_DB_USER') ?: 'root';
            $password = getenv('OPENSHARE_DB_PASSWORD') ?: '';
            $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4';
            $db = new PDO($dsn, $user, $password, [
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } elseif (dbDriver() === 'sqlsrv') {
            if (!in_array('sqlsrv', PDO::getAvailableDrivers(), true)) {
                throw new RuntimeException('PHP cần bật pdo_sqlsrv để kết nối SQL Server.');
            }
            $dsn = 'sqlsrv:Server=' . (getenv('OPENSHARE_DB_SERVER') ?: DB_SERVER);
            $dsn .= ';Database=' . (getenv('OPENSHARE_DB_NAME') ?: DB_NAME) . ';Trusted_Connection=Yes;TrustServerCertificate=1';
            $db = new PDO($dsn, getenv('OPENSHARE_DB_USER') ?: null, getenv('OPENSHARE_DB_PASSWORD') ?: null);
        } else {
            if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
                throw new RuntimeException('PHP cần bật phần mở rộng pdo_sqlite để chạy OpenShare.');
            }
            $db = new PDO('sqlite:' . DB_FILE);
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if (dbDriver() === 'sqlite') $db->exec('PRAGMA foreign_keys = ON');
        return $db;
    }
    }
    if (!function_exists('createSchema')) {
    function createSchema(PDO $db): void {
        if (dbDriver() === 'mysql') {
            $sql = file_get_contents(APP_ROOT . '/database/schema-mysql.sql');
            if ($sql === false) throw new RuntimeException('Không thể đọc schema MySQL.');
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) $db->exec($statement);
            return;
        }
        if (dbDriver() === 'sqlsrv') {
            $sql = file_get_contents(APP_ROOT . '/database/schema-sqlserver.sql');
            if ($sql === false) throw new RuntimeException('Không thể đọc schema SQL Server.');
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) $db->exec($statement);
            return;
        }
        $db->exec(<<<'SQL'
    CREATE TABLE IF NOT EXISTS users (
        id TEXT PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL UNIQUE,
        role TEXT NOT NULL CHECK (role IN ('owner','recipient','admin')),
        password TEXT NOT NULL, quota INTEGER NOT NULL
    );
    CREATE TABLE IF NOT EXISTS folders (
        id TEXT PRIMARY KEY, owner_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        name TEXT NOT NULL, parent_id TEXT, created_at TEXT NOT NULL,
        UNIQUE(owner_id, name)
    );
    CREATE TABLE IF NOT EXISTS files (
        id TEXT PRIMARY KEY, owner_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        folder_id TEXT NOT NULL REFERENCES folders(id), original_name TEXT NOT NULL,
        stored_name TEXT NOT NULL UNIQUE, size INTEGER NOT NULL, mime TEXT NOT NULL,
        created_at TEXT NOT NULL, download_count INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS shares (
        id TEXT PRIMARY KEY, file_id TEXT NOT NULL REFERENCES files(id) ON DELETE CASCADE,
        owner_id TEXT NOT NULL REFERENCES users(id), recipient_id TEXT NOT NULL REFERENCES users(id),
        expires_at TEXT NOT NULL, max_downloads INTEGER NOT NULL, downloads INTEGER NOT NULL DEFAULT 0,
        password_hash TEXT, created_at TEXT NOT NULL, revoked_at TEXT
    );
    CREATE TABLE IF NOT EXISTS share_queue (
        id TEXT PRIMARY KEY, share_id TEXT NOT NULL REFERENCES shares(id) ON DELETE CASCADE,
        user_id TEXT NOT NULL REFERENCES users(id), requested_at REAL NOT NULL,
        status TEXT NOT NULL CHECK (status IN ('waiting','granted'))
    );
    CREATE TABLE IF NOT EXISTS access_logs (
        id TEXT PRIMARY KEY, at TEXT NOT NULL, action TEXT NOT NULL,
        user_id TEXT NOT NULL, file_id TEXT, success INTEGER NOT NULL DEFAULT 1
    );
    CREATE TABLE IF NOT EXISTS share_acceptances (
        share_id TEXT PRIMARY KEY REFERENCES shares(id) ON DELETE CASCADE,
        user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        accepted_at TEXT NOT NULL
    );
    CREATE INDEX IF NOT EXISTS idx_files_owner ON files(owner_id);
    CREATE INDEX IF NOT EXISTS idx_shares_file ON shares(file_id);
    CREATE INDEX IF NOT EXISTS idx_shares_recipient ON shares(recipient_id);
    CREATE INDEX IF NOT EXISTS idx_logs_file ON access_logs(file_id);
    SQL);
    }
    }
    if (!function_exists('databaseToArray')) {
    function databaseToArray(PDO $db): array {
        $data = ['users'=>[], 'folders'=>[], 'files'=>[], 'shares'=>[], 'logs'=>[]];
        foreach (['users','folders','files'] as $table) {
            foreach ($db->query("SELECT * FROM {$table}") as $row) $data[$table][$row['id']] = $row;
        }
        foreach ($db->query('SELECT * FROM shares') as $row) {
            $row['queue'] = [];
            $row['accepted'] = ($row['owner_id'] === $row['recipient_id']);
            $data['shares'][$row['id']] = $row;
        }
        foreach ($db->query('SELECT share_id FROM share_acceptances') as $row) {
            if (isset($data['shares'][$row['share_id']])) $data['shares'][$row['share_id']]['accepted'] = true;
        }
        foreach ($db->query('SELECT * FROM share_queue ORDER BY requested_at, id') as $row) {
            $shareId = $row['share_id'];
            if (isset($data['shares'][$shareId])) {
                unset($row['share_id']);
                $data['shares'][$shareId]['queue'][] = $row;
            }
        }
        foreach ($db->query('SELECT * FROM access_logs ORDER BY id') as $row) {
            $row['success'] = (bool)$row['success'];
            $data['logs'][] = $row;
        }
        normalizeStore($data);
        return $data;
    }
    }
    if (!function_exists('arrayToDatabase')) {
    function arrayToDatabase(PDO $db, array $data): void {
        foreach (['share_acceptances','share_queue','access_logs','shares','files','folders','users'] as $table) $db->exec("DELETE FROM {$table}");
        $userStmt = $db->prepare('INSERT INTO users (id,name,email,role,password,quota) VALUES (?,?,?,?,?,?)');
        foreach ($data['users'] as $row) $userStmt->execute([$row['id'],$row['name'],$row['email'],$row['role'],$row['password'],$row['quota']]);
        $folderStmt = $db->prepare('INSERT INTO folders (id,owner_id,name,parent_id,created_at) VALUES (?,?,?,?,?)');
        foreach ($data['folders'] as $row) $folderStmt->execute([$row['id'],$row['owner_id'],$row['name'],$row['parent_id'],$row['created_at']]);
        $fileStmt = $db->prepare('INSERT INTO files (id,owner_id,folder_id,original_name,stored_name,size,mime,created_at,download_count) VALUES (?,?,?,?,?,?,?,?,?)');
        foreach ($data['files'] as $row) $fileStmt->execute([$row['id'],$row['owner_id'],$row['folder_id'] ?? 'root-' . $row['owner_id'],$row['original_name'],$row['stored_name'],$row['size'],$row['mime'],$row['created_at'],$row['download_count']]);
        $shareStmt = $db->prepare('INSERT INTO shares (id,file_id,owner_id,recipient_id,expires_at,max_downloads,downloads,password_hash,created_at,revoked_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $queueStmt = $db->prepare('INSERT INTO share_queue (id,share_id,user_id,requested_at,status) VALUES (?,?,?,?,?)');
        $acceptanceStmt = $db->prepare('INSERT INTO share_acceptances (share_id,user_id,accepted_at) VALUES (?,?,?)');
        foreach ($data['shares'] as $row) {
            $shareStmt->execute([$row['id'],$row['file_id'],$row['owner_id'],$row['recipient_id'],$row['expires_at'],$row['max_downloads'],$row['downloads'],$row['password_hash'],$row['created_at'],$row['revoked_at']]);
            foreach ($row['queue'] ?? [] as $entry) $queueStmt->execute([$entry['id'],$row['id'],$entry['user_id'],$entry['requested_at'],$entry['status']]);
            if (($row['accepted'] ?? ($row['owner_id'] === $row['recipient_id'])) && $row['owner_id'] !== $row['recipient_id']) {
                $acceptanceStmt->execute([$row['id'], $row['recipient_id'], $row['accepted_at'] ?? $row['created_at']]);
            }
        }
        $logStmt = $db->prepare('INSERT INTO access_logs (id,at,action,user_id,file_id,success) VALUES (?,?,?,?,?,?)');
        foreach ($data['logs'] as $row) $logStmt->execute([$row['id'],$row['at'],$row['action'],$row['user_id'],$row['file_id'],$row['success'] ? 1 : 0]);
    }
    }
    $shares = [];
    for ($number = 1; $number <= 50; $number++) {
        $fileNumber = (($number - 1) % 30) + 1;
        $fileId = 'demo-file-' . str_pad((string)$fileNumber, 2, '0', STR_PAD_LEFT);
        $ownerId = $files[$fileId]['owner_id'];
        $recipientId = $ownerId === 'recipient' ? 'owner' : 'recipient';
        $shareId = 'demo-share-' . str_pad((string)$number, 2, '0', STR_PAD_LEFT);
        $shares[$shareId] = [
            'id' => $shareId,
            'file_id' => $fileId,
            'owner_id' => $ownerId,
            'recipient_id' => $recipientId,
            'expires_at' => date(DATE_ATOM, time() + (7 + ($number % 30)) * 86400),
            'max_downloads' => 5 + ($number % 6),
            'downloads' => 0,
            'password_hash' => null,
            'created_at' => date(DATE_ATOM, time() - ($number % 10) * 86400),
            'revoked_at' => null,
            'queue' => [],
        ];
    }
    return ['users'=>$users, 'folders'=>$folders, 'files'=>$files, 'shares'=>$shares, 'logs'=>[]];
}
function normalizeStore(array &$data): void {
    if (!isset($data['folders']) || !is_array($data['folders'])) $data['folders'] = [];
    foreach ($data['users'] as $user) {
        $rootId = 'root-' . $user['id'];
        if (!isset($data['folders'][$rootId])) $data['folders'][$rootId] = ['id'=>$rootId,'owner_id'=>$user['id'],'name'=>'Tệp của tôi','parent_id'=>null,'created_at'=>date(DATE_ATOM)];
    }
    foreach ($data['files'] as &$file) if (!isset($file['folder_id'])) $file['folder_id'] = 'root-' . $file['owner_id'];
    foreach ($data['shares'] as &$share) normalizeShare($share);
}
function resetStore(): void {
    if (dbDriver() === 'sqlite' && !is_dir(dirname(DB_FILE))) mkdir(dirname(DB_FILE), 0770, true);
    if (!is_dir(FILE_ROOT)) mkdir(FILE_ROOT, 0770, true);
    foreach (glob(FILE_ROOT . '/*') ?: [] as $path) if (is_file($path)) unlink($path);
    $data = seedData();
    $db = database(); createSchema($db); $db->beginTransaction(); arrayToDatabase($db, $data); $db->commit();
    foreach ($data['files'] as $file) {
        file_put_contents(FILE_ROOT . '/' . $file['stored_name'], "OpenShare demo file {$file['id']}\n", LOCK_EX);
    }
}
function readStore(): array {
    if (!function_exists('database')) seedData();
    $db = database(); createSchema($db);
    if ((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
        $db->beginTransaction(); arrayToDatabase($db, seedData()); $db->commit();
    }
    return databaseToArray($db);
}
function withStore(callable $callback): mixed {
    if (!function_exists('database')) seedData();
    $db = database(); createSchema($db); $db->beginTransaction();
    if ((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) arrayToDatabase($db, seedData());
    $data = databaseToArray($db);
    $result = $callback($data);
    arrayToDatabase($db, $data); $db->commit();
    return $result;
}
function logEvent(array &$data, string $action, string $userId, string $fileId = '', bool $success = true): void {
    $data['logs'][] = ['id'=>bin2hex(random_bytes(6)), 'at'=>date(DATE_ATOM), 'action'=>$action, 'user_id'=>$userId, 'file_id'=>$fileId, 'success'=>$success];
}
function userUsage(array $data, string $userId): int {
    return array_sum(array_map(fn($f) => $f['owner_id'] === $userId ? (int)$f['size'] : 0, $data['files']));
}
function allowedExtension(string $name): bool { return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['pdf','txt','jpg','jpeg','png','zip','docx'], true); }
function detectedMimeIsAllowed(string $name, string $path): bool {
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $mime = is_file($path) ? (mime_content_type($path) ?: '') : '';
    return in_array($mime, ALLOWED_MIME_TYPES[$extension] ?? [], true);
}
function diskHasSpace(int $bytes): bool {
    $free = disk_free_space(FILE_ROOT);
    return $free !== false && $free >= $bytes;
}
function validShare(array $share, int $now): bool {
    return $share['revoked_at'] === null && strtotime($share['expires_at']) > $now && $share['downloads'] < $share['max_downloads'];
}
function userCanManageFile(array $user, array $file): bool {
    return ($user['role'] ?? '') === 'admin' || ($file['owner_id'] ?? '') === ($user['id'] ?? '');
}
function userCanDownloadShare(array $user, array $share): bool {
    return isPublicShare($share) || ($user['role'] ?? '') === 'admin' || ($share['recipient_id'] ?? '') === ($user['id'] ?? '');
}
function isPublicShare(array $share): bool {
    return ($share['recipient_id'] ?? '') === ($share['owner_id'] ?? '');
}
function shareIsAccepted(array $share): bool {
    return isPublicShare($share) || ($share['accepted'] ?? false);
}
function normalizeShare(array &$share): void {
    if (!isset($share['queue']) || !is_array($share['queue'])) $share['queue'] = [];
}
function queuePosition(array $share, string $userId): ?int {
    normalizeShare($share);
    $position = 0;
    foreach ($share['queue'] as $entry) {
        if (($entry['status'] ?? 'waiting') !== 'waiting') continue;
        $position++;
        if ($entry['user_id'] === $userId) return $position;
    }
    return null;
}
function requestDownloadSlot(array &$share, string $userId, int $now): array {
    normalizeShare($share);
    if ($share['revoked_at'] !== null || strtotime($share['expires_at']) <= $now) return ['status'=>'invalid'];
    $remaining = (int)$share['max_downloads'] - (int)$share['downloads'];
    if ($remaining > 1) {
        $share['downloads']++;
        return ['status'=>'granted'];
    }
    $position = queuePosition($share, $userId);
    if ($position === null) {
        $share['queue'][] = ['id'=>bin2hex(random_bytes(6)), 'user_id'=>$userId, 'requested_at'=>microtime(true), 'status'=>'waiting'];
        $position = queuePosition($share, $userId);
    }
    $waitingKeys = array_keys(array_filter($share['queue'], fn($entry) => ($entry['status'] ?? 'waiting') === 'waiting'));
    $headKey = $waitingKeys[0] ?? null;
    $head = $headKey === null ? null : $share['queue'][$headKey];
    if ($head && $head['user_id'] === $userId && $remaining === 1) {
        $share['queue'][$headKey]['status'] = 'granted';
        $share['downloads']++;
        return ['status'=>'granted', 'position'=>1];
    }
    return ['status'=>'queued', 'position'=>$position];
}
function safeStoredPath(array $file): ?string {
    $storedName = (string)($file['stored_name'] ?? '');
    if ($storedName === '' || basename($storedName) !== $storedName || str_contains($storedName, "\0")) return null;
    $root = realpath(FILE_ROOT);
    $path = realpath(FILE_ROOT . DIRECTORY_SEPARATOR . $storedName);
    if ($root === false || $path === false || !is_file($path)) return null;
    return str_starts_with($path, $root . DIRECTORY_SEPARATOR) ? $path : null;
}
function resetDemoFiles(): void {
    foreach (glob(FILE_ROOT . '/*') ?: [] as $path) if (is_file($path)) unlink($path);
}
