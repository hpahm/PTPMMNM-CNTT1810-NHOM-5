<?php
declare(strict_types=1);

if (!function_exists('mb_strlen')) {
    function mb_strlen(string $string, ?string $encoding = null): int {
        return strlen($string);
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string {
        return $length === null ? substr($string, $start) : substr($string, $start, $length);
    }
}
if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper(string $string, ?string $encoding = null): string {
        return strtoupper($string);
    }
}

const APP_ROOT = __DIR__ . '/..';
const DB_FILE = APP_ROOT . '/storage/openshare.sqlite';
const DB_SERVER = 'DESKTOP-L3KA80E\\SQLEXPRESS';
const DB_NAME = 'OpenShare';
const FILE_ROOT = APP_ROOT . '/storage/files';
const MAX_FILE_BYTES = 10_000_000;
const USER_QUOTA_BYTES = 1_073_741_824; // 1 GB (1024 * 1024 * 1024 bytes)
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
        $sessionPath = APP_ROOT . '/storage/sessions';
        if (!is_dir($sessionPath) && !mkdir($sessionPath, 0775, true) && !is_dir($sessionPath)) {
            throw new RuntimeException('Không thể tạo thư mục lưu session: ' . $sessionPath);
        }
        ini_set('session.use_strict_mode', '1');
        $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        session_save_path($sessionPath);
        session_start();
    }
    if (!headers_sent()) {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
}

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

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
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function verifyCsrf(): void {
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), post('csrf'))) {
        http_response_code(400);
        exit('Yêu cầu không hợp lệ (Lỗi CSRF Token). Vui lòng thử lại.');
    }
}

function flash(string $message, string $type = 'ok'): void {
    $_SESSION['flash'] = [$type, $message];
}

function takeFlash(): ?array {
    $value = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $value;
}

function redirect(string $path): never {
    header('Location: ' . $path);
    exit;
}

function currentUser(): ?array {
    $id = $_SESSION['user_id'] ?? null;
    if (!is_string($id)) {
        return null;
    }
    $data = readStore();
    return $data['users'][$id] ?? null;
}

function requireLogin(): array {
    $user = currentUser();
    if (!$user) {
        redirect('/login.php');
    }
    return $user;
}

function requireRole(array $roles): array {
    $user = requireLogin();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        exit('Bạn không có quyền thực hiện thao tác này.');
    }
    return $user;
}

function formatBytes(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 2) . ' MB';
}

function database(): PDO {
    if (!class_exists('PDO')) {
        throw new RuntimeException('PHP PDO chưa được bật.');
    }
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
    if (dbDriver() === 'sqlite') {
        $db->exec('PRAGMA foreign_keys = ON');
    }
    return $db;
}

function createSchema(PDO $db): void {
    if (dbDriver() === 'mysql') {
        $sql = file_get_contents(APP_ROOT . '/database/schema-mysql.sql');
        if ($sql === false) throw new RuntimeException('Không thể đọc schema MySQL.');
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $db->exec($statement);
        }
        return;
    }
    if (dbDriver() === 'sqlsrv') {
        $sql = file_get_contents(APP_ROOT . '/database/schema-sqlserver.sql');
        if ($sql === false) throw new RuntimeException('Không thể đọc schema SQL Server.');
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $db->exec($statement);
        }
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
    share_id TEXT NOT NULL REFERENCES shares(id) ON DELETE CASCADE,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    accepted_at TEXT NOT NULL,
    PRIMARY KEY (share_id, user_id)
);
CREATE INDEX IF NOT EXISTS idx_files_owner ON files(owner_id);
CREATE INDEX IF NOT EXISTS idx_shares_file ON shares(file_id);
CREATE INDEX IF NOT EXISTS idx_shares_recipient ON shares(recipient_id);
CREATE INDEX IF NOT EXISTS idx_logs_file ON access_logs(file_id);
SQL);
}

