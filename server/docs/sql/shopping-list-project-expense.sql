-- Apply AFTER project-note-type-shopping.sql (migration 2026_11_15_000001).
-- Equivalent to 2026_11_15_000002_add_shopping_origin_to_project_progress_notes.
-- Execute once; do not also run that Laravel migration against the same database.
-- Existing tables must be InnoDB. shopping_lists.id is BIGINT UNSIGNED,
-- as confirmed in the supplied production schema. Existing Notes are unchanged.
ALTER TABLE project_progress_notes
  ADD COLUMN shopping_list_id BIGINT UNSIGNED NULL,
  ADD COLUMN shopping_request_hash
    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  ADD UNIQUE KEY shopping_note_user_unique (shopping_list_id, created_by),
  ADD CONSTRAINT shopping_note_list_fk
    FOREIGN KEY (shopping_list_id) REFERENCES shopping_lists (id)
    ON DELETE RESTRICT;
