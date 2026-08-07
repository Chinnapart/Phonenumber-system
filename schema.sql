-- =========================================================
-- ConnectPro Database Schema
-- MySQL 8.0+
-- =========================================================

CREATE DATABASE IF NOT EXISTS connectpro
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE connectpro;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;


-- =========================================================
-- 1. ROLES
-- สิทธิ์ของระบบมี 2 Role: admin และ user
-- =========================================================

CREATE TABLE roles (
    id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_key VARCHAR(30) NOT NULL,
    role_name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_role_key (role_key)
) ENGINE=InnoDB;


-- =========================================================
-- 2. USERS
-- บัญชีผู้ใช้งาน Admin และ User ทั่วไป
-- password_hash ต้องสร้างด้วย PHP password_hash()
-- =========================================================

CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_id TINYINT UNSIGNED NOT NULL,

    employee_code VARCHAR(30) NULL,
    username VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,

    display_name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NULL,
    avatar_path VARCHAR(500) NULL,

    account_status ENUM(
        'active',
        'inactive',
        'locked'
    ) NOT NULL DEFAULT 'active',

    failed_login_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,

    last_login_at DATETIME NULL,
    last_login_ip VARCHAR(45) NULL,
    password_changed_at DATETIME NULL,

    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    deleted_at DATETIME NULL,

    PRIMARY KEY (id),

    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_employee_code (employee_code),
    UNIQUE KEY uq_users_email (email),

    KEY idx_users_role_status (
        role_id,
        account_status
    ),

    KEY idx_users_deleted_at (deleted_at),

    CONSTRAINT fk_users_role
        FOREIGN KEY (role_id)
        REFERENCES roles(id)
        ON UPDATE RESTRICT
        ON DELETE RESTRICT,

    CONSTRAINT fk_users_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON UPDATE RESTRICT
        ON DELETE SET NULL,

    CONSTRAINT fk_users_updated_by
        FOREIGN KEY (updated_by)
        REFERENCES users(id)
        ON UPDATE RESTRICT
        ON DELETE SET NULL

) ENGINE=InnoDB;


-- =========================================================
-- 3. DEPARTMENTS
-- ข้อมูลแผนกภายในองค์กร
-- =========================================================

CREATE TABLE departments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    department_code VARCHAR(30) NOT NULL,
    department_name VARCHAR(150) NOT NULL,
    description VARCHAR(500) NULL,

    color_hex CHAR(7) NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,

    is_active BOOLEAN NOT NULL DEFAULT TRUE,

    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    deleted_at DATETIME NULL,

    PRIMARY KEY (id),

    UNIQUE KEY uq_departments_code (department_code),
    UNIQUE KEY uq_departments_name (department_name),

    KEY idx_departments_active_sort (
        is_active,
        sort_order
    ),

    KEY idx_departments_deleted_at (deleted_at),

    CONSTRAINT fk_departments_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON UPDATE RESTRICT
        ON DELETE SET NULL,

    CONSTRAINT fk_departments_updated_by
        FOREIGN KEY (updated_by)
        REFERENCES users(id)
        ON UPDATE RESTRICT
        ON DELETE SET NULL,

    CONSTRAINT chk_departments_color_hex
        CHECK (
            color_hex IS NULL
            OR color_hex REGEXP '^#[0-9A-Fa-f]{6}$'
        )

) ENGINE=InnoDB;


-- =========================================================
-- 4. LOCATIONS
-- สถานที่ อาคาร ชั้น และห้องทำงาน
-- =========================================================

CREATE TABLE locations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    location_code VARCHAR(30) NOT NULL,
    location_name VARCHAR(150) NOT NULL,

    building VARCHAR(100) NULL,
    floor_name VARCHAR(50) NULL,
    room_name VARCHAR(100) NULL,

    description VARCHAR(500) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,

    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    deleted_at DATETIME NULL,

    PRIMARY KEY (id),

    UNIQUE KEY uq_locations_code (location_code),
    UNIQUE KEY uq_locations_name (location_name),

    KEY idx_locations_active (is_active),
    KEY idx_locations_deleted_at (deleted_at),

    CONSTRAINT fk_locations_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON UPDATE RESTRICT
        ON DELETE SET NULL,

    CONSTRAINT fk_locations_updated_by
        FOREIGN KEY (updated_by)
        REFERENCES users(id)
        ON UPDATE RESTRICT
        ON DELETE SET NULL

) ENGINE=InnoDB;


