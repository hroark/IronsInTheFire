-- Notes subsite schema. Import this once into an empty MySQL database.
-- Tables use InnoDB so the foreign keys are enforced.

CREATE TABLE IF NOT EXISTS notebooks (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_notebooks_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  notebook_id INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  body_md MEDIUMTEXT NOT NULL,
  pinned TINYINT(1) NOT NULL DEFAULT 0,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notes_list (archived, pinned, updated_at),
  KEY idx_notes_notebook (notebook_id, archived, pinned, updated_at),
  CONSTRAINT fk_notes_notebook FOREIGN KEY (notebook_id) REFERENCES notebooks (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tags (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(80) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tags_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS note_tags (
  note_id INT UNSIGNED NOT NULL,
  tag_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (note_id, tag_id),
  KEY idx_note_tags_tag (tag_id),
  CONSTRAINT fk_note_tags_note FOREIGN KEY (note_id) REFERENCES notes (id) ON DELETE CASCADE,
  CONSTRAINT fk_note_tags_tag FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS links (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  notebook_id INT UNSIGNED NOT NULL,
  url VARCHAR(2048) NOT NULL,
  title VARCHAR(255) NOT NULL,
  note TEXT NOT NULL,
  page_title VARCHAR(255) NOT NULL DEFAULT '',
  page_description VARCHAR(1000) NOT NULL DEFAULT '',
  site_name VARCHAR(255) NOT NULL DEFAULT '',
  snapshot_text MEDIUMTEXT NULL,
  preview_path VARCHAR(255) NULL,
  http_status SMALLINT NULL,
  link_state VARCHAR(20) NOT NULL DEFAULT 'unknown',
  checked_at DATETIME NULL,
  captured_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_links_notebook (notebook_id, created_at),
  KEY idx_links_checked (checked_at, id),
  CONSTRAINT fk_links_notebook FOREIGN KEY (notebook_id) REFERENCES notebooks (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS link_tags (
  link_id INT UNSIGNED NOT NULL,
  tag_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (link_id, tag_id),
  KEY idx_link_tags_tag (tag_id),
  CONSTRAINT fk_link_tags_link FOREIGN KEY (link_id) REFERENCES links (id) ON DELETE CASCADE,
  CONSTRAINT fk_link_tags_tag FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO notebooks (name, sort_order)
SELECT 'Inbox', 0
WHERE NOT EXISTS (SELECT 1 FROM notebooks);

CREATE TABLE IF NOT EXISTS app_password (
  id TINYINT UNSIGNED NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journal_seal (
  id TINYINT UNSIGNED NOT NULL,
  kdf_iterations INT UNSIGNED NOT NULL,
  salt VARCHAR(255) NOT NULL,
  verifier_iv VARCHAR(255) NOT NULL,
  verifier VARCHAR(512) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS upload_folders (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  parent_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_upload_folders_parent (parent_id, name),
  CONSTRAINT fk_upload_folders_parent FOREIGN KEY (parent_id) REFERENCES upload_folders (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS uploads (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  folder_id INT UNSIGNED NULL,
  original_name VARCHAR(180) NOT NULL,
  stored_name VARCHAR(80) NOT NULL,
  mime VARCHAR(160) NOT NULL,
  byte_size INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_uploads_folder (folder_id, created_at),
  CONSTRAINT fk_uploads_folder FOREIGN KEY (folder_id) REFERENCES upload_folders (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journal_entries (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  iv VARCHAR(255) NOT NULL,
  ciphertext MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_journal_entries_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
