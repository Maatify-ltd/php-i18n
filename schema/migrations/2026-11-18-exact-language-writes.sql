-- Upgrade an existing I18n RC.1 or RC.2 schema before enabling RC.3 writers.
-- Run in a maintenance window with SQL error handling that stops on the first failure.
DROP TEMPORARY TABLE IF EXISTS maa_i18n_exact_language_preflight;
CREATE TEMPORARY TABLE maa_i18n_exact_language_preflight (
    invalid_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    invalid_active_flags BIGINT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT chk_i18n_language_upgrade_requires_valid_codes CHECK (invalid_count = 0),
    CONSTRAINT chk_i18n_upgrade_requires_valid_active_flags CHECK (invalid_active_flags = 0)
);

SET @i18n_whitespace_only = CONCAT(
    '^[[:space:]', CONVERT(CHAR(11) USING utf8mb4), CONVERT(CHAR(12) USING utf8mb4),
    CONVERT(0xC285 USING utf8mb4), CONVERT(CHAR(92) USING utf8mb4), 'p{Z}]*$'
);
INSERT INTO maa_i18n_exact_language_preflight (invalid_count)
SELECT COUNT(*) FROM maa_i18n_translations
WHERE language_code IS NOT NULL AND language_code REGEXP @i18n_whitespace_only;
INSERT INTO maa_i18n_exact_language_preflight (invalid_count)
SELECT COUNT(*) FROM maa_i18n_domain_language_summary
WHERE language_code IS NOT NULL AND language_code REGEXP @i18n_whitespace_only;
INSERT INTO maa_i18n_exact_language_preflight (invalid_active_flags)
SELECT COUNT(*) FROM maa_i18n_scopes WHERE is_active NOT IN (0, 1);
INSERT INTO maa_i18n_exact_language_preflight (invalid_active_flags)
SELECT COUNT(*) FROM maa_i18n_domains WHERE is_active NOT IN (0, 1);
DROP TEMPORARY TABLE maa_i18n_exact_language_preflight;

ALTER TABLE maa_i18n_translations
    DROP CHECK chk_maa_i18n_translations_language_code,
    MODIFY COLUMN language_code VARCHAR(16)
        CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NULL
        COMMENT 'Exact Host-owned language code, NULL = exact unlocalized scope (ADR-019)',
    MODIFY COLUMN language_code_identity VARCHAR(16)
        CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin
        GENERATED ALWAYS AS (COALESCE(language_code, '')) STORED
        COMMENT 'NULL-safe identity of language_code (NULL maps to empty string)',
    ADD CONSTRAINT chk_maa_i18n_translations_language_code
        CHECK (language_code IS NULL OR (
            CHAR_LENGTH(language_code) BETWEEN 1 AND 16
            AND language_code NOT REGEXP CONCAT(
                '^[[:space:]', CONVERT(CHAR(11) USING utf8mb4), CONVERT(CHAR(12) USING utf8mb4),
                CONVERT(0xC285 USING utf8mb4), CONVERT(CHAR(92) USING utf8mb4), 'p{Z}]*$'
            )
        ));

ALTER TABLE maa_i18n_domain_language_summary
    DROP CHECK chk_maa_i18n_domain_language_summary_language_code,
    MODIFY COLUMN language_code VARCHAR(16)
        CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NULL
        COMMENT 'Exact Host-owned language code, NULL = exact unlocalized scope (ADR-019)',
    MODIFY COLUMN language_code_identity VARCHAR(16)
        CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin
        GENERATED ALWAYS AS (COALESCE(language_code, '')) STORED
        COMMENT 'NULL-safe identity of language_code (NULL maps to empty string)',
    ADD CONSTRAINT chk_maa_i18n_domain_language_summary_language_code
        CHECK (language_code IS NULL OR (
            CHAR_LENGTH(language_code) BETWEEN 1 AND 16
            AND language_code NOT REGEXP CONCAT(
                '^[[:space:]', CONVERT(CHAR(11) USING utf8mb4), CONVERT(CHAR(12) USING utf8mb4),
                CONVERT(0xC285 USING utf8mb4), CONVERT(CHAR(92) USING utf8mb4), 'p{Z}]*$'
            )
        ));

CREATE TABLE IF NOT EXISTS maa_i18n_language_write_locks (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY
        COMMENT 'Slot 0 coordinates summaries, 1 and 2 ordering, 3 to 255 language writes'
) ENGINE=InnoDB
    COMMENT='Internal transaction locks for translation writes, summaries and ordering';

-- The exact constraint may already exist after a successful or partially applied upgrade.
SET @i18n_active_check_ddl = IF(
    EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'maa_i18n_scopes'
          AND CONSTRAINT_NAME = 'chk_maa_i18n_scopes_active' AND CONSTRAINT_TYPE = 'CHECK'
    ),
    'DO 0',
    'ALTER TABLE maa_i18n_scopes ADD CONSTRAINT chk_maa_i18n_scopes_active CHECK (is_active IN (0, 1))'
);
PREPARE maa_i18n_active_check FROM @i18n_active_check_ddl;
EXECUTE maa_i18n_active_check;
DEALLOCATE PREPARE maa_i18n_active_check;

-- The exact constraint may already exist after a successful or partially applied upgrade.
SET @i18n_active_check_ddl = IF(
    EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'maa_i18n_domains'
          AND CONSTRAINT_NAME = 'chk_maa_i18n_domains_active' AND CONSTRAINT_TYPE = 'CHECK'
    ),
    'DO 0',
    'ALTER TABLE maa_i18n_domains ADD CONSTRAINT chk_maa_i18n_domains_active CHECK (is_active IN (0, 1))'
);
PREPARE maa_i18n_active_check FROM @i18n_active_check_ddl;
EXECUTE maa_i18n_active_check;
DEALLOCATE PREPARE maa_i18n_active_check;

-- Reassign positions only if the old allocator left ties. Preserve the previous display order.
UPDATE maa_i18n_scopes AS governance_row
JOIN (
    SELECT id, ROW_NUMBER() OVER (ORDER BY sort_order, id) AS repaired_position
    FROM maa_i18n_scopes
) AS repaired ON repaired.id = governance_row.id
JOIN (
    SELECT COUNT(*) > COUNT(DISTINCT sort_order) AS has_ties FROM maa_i18n_scopes
) AS ordering_state ON ordering_state.has_ties
SET governance_row.sort_order = repaired.repaired_position;

-- Reassign positions only if the old allocator left ties. Preserve the previous display order.
UPDATE maa_i18n_domains AS governance_row
JOIN (
    SELECT id, ROW_NUMBER() OVER (ORDER BY sort_order, id) AS repaired_position
    FROM maa_i18n_domains
) AS repaired ON repaired.id = governance_row.id
JOIN (
    SELECT COUNT(*) > COUNT(DISTINCT sort_order) AS has_ties FROM maa_i18n_domains
) AS ordering_state ON ordering_state.has_ties
SET governance_row.sort_order = repaired.repaired_position;
