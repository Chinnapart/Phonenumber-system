-- ============================================================================
-- ConnectPro Initial Seed Data
-- Target: MySQL 8.0+
-- Run after database/schema.sql
-- Change default passwords before production use.
-- ============================================================================

USE connectpro;
SET NAMES utf8mb4;
SET time_zone = '+07:00';
SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;

-- 1. Roles
INSERT INTO roles (id, role_key, role_name, description, is_active) VALUES
(1, 'admin', 'Administrator', 'Full system administration access', TRUE),
(2, 'user', 'General User', 'Read-only directory access', TRUE)
ON DUPLICATE KEY UPDATE
role_name = VALUES(role_name),
description = VALUES(description),
is_active = VALUES(is_active);

-- 2. Initial test accounts
-- admin / Admin@12345
-- user  / User@12345
-- Password hashes are bcrypt-compatible with PHP password_verify().
INSERT INTO users (
    id, role_id, employee_code, username, password_hash,
    display_name, email, account_status, failed_login_count,
    password_changed_at
) VALUES
(1, 1, 'ADM001', 'admin', '$2b$12$sx1SbY7x0bi0nr0xSMbk.eS/4VtxQzQ02317UbPXf8TXUz3sG/kGS',
 'System Administrator', 'admin@connectpro.local', 'active', 0, CURRENT_TIMESTAMP),
(2, 2, 'USR001', 'user', '$2b$12$y6VFUjwKbkkMDPHqIaWwm.4S/ZTRcQk6K5op73vHKBVtwrFID7Cci',
 'General User', 'user@connectpro.local', 'active', 0, CURRENT_TIMESTAMP)
ON DUPLICATE KEY UPDATE
role_id = VALUES(role_id),
display_name = VALUES(display_name),
email = VALUES(email),
account_status = VALUES(account_status);

-- 3. Departments
INSERT INTO departments (
    id, department_code, department_name, description,
    color_hex, sort_order, is_active, created_by, updated_by
) VALUES
(1, 'IT-SUPPORT', 'IT Support', 'Information technology support team', '#38BDF8', 10, TRUE, 1, 1),
(2, 'IT-DEPT', 'IT Department', 'Information technology department', '#3B82F6', 20, TRUE, 1, 1),
(3, 'MANAGEMENT', 'Management', 'Company management team', '#8B5CF6', 30, TRUE, 1, 1),
(4, 'HR', 'Human Resources', 'Human resources department', '#EC4899', 40, TRUE, 1, 1),
(5, 'MAINTENANCE', 'Maintenance', 'Maintenance and technical service team', '#F97316', 50, TRUE, 1, 1),
(6, 'ACCOUNTING', 'Accounting', 'Accounting and finance department', '#10B981', 60, TRUE, 1, 1),
(7, 'SAFETY', 'Safety', 'Occupational health and safety department', '#F59E0B', 70, TRUE, 1, 1)
ON DUPLICATE KEY UPDATE
department_name = VALUES(department_name),
description = VALUES(description),
color_hex = VALUES(color_hex),
sort_order = VALUES(sort_order),
is_active = VALUES(is_active),
updated_by = VALUES(updated_by);

-- 4. Locations
INSERT INTO locations (
    id, location_code, location_name, building, floor_name,
    room_name, description, is_active, created_by, updated_by
) VALUES
(1, 'HQ-F1', 'Head Office Floor 1', 'Head Office', '1', NULL, 'Main office first floor', TRUE, 1, 1),
(2, 'HQ-F2', 'Head Office Floor 2', 'Head Office', '2', NULL, 'Main office second floor', TRUE, 1, 1),
(3, 'FACTORY-A', 'Factory Area A', 'Factory', '1', 'Area A', 'Production area A', TRUE, 1, 1),
(4, 'FACTORY-B', 'Factory Area B', 'Factory', '1', 'Area B', 'Production area B', TRUE, 1, 1)
ON DUPLICATE KEY UPDATE
location_name = VALUES(location_name),
building = VALUES(building),
floor_name = VALUES(floor_name),
room_name = VALUES(room_name),
description = VALUES(description),
is_active = VALUES(is_active),
updated_by = VALUES(updated_by);

