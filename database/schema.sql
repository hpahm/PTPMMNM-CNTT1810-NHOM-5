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
    UNIQUE (owner_id, name)
);

CREATE TABLE files (
    id TEXT PRIMARY KEY,
    owner_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    folder_id TEXT NOT NULL REFERENCES folders(id),
    original_name TEXT NOT NULL,
    stored_name TEXT NOT NULL UNIQUE,
    size INTEGER NOT NULL CHECK (size > 0),
    mime TEXT NOT NULL,
    created_at TEXT NOT NULL,
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
    share_id TEXT PRIMARY KEY REFERENCES shares(id) ON DELETE CASCADE,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    accepted_at TEXT NOT NULL
);

CREATE INDEX idx_files_owner ON files(owner_id);
CREATE INDEX idx_shares_file ON shares(file_id);
CREATE INDEX idx_shares_recipient ON shares(recipient_id);
CREATE INDEX idx_logs_file ON access_logs(file_id);