-- =========================================================
-- 5. CONTACTS
-- ข้อมูลรายชื่อและเบอร์โทรศัพท์ภายในองค์กร
-- =========================================================

CREATE TABLE contacts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    employee_code VARCHAR(30) NULL,

    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    display_name VARCHAR(200) NOT NULL,

    position_title VARCHAR(150) NULL,

    department_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NULL,

    extension_number VARCHAR(20) NULL,
    mobile_number VARCHAR(30) NULL,
    office_number VARCHAR(30) NULL,

    email VARCHAR(190) NULL,

    ip_address VARCHAR(45) NULL,
    computer_name VARCHAR(100) NULL,

    profile_image_path VARCHAR(500) NULL,
    notes TEXT NULL,

    contact_status ENUM(
        'active',
        'inactive'
    ) NOT NULL DEFAULT 'active',

    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    deleted_at DATETIME NULL,

    PRIMARY KEY (id),

    UNIQUE KEY uq_contacts_employee_code (employee_code),
    UNIQUE KEY uq_contacts_extension (extension_number),
    UNIQUE KEY uq_contacts_email (email),
    UNIQUE KEY uq_contacts_ip_address (ip_address),

    KEY idx_contacts_display_name (display_name),

    KEY idx_contacts_name (
        first_name,
        last_name
    ),

    KEY idx_contacts_department_status (
        department_id,
        contact_status
    ),

    KEY idx_contacts_location (location_id),
    KEY idx_contacts_updated_at (updated_at),
    KEY idx_contacts_deleted_at (deleted_at),

    CONSTRAINT fk_contacts_department
        FOREIGN KEY (department_id)
        REFERENCES departments(id)
        ON UPDATE RESTRICT
        ON DELETE RESTRICT,

    CONSTRAINT fk_contacts_location
        FOREIGN KEY (location_id)
        REFERENCES locations(id)
        ON UPDATE RESTRICT
        ON DELETE SET NULL,

    CONSTRAINT fk_contacts_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON UPDATE RESTRICT
        ON DELETE SET NULL,

    CONSTRAINT fk_contacts_updated_by
        FOREIGN KEY (updated_by)
        REFERENCES users(id)
        ON UPDATE RESTRICT
        ON DELETE SET NULL

) ENGINE=InnoDB;


-- =========================================================
-- 6. CONTACT PRESENCE
-- สถานะ Online / Offline ล่าสุดของแต่ละ Contact
-- =========================================================

CREATE TABLE contact_presence (
    contact_id BIGINT UNSIGNED NOT NULL,

    presence_status ENUM(
        'online',
        'offline',
        'unknown'
    ) NOT NULL DEFAULT 'unknown',

    check_method ENUM(
        'ping',
        'heartbeat',
        'manual'
    ) NOT NULL DEFAULT 'ping',

    response_time_ms INT UNSIGNED NULL,

    last_checked_at DATETIME NULL,
    last_online_at DATETIME NULL,

    consecutive_failures SMALLINT UNSIGNED
        NOT NULL DEFAULT 0,

    status_message VARCHAR(255) NULL,

    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (contact_id),

    KEY idx_presence_status_checked (
        presence_status,
        last_checked_at
    ),

    KEY idx_presence_last_online (last_online_at),

    CONSTRAINT fk_contact_presence_contact
        FOREIGN KEY (contact_id)
        REFERENCES contacts(id)
        ON UPDATE RESTRICT
        ON DELETE CASCADE

) ENGINE=InnoDB;


-- =========================================================
-- 7. PRESENCE HISTORY
-- ประวัติการตรวจสอบสถานะ IP Address
-- =========================================================

