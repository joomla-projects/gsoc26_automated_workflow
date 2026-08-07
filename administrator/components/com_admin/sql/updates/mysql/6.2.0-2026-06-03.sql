CREATE TABLE IF NOT EXISTS `#__workflow_transition_automation` (
    `id` int NOT NULL AUTO_INCREMENT,
    `transition_id` int NOT NULL COMMENT 'Foreign Key to #__workflow_transitions.id',
    `published` tinyint NOT NULL DEFAULT 0,
    `ordering` int NOT NULL DEFAULT 0,
    `rule_type` varchar(20) NOT NULL DEFAULT 'delay' COMMENT 'delay or cron',
	`delay_value` int DEFAULT NULL,
	`delay_unit` varchar(10) DEFAULT NULL COMMENT 'minutes, hours, days, months',
    `cron_expression` varchar(100) DEFAULT NULL,
	`condition_tree` text COMMENT 'JSON expression tree gating this rule (evaluated at selection)',
	`fire_condition` text COMMENT 'JSON expression tree: gate evaluated live at fire time',
    `run_as_user_id` int NOT NULL DEFAULT 0 COMMENT 'User identity used to execute the transition',
    `loop_mode` tinyint NOT NULL DEFAULT 0 COMMENT 'Chain into the target stage rule when set',
    `created` datetime NOT NULL,
    `created_by` int NOT NULL DEFAULT 0,
    `modified` datetime NOT NULL,
    `modified_by` int NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
	KEY `idx_transition` (`transition_id`),
	KEY `idx_published` (`published`),
	KEY `idx_run_as` (`run_as_user_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 DEFAULT COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__workflow_automation_schedule` (
    `id` int NOT NULL AUTO_INCREMENT,
    `item_id` int NOT NULL DEFAULT 0 COMMENT 'Extension table id value',
    `extension` varchar(50) NOT NULL,
    `stage_id` int NOT NULL COMMENT 'Foreign Key to #__workflow_stages.id',
    `entered_at` datetime NOT NULL COMMENT 'When the item arrived in stage_id',
    `next_transition_at` datetime NULL,
	`triggered_by` ENUM('manual', 'automation') NOT NULL DEFAULT 'manual' COMMENT 'Determine if a transition was triggered manually or by the automation',
	`requires_intervention` tinyint NOT NULL DEFAULT 0 COMMENT 'Set when an automated transition failed; excluded from the scheduler until an admin clears it',
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_item_extension` (`item_id`, `extension`),
    KEY `idx_stage_entered` (`stage_id`, `entered_at`),
    KEY `idx_next_transition_at` (`next_transition_at`),
	KEY `idx_requires_intervention` (`requires_intervention`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 DEFAULT COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__workflow_automation_log` (
    `id` int NOT NULL AUTO_INCREMENT,
    `rule_id` int DEFAULT NULL COMMENT 'Foreign Key to #__workflow_transition_automation.id',
    `item_id` int NOT NULL DEFAULT 0,
    `extension` varchar(50) NOT NULL,
    `transition_id` int NOT NULL,
    `from_stage_id` int NOT NULL DEFAULT 0,
    `to_stage_id` int NOT NULL DEFAULT 0,
    `run_as_user_id` int NOT NULL DEFAULT 0,
    `trigger_type` varchar(20) NOT NULL DEFAULT 'rule',
    `exit_code` tinyint NOT NULL DEFAULT 0 COMMENT '0 ok, 1 permission denied, 2 invalid transition, 3 exception',
    `note` varchar(500) DEFAULT NULL,
    `executed_at` datetime NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_rule_id` (`rule_id`),
    KEY `idx_item_id` (`item_id`),
    KEY `idx_executed_at` (`executed_at`),
    KEY `idx_exit_code` (`exit_code`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 DEFAULT COLLATE = utf8mb4_unicode_ci;