-- 5. Sample contacts
INSERT INTO contacts (
    id, employee_code, first_name, last_name, display_name,
    position_title, department_id, location_id, extension_number,
    mobile_number, office_number, email, ip_address, computer_name,
    profile_image_path, notes, contact_status, created_by, updated_by
) VALUES
(1, 'EMP1001', 'Poonkasem', 'Pathamanat', 'Poonkasem Pathamanat', 'IT Support Specialist', 1, 1, '2456', '081-234-5678', NULL, 'poonkasem@connectpro.local', '192.168.10.25', 'PC-IT-001', NULL, NULL, 'active', 1, 1),
(2, 'EMP1002', 'Sillapatong', 'Chinnapart', 'Sillapatong Chinnapart', 'Network Engineer', 2, 1, '2201', '082-345-6789', NULL, 'sillapatong@connectpro.local', '192.168.10.45', 'PC-IT-002', NULL, NULL, 'active', 1, 1),
(3, 'EMP1003', 'Hirota', 'Kensuke', 'Hirota Kensuke', 'IT Manager', 3, 2, '1102', '083-456-7890', NULL, 'hirota@connectpro.local', '192.168.10.88', 'PC-MGT-001', NULL, NULL, 'active', 1, 1),
(4, 'EMP1004', 'Nattaporn', 'Phromsri', 'Nattaporn Phromsri', 'HR Officer', 4, 2, '3305', '084-567-8901', NULL, 'nattaporn@connectpro.local', '192.168.20.15', 'PC-HR-001', NULL, NULL, 'active', 1, 1),
(5, 'EMP1005', 'Anucha', 'Intachai', 'Anucha Intachai', 'Maintenance Technician', 5, 3, '4408', '085-678-9012', NULL, 'anucha@connectpro.local', '192.168.30.22', 'PC-MTN-001', NULL, NULL, 'active', 1, 1),
(6, 'EMP1006', 'Saranya', 'Chuensuk', 'Saranya Chuensuk', 'Accountant', 6, 2, '5503', '086-789-0123', NULL, 'saranya@connectpro.local', '192.168.40.18', 'PC-ACC-001', NULL, NULL, 'active', 1, 1),
(7, 'EMP1007', 'Worawat', 'Detchawat', 'Worawat Detchawat', 'Safety Officer', 7, 4, '6607', '087-890-1234', NULL, 'worawat@connectpro.local', '192.168.50.31', 'PC-SAF-001', NULL, NULL, 'active', 1, 1)
ON DUPLICATE KEY UPDATE
display_name = VALUES(display_name),
position_title = VALUES(position_title),
department_id = VALUES(department_id),
location_id = VALUES(location_id),
mobile_number = VALUES(mobile_number),
computer_name = VALUES(computer_name),
contact_status = VALUES(contact_status),
updated_by = VALUES(updated_by);

-- 6. Demonstration presence values
INSERT INTO contact_presence (
    contact_id, presence_status, check_method, response_time_ms,
    last_checked_at, last_online_at, consecutive_failures, status_message
) VALUES
(1, 'online',  'manual', 12, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 0, 'Initial demonstration status'),
(2, 'online',  'manual', 18, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 0, 'Initial demonstration status'),
(3, 'online',  'manual', 15, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 0, 'Initial demonstration status'),
(4, 'offline', 'manual', NULL, CURRENT_TIMESTAMP, NULL, 1, 'Initial demonstration status'),
(5, 'online',  'manual', 21, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 0, 'Initial demonstration status'),
(6, 'offline', 'manual', NULL, CURRENT_TIMESTAMP, NULL, 1, 'Initial demonstration status'),
(7, 'online',  'manual', 17, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 0, 'Initial demonstration status')
ON DUPLICATE KEY UPDATE
presence_status = VALUES(presence_status),
check_method = VALUES(check_method),
response_time_ms = VALUES(response_time_ms),
last_checked_at = VALUES(last_checked_at),
last_online_at = VALUES(last_online_at),
consecutive_failures = VALUES(consecutive_failures),
status_message = VALUES(status_message);

-- 7. System settings
INSERT INTO system_settings (
    setting_key, setting_value, value_type, setting_group,
    description, is_public, updated_by
) VALUES
('system_name', 'ConnectPro', 'string', 'general', 'Application display name', TRUE, 1),
('company_name', 'Your Company', 'string', 'general', 'Organization name', TRUE, 1),
('records_per_page', '20', 'integer', 'display', 'Default rows per page', TRUE, 1),
('session_timeout_minutes', '60', 'integer', 'security', 'Inactive session timeout', FALSE, 1),
('max_failed_login_attempts', '5', 'integer', 'security', 'Attempt limit before lock', FALSE, 1),
('account_lock_minutes', '15', 'integer', 'security', 'Temporary lock duration', FALSE, 1),
('presence_check_interval_seconds', '300', 'integer', 'presence', 'Time between checks', FALSE, 1),
('presence_failure_threshold', '2', 'integer', 'presence', 'Failures before offline', FALSE, 1),
('presence_history_retention_days', '30', 'integer', 'presence', 'Presence history retention', FALSE, 1),
('upload_max_size_mb', '10', 'integer', 'upload', 'Maximum import file size', FALSE, 1),
('export_expiration_hours', '24', 'integer', 'export', 'Export file lifetime', FALSE, 1),
('allow_user_view_ip', 'true', 'boolean', 'privacy', 'Allow users to view IP addresses', FALSE, 1),
('allow_user_view_mobile', 'true', 'boolean', 'privacy', 'Allow users to view mobile numbers', FALSE, 1)
ON DUPLICATE KEY UPDATE
setting_value = VALUES(setting_value),
value_type = VALUES(value_type),
setting_group = VALUES(setting_group),
description = VALUES(description),
is_public = VALUES(is_public),
updated_by = VALUES(updated_by);

-- 8. Initial activity record
INSERT INTO activity_logs (
    user_id, action_key, module_name, target_type, target_id,
    description, request_method, request_path, ip_address, result_status
) VALUES
(1, 'seed.initialized', 'system', 'database', NULL,
 'ConnectPro initial seed data was installed', 'CLI',
 'database/seed.sql', '127.0.0.1', 'success');

COMMIT;
SET FOREIGN_KEY_CHECKS = 1;

-- End of ConnectPro seed.sql
