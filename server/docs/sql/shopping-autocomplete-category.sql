-- Run once after the existing shopping-list migrations. Do not also run the
-- equivalent 2026_11_06_000001_add_pharmacy_shopping_category Laravel migration.
-- Existing items and existing categories are not changed.
INSERT IGNORE INTO shopping_categories (name, emoji, sort_order, created_at, updated_at)
VALUES ('Farmacie', '💊', 100, NOW(), NOW());
