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
if (!function_exists('mime_content_type')) {
    function mime_content_type(string $filename): string|false {
        if (!is_file($filename)) {
            return false;
        }
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($filename);
            if ($mime) return $mime;
        }
        $handle = @fopen($filename, 'rb');
        if ($handle) {
            $bytes = (string)fread($handle, 64);
            fclose($handle);
            if (str_starts_with($bytes, "%PDF-")) {
                return 'application/pdf';
            }
            if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
                return 'image/png';
            }
            if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
                return 'image/jpeg';
            }
            if (str_starts_with($bytes, "PK\x03\x04") || str_starts_with($bytes, "PK\x05\x06")) {
                return 'application/zip';
            }
            if (str_starts_with($bytes, "<?php") || str_starts_with($bytes, "<?")) {
                return 'text/x-php';
            }
            if (!str_contains($bytes, "\0")) {
                return 'text/plain';
            }
        }
        return 'application/octet-stream';
    }
}

require_once __DIR__ . '/zip.php';

const APP_ROOT = __DIR__ . '/..';
define('DB_FILE', getenv('OPENSHARE_DB_FILE') ?: APP_ROOT . '/storage/openshare.sqlite');
const DB_SERVER = 'DESKTOP-L3KA80E\\SQLEXPRESS';
const DB_NAME = 'OpenShare';
define('FILE_ROOT', getenv('OPENSHARE_FILE_ROOT') ?: APP_ROOT . '/storage/files');
const MAX_FILE_BYTES = 10_000_000;
const USER_QUOTA_BYTES = 1_073_741_824; // 1 GB (1024 * 1024 * 1024 bytes)
const TEXT_EDITABLE_EXTENSIONS = ['txt', 'md', 'csv', 'json', 'xml', 'log'];
const ALLOWED_MIME_TYPES = [
    'pdf'  => ['application/pdf'],
    'txt'  => ['text/plain'],
    'log'  => ['text/plain'],
    'md'   => ['text/plain', 'text/markdown', 'text/x-markdown'],
    'csv'  => ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'],
    'json' => ['text/plain', 'application/json'],
    'xml'  => ['text/plain', 'text/xml', 'application/xml'],
    'rtf'  => ['application/rtf', 'text/rtf'],
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png'  => ['image/png'],
    'gif'  => ['image/gif'],
    'webp' => ['image/webp'],
    'bmp'  => ['image/bmp', 'image/x-ms-bmp'],
    'mp3'  => ['audio/mpeg', 'audio/mp3', 'application/octet-stream'],
    'wav'  => ['audio/wav', 'audio/x-wav', 'audio/wave'],
    'mp4'  => ['video/mp4', 'application/mp4'],
    'webm' => ['video/webm', 'audio/webm'],
    'zip'  => ['application/zip', 'application/x-zip-compressed'],
    'rar'  => ['application/x-rar-compressed', 'application/vnd.rar', 'application/x-rar'],
    '7z'   => ['application/x-7z-compressed'],
    'tar'  => ['application/x-tar'],
    'gz'   => ['application/gzip', 'application/x-gzip'],
    'doc'  => ['application/msword', 'application/x-ole-storage'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
    'xls'  => ['application/vnd.ms-excel', 'application/x-ole-storage'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
    'ppt'  => ['application/vnd.ms-powerpoint', 'application/x-ole-storage'],
    'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
    'odt'  => ['application/vnd.oasis.opendocument.text', 'application/zip'],
    'ods'  => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
    'odp'  => ['application/vnd.oasis.opendocument.presentation', 'application/zip'],
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
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
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

function currentUser(bool $refresh = false): ?array {
    static $cachedId = null;
    static $cachedUser = null;
    $id = $_SESSION['user_id'] ?? null;
    if (!is_string($id) || $id === '') {
        $cachedId = null;
        $cachedUser = null;
        return null;
    }
    if (!$refresh && $cachedId === $id && $cachedUser !== null) {
        return $cachedUser;
    }
    $stmt = database()->prepare('SELECT id, name, email, role, quota FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        $cachedId = null;
        $cachedUser = null;
        return null;
    }
    $user['quota'] = (int)$user['quota'];
    $cachedId = $id;
    $cachedUser = $user;
    return $user;
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
    static $connections = [];
    $driver = dbDriver();
    $cacheKey = $driver . ':' . ($driver === 'sqlite' ? DB_FILE : (getenv('OPENSHARE_DB_NAME') ?: DB_NAME));
    if (isset($connections[$cacheKey])) {
        return $connections[$cacheKey];
    }
    if (!class_exists('PDO')) {
        throw new RuntimeException('PHP PDO chưa được bật.');
    }
    if ($driver === 'mysql') {
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
    } elseif ($driver === 'sqlsrv') {
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
        $dir = dirname(DB_FILE);
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('Không thể tạo thư mục cơ sở dữ liệu.');
        }
        $db = new PDO('sqlite:' . DB_FILE);
    }
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    if ($driver === 'sqlite') {
        $db->exec('PRAGMA foreign_keys = ON');
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec('PRAGMA synchronous = NORMAL');
        $db->exec('PRAGMA busy_timeout = 5000');
        $db->exec('PRAGMA cache_size = -2000');
        $db->exec('PRAGMA temp_store = FILE');
    }
    ensureSchema($db);
    $connections[$cacheKey] = $db;
    return $db;
}

function ensureSchema(PDO $db): void {
    static $checked = [];
    $key = dbDriver() . ':' . (dbDriver() === 'sqlite' ? DB_FILE : 'remote');
    if (isset($checked[$key])) {
        return;
    }
    if (dbDriver() === 'sqlite') {
        $exists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='rate_limits'")->fetchColumn();
        if (!$exists) {
            createSchema($db);
        }
        $folderCols = array_column($db->query('PRAGMA table_info(folders)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('deleted_at', $folderCols, true)) {
            $db->exec('ALTER TABLE folders ADD COLUMN deleted_at TEXT');
        }
        $fileCols = array_column($db->query('PRAGMA table_info(files)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('deleted_at', $fileCols, true)) {
            $db->exec('ALTER TABLE files ADD COLUMN deleted_at TEXT');
        }
        if (!in_array('file_hash', $fileCols, true)) {
            $db->exec('ALTER TABLE files ADD COLUMN file_hash TEXT');
        }
        $db->exec('CREATE INDEX IF NOT EXISTS idx_files_hash ON files(owner_id, file_hash)');
    } else {
        createSchema($db);
    }
    $checked[$key] = true;
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
    name TEXT NOT NULL, parent_id TEXT, created_at TEXT NOT NULL, deleted_at TEXT,
    UNIQUE(owner_id, parent_id, name)
);
CREATE TABLE IF NOT EXISTS files (
    id TEXT PRIMARY KEY, owner_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    folder_id TEXT NOT NULL REFERENCES folders(id), original_name TEXT NOT NULL,
    stored_name TEXT NOT NULL UNIQUE, size INTEGER NOT NULL, mime TEXT NOT NULL,
    file_hash TEXT, created_at TEXT NOT NULL, deleted_at TEXT, download_count INTEGER NOT NULL DEFAULT 0
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
CREATE TABLE IF NOT EXISTS rate_limits (
    action_key TEXT PRIMARY KEY,
    attempts INTEGER NOT NULL DEFAULT 0,
    window_start INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_files_owner ON files(owner_id);
CREATE INDEX IF NOT EXISTS idx_files_owner_folder ON files(owner_id, folder_id);
CREATE INDEX IF NOT EXISTS idx_folders_owner_parent ON folders(owner_id, parent_id);
CREATE INDEX IF NOT EXISTS idx_shares_file ON shares(file_id);
CREATE INDEX IF NOT EXISTS idx_shares_recipient ON shares(recipient_id);
CREATE INDEX IF NOT EXISTS idx_queue_share ON share_queue(share_id, requested_at);
CREATE INDEX IF NOT EXISTS idx_logs_file ON access_logs(file_id);
CREATE INDEX IF NOT EXISTS idx_logs_user ON access_logs(user_id);
CREATE INDEX IF NOT EXISTS idx_logs_at ON access_logs(at);
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
    foreach ($data['shares'] as &$share) {
        normalizeShare($share);
    }
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

function resetStore(): void {
    if (dbDriver() === 'sqlite' && !is_dir(dirname(DB_FILE))) mkdir(dirname(DB_FILE), 0770, true);
    if (!is_dir(FILE_ROOT)) mkdir(FILE_ROOT, 0770, true);
    foreach (glob(FILE_ROOT . '/*') ?: [] as $path) {
        if (is_file($path)) unlink($path);
    }
    $db = database();
    createSchema($db);
    $db->beginTransaction();
    foreach (['rate_limits','share_acceptances','share_queue','access_logs','shares','files','folders','users'] as $table) {
        $db->exec("DELETE FROM {$table}");
    }
    $db->commit();
}

function readStore(): array {
    $db = database();
    return databaseToArray($db);
}

function withStore(callable $callback): mixed {
    $db = database();
    beginImmediate($db);
    try {
        $data = databaseToArray($db);
        $result = $callback($data);
        arrayToDatabase($db, $data);
        $db->commit();
        return $result;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function beginImmediate(PDO $db): void {
    $db->beginTransaction();
}

function clientIp(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    return is_string($ip) && $ip !== '' ? substr($ip, 0, 45) : '127.0.0.1';
}

function findUserByEmailSql(string $email): ?array {
    $needle = strtolower(trim($email));
    if ($needle === '') return null;
    $stmt = database()->prepare('SELECT * FROM users WHERE LOWER(email) = ? LIMIT 1');
    $stmt->execute([$needle]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['quota'] = (int)$row['quota'];
    return $row;
}

function systemHasAdmin(): bool {
    return (bool)database()->query("SELECT 1 FROM users WHERE role = 'admin' LIMIT 1")->fetchColumn();
}

function userUsageBytes(string $userId): int {
    $stmt = database()->prepare('SELECT COALESCE(SUM(size), 0) FROM files WHERE owner_id = ?');
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

function countPendingInvitationsForUser(string $userId): int {
    $nowIso = date(DATE_ATOM);
    $stmt = database()->prepare(
        'SELECT COUNT(*) FROM shares s
         INNER JOIN files f ON f.id = s.file_id
         LEFT JOIN share_acceptances sa ON sa.share_id = s.id AND sa.user_id = ?
         WHERE s.recipient_id = ? AND s.owner_id <> ?
           AND f.deleted_at IS NULL
           AND s.revoked_at IS NULL AND s.expires_at > ?
           AND s.downloads < s.max_downloads AND sa.user_id IS NULL'
    );
    $stmt->execute([$userId, $userId, $userId, $nowIso]);
    return (int)$stmt->fetchColumn();
}

function logActivity(PDO $db, string $action, string $userId, string $fileId = '', bool $success = true): void {
    $stmt = $db->prepare('INSERT INTO access_logs (id, at, action, user_id, file_id, success) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([bin2hex(random_bytes(6)), date(DATE_ATOM), $action, $userId, $fileId, $success ? 1 : 0]);
}

function streamFileResponse(string $path, string $mime, string $downloadName, string $disposition = 'attachment'): never {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $cleanAsciiName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $downloadName);
    header('Content-Type: ' . ($mime ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . $disposition . '; filename="' . $cleanAsciiName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    $handle = fopen($path, 'rb');
    if ($handle !== false) {
        while (!feof($handle)) {
            echo fread($handle, 65536);
            flush();
        }
        fclose($handle);
    }
    exit;
}

function requestDownloadSlotSql(PDO $db, string $shareId, string $userId, int $now): array {
    $stmt = $db->prepare('SELECT * FROM shares WHERE id = ? LIMIT 1');
    $stmt->execute([$shareId]);
    $share = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$share || $share['revoked_at'] !== null || strtotime((string)$share['expires_at']) <= $now) {
        return ['status' => 'invalid'];
    }
    $remaining = (int)$share['max_downloads'] - (int)$share['downloads'];
    if ($remaining > 1) {
        $db->prepare('UPDATE shares SET downloads = downloads + 1 WHERE id = ?')->execute([$shareId]);
        return ['status' => 'granted'];
    }

    $qStmt = $db->prepare("SELECT id, user_id FROM share_queue WHERE share_id = ? AND status = 'waiting' ORDER BY requested_at ASC, id ASC");
    $qStmt->execute([$shareId]);
    $waiting = $qStmt->fetchAll(PDO::FETCH_ASSOC);

    $position = null;
    foreach ($waiting as $idx => $entry) {
        if ($entry['user_id'] === $userId) {
            $position = $idx + 1;
            break;
        }
    }
    if ($position === null) {
        $entryId = bin2hex(random_bytes(6));
        $db->prepare('INSERT INTO share_queue (id, share_id, user_id, requested_at, status) VALUES (?, ?, ?, ?, ?)')
           ->execute([$entryId, $shareId, $userId, microtime(true), 'waiting']);
        $waiting[] = ['id' => $entryId, 'user_id' => $userId];
        $position = count($waiting);
    }

    $head = $waiting[0] ?? null;
    if ($head && $head['user_id'] === $userId && $remaining === 1) {
        $db->prepare("UPDATE share_queue SET status = 'granted' WHERE id = ?")->execute([$head['id']]);
        $db->prepare('UPDATE shares SET downloads = downloads + 1 WHERE id = ?')->execute([$shareId]);
        return ['status' => 'granted', 'position' => 1];
    }
    return ['status' => 'queued', 'position' => $position];
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
    return array_key_exists(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ALLOWED_MIME_TYPES);
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
    $db = database();
    $stmt = $db->prepare('SELECT attempts, window_start FROM rate_limits WHERE action_key = ? LIMIT 1');
    $stmt->execute([$action]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    $now = time();
    if ($now - (int)$row['window_start'] > $decaySeconds) {
        $db->prepare('DELETE FROM rate_limits WHERE action_key = ?')->execute([$action]);
        return false;
    }
    return (int)$row['attempts'] >= $maxAttempts;
}

function recordFailedAttempt(string $action, int $decaySeconds = 300): void {
    $db = database();
    $now = time();
    $stmt = $db->prepare('SELECT attempts, window_start FROM rate_limits WHERE action_key = ? LIMIT 1');
    $stmt->execute([$action]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || ($now - (int)$row['window_start'] > $decaySeconds)) {
        $db->prepare('DELETE FROM rate_limits WHERE action_key = ?')->execute([$action]);
        $db->prepare('INSERT INTO rate_limits (action_key, attempts, window_start) VALUES (?, 1, ?)')->execute([$action, $now]);
    } else {
        $db->prepare('UPDATE rate_limits SET attempts = attempts + 1 WHERE action_key = ?')->execute([$action]);
    }
}

function clearRateLimit(string $action): void {
    database()->prepare('DELETE FROM rate_limits WHERE action_key = ?')->execute([$action]);
}

function getRateLimitRemainingSeconds(string $action, int $decaySeconds = 300): int {
    $stmt = database()->prepare('SELECT window_start FROM rate_limits WHERE action_key = ? LIMIT 1');
    $stmt->execute([$action]);
    $start = $stmt->fetchColumn();
    if ($start === false) return 1;
    return max(1, $decaySeconds - (time() - (int)$start));
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

function readDocxParagraphs(string $path): array {
    $entries = zipReadEntries($path, static fn(string $name): bool => $name === 'word/document.xml');
    $xml = $entries['word/document.xml'] ?? null;
    if ($xml === null) {
        throw new RuntimeException('DOCX không chứa nội dung văn bản hợp lệ.');
    }
    $document = new DOMDocument();
    $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    $paragraphs = [];
    foreach ($xpath->query('//*[local-name()="p"]') ?: [] as $paragraph) {
        $text = '';
        foreach ($xpath->query('.//*[local-name()="t" or local-name()="tab" or local-name()="br"]', $paragraph) ?: [] as $node) {
            if ($node->localName === 'tab') {
                $text .= "\t";
            } elseif ($node->localName === 'br') {
                $text .= "\n";
            } else {
                $text .= $node->textContent;
            }
        }
        $paragraphs[] = $text;
    }
    return $paragraphs ?: [''];
}

function docxTextNodeHtml(DOMNode $node, DOMXPath $xpath, array $images = []): string {
    $html = '';
    foreach ($node->childNodes as $child) {
        if ($child->nodeType === XML_TEXT_NODE) {
            $html .= htmlspecialchars($child->nodeValue ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        } elseif ($child->nodeType === XML_ELEMENT_NODE) {
            $name = $child->localName;
            if ($name === 't' || $name === 'delText') {
                $html .= htmlspecialchars($child->textContent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            } elseif ($name === 'tab') {
                $html .= '<span class="docx-tab">&#9;</span>';
            } elseif ($name === 'br' || $name === 'cr') {
                $html .= '<br>';
            } elseif ($name === 'drawing' || $name === 'pict' || $name === 'object') {
                $embed = $xpath->query('.//*[local-name()="blip"]', $child)->item(0);
                $relationshipId = $embed instanceof DOMElement
                    ? $embed->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'embed')
                    : '';
                if ($relationshipId !== '' && isset($images[$relationshipId])) {
                    $html .= '<img class="docx-embedded-image" src="' . $images[$relationshipId] . '" alt="Ảnh trong tài liệu">';
                } else {
                    $html .= '<span class="docx-unsupported-object">[Đối tượng nhúng]</span>';
                }
            } else {
                $html .= docxTextNodeHtml($child, $xpath, $images);
            }
        }
    }
    return $html;
}

function docxParagraphHtml(DOMElement $paragraph, DOMXPath $xpath, array $images = []): string {
    $html = '';
    foreach ($xpath->query('./*[local-name()="r" or local-name()="hyperlink" or local-name()="fldSimple"]', $paragraph) ?: [] as $container) {
        $runProperties = $xpath->query('./*[local-name()="rPr"]', $container)?->item(0);
        $runHtml = docxTextNodeHtml($container, $xpath, $images);
        if ($runProperties instanceof DOMElement) {
            if ($xpath->query('./*[local-name()="b" or local-name()="bCs"]', $runProperties)->length > 0) {
                $runHtml = '<strong>' . $runHtml . '</strong>';
            }
            if ($xpath->query('./*[local-name()="i" or local-name()="iCs"]', $runProperties)->length > 0) {
                $runHtml = '<em>' . $runHtml . '</em>';
            }
            if ($xpath->query('./*[local-name()="u"]', $runProperties)->length > 0) {
                $runHtml = '<u>' . $runHtml . '</u>';
            }
        }
        $html .= $runHtml;
    }
    return '<p>' . ($html !== '' ? $html : '&nbsp;') . '</p>';
}

function docxBlockHtml(DOMNode $container, DOMXPath $xpath, array $images = []): string {
    $html = '';
    foreach ($container->childNodes as $child) {
        if (!$child instanceof DOMElement) {
            continue;
        }
        if ($child->localName === 'p') {
            $html .= docxParagraphHtml($child, $xpath, $images);
        } elseif ($child->localName === 'tbl') {
            $html .= '<table class="docx-table"><tbody>';
            foreach ($xpath->query('./*[local-name()="tr"]', $child) ?: [] as $row) {
                $html .= '<tr>';
                foreach ($xpath->query('./*[local-name()="tc"]', $row) ?: [] as $cell) {
                    $html .= '<td>' . docxBlockHtml($cell, $xpath, $images) . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
        }
    }
    return $html;
}

function docxImageMap(array $entries, string $partName): array {
    $relsPath = 'word/_rels/' . basename($partName) . '.rels';
    $relsXml = $entries[$relsPath] ?? null;
    if ($relsXml === null) {
        return [];
    }
    $rels = new DOMDocument();
    if (!$rels->loadXML($relsXml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
        return [];
    }
    $map = [];
    foreach ($rels->getElementsByTagNameNS('http://schemas.openxmlformats.org/package/2006/relationships', 'Relationship') as $relationship) {
        $target = ltrim(str_replace('\\', '/', (string)$relationship->getAttribute('Target')), '/');
        $id = (string)$relationship->getAttribute('Id');
        if ($id === '' || !str_contains($target, 'media/')) {
            continue;
        }
        $entryKey = str_starts_with($target, 'word/') ? $target : 'word/' . $target;
        $rawImage = $entries[$entryKey] ?? null;
        if ($rawImage === null) {
            continue;
        }
        $extension = strtolower(pathinfo($entryKey, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            default => 'image/' . $extension,
        };
        $map[$id] = 'data:' . $mime . ';base64,' . base64_encode($rawImage);
    }
    return $map;
}

function readDocxHtml(string $path): string {
    $entries = zipReadEntries($path, static fn(string $name): bool => str_starts_with($name, 'word/'));
    $documentXml = $entries['word/document.xml'] ?? null;
    if ($documentXml === null) {
        throw new RuntimeException('DOCX không chứa nội dung văn bản hợp lệ.');
    }
    $document = new DOMDocument();
    if (!$document->loadXML($documentXml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
        throw new RuntimeException('Không thể đọc cấu trúc DOCX.');
    }
    $xpath = new DOMXPath($document);
    $body = $xpath->query('//*[local-name()="body"]')->item(0);
    if (!$body) {
        throw new RuntimeException('DOCX không chứa phần nội dung chính.');
    }
    $html = docxBlockHtml($body, $xpath, docxImageMap($entries, 'word/document.xml'));
    foreach ($entries as $entryName => $entryXml) {
        if (preg_match('#^word/header[^/]*\.xml$#', $entryName)) {
            $header = new DOMDocument();
            if ($header->loadXML($entryXml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                $headerXpath = new DOMXPath($header);
                $headerBody = $headerXpath->query('//*[local-name()="hdr"]')->item(0);
                if ($headerBody) {
                    $html = '<div class="docx-header">' . docxBlockHtml($headerBody, $headerXpath, docxImageMap($entries, $entryName)) . '</div>' . $html;
                }
            }
        } elseif (preg_match('#^word/footer[^/]*\.xml$#', $entryName)) {
            $footer = new DOMDocument();
            if ($footer->loadXML($entryXml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                $footerXpath = new DOMXPath($footer);
                $footerBody = $footerXpath->query('//*[local-name()="ftr"]')->item(0);
                if ($footerBody) {
                    $html .= '<div class="docx-footer">' . docxBlockHtml($footerBody, $footerXpath, docxImageMap($entries, $entryName)) . '</div>';
                }
            }
        }
    }
    return $html !== '' ? $html : '<p>&nbsp;</p>';
}

function buildDocxDocumentXml(string $content): string {
    $paragraphs = preg_split('/\R/u', str_replace(["\r\n", "\r"], "\n", $content)) ?: [''];
    $body = '';
    foreach ($paragraphs as $paragraph) {
        $escaped = htmlspecialchars($paragraph, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $body .= '<w:p><w:r><w:t xml:space="preserve">' . $escaped . '</w:t></w:r></w:p>';
    }
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:body>' . $body
        . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="720" w:footer="720" w:gutter="0"/></w:sectPr>'
        . '</w:body></w:document>';
}

function saveDocxContent(string $path, string $content): void {
    if (strlen($content) > 1_000_000) {
        throw new RuntimeException('Nội dung DOCX không được vượt quá 1 MB.');
    }
    zipReplaceEntry($path, 'word/document.xml', buildDocxDocumentXml($content));
}

function layoutStart(string $title): void {
    $user = currentUser();
    $flash = takeFlash();
    $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $searchQ = get('q');
    
    $isHome = $currentPath === '/' && get('folder') === '';
    $isShared = $currentPath === '/shared.php';
    $isLogs = $currentPath === '/logs.php';
    $isReport = $currentPath === '/report.php';
    $isTrash = $currentPath === '/trash.php';

    $navClass = static fn(bool $active): string => $active ? ' class="nav-item active"' : ' class="nav-item"';

    $usedBytes = 0;
    $quotaBytes = USER_QUOTA_BYTES;
    $usedPercent = 0;
    $roleName = 'Người dùng';
    if ($user) {
        $usedBytes = userUsageBytes($user['id']);
        $quotaBytes = (int)($user['quota'] ?? USER_QUOTA_BYTES);
        $usedPercent = $quotaBytes > 0 ? min(100, round(($usedBytes / $quotaBytes) * 100)) : 0;
        $pendingInvites = countPendingInvitationsForUser($user['id']);
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
    $cssFile = __DIR__ . '/../public/assets/app.css';
    $cssVer = file_exists($cssFile) ? (string)filemtime($cssFile) : '2.0';
    echo '<link rel="stylesheet" href="/assets/app.css?v=' . $cssVer . '">';
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
        echo '        <a href="/profile.php" class="dropdown-item">👤 Hồ sơ & Mật khẩu</a>';
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
        echo '      <button type="button" class="btn-new" id="new-menu-button" aria-expanded="false">';
        echo '        <svg width="24" height="24" viewBox="0 0 24 24"><path fill="#EA4335" d="M11 11V5h2v6h6v2h-6v6h-2v-6H5v-2z"/><path fill="#4285F4" d="M11 11V5h2v6z"/><path fill="#FBBC05" d="M19 11v2h-6v-2z"/><path fill="#34A853" d="M11 13v6h2v-6z"/></svg>';
        echo '        <span>Mới</span>';
        echo '      </button>';
        echo '      <div class="new-menu" id="new-menu" hidden>';
        echo '        <button type="button" data-new-action="folder">📁 <span>Tạo thư mục mới</span></button>';
        echo '        <button type="button" data-new-action="file">📄 <span>Tải tệp lên</span></button>';
        echo '        <button type="button" data-new-action="folder-upload">📂 <span>Tải thư mục lên</span></button>';
        echo '      </div>';
        echo '      <input type="file" id="new-file-input" class="visually-hidden" multiple accept="' . h('.' . implode(',.', array_keys(ALLOWED_MIME_TYPES))) . '">';
        echo '      <input type="file" id="new-folder-input" class="visually-hidden" webkitdirectory directory multiple>';
        echo '    </div>';
        echo '    <nav class="sidebar-nav">';
        echo '      <a href="/" data-drop-folder="root-' . h($user['id']) . '"' . $navClass($isHome) . '><span class="nav-icon">📁</span><span>Drive của tôi</span></a>';
        $inviteBadgeHtml = $pendingInvites > 0 ? ' <span class="nav-badge-alert">' . $pendingInvites . '</span>' : '';
        echo '      <a href="/shared.php"' . $navClass($isShared) . '><span class="nav-icon">👥</span><span>Được chia sẻ với tôi' . $inviteBadgeHtml . '</span></a>';
        echo '      <a href="/logs.php"' . $navClass($isLogs) . '><span class="nav-icon">⏱</span><span>Hoạt động</span></a>';
        echo '      <a href="/report.php"' . $navClass($isReport) . '><span class="nav-icon">📊</span><span>Bộ nhớ lưu trữ</span></a>';
        echo '      <a href="/trash.php"' . $navClass($isTrash) . '><span class="nav-icon">🗑</span><span>Thùng rác</span></a>';
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