CREATE TABLE presence_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    contact_id BIGINT UNSIGNED NOT NULL,
    ip_address VARCHAR(45) NULL,

    presence_status ENUM(
        'online',
        'offline',
        'unknown'
    ) NOT NULL,

    check_method ENUM(
        'ping',
        'heartbeat',
        'manual'
    ) NOT NULL DEFAULT 'ping',

    response_time_ms INT UNSIGNED NULL,
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    error_message VARCHAR(500) NULL,

    PRIMARY KEY (id),

    KEY idx_presence_history_contact_checked (
        contact_id,
        checked_at
    ),

    KEY idx_presence_history_status_checked (
        presence_status,
        checked_at
    ),

    CONSTRAINT fk_presence_history_contact
        FOREIGN KEY (contact_id)
        REFERENCES contacts(id)
        ON UPDATE RESTRICT
        ON DELETE CASCADE

) ENGINE=InnoDB;


-- =========================================================
-- 8. ACTIVITY LOGS
-- เก็บประวัติการกระทำในระบบ
-- =========================================================

CREATE TABLE activity_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    user_id BIGINT UNSIGNED NULL,

    action_key VARCHAR(80) NOT NULL,
    module_name VARCHAR(80) NOT NULL,

    target_type VARCHAR(80) NULL,
    target_id BIGINT UNSIGNED NULL,

    description VARCHAR(1000) NOT NULL,

    old_values JSON NULL,
    new_values JSON NULL,

    request_method VARCHAR(10) NULL,
    request_path VARCHAR(500) NULL,

    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(1000) NULL,

    result_status ENUM(
        'success',
        'failed',
        'denied'
    ) NOT NULL DEFAULT 'success',

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    KEY idx_activity_logs_user_created (
        user_id,
        created_at
    ),

    KEY idx_activity_logs_module_action (
        module_name,
        action_key
    ),

    KEY idx_activity_logs_target (
        target_type,
        target_id
    ),

    KEY idx_activity_logs_result_created (
        result_status,
        created_at
    ),

    CONSTRAINT fk_activity_logs_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON UPDATE RESTRICT
        ON DELETE SET NULL

) ENGINE=InnoDB;


-- =========================================================
-- 9. IMPORT JOBS
-- เก็บประวัติงาน Import Excel หรือ CSV
-- =========================================================

CREATE TABLE import_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    requested_by BIGINT UNSIGNED NULL,

    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(255) NOT NULL,

    file_type ENUM(
        'xlsx',
        'csv'
    ) NOT NULL,

    job_status ENUM(
        'uploaded',
        'validating',
        'ready',
        'processing',
        'completed',
        'failed',
        'cancelled'
    ) NOT NULL DEFAULT 'uploaded',

    total_rows INT UNSIGNED NOT NULL DEFAULT 0,
    success_rows INT UNSIGNED NOT NULL DEFAULT 0,
    failed_rows INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_rows INT UNSIGNED NOT NULL DEFAULT 0,

    error_summary VARCHAR(1000) NULL,

    started_at DATETIME NULL,
    completed_at DATETIME NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    KEY idx_import_jobs_user_created (
        requested_by,
        created_at
    ),

    KEY idx_import_jobs_status_created (
        job_status,
        created_at
    ),

    CONSTRAINT fk_import_jobs_requested_by
        FOREIGN KEY (requested_by)
        REFERENCES users(id)
        ON UPDATE RESTRICT
        ON DELETE SET NULL

) ENGINE=InnoDB;


-- =========================================================
-- 10. IMPORT ERRORS
-- เก็บ Error รายแถวจากการ Import
-- =========================================================

CREATE TABLE import_errors (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    import_job_id BIGINT UNSIGNED NOT NULL,

    row_number INT UNSIGNED NOT NULL,
    field_name VARCHAR(100) NULL,

    error_code VARCHAR(80) NOT NULL,
    error_message VARCHAR(1000) NOT NULL,

    row_data JSON NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    KEY idx_import_errors_job_row (
        import_job_id,
        row_number
    ),

    CONSTRAINT fk_import_errors_job
        FOREIGN KEY (import_job_id)
        REFERENCES import_jobs(id)
        ON UPDATE RESTRICT
        ON DELETE CASCADE

) ENGINE=InnoDB;


