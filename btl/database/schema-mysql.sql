SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    role VARCHAR(20) NOT NULL,
    password VARCHAR(255) NOT NULL,
    quota BIGINT NOT NULL,
    CONSTRAINT users_role_chk CHECK (role IN ('owner', 'recipient', 'admin')),
    CONSTRAINT users_quota_chk CHECK (quota >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS folders (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    owner_id VARCHAR(64) NOT NULL,
    name VARCHAR(160) NOT NULL,
    parent_id VARCHAR(64) NULL,
    created_at VARCHAR(40) NOT NULL,
    CONSTRAINT folders_owner_name_uq UNIQUE (owner_id, name),     
    CONSTRAINT folders_owner_fk FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS files (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    owner_id VARCHAR(64) NOT NULL,
    folder_id VARCHAR(64) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL UNIQUE,
    size BIGINT NOT NULL,
    mime VARCHAR(255) NOT NULL,
    created_at VARCHAR(40) NOT NULL,
    download_count INT NOT NULL DEFAULT 0,
    CONSTRAINT files_size_chk CHECK (size > 0),
    CONSTRAINT files_download_count_chk CHECK (download_count >= 0),
    CONSTRAINT files_owner_fk FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT files_folder_fk FOREIGN KEY (folder_id) REFERENCES folders(id),
    KEY idx_files_owner (owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS shares (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    file_id VARCHAR(64) NOT NULL,
    owner_id VARCHAR(64) NOT NULL,
    recipient_id VARCHAR(64) NOT NULL,
    expires_at VARCHAR(40) NOT NULL,
    max_downloads INT NOT NULL,
    downloads INT NOT NULL DEFAULT 0,
    password_hash VARCHAR(255) NULL,
    created_at VARCHAR(40) NOT NULL,
    revoked_at VARCHAR(40) NULL,
    CONSTRAINT shares_max_downloads_chk CHECK (max_downloads > 0),
    CONSTRAINT shares_downloads_chk CHECK (downloads >= 0 AND downloads <= max_downloads),
    CONSTRAINT shares_file_fk FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    CONSTRAINT shares_owner_fk FOREIGN KEY (owner_id) REFERENCES users(id),
    CONSTRAINT shares_recipient_fk FOREIGN KEY (recipient_id) REFERENCES users(id),
    KEY idx_shares_file (file_id),
    KEY idx_shares_recipient (recipient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS share_queue (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    share_id VARCHAR(64) NOT NULL,
    user_id VARCHAR(64) NOT NULL,
    requested_at DOUBLE NOT NULL,
    status VARCHAR(20) NOT NULL,
    CONSTRAINT share_queue_status_chk CHECK (status IN ('waiting', 'granted')),
    CONSTRAINT share_queue_share_fk FOREIGN KEY (share_id) REFERENCES shares(id) ON DELETE CASCADE,
    CONSTRAINT share_queue_user_fk FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS access_logs (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    at VARCHAR(40) NOT NULL,
    action VARCHAR(80) NOT NULL,
    user_id VARCHAR(64) NOT NULL,
    file_id VARCHAR(64) NULL,
    success TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT access_logs_success_chk CHECK (success IN (0, 1)),
    KEY idx_logs_file (file_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS share_acceptances (
    share_id VARCHAR(64) NOT NULL PRIMARY KEY,
    user_id VARCHAR(64) NOT NULL,
    accepted_at VARCHAR(40) NOT NULL,
    CONSTRAINT share_acceptances_share_fk FOREIGN KEY (share_id) REFERENCES shares(id) ON DELETE CASCADE,
    CONSTRAINT share_acceptances_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
