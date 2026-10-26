IF OBJECT_ID(N'access_logs', N'U') IS NULL CREATE TABLE access_logs (
    id NVARCHAR(32) NOT NULL PRIMARY KEY, at NVARCHAR(40) NOT NULL,
    action NVARCHAR(80) NOT NULL, user_id NVARCHAR(64) NOT NULL,
    file_id NVARCHAR(64) NULL, success BIT NOT NULL DEFAULT 1
);
IF OBJECT_ID(N'users', N'U') IS NULL CREATE TABLE users (
    id NVARCHAR(64) NOT NULL PRIMARY KEY, name NVARCHAR(100) NOT NULL,
    email NVARCHAR(255) NOT NULL UNIQUE, role NVARCHAR(20) NOT NULL,
    password NVARCHAR(255) NOT NULL, quota BIGINT NOT NULL
);
IF OBJECT_ID(N'folders', N'U') IS NULL CREATE TABLE folders (
    id NVARCHAR(64) NOT NULL PRIMARY KEY, owner_id NVARCHAR(64) NOT NULL,
    name NVARCHAR(80) NOT NULL, parent_id NVARCHAR(64) NULL, created_at NVARCHAR(40) NOT NULL,
    CONSTRAINT UQ_folders_owner_name UNIQUE (owner_id, name),
    CONSTRAINT FK_folders_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
);
IF OBJECT_ID(N'files', N'U') IS NULL CREATE TABLE files (
    id NVARCHAR(64) NOT NULL PRIMARY KEY, owner_id NVARCHAR(64) NOT NULL,
    folder_id NVARCHAR(64) NOT NULL, original_name NVARCHAR(255) NOT NULL,
    stored_name NVARCHAR(255) NOT NULL UNIQUE, size BIGINT NOT NULL, mime NVARCHAR(255) NOT NULL,
    created_at NVARCHAR(40) NOT NULL, download_count INT NOT NULL DEFAULT 0,
    CONSTRAINT FK_files_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT FK_files_folder FOREIGN KEY (folder_id) REFERENCES folders(id)
);
IF OBJECT_ID(N'shares', N'U') IS NULL CREATE TABLE shares (
    id NVARCHAR(64) NOT NULL PRIMARY KEY, file_id NVARCHAR(64) NOT NULL,
    owner_id NVARCHAR(64) NOT NULL, recipient_id NVARCHAR(64) NOT NULL,
    expires_at NVARCHAR(40) NOT NULL, max_downloads INT NOT NULL, downloads INT NOT NULL DEFAULT 0,
    password_hash NVARCHAR(255) NULL, created_at NVARCHAR(40) NOT NULL, revoked_at NVARCHAR(40) NULL,
    CONSTRAINT FK_shares_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    CONSTRAINT FK_shares_owner FOREIGN KEY (owner_id) REFERENCES users(id),
    CONSTRAINT FK_shares_recipient FOREIGN KEY (recipient_id) REFERENCES users(id)
);
IF OBJECT_ID(N'share_queue', N'U') IS NULL CREATE TABLE share_queue (
    id NVARCHAR(64) NOT NULL PRIMARY KEY, share_id NVARCHAR(64) NOT NULL,
    user_id NVARCHAR(64) NOT NULL, requested_at FLOAT NOT NULL,
    status NVARCHAR(20) NOT NULL,
    CONSTRAINT FK_queue_share FOREIGN KEY (share_id) REFERENCES shares(id) ON DELETE CASCADE,
    CONSTRAINT FK_queue_user FOREIGN KEY (user_id) REFERENCES users(id)
);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'idx_files_owner') CREATE INDEX idx_files_owner ON files(owner_id);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'idx_shares_file') CREATE INDEX idx_shares_file ON shares(file_id);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'idx_shares_recipient') CREATE INDEX idx_shares_recipient ON shares(recipient_id);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'idx_logs_file') CREATE INDEX idx_logs_file ON access_logs(file_id);