-- =========================================================
-- 11. EXPORT JOBS
-- เก็บประวัติงาน Export
-- =========================================================

CREATE TABLE export_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    requested_by BIGINT UNSIGNED NULL,

    export_type ENUM(
        'xlsx',
        'csv'
    ) NOT NULL,

    export_status ENUM(
        'queued',
        'processing',
        'completed',
        'failed',
        'expired'
    ) NOT NULL DEFAULT 'queued',

    stored_filename VARCHAR(255) NULL,
    filter_data JSON NULL,

    total_rows INT UNSIGNED NOT NULL DEFAULT 0,
    error_message VARCHAR(1000) NULL,

    expires_at DATETIME NULL,
    completed_at DATETIME NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    KEY idx_export_jobs_user_created (
        requested_by,
        created_at
    ),

    KEY idx_export_jobs_status_expiry (
        export_status,
        expires_at
    ),

    CONSTRAINT fk_export_jobs_requested_by
        FOREIGN KEY (requested_by)
        REFERENCES users(id)
        ON UPDATE RESTRICT
        ON DELETE SET NULL

) ENGINE=InnoDB;


-- =========================================================
-- 12. USER SESSIONS
-- Session ฝั่ง Server
-- เก็บเฉพาะ Hash ของ Token ห้ามเก็บ Raw Token
-- =========================================================

CREATE TABLE user_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    user_id BIGINT UNSIGNED NOT NULL,

    session_token_hash CHAR(64) NOT NULL,

    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(1000) NULL,

    last_activity_at DATETIME NOT NULL
        DEFAULT CURRENT_TIMESTAMP,

    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_user_sessions_token_hash (
        session_token_hash
    ),

    KEY idx_user_sessions_user_expiry (
        user_id,
        expires_at
    ),

    KEY idx_user_sessions_expiry_revoked (
        expires_at,
        revoked_at
    ),

    CONSTRAINT fk_user_sessions_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON UPDATE RESTRICT
        ON DELETE CASCADE

) ENGINE=InnoDB;


-- =========================================================
-- 13. LOGIN ATTEMPTS
-- เก็บประวัติ Login สำเร็จและไม่สำเร็จ
-- =========================================================

CREATE TABLE login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    username VARCHAR(100) NOT NULL,
    user_id BIGINT UNSIGNED NULL,

    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(1000) NULL,

    was_successful BOOLEAN NOT NULL DEFAULT FALSE,
    failure_reason VARCHAR(255) NULL,

    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    KEY idx_login_attempts_username_time (
        username,
        attempted_at
    ),

    KEY idx_login_attempts_ip_time (
        ip_address,
        attempted_at
    ),

    KEY idx_login_attempts_user_time (
        user_id,
        attempted_at
    ),

    CONSTRAINT fk_login_attempts_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON UPDATE RESTRICT
        ON DELETE SET NULL

) ENGINE=InnoDB;


-- =========================================================
-- 14. SYSTEM SETTINGS
-- ค่าตั้งค่าระบบที่ Admin จัดการได้
-- ห้ามเก็บ Password หรือ Secret ในตารางนี้
-- =========================================================

CREATE TABLE system_settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NULL,

    value_type ENUM(
        'string',
        'integer',
        'boolean',
        'json'
    ) NOT NULL DEFAULT 'string',

    setting_group VARCHAR(80) NOT NULL DEFAULT 'general',
    description VARCHAR(500) NULL,

    is_public BOOLEAN NOT NULL DEFAULT FALSE,

    updated_by BIGINT UNSIGNED NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_system_settings_key (setting_key),
    KEY idx_system_settings_group (setting_group),

    CONSTRAINT fk_system_settings_updated_by
        FOREIGN KEY (updated_by)
        REFERENCES users(id)
        ON UPDATE RESTRICT
        ON DELETE SET NULL

) ENGINE=InnoDB;


SET FOREIGN_KEY_CHECKS = 1;

-- =========================================================
-- END OF CONNECTPRO SCHEMA
-- =========================================================