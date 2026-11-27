-- ICARS schema - Milestone 2 (users, roles, departments, staff profiles, sessions, audit)
-- FRESH INSTALL: drop the old `icars` database in phpMyAdmin first (Milestone 1 schema is replaced),
-- then import this file, then seed.sql.
CREATE DATABASE IF NOT EXISTS icars CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE icars;

-- Lookup: application roles
CREATE TABLE roles (
    id          TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(30)  NOT NULL,
    description VARCHAR(150) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB;

-- Organisational departments (used for staff profiles now, complaint routing later)
CREATE TABLE departments (
    id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_departments_name (name)
) ENGINE=InnoDB;

-- Accounts (all roles). Role is a foreign key, not an ENUM.
CREATE TABLE users (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_id             TINYINT UNSIGNED NOT NULL,
    full_name           VARCHAR(120) NOT NULL,
    email               VARCHAR(190) NOT NULL,
    password_hash       VARCHAR(255) NOT NULL,
    phone               VARCHAR(20)  NULL,
    is_active           TINYINT(1)   NOT NULL DEFAULT 1,
    last_login_at       DATETIME NULL,
    password_changed_at DATETIME NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role (role_id),
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Extra data that exists only for staff users (1:1 with users). Used by FR-07 later.
CREATE TABLE staff_profiles (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED NOT NULL,
    department_id SMALLINT UNSIGNED NOT NULL,
    job_title     VARCHAR(100) NULL,
    expertise     VARCHAR(255) NULL,
    is_available  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_staff_user (user_id),
    KEY idx_staff_department (department_id),
    CONSTRAINT fk_staff_user FOREIGN KEY (user_id)       REFERENCES users(id)       ON DELETE CASCADE,
    CONSTRAINT fk_staff_dept FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Server-side registry of login sessions: enables instant revocation
-- (deactivation, password change, "sign out other devices"). Only a SHA-256 of the session id is stored.
CREATE TABLE user_sessions (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id          INT UNSIGNED NOT NULL,
    session_hash     CHAR(64)     NOT NULL,
    ip_address       VARCHAR(45)  NOT NULL,
    user_agent       VARCHAR(255) NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at       DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_session_hash (session_hash),
    KEY idx_sessions_user (user_id, revoked_at),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Brute-force throttling
CREATE TABLE login_attempts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email        VARCHAR(190) NOT NULL,
    ip_address   VARCHAR(45)  NOT NULL,
    was_success  TINYINT(1)   NOT NULL DEFAULT 0,
    attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_attempts_lookup (email, ip_address, attempted_at)
) ENGINE=InnoDB;

-- Audit trail (rule 19). Never stores passwords or hashes.
CREATE TABLE audit_logs (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NULL,
    action      VARCHAR(60)  NOT NULL,
    entity_type VARCHAR(60)  NOT NULL,
    entity_id   BIGINT UNSIGNED NULL,
    old_values  JSON NULL,
    new_values  JSON NULL,
    ip_address  VARCHAR(45)  NULL,
    user_agent  VARCHAR(255) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_entity (entity_type, entity_id),
    KEY idx_audit_user (user_id),
    KEY idx_audit_created (created_at),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Milestone 3: complaints
-- ---------------------------------------------------------------------------

-- Lookup: complaint categories (manual choice now; AI classification uses the same list later)
CREATE TABLE IF NOT EXISTS complaint_categories (
    id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB;

-- Lookup: the full complaint lifecycle (only "Submitted" is used in this milestone)
CREATE TABLE IF NOT EXISTS complaint_statuses (
    id         TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(40) NOT NULL,
    sort_order TINYINT UNSIGNED NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,           -- 0 = reserved for a later milestone
    PRIMARY KEY (id),
    UNIQUE KEY uq_statuses_name (name)
) ENGINE=InnoDB;

-- Priority / severity scale (shared by complaints.priority_id and complaints.severity_id)
CREATE TABLE IF NOT EXISTS complaint_levels (
    id         TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(20) NOT NULL,
    sort_order TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_levels_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS complaints (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    complaint_no VARCHAR(30)  NOT NULL,                 -- public reference, e.g. ICARS-2026-000042
    user_id      INT UNSIGNED NOT NULL,                 -- the complainant
    category_id  SMALLINT UNSIGNED NULL,                -- NULL = not chosen yet
    department_id SMALLINT UNSIGNED NULL,               -- responsible department (admin now, AI later)
    assigned_to  INT UNSIGNED NULL,                     -- staff member handling it
    status_id    TINYINT UNSIGNED NOT NULL,
    priority_id  TINYINT UNSIGNED NULL,
    severity_id  TINYINT UNSIGNED NULL,
    title        VARCHAR(150) NOT NULL,
    description  TEXT         NOT NULL,
    resolution_details TEXT NULL,
    location     VARCHAR(150) NOT NULL,
    resolved_at  DATETIME NULL,
    resolution_confirmed_at DATETIME NULL,
    closed_at    DATETIME NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_complaints_no (complaint_no),
    KEY idx_complaints_user (user_id, created_at),
    KEY idx_complaints_status (status_id),
    KEY idx_complaints_category (category_id),
    KEY idx_complaints_department (department_id),
    KEY idx_complaints_assignee (assigned_to),
    KEY idx_complaints_priority (priority_id),
    CONSTRAINT fk_complaints_user       FOREIGN KEY (user_id)       REFERENCES users(id)                ON DELETE RESTRICT,
    CONSTRAINT fk_complaints_category   FOREIGN KEY (category_id)   REFERENCES complaint_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_complaints_department FOREIGN KEY (department_id) REFERENCES departments(id)          ON DELETE SET NULL,
    CONSTRAINT fk_complaints_assignee   FOREIGN KEY (assigned_to)   REFERENCES users(id)                ON DELETE SET NULL,
    CONSTRAINT fk_complaints_status     FOREIGN KEY (status_id)     REFERENCES complaint_statuses(id)   ON DELETE RESTRICT,
    CONSTRAINT fk_complaints_priority   FOREIGN KEY (priority_id)   REFERENCES complaint_levels(id)     ON DELETE SET NULL,
    CONSTRAINT fk_complaints_severity   FOREIGN KEY (severity_id)   REFERENCES complaint_levels(id)     ON DELETE SET NULL
) ENGINE=InnoDB;

-- Files live in storage/uploads (outside the web root) under a random name; only metadata is stored here.
CREATE TABLE IF NOT EXISTS complaint_attachments (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    complaint_id   INT UNSIGNED NOT NULL,
    uploaded_by    INT UNSIGNED NULL,
    original_name  VARCHAR(255) NOT NULL,               -- sanitised, for display/download only
    stored_path    VARCHAR(255) NOT NULL,               -- relative to storage/uploads, e.g. 2026/09/3fa9...pdf
    mime_type      VARCHAR(100) NOT NULL,               -- detected server-side, never client-supplied
    file_size      INT UNSIGNED NOT NULL,
    sha256         CHAR(64)     NOT NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attachment_path (stored_path),
    KEY idx_attachments_complaint (complaint_id),
    CONSTRAINT fk_attach_complaint FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE,
    CONSTRAINT fk_attach_user      FOREIGN KEY (uploaded_by)  REFERENCES users(id)      ON DELETE SET NULL
) ENGINE=InnoDB;

-- Every important event of a complaint: submission, status changes, assignment, classification corrections.
-- visibility 'internal' rows are shown to administrators only.
CREATE TABLE IF NOT EXISTS complaint_history (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    complaint_id   INT UNSIGNED NOT NULL,
    event_type     VARCHAR(30)  NOT NULL,              -- submitted | status_changed | assigned | classification_updated
    from_status_id TINYINT UNSIGNED NULL,
    to_status_id   TINYINT UNSIGNED NULL,
    changed_by     INT UNSIGNED NULL,
    visibility     ENUM('public','internal') NOT NULL DEFAULT 'public',
    note           VARCHAR(500) NULL,
    old_values     JSON NULL,
    new_values     JSON NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_history_complaint (complaint_id, created_at),
    KEY idx_history_event (event_type),
    CONSTRAINT fk_chist_complaint FOREIGN KEY (complaint_id)   REFERENCES complaints(id)         ON DELETE CASCADE,
    CONSTRAINT fk_chist_from      FOREIGN KEY (from_status_id) REFERENCES complaint_statuses(id) ON DELETE RESTRICT,
    CONSTRAINT fk_chist_to        FOREIGN KEY (to_status_id)   REFERENCES complaint_statuses(id) ON DELETE RESTRICT,
    CONSTRAINT fk_chist_user      FOREIGN KEY (changed_by)     REFERENCES users(id)              ON DELETE SET NULL
) ENGINE=InnoDB;

-- Explainable local triage recommendation and subsequent staff/admin decision (internal only).
CREATE TABLE IF NOT EXISTS complaint_recommendations (
    complaint_id INT UNSIGNED NOT NULL,
    suggestion_json JSON NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    decision_json JSON NULL,
    decided_by INT UNSIGNED NULL,
    decided_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (complaint_id),
    CONSTRAINT fk_recommendation_complaint FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE,
    CONSTRAINT fk_recommendation_actor FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS complaint_feedback (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    complaint_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    feedback TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id), UNIQUE KEY uq_feedback_complaint (complaint_id),
    CONSTRAINT fk_feedback_complaint FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE,
    CONSTRAINT fk_feedback_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT chk_feedback_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB;

CREATE OR REPLACE VIEW complaint_resolution_knowledge AS
SELECT c.id AS complaint_id, c.complaint_no, c.category_id, cat.name AS category,
       c.priority_id, p.name AS priority, c.title, c.description, c.resolution_details AS resolution,
       c.created_at, c.resolved_at, TIMESTAMPDIFF(SECOND, c.created_at, c.resolved_at) AS resolution_seconds,
       f.rating, f.feedback
  FROM complaints c JOIN complaint_statuses s ON s.id=c.status_id AND s.name IN ('Resolved','Closed')
  LEFT JOIN complaint_categories cat ON cat.id=c.category_id
  LEFT JOIN complaint_levels p ON p.id=c.priority_id
  LEFT JOIN complaint_feedback f ON f.complaint_id=c.id
 WHERE c.resolved_at IS NOT NULL AND c.resolution_details IS NOT NULL;

-- ---------------------------------------------------------------------------
-- Milestone 5: AI request log (metadata only; never API keys or headers)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_request_logs (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    task             VARCHAR(40)  NOT NULL,              -- analyze_complaint | classify_complaint | analyze_priority | recommend_department | recommend_resolution | ping
    provider         VARCHAR(30)  NOT NULL,
    model            VARCHAR(100) NOT NULL,
    status           ENUM('success','error') NOT NULL,
    error_code       VARCHAR(40)  NULL,                  -- timeout | auth_failed | rate_limited | invalid_json | schema_invalid ...
    http_status      SMALLINT UNSIGNED NULL,
    attempts         TINYINT UNSIGNED NOT NULL DEFAULT 0,
    latency_ms       INT UNSIGNED NOT NULL DEFAULT 0,
    prompt_chars     INT UNSIGNED NOT NULL DEFAULT 0,
    response_chars   INT UNSIGNED NOT NULL DEFAULT 0,
    input_tokens     INT UNSIGNED NULL,
    output_tokens    INT UNSIGNED NULL,
    complaint_id     INT UNSIGNED NULL,
    user_id          INT UNSIGNED NULL,
    error_detail     VARCHAR(500) NULL,                  -- scrubbed of secrets
    attempt_log      JSON NULL,                          -- per-attempt outcome and timing
    request_excerpt  TEXT NULL,                          -- only filled when ai.log_payloads = true
    response_excerpt TEXT NULL,                          -- only filled when ai.log_payloads = true
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ailog_created (created_at),
    KEY idx_ailog_status (status, created_at),
    KEY idx_ailog_complaint (complaint_id),
    CONSTRAINT fk_ailog_complaint FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE SET NULL,
    CONSTRAINT fk_ailog_user      FOREIGN KEY (user_id)      REFERENCES users(id)      ON DELETE SET NULL
) ENGINE=InnoDB;
