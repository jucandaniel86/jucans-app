-- Manual alternative to 2026_11_15_000001_add_shopping_configuration_to_project_note_types.
-- Execute once; do not also run the Laravel migration on the same database.
-- Prerequisites: existing InnoDB tables, BIGINT UNSIGNED IDs, and unique keys:
-- project_custom_fields(project_id, id): custom_fields_project_identity
-- project_custom_field_options(field_id, id): field_options_identity
-- These parent keys and BIGINT UNSIGNED types were confirmed from supplied production DDL.
-- No data backfill is required.
ALTER TABLE project_note_types
  ADD COLUMN is_shopping ENUM('1') NULL,
  ADD COLUMN shopping_amount_field_id BIGINT UNSIGNED NULL,
  ADD COLUMN shopping_provider_field_id BIGINT UNSIGNED NULL,
  ADD COLUMN shopping_provider_option_id BIGINT UNSIGNED NULL,
  ADD UNIQUE KEY note_types_shopping_unique (project_id, is_shopping),
  ADD KEY shopping_amount_field_index (project_id, shopping_amount_field_id),
  ADD KEY shopping_provider_field_index (project_id, shopping_provider_field_id),
  ADD KEY shopping_provider_option_index (shopping_provider_field_id, shopping_provider_option_id),
  ADD CONSTRAINT shopping_amount_field_fk
    FOREIGN KEY (project_id, shopping_amount_field_id)
    REFERENCES project_custom_fields (project_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT shopping_provider_field_fk
    FOREIGN KEY (project_id, shopping_provider_field_id)
    REFERENCES project_custom_fields (project_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT shopping_provider_option_fk
    FOREIGN KEY (shopping_provider_field_id, shopping_provider_option_id)
    REFERENCES project_custom_field_options (field_id, id) ON DELETE RESTRICT;