function databaseToArray(PDO $db): array {
    $data = ['users'=>[], 'folders'=>[], 'files'=>[], 'shares'=>[], 'logs'=>[]];
    foreach (['users','folders','files'] as $table) {
        foreach ($db->query("SELECT * FROM {$table}") as $row) {
            $data[$table][$row['id']] = $row;
        }
    }
    foreach ($db->query('SELECT * FROM shares') as $row) {
        $row['queue'] = [];
        $row['acceptances'] = [];
        $row['accepted'] = ($row['owner_id'] === $row['recipient_id']);
        $data['shares'][$row['id']] = $row;
    }
    foreach ($db->query('SELECT * FROM share_acceptances') as $row) {
        $shareId = $row['share_id'];
        $uId = $row['user_id'];
        if (isset($data['shares'][$shareId])) {
            $data['shares'][$shareId]['acceptances'][$uId] = $row['accepted_at'];
            $data['shares'][$shareId]['accepted'] = true;
        }
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

function arrayToDatabase(PDO $db, array $data): void {
    foreach (['share_acceptances','share_queue','access_logs','shares','files','folders','users'] as $table) {
        $db->exec("DELETE FROM {$table}");
    }
    $userStmt = $db->prepare('INSERT INTO users (id,name,email,role,password,quota) VALUES (?,?,?,?,?,?)');
    foreach ($data['users'] as $row) {
        $userStmt->execute([$row['id'],$row['name'],$row['email'],$row['role'],$row['password'],$row['quota']]);
    }
    $folderStmt = $db->prepare('INSERT INTO folders (id,owner_id,name,parent_id,created_at) VALUES (?,?,?,?,?)');
    foreach ($data['folders'] as $row) {
        $folderStmt->execute([$row['id'],$row['owner_id'],$row['name'],$row['parent_id'],$row['created_at']]);
    }
    $fileStmt = $db->prepare('INSERT INTO files (id,owner_id,folder_id,original_name,stored_name,size,mime,created_at,download_count) VALUES (?,?,?,?,?,?,?,?,?)');
    foreach ($data['files'] as $row) {
        $fileStmt->execute([$row['id'],$row['owner_id'],$row['folder_id'] ?? 'root-' . $row['owner_id'],$row['original_name'],$row['stored_name'],$row['size'],$row['mime'],$row['created_at'],$row['download_count']]);
    }
    $shareStmt = $db->prepare('INSERT INTO shares (id,file_id,owner_id,recipient_id,expires_at,max_downloads,downloads,password_hash,created_at,revoked_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $queueStmt = $db->prepare('INSERT INTO share_queue (id,share_id,user_id,requested_at,status) VALUES (?,?,?,?,?)');
    $acceptanceStmt = $db->prepare('INSERT INTO share_acceptances (share_id,user_id,accepted_at) VALUES (?,?,?)');
    foreach ($data['shares'] as $row) {
        $shareStmt->execute([$row['id'],$row['file_id'],$row['owner_id'],$row['recipient_id'],$row['expires_at'],$row['max_downloads'],$row['downloads'],$row['password_hash'],$row['created_at'],$row['revoked_at']]);
        foreach ($row['queue'] ?? [] as $entry) {
            $queueStmt->execute([$entry['id'],$row['id'],$entry['user_id'],$entry['requested_at'],$entry['status']]);
        }
        if (!empty($row['acceptances']) && is_array($row['acceptances'])) {
            foreach ($row['acceptances'] as $uId => $accAt) {
                $acceptanceStmt->execute([$row['id'], $uId, is_string($accAt) ? $accAt : date(DATE_ATOM)]);
            }
        } elseif (($row['accepted'] ?? ($row['owner_id'] === $row['recipient_id'])) && $row['owner_id'] !== $row['recipient_id']) {
            $acceptanceStmt->execute([$row['id'], $row['recipient_id'], $row['accepted_at'] ?? $row['created_at']]);
        }
    }
    $logStmt = $db->prepare('INSERT INTO access_logs (id,at,action,user_id,file_id,success) VALUES (?,?,?,?,?,?)');
    foreach ($data['logs'] as $row) {
        $logStmt->execute([$row['id'],$row['at'],$row['action'],$row['user_id'],$row['file_id'],$row['success'] ? 1 : 0]);
    }
}

function seedData(): array {
    $password = password_hash('123', PASSWORD_DEFAULT);
    $users = [
        'owner'=>['id'=>'owner','name'=>'Chủ tệp','email'=>'owner@gmail.com','role'=>'owner','password'=>$password,'quota'=>USER_QUOTA_BYTES],
        'recipient'=>['id'=>'recipient','name'=>'Người nhận','email'=>'recipient@gmail.com','role'=>'recipient','password'=>$password,'quota'=>USER_QUOTA_BYTES],
    ];
    $folders = [
        'root-owner'=>['id'=>'root-owner','owner_id'=>'owner','name'=>'Tệp của tôi','parent_id'=>null,'created_at'=>date(DATE_ATOM)],
        'root-recipient'=>['id'=>'root-recipient','owner_id'=>'recipient','name'=>'Tệp của tôi','parent_id'=>null,'created_at'=>date(DATE_ATOM)],
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
        if (!isset($data['folders'][$rootId])) {
            $data['folders'][$rootId] = ['id'=>$rootId,'owner_id'=>$user['id'],'name'=>'Tệp của tôi','parent_id'=>null,'created_at'=>date(DATE_ATOM)];
        }
    }
    foreach ($data['files'] as &$file) {
        if (!isset($file['folder_id'])) $file['folder_id'] = 'root-' . $file['owner_id'];
    }
    foreach ($data['shares'] as &$share) {
        normalizeShare($share);
    }
}

function resetStore(): void {
    if (dbDriver() === 'sqlite' && !is_dir(dirname(DB_FILE))) mkdir(dirname(DB_FILE), 0770, true);
    if (!is_dir(FILE_ROOT)) mkdir(FILE_ROOT, 0770, true);
    foreach (glob(FILE_ROOT . '/*') ?: [] as $path) {
        if (is_file($path)) unlink($path);
    }
    $data = seedData();
    $db = database();
    createSchema($db);
    $db->beginTransaction();
    arrayToDatabase($db, $data);
    $db->commit();
    foreach ($data['files'] as $file) {
        file_put_contents(FILE_ROOT . '/' . $file['stored_name'], "OpenShare demo file {$file['id']}\n", LOCK_EX);
    }
}

function readStore(): array {
    $db = database();
    createSchema($db);
    if ((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
        $db->beginTransaction();
        arrayToDatabase($db, seedData());
        $db->commit();
    }
    return databaseToArray($db);
}

function withStore(callable $callback): mixed {
    $db = database();
    createSchema($db);
    $db->beginTransaction();
    if ((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
        arrayToDatabase($db, seedData());
    }
    $data = databaseToArray($db);
    $result = $callback($data);
    arrayToDatabase($db, $data);
    $db->commit();
    return $result;
}

function logEvent(array &$data, string $action, string $userId, string $fileId = '', bool $success = true): void {
    $data['logs'][] = [
        'id' => bin2hex(random_bytes(6)),
        'at' => date(DATE_ATOM),
        'action' => $action,
        'user_id' => $userId,
        'file_id' => $fileId,
        'success' => $success
    ];
}

function userUsage(array $data, string $userId): int {
    return array_sum(array_map(fn($f) => ($f['owner_id'] ?? '') === $userId ? (int)($f['size'] ?? 0) : 0, $data['files']));
}

function allowedExtension(string $name): bool {
    return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['pdf','txt','jpg','jpeg','png','zip','docx'], true);
}

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
    return $share['revoked_at'] === null && strtotime($share['expires_at']) > $now && (int)$share['downloads'] < (int)$share['max_downloads'];
}

function userCanManageFile(?array $user, array $file): bool {
    if (!$user) return false;
    return ($user['role'] ?? '') === 'admin' || ($file['owner_id'] ?? '') === ($user['id'] ?? '');
}

function userCanDownloadShare(?array $user, array $share): bool {
    if (isPublicShare($share)) return true;
    if (!$user) return false;
    return ($user['role'] ?? '') === 'admin' || ($share['recipient_id'] ?? '') === ($user['id'] ?? '');
}

function findUserByEmail(array $data, string $email): ?array {
    $needle = strtolower(trim($email));
    if ($needle === '') return null;
    foreach ($data['users'] as $u) {
        if (strtolower((string)($u['email'] ?? '')) === $needle) {
            return $u;
        }
    }
    return null;
}

function isPublicShare(array $share): bool {
    return ($share['recipient_id'] ?? '') === ($share['owner_id'] ?? '');
}

function isShareAcceptedByUser(array $share, string $userId): bool {
    if (isset($share['acceptances'][$userId])) {
        return true;
    }
    if (($share['recipient_id'] ?? '') === $userId && !empty($share['accepted'])) {
        return true;
    }
    return false;
}

function shareIsAccepted(array $share, ?string $userId = null): bool {
    if ($userId !== null) {
        return isShareAcceptedByUser($share, $userId);
    }
    return isPublicShare($share) || ($share['accepted'] ?? false);
}

function getPendingInvitations(array $data, string $userId): array {
    $pending = [];
    $now = time();
    foreach ($data['shares'] as $share) {
        if (($share['recipient_id'] ?? '') === $userId && ($share['owner_id'] ?? '') !== $userId) {
            if (validShare($share, $now) && !isShareAcceptedByUser($share, $userId)) {
                $file = $data['files'][$share['file_id']] ?? null;
                if ($file) {
                    $pending[] = [$share, $file];
                }
            }
        }
    }
    return $pending;
}

function countPendingInvitations(array $data, string $userId): int {
    return count(getPendingInvitations($data, $userId));
}

function isRateLimited(string $action, int $maxAttempts = 5, int $decaySeconds = 300): bool {
    $key = 'rate_limit_' . $action;
    $record = $_SESSION[$key] ?? null;
    if (!is_array($record)) return false;
    $now = time();
    if ($now - ($record['start'] ?? 0) > $decaySeconds) {
        unset($_SESSION[$key]);
        return false;
    }
    return ($record['count'] ?? 0) >= $maxAttempts;
}

function recordFailedAttempt(string $action): void {
    $key = 'rate_limit_' . $action;
    $now = time();
    if (!isset($_SESSION[$key]) || ($now - ($_SESSION[$key]['start'] ?? 0) > 300)) {
        $_SESSION[$key] = ['count' => 1, 'start' => $now];
    } else {
        $_SESSION[$key]['count'] = ((int)$_SESSION[$key]['count']) + 1;
    }
}

function clearRateLimit(string $action): void {
    unset($_SESSION['rate_limit_' . $action]);
}

function getRateLimitRemainingSeconds(string $action, int $decaySeconds = 300): int {
    $key = 'rate_limit_' . $action;
    $start = (int)($_SESSION[$key]['start'] ?? time());
    return max(1, $decaySeconds - (time() - $start));
}

function normalizeShare(array &$share): void {
    if (!isset($share['queue']) || !is_array($share['queue'])) {
        $share['queue'] = [];
    }
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
    if ($share['revoked_at'] !== null || strtotime($share['expires_at']) <= $now) {
        return ['status'=>'invalid'];
    }
    $remaining = (int)$share['max_downloads'] - (int)$share['downloads'];
    if ($remaining > 1) {
        $share['downloads']++;
        return ['status'=>'granted'];
    }
    $position = queuePosition($share, $userId);
    if ($position === null) {
        $share['queue'][] = [
            'id' => bin2hex(random_bytes(6)),
            'user_id' => $userId,
            'requested_at' => microtime(true),
            'status' => 'waiting'
        ];
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
    if ($storedName === '' || basename($storedName) !== $storedName || str_contains($storedName, "\0")) {
        return null;
    }
    $root = realpath(FILE_ROOT);
    $path = realpath(FILE_ROOT . DIRECTORY_SEPARATOR . $storedName);
    if ($root === false || $path === false || !is_file($path)) {
        return null;
    }
    return str_starts_with($path, $root . DIRECTORY_SEPARATOR) ? $path : null;
}

function resetDemoFiles(): void {
    foreach (glob(FILE_ROOT . '/*') ?: [] as $path) {
        if (is_file($path)) unlink($path);
    }
}

function layoutStart(string $title): void {
    $user = currentUser();
    $flash = takeFlash();
    $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $searchQ = get('q');
    
    $isHome = $currentPath === '/' && get('folder') === '';
    $isFolders = $currentPath === '/folders.php' || ($currentPath === '/' && get('folder') !== '');
    $isShared = $currentPath === '/shared.php';
    $isLogs = $currentPath === '/logs.php';
    $isReport = $currentPath === '/report.php';

    $navClass = static fn(bool $active): string => $active ? ' class="nav-item active"' : ' class="nav-item"';

    $usedBytes = 0;
    $quotaBytes = USER_QUOTA_BYTES;
    $usedPercent = 0;
    $roleName = 'Người dùng';
    if ($user) {
        $data = readStore();
        $usedBytes = userUsage($data, $user['id']);
        $quotaBytes = (int)($user['quota'] ?? USER_QUOTA_BYTES);
        $usedPercent = $quotaBytes > 0 ? min(100, round(($usedBytes / $quotaBytes) * 100)) : 0;
        $pendingInvites = countPendingInvitations($data, $user['id']);
        $roleName = match($user['role'] ?? '') {
            'owner' => 'Chủ tệp',
            'admin' => 'Quản trị viên',
            default => 'Người nhận',
        };
    }

    echo '<!doctype html><html lang="vi"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . ' · OpenShare Drive</title>';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;700&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">';
    echo '<link rel="stylesheet" href="/assets/app.css">';
    echo '</head><body>';

    if ($user) {
        echo '<header class="topbar">';
        echo '  <div class="topbar-left">';
        echo '    <a class="brand" href="/" title="OpenShare Drive">';
        echo '      <div class="drive-logo">';
        echo '        <svg viewBox="0 0 87.3 78" class="drive-logo-svg">';
        echo '          <path d="m6.6 66.85 3.85 6.65c.8 1.4 1.95 2.5 3.3 3.3l13.75-23.8h-27.5c0 1.55.4 3.1 1.2 4.5z" fill="#0066da"/>';
        echo '          <path d="m43.65 25-13.75-23.8c-1.35.8-2.5 1.9-3.3 3.3l-25.4 44c-.8 1.4-1.2 2.95-1.2 4.5h27.5z" fill="#00ac47"/>';
        echo '          <path d="m73.55 76.8c1.35-.8 2.5-1.9 3.3-3.3l1.6-2.75 7.65-13.25c.8-1.4 1.2-2.95 1.2-4.5h-27.502l5.852 11.5z" fill="#ea4335"/>';
        echo '          <path d="m43.65 25 13.75-23.8c-1.35-.8-2.9-1.2-4.5-1.2h-18.5c-1.6 0-3.15.45-4.5 1.25z" fill="#00832d"/>';
        echo '          <path d="m59.8 53h-32.3l-13.75 23.8c1.35.8 2.9 1.2 4.5 1.2h50.8c1.6 0 3.15-.45 4.5-1.25z" fill="#2684fc"/>';
        echo '          <path d="m73.4 26.5-12.7-22c-.8-1.4-1.95-2.5-3.3-3.3l-13.75 23.8 16.15 28h27.45c0-1.55-.4-3.1-1.2-4.5z" fill="#ffba00"/>';
        echo '        </svg>';
        echo '      </div>';
        echo '      <span class="brand-text">Drive</span>';
        echo '    </a>';
        echo '  </div>';

        echo '  <form class="search-bar" method="get" action="/">';
        echo '    <button type="submit" class="search-icon" aria-label="Tìm kiếm">🔍</button>';
        echo '    <input type="text" name="q" value="' . h($searchQ) . '" placeholder="Tìm kiếm trong Drive..." autocomplete="off">';
        if ($searchQ !== '') {
            echo '    <a href="/" class="search-clear" title="Xóa tìm kiếm">✕</a>';
        }
        echo '  </form>';

        echo '  <div class="topbar-right">';
        if ($pendingInvites > 0) {
            echo '    <a href="/shared.php" class="topbar-invite-btn" title="' . $pendingInvites . ' lời mời chia sẻ tệp đang chờ bạn">';
            echo '      <span class="invite-bell-icon">📬</span>';
            echo '      <span class="invite-bell-badge">' . $pendingInvites . '</span>';
            echo '    </a>';
        }
        echo '    <a href="/report.php" class="topbar-quota-badge" title="Dung lượng đã dùng">';
        echo '      <span class="quota-icon">☁</span>';
        echo '      <span>' . formatBytes($usedBytes) . ' / ' . formatBytes($quotaBytes) . '</span>';
        echo '    </a>';
        echo '    <details class="user-menu">';
        echo '      <summary class="user-avatar" title="' . h($user['name']) . ' (' . h($user['email']) . ')">';
        echo '        <span>' . h(mb_strtoupper(mb_substr($user['name'], 0, 1, 'UTF-8'), 'UTF-8')) . '</span>';
        echo '      </summary>';
        echo '      <div class="user-dropdown">';
        echo '        <div class="dropdown-header">';
        echo '          <strong>' . h($user['name']) . '</strong>';
        echo '          <div class="dropdown-email">' . h($user['email']) . '</div>';
        echo '          <span class="role-pill ' . h($user['role']) . '">' . h($roleName) . '</span>';
        echo '        </div>';
        echo '        <div class="dropdown-divider"></div>';
        echo '        <a href="/report.php" class="dropdown-item">📊 Báo cáo lưu trữ (' . $usedPercent . '%)</a>';
        echo '        <a href="/logs.php" class="dropdown-item">⏱ Nhật ký hoạt động</a>';
        echo '        <div class="dropdown-divider"></div>';
        echo '        <a href="/logout.php" class="dropdown-item text-danger">↪ Đăng xuất</a>';
        echo '      </div>';
        echo '    </details>';
        echo '  </div>';
        echo '</header>';

        echo '<div class="app-layout">';
        echo '  <aside class="sidebar">';
        echo '    <div class="sidebar-action">';
        echo '      <a href="/upload.php" class="btn-new">';
        echo '        <svg width="24" height="24" viewBox="0 0 24 24"><path fill="#EA4335" d="M11 11V5h2v6h6v2h-6v6h-2v-6H5v-2z"/><path fill="#4285F4" d="M11 11V5h2v6z"/><path fill="#FBBC05" d="M19 11v2h-6v-2z"/><path fill="#34A853" d="M11 13v6h2v-6z"/></svg>';
        echo '        <span>Mới</span>';
        echo '      </a>';
        echo '    </div>';
        echo '    <nav class="sidebar-nav">';
        echo '      <a href="/"' . $navClass($isHome) . '><span class="nav-icon">📁</span><span>Drive của tôi</span></a>';
        $inviteBadgeHtml = $pendingInvites > 0 ? ' <span class="nav-badge-alert">' . $pendingInvites . '</span>' : '';
        echo '      <a href="/shared.php"' . $navClass($isShared) . '><span class="nav-icon">👥</span><span>Được chia sẻ với tôi' . $inviteBadgeHtml . '</span></a>';
        echo '      <a href="/folders.php"' . $navClass($isFolders) . '><span class="nav-icon">📂</span><span>Thư mục</span></a>';
        echo '      <a href="/logs.php"' . $navClass($isLogs) . '><span class="nav-icon">⏱</span><span>Hoạt động</span></a>';
        echo '      <a href="/report.php"' . $navClass($isReport) . '><span class="nav-icon">📊</span><span>Bộ nhớ lưu trữ</span></a>';
        echo '    </nav>';
        echo '    <div class="sidebar-storage">';
        echo '      <div class="storage-meta">';
        echo '        <span>Bộ nhớ</span>';
        echo '        <span>' . $usedPercent . '%</span>';
        echo '      </div>';
        echo '      <div class="storage-bar">';
        $barClass = $usedPercent >= 80 ? 'danger' : '';
        echo '        <div class="storage-fill ' . $barClass . '" style="width:' . $usedPercent . '%"></div>';
        echo '      </div>';
        echo '      <div class="storage-text">' . formatBytes($usedBytes) . ' / ' . formatBytes($quotaBytes) . ' đã dùng</div>';
        echo '      <a href="/report.php" class="storage-link">Xem chi tiết</a>';
        echo '    </div>';
        echo '  </aside>';
        echo '  <main class="main-content">';
    } else {
        echo '<header class="auth-header">';
        echo '  <a class="brand" href="/login.php">';
        echo '    <div class="drive-logo">';
        echo '      <svg viewBox="0 0 87.3 78" class="drive-logo-svg">';
        echo '        <path d="m6.6 66.85 3.85 6.65c.8 1.4 1.95 2.5 3.3 3.3l13.75-23.8h-27.5c0 1.55.4 3.1 1.2 4.5z" fill="#0066da"/>';
        echo '        <path d="m43.65 25-13.75-23.8c-1.35.8-2.5 1.9-3.3 3.3l-25.4 44c-.8 1.4-1.2 2.95-1.2 4.5h27.5z" fill="#00ac47"/>';
        echo '        <path d="m73.55 76.8c1.35-.8 2.5-1.9 3.3-3.3l1.6-2.75 7.65-13.25c.8-1.4 1.2-2.95 1.2-4.5h-27.502l5.852 11.5z" fill="#ea4335"/>';
        echo '        <path d="m43.65 25 13.75-23.8c-1.35-.8-2.9-1.2-4.5-1.2h-18.5c-1.6 0-3.15.45-4.5 1.25z" fill="#00832d"/>';
        echo '        <path d="m59.8 53h-32.3l-13.75 23.8c1.35.8 2.9 1.2 4.5 1.2h50.8c1.6 0 3.15-.45 4.5-1.25z" fill="#2684fc"/>';
        echo '        <path d="m73.4 26.5-12.7-22c-.8-1.4-1.95-2.5-3.3-3.3l-13.75 23.8 16.15 28h27.45c0-1.55-.4-3.1-1.2-4.5z" fill="#ffba00"/>';
        echo '      </svg>';
        echo '    </div>';
        echo '    <span class="brand-text">OpenShare</span>';
        echo '  </a>';
        echo '  <div class="auth-nav">';
        echo '    <a href="/login.php" class="btn-text">Đăng nhập</a>';
        echo '    <a href="/register.php" class="btn-outline">Tạo tài khoản</a>';
        echo '  </div>';
        echo '</header>';
        echo '<main class="auth-main">';
    }

    if ($flash) {
        $flashType = $flash[0] === 'error' ? 'toast-error' : 'toast-ok';
        $flashIcon = $flash[0] === 'error' ? '⚠️' : '✓';
        echo '<div class="toast-banner ' . h($flashType) . '">';
        echo '  <span class="toast-icon">' . $flashIcon . '</span>';
        echo '  <span class="toast-text">' . h($flash[1]) . '</span>';
        echo '  <button type="button" class="toast-close" onclick="this.parentElement.remove()">✕</button>';
        echo '</div>';
    }
}

function layoutEnd(): void {
    if (currentUser()) {
        echo '  </main>';
        echo '</div>';
    } else {
        echo '</main>';
    }
    echo '<footer class="global-footer">';
    echo '  <span>OpenShare · Bài tập lớn Phát triển phần mềm mã nguồn mở (Nhóm 5)</span>';
    echo '</footer>';
    echo '</body></html>';
}
