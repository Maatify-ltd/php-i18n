-- Optional Translation Key Preview storage for fresh and existing installations.
CREATE TABLE IF NOT EXISTS maa_i18n_translation_key_previews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Preview identifier, not an authorization credential',
    targets JSON NOT NULL COMMENT 'Exact scope, domain, and optional key selectors',
    expires_at DATETIME(6) NOT NULL COMMENT 'Exclusive expiry in UTC',
    PRIMARY KEY (id),
    KEY idx_maa_i18n_preview_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Optional presentation-only translation key previews';
