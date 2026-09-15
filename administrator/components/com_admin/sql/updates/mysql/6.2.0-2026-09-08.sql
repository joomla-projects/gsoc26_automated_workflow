-- entered_at is stamped with the upgrade time rather than a guess. Nothing records when these
-- items actually entered their stage, and using a date like `modified` would leave anything
-- edited long ago instantly overdue, transitioning a whole back catalogue on the first run.
INSERT INTO `#__workflow_item_state` (`item_id`, `extension`, `stage_id`, `entered_at`, `triggered_by`, `requires_intervention`)
SELECT a.`item_id`, a.`extension`, a.`stage_id`, UTC_TIMESTAMP(), 'manual', 0
FROM `#__workflow_associations` a
LEFT JOIN `#__workflow_item_state` s ON s.`item_id` = a.`item_id` AND s.`extension` = a.`extension`
WHERE s.`id` IS NULL;
