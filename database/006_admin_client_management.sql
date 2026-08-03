-- =====================================================================
-- MailAI Platform — Super Admin: full client CRUD, impersonation,
-- forced logout, soft-delete/purge, and internal notes (spec section
-- 1.2 "Client / Tenant Management").
-- =====================================================================

-- session_version: bumped by "Force logout" — SessionAuth::requireClient()
-- compares the session's stored version against this column on every
-- request and kicks the user back to login on mismatch, so a stale
-- browser session can be invalidated without waiting for it to expire.
-- deleted_at: soft-delete marker. A soft-deleted client can no longer
-- log in (SessionAuth checks this) but the row + all tenant data stays
-- in place until a super admin explicitly runs the separate "permanent
-- purge" action, which is a real DELETE (cascades via existing FKs).
ALTER TABLE clients
    ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'bumped to force-logout all of this client''s sessions',
    ADD COLUMN deleted_at DATETIME NULL COMMENT 'soft-delete marker, NULL = active tenant';

CREATE TABLE IF NOT EXISTS client_notes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id       INT UNSIGNED NOT NULL,
    admin_email     VARCHAR(190) NOT NULL COMMENT 'super_admins.email of whoever wrote the note',
    note            TEXT NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_client_notes_client (client_id),
    CONSTRAINT fk_client_notes_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Platform-wide audit trail for super-admin actions themselves (distinct
-- from ai_audit_log, which is the AI agent's own action log). Every
-- CRUD/impersonate/force-logout/purge action a super admin takes against
-- a client gets one row here — this is what answers "who suspended this
-- client and when" (spec 1.7 "Platform-wide audit log").
CREATE TABLE IF NOT EXISTS admin_audit_log (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_email     VARCHAR(190) NOT NULL,
    action          VARCHAR(64) NOT NULL COMMENT 'e.g. suspend_client, update_plan, impersonate, force_logout, purge_client',
    client_id       INT UNSIGNED NULL COMMENT 'NULL for platform-level actions not tied to one client',
    details_json    JSON NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin_audit_client (client_id),
    INDEX idx_admin_audit_admin (admin_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
