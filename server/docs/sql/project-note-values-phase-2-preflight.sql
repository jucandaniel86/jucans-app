-- Jucans Phase 2: READ-ONLY checks before the separate schema script.
-- Select the intended database before running. ALL check_result rows must say PASS.
-- STOP on any STOP result or SQL error. These SELECTs do not automatically abort
-- a client's later statements: do not concatenate this file with the DDL script.

SELECT DATABASE() AS selected_database, VERSION() AS server_version,
       @@foreign_key_checks AS foreign_key_checks, @@sql_mode AS sql_mode;

SELECT 'database_selected' AS check_name,
       IF(DATABASE() IS NOT NULL, 'PASS', 'STOP: select the target database') AS check_result
UNION ALL
SELECT 'mysql_version',
       IF(LOWER(VERSION()) NOT LIKE '%mariadb%' AND (
           CAST(SUBSTRING_INDEX(VERSION(), '.', 1) AS UNSIGNED) > 8 OR
           (CAST(SUBSTRING_INDEX(VERSION(), '.', 1) AS UNSIGNED) = 8 AND (
               CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(VERSION(), '.', 2), '.', -1) AS UNSIGNED) > 0 OR
               CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(VERSION(), '.', 3), '.', -1) AS UNSIGNED) >= 16
           ))
       ), 'PASS', 'STOP: requires MySQL >= 8.0.16; MariaDB is not verified')
UNION ALL
SELECT 'foreign_keys_enabled', IF(@@foreign_key_checks = 1, 'PASS', 'STOP: foreign_key_checks must be enabled')
UNION ALL
SELECT 'strict_mode', IF(FIND_IN_SET('STRICT_TRANS_TABLES', @@sql_mode) OR FIND_IN_SET('STRICT_ALL_TABLES', @@sql_mode), 'PASS', 'STOP: strict SQL mode is required');

SELECT 'existing_innodb_tables' AS check_name,
       IF(COUNT(*) = 4 AND SUM(UPPER(ENGINE) = 'INNODB') = 4,
          'PASS', 'STOP: missing prerequisite tables or non-InnoDB storage') AS check_result
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('project_progress_notes', 'project_note_types', 'project_custom_fields', 'project_custom_field_options');

SELECT 'project_identity_unique_keys' AS check_name,
       IF(COUNT(DISTINCT TABLE_NAME) = 3, 'PASS', 'STOP: missing unique (project_id,id) prerequisite') AS check_result
FROM (
    SELECT TABLE_NAME, INDEX_NAME
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME IN ('project_progress_notes', 'project_note_types', 'project_custom_fields')
    GROUP BY TABLE_NAME, INDEX_NAME
    HAVING MIN(NON_UNIQUE) = 0
       AND GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') = 'project_id,id'
) AS identity_keys;

SELECT 'phase2_not_already_installed' AS check_name,
       IF(
           NOT EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'project_progress_note_values')
           AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'project_progress_notes' AND COLUMN_NAME IN ('note_type_id', 'configuration_snapshot', 'configuration_captured_at'))
           AND NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'project_custom_field_options' AND INDEX_NAME = 'field_options_identity')
           AND NOT EXISTS (SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'note_context_immutable'),
           'PASS', 'STOP: Phase 2 objects already exist; inspect complete or partial application, do not rerun'
       ) AS check_result;

-- Empty result expected. A row means Laravel considers this migration already applied.
SELECT migration, batch FROM migrations
WHERE migration = '2026_10_31_000001_add_progress_note_values';

-- Also verify CREATE/ALTER/INDEX/REFERENCES/TRIGGER privileges and backup/rehearse
-- the operation before proceeding. Do not change foreign_key_checks to bypass errors.
