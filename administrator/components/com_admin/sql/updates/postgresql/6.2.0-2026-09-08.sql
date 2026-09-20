-- See the MySQL file for why entered_at is the upgrade time rather than a guess.
INSERT INTO "#__workflow_item_state" ("item_id", "extension", "stage_id", "entered_at", "triggered_by", "requires_intervention")
SELECT a."item_id", a."extension", a."stage_id", timezone('UTC', now()), 'manual', 0
FROM "#__workflow_associations" a
LEFT JOIN "#__workflow_item_state" s ON s."item_id" = a."item_id" AND s."extension" = a."extension"
WHERE s."id" IS NULL;
