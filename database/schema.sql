PRAGMA foreign_keys = ON;

CREATE TABLE users (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    role TEXT NOT NULL CHECK (role IN ('owner', 'recipient', 'admin')),
    password TEXT NOT NULL,
    quota INTEGER NOT NULL
);

CREATE TABLE folders (
    id TEXT PRIMARY KEY,
    owner_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    parent_id TEXT,
    created_at TEXT NOT NULL,
    deleted_at TEXT,
    UNIQUE (owner_id, parent_id, name)
);

CREATE TABLE files (
    id TEXT PRIMARY KEY,
    owner_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    folder_id TEXT NOT NULL REFERENCES folders(id),
    original_name TEXT NOT NULL,
    stored_name TEXT NOT NULL UNIQUE,
    size INTEGER NOT NULL CHECK (size > 0),
    mime TEXT NOT NULL,
    file_hash TEXT,
    created_at TEXT NOT NULL,
    deleted_at TEXT,
    download_count INTEGER NOT NULL DEFAULT 0 CHECK (download_count >= 0)
);

CREATE TABLE shares (
    id TEXT PRIMARY KEY,
    file_id TEXT NOT NULL REFERENCES files(id) ON DELETE CASCADE,
    owner_id TEXT NOT NULL REFERENCES users(id),
    recipient_id TEXT NOT NULL REFERENCES users(id),
    expires_at TEXT NOT NULL,
    max_downloads INTEGER NOT NULL CHECK (max_downloads > 0),
    downloads INTEGER NOT NULL DEFAULT 0 CHECK (downloads >= 0),
    password_hash TEXT,
    created_at TEXT NOT NULL,
    revoked_at TEXT
);

CREATE TABLE share_queue (
    id TEXT PRIMARY KEY,
    share_id TEXT NOT NULL REFERENCES shares(id) ON DELETE CASCADE,
    user_id TEXT NOT NULL REFERENCES users(id),
    requested_at REAL NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('waiting', 'granted'))
);

CREATE TABLE access_logs (
    id TEXT PRIMARY KEY,
    at TEXT NOT NULL,
    action TEXT NOT NULL,
    user_id TEXT NOT NULL,
    file_id TEXT,
    success INTEGER NOT NULL DEFAULT 1 CHECK (success IN (0, 1))
);

CREATE TABLE share_acceptances (
    share_id TEXT NOT NULL REFERENCES shares(id) ON DELETE CASCADE,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    accepted_at TEXT NOT NULL,
    PRIMARY KEY (share_id, user_id)
);

CREATE TABLE rate_limits (
    action_key TEXT PRIMARY KEY,
    attempts INTEGER NOT NULL DEFAULT 0,
    window_start INTEGER NOT NULL
);

CREATE INDEX idx_files_owner ON files(owner_id);
CREATE INDEX idx_files_owner_folder ON files(owner_id, folder_id);
CREATE INDEX idx_folders_owner_parent ON folders(owner_id, parent_id);
CREATE INDEX idx_shares_file ON shares(file_id);
CREATE INDEX idx_shares_recipient ON shares(recipient_id);
CREATE INDEX idx_queue_share ON share_queue(share_id, requested_at);
CREATE INDEX idx_logs_file ON access_logs(file_id);
CREATE INDEX idx_logs_user ON access_logs(user_id);
CREATE INDEX idx_logs_at ON access_logs(at);
