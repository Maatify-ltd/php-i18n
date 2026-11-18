-- Upgrade an existing I18n RC.1 or RC.2 schema before enabling RC.3 writers.
-- Run in a maintenance window with SQL error handling that stops on the first failure.
DROP TEMPORARY TABLE IF EXISTS maa_i18n_exact_language_preflight;
CREATE TEMPORARY TABLE maa_i18n_exact_language_preflight (
    invalid_count BIGINT UNSIGNED NOT NULL,
    CONSTRAINT chk_i18n_language_upgrade_requires_valid_codes CHECK (invalid_count = 0)
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
        COMMENT 'Stable language write lock slot from 0 to 255'
) ENGINE=InnoDB
    COMMENT='Internal transaction locks for translation writes and language re-keying (ADR-024)';
