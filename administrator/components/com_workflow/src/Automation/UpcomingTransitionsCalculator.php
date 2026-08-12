<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_workflow
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Workflow\Administrator\Automation;

use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Works out, on the fly, each item's next automated transition in a workflow.
 *
 * Reads the items currently sitting in the workflow's stages, applies each rule's filter to
 * see whether it is in scope, computes the fire time the same way the scheduler does, and
 * keeps the soonest move per item. Nothing is stored: this recomputes every time the view is
 * opened, so it is always current. It never fires anything.
 *
 * @since  __DEPLOY_VERSION__
 */
final class UpcomingTransitionsCalculator
{
    /**
     * The com_content publishing states that take an item out of circulation, so it has no
     * upcoming transition worth previewing. These mirror ContentComponent::CONDITION_ARCHIVED
     * and ::CONDITION_TRASHED, repeated here rather than imported so that com_workflow does
     * not depend on com_content being installed.
     *
     * @var    integer
     * @since  __DEPLOY_VERSION__
     */
    private const CONTENT_STATE_ARCHIVED = 2;

    /**
     * @var    integer
     * @since  __DEPLOY_VERSION__
     */
    private const CONTENT_STATE_TRASHED = -2;

    /**
     * @var    DatabaseInterface
     * @since  __DEPLOY_VERSION__
     */
    private DatabaseInterface $database;

    /**
     * @var    ConditionEvaluator
     * @since  __DEPLOY_VERSION__
     */
    private ConditionEvaluator $conditionEvaluator;

    /**
     * @var    ItemFieldResolver
     * @since  __DEPLOY_VERSION__
     */
    private ItemFieldResolver $itemFieldResolver;

    /**
     * @var    ConditionWindowCalculator
     * @since  __DEPLOY_VERSION__
     */
    private ConditionWindowCalculator $conditionWindowCalculator;

    /**
     * @param   DatabaseInterface  $database  The database driver.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(DatabaseInterface $database)
    {
        $this->database                  = $database;
        $this->conditionEvaluator        = new ConditionEvaluator();
        $this->itemFieldResolver         = new ItemFieldResolver($database);
        $this->conditionWindowCalculator = new ConditionWindowCalculator($database);
    }

    /**
     * Calculates the next automated transition for every item in a workflow.
     *
     * @param   integer  $workflowId  The workflow id.
     *
     * @return  UpcomingTransition[]  Soonest first; items with no computable time come last.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function forWorkflow(int $workflowId): array
    {
        return $this->buildFromRows($this->fetchRowsForWorkflow($workflowId));
    }

    /**
     * Calculates the next automated transition for every item under an extension, across all
     * of its workflows.
     *
     * @param   string  $extension  The workflow extension, e.g. com_content.article.
     *
     * @return  UpcomingTransition[]  Soonest first; items with no computable time come last.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function forExtension(string $extension): array
    {
        return $this->buildFromRows($this->fetchRowsForExtension($extension));
    }

    /**
     * Calculates the next automated transition for a single item, or null if it has none.
     *
     * @param   integer  $itemId     The content item id.
     * @param   string   $extension  The workflow extension, e.g. com_content.article.
     *
     * @return  UpcomingTransition|null
     *
     * @since   __DEPLOY_VERSION__
     */
    public function forItem(int $itemId, string $extension): ?UpcomingTransition
    {
        $upcoming = $this->buildFromRows($this->fetchRowsForItem($itemId, $extension));

        return $upcoming[0] ?? null;
    }

    /**
     * Turns candidate rows into upcoming transitions: soonest in-scope rule per item.
     *
     * @param   object[]  $rows  Candidate (item, transition, rule) rows.
     *
     * @return  UpcomingTransition[]  Soonest first; uncomputable times last.
     *
     * @since   __DEPLOY_VERSION__
     */
    private function buildFromRows(array $rows): array
    {
        // Keep the soonest in-scope rule per item. An item can have several automated exits
        // from its stage; we only show the one that fires next.
        $bestByItem = [];

        foreach ($rows as $row) {
            $resolveField = $this->itemFieldResolver->forItem((int) $row->item_id, $row->extension);

            // The filter decides whether this rule applies to this item at all. A rule that
            // cannot be evaluated is logged and skipped, so one broken rule never takes down
            // the whole view.
            try {
                if (!$this->conditionEvaluator->evaluate($row->item_filter, $resolveField)) {
                    continue;
                }
            } catch (ConditionEvaluationException $invalidCondition) {
                Log::add(
                    \sprintf(
                        'Skipping automation on transition %d for item %s.%d: %s',
                        (int) $row->transition_id,
                        $row->extension,
                        (int) $row->item_id,
                        $invalidCondition->getMessage()
                    ),
                    Log::WARNING,
                    'workflow'
                );

                continue;
            }

            // Rules compete on when they become due, before any condition is considered, so
            // the item keeps the rule that comes up first.
            $deadline = DeadlineCalculator::forRule($row->entered_at, $row);
            $itemKey  = $row->extension . '.' . $row->item_id;

            if (!isset($bestByItem[$itemKey]) || $this->isSooner($deadline, $bestByItem[$itemKey]['deadline'])) {
                $bestByItem[$itemKey] = ['row' => $row, 'deadline' => $deadline];
            }
        }

        $now      = new \DateTime('now', new \DateTimeZone('UTC'));
        $upcoming = [];

        foreach ($bestByItem as $winner) {
            $upcoming[] = $this->buildUpcomingTransition($winner['row'], $winner['deadline'], $now);
        }

        usort($upcoming, [$this, 'compareByFiresAt']);

        return $upcoming;
    }

    /**
     * Loads the candidate rows for a whole workflow.
     *
     * @param   integer  $workflowId  The workflow id.
     *
     * @return  object[]
     *
     * @since   __DEPLOY_VERSION__
     */
    private function fetchRowsForWorkflow(int $workflowId): array
    {
        $db    = $this->database;
        $query = $this->baseRowsQuery()
            ->where($db->quoteName('wt.workflow_id') . ' = :workflowId')
            ->bind(':workflowId', $workflowId, ParameterType::INTEGER);

        return $db->setQuery($query)->loadObjectList() ?: [];
    }

    /**
     * Loads the candidate rows for every workflow under an extension.
     *
     * @param   string  $extension  The workflow extension.
     *
     * @return  object[]
     *
     * @since   __DEPLOY_VERSION__
     */
    private function fetchRowsForExtension(string $extension): array
    {
        $db    = $this->database;
        $query = $this->baseRowsQuery()
            ->select($db->quoteName('w.title', 'workflow_title'))
            ->join(
                'LEFT',
                $db->quoteName('#__workflows', 'w'),
                $db->quoteName('w.id') . ' = ' . $db->quoteName('wt.workflow_id')
            )
            ->where($db->quoteName('wis.extension') . ' = :extension')
            ->bind(':extension', $extension);

        return $db->setQuery($query)->loadObjectList() ?: [];
    }

    /**
     * Loads the candidate rows for a single item.
     *
     * @param   integer  $itemId     The content item id.
     * @param   string   $extension  The workflow extension.
     *
     * @return  object[]
     *
     * @since   __DEPLOY_VERSION__
     */
    private function fetchRowsForItem(int $itemId, string $extension): array
    {
        $db    = $this->database;
        $query = $this->baseRowsQuery()
            ->where($db->quoteName('wis.item_id') . ' = :itemId')
            ->where($db->quoteName('wis.extension') . ' = :extension')
            ->bind(':itemId', $itemId, ParameterType::INTEGER)
            ->bind(':extension', $extension);

        return $db->setQuery($query)->loadObjectList() ?: [];
    }

    /**
     * Builds the shared select and joins, without a scope clause.
     *
     * @return  QueryInterface
     *
     * @since   __DEPLOY_VERSION__
     */
    private function baseRowsQuery(): QueryInterface
    {
        $db = $this->database;

        return $db->getQuery(true)
            ->select(
                [
                    $db->quoteName('wis.item_id'),
                    $db->quoteName('wis.extension'),
                    $db->quoteName('wis.entered_at'),
                    $db->quoteName('wis.requires_intervention'),
                    $db->quoteName('wt.id', 'transition_id'),
                    $db->quoteName('wt.from_stage_id'),
                    $db->quoteName('wt.to_stage_id'),
                    $db->quoteName('sfrom.title', 'from_stage_title'),
                    $db->quoteName('sto.title', 'to_stage_title'),
                    $db->quoteName('war.rule_type'),
                    $db->quoteName('war.delay_value'),
                    $db->quoteName('war.delay_unit'),
                    $db->quoteName('war.cron_expression'),
                    $db->quoteName('war.item_filter'),
                    $db->quoteName('war.fire_condition'),
                    $db->quoteName('war.ordering'),
                    $db->quoteName('c.title', 'item_title'),
                ]
            )
            ->from($db->quoteName('#__workflow_item_state', 'wis'))
            ->join(
                'INNER',
                $db->quoteName('#__workflow_transitions', 'wt'),
                $db->quoteName('wt.from_stage_id') . ' = ' . $db->quoteName('wis.stage_id')
            )
            ->join(
                'INNER',
                $db->quoteName('#__workflow_automation_rules', 'war'),
                $db->quoteName('war.transition_id') . ' = ' . $db->quoteName('wt.id')
            )
            ->join(
                'LEFT',
                $db->quoteName('#__workflow_stages', 'sfrom'),
                $db->quoteName('sfrom.id') . ' = ' . $db->quoteName('wt.from_stage_id')
            )
            ->join(
                'LEFT',
                $db->quoteName('#__workflow_stages', 'sto'),
                $db->quoteName('sto.id') . ' = ' . $db->quoteName('wt.to_stage_id')
            )
            ->join(
                'LEFT',
                $db->quoteName('#__content', 'c'),
                $db->quoteName('c.id') . ' = ' . $db->quoteName('wis.item_id')
                    . ' AND ' . $db->quoteName('wis.extension') . ' = ' . $db->quote('com_content.article')
            )
            ->where($db->quoteName('war.published') . ' = 1')
            // Trashed and archived items are out of circulation, so they have no upcoming
            // transition to preview. The state column is null for extensions other than
            // com_content, which the left join above leaves unmatched; those are kept.
            ->where(
                '(' . $db->quoteName('c.state') . ' IS NULL OR '
                . $db->quoteName('c.state') . ' NOT IN ('
                . self::CONTENT_STATE_TRASHED . ', ' . self::CONTENT_STATE_ARCHIVED . '))'
            )
            ->order($db->quoteName('war.ordering') . ' ASC');
    }

    /**
     * Builds one upcoming transition from a winning row.
     *
     * @param   object          $row       The chosen (item, transition, rule) row.
     * @param   \DateTime|null  $deadline  The moment the rule becomes due, or null.
     * @param   \DateTime       $now       Current time (UTC).
     *
     * @return  UpcomingTransition
     *
     * @since   __DEPLOY_VERSION__
     */
    private function buildUpcomingTransition(object $row, ?\DateTime $deadline, \DateTime $now): UpcomingTransition
    {
        $requiresIntervention = (int) $row->requires_intervention === 1;
        $hasCondition         = $row->fire_condition !== null && trim((string) $row->fire_condition) !== '';

        [$firesAt, $status] = $this->resolveFireTime($row, $deadline, $now, $hasCondition, $requiresIntervention);

        return new UpcomingTransition(
            itemId: (int) $row->item_id,
            extension: (string) $row->extension,
            itemTitle: (string) ($row->item_title ?? ''),
            editUrl: $this->buildEditUrl($row),
            fromStage: (string) ($row->from_stage_title ?? ''),
            toStage: (string) ($row->to_stage_title ?? ''),
            firesAt: $firesAt,
            status: $status,
            ruleType: (string) $row->rule_type,
            delayValue: $row->delay_value !== null ? (int) $row->delay_value : null,
            delayUnit: $row->delay_unit,
            cronExpression: $row->cron_expression,
            hasCondition: $hasCondition,
            workflowTitle: (string) ($row->workflow_title ?? '')
        );
    }

    /**
     * Works out when a rule will really fire, and the status to show for it.
     *
     * The deadline only says when the rule becomes due. When a fire condition gates it, the
     * transition waits until that condition holds, so the moment it actually fires is the
     * first match at or after the deadline. An overdue item is searched from now rather than
     * from its deadline, because the past cannot be its next opportunity.
     *
     * @param   object          $row                   The rule row.
     * @param   \DateTime|null  $deadline              The moment the rule becomes due.
     * @param   \DateTime       $now                   Current time (UTC).
     * @param   boolean         $hasCondition          Whether a fire condition exists.
     * @param   boolean         $requiresIntervention  Whether the item is flagged stuck.
     *
     * @return  array{0: \DateTime|null, 1: string}  The fire time and the display status.
     *
     * @since   __DEPLOY_VERSION__
     */
    private function resolveFireTime(
        object $row,
        ?\DateTime $deadline,
        \DateTime $now,
        bool $hasCondition,
        bool $requiresIntervention
    ): array {
        if ($requiresIntervention) {
            return [$deadline, 'needs_attention'];
        }

        if ($deadline === null) {
            return [null, 'not_scheduled'];
        }

        if (!$hasCondition) {
            return [$deadline, 'scheduled'];
        }

        // A rule whose condition cannot be evaluated has no knowable fire time, and the broken
        // rule is what needs fixing, so it is logged and flagged rather than guessed at.
        try {
            $firesAt = $this->conditionWindowCalculator->firstMatchAtOrAfter(
                $row->fire_condition,
                max($deadline, $now),
                (int) $row->item_id,
                (string) $row->extension
            );
        } catch (ConditionEvaluationException $invalidCondition) {
            Log::add(
                \sprintf(
                    'Invalid fire condition on transition %d for item %s.%d: %s',
                    (int) $row->transition_id,
                    $row->extension,
                    (int) $row->item_id,
                    $invalidCondition->getMessage()
                ),
                Log::WARNING,
                'workflow'
            );

            return [null, 'needs_attention'];
        }

        // The condition never opens again within the search horizon, so there is no fire time
        // to promise: for example a rule gated on a date that has already passed.
        if ($firesAt === null) {
            return [null, 'not_scheduled'];
        }

        return [$firesAt, 'scheduled'];
    }

    /**
     * Builds an un-routed admin edit link for the item, or '' when the extension is not linkable.
     *
     * @param   object  $row  The rule row.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private function buildEditUrl(object $row): string
    {
        $parts  = explode('.', (string) $row->extension);
        $option = $parts[0] ?? '';
        $type   = $parts[1] ?? '';

        if ($option === '' || $type === '') {
            return '';
        }

        return 'index.php?option=' . $option . '&task=' . $type . '.edit&id=' . (int) $row->item_id;
    }

    /**
     * Whether a candidate fire time is sooner than the current best. Null (uncomputable) is
     * treated as the latest possible, so a computable time always wins.
     *
     * @param   \DateTime|null  $candidate  The candidate time.
     * @param   \DateTime|null  $current    The current best time.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private function isSooner(?\DateTime $candidate, ?\DateTime $current): bool
    {
        if ($candidate === null) {
            return false;
        }

        if ($current === null) {
            return true;
        }

        return $candidate < $current;
    }

    /**
     * Sort comparator: soonest first, uncomputable (null) last.
     *
     * @param   UpcomingTransition  $a  First item.
     * @param   UpcomingTransition  $b  Second item.
     *
     * @return  integer
     *
     * @since   __DEPLOY_VERSION__
     */
    private function compareByFiresAt(UpcomingTransition $a, UpcomingTransition $b): int
    {
        if ($a->firesAt === null && $b->firesAt === null) {
            return 0;
        }

        if ($a->firesAt === null) {
            return 1;
        }

        if ($b->firesAt === null) {
            return -1;
        }

        return $a->firesAt <=> $b->firesAt;
    }

    /**
     * Calculates the next automated transition for a set of items, keyed by item id.
     *
     * One query for the whole set, so a list view can badge many rows without a query each
     * Only items that actually have a pending move appear in the result.
     *
     * @param int[] $itemIds The content item ids.
     * @param string $extension The workflow extension, e.g. com_content.article.
     *
     * @return array<int, UpcomingTransition>
     *
     * @since __DEPLOY_VERSION__
     */
    public function forItems(array $itemIds, string $extension): array
    {
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));

        if (empty($itemIds)) {
            return [];
        }

        $byItem = [];

        foreach ($this->buildFromRows($this->fetchRowsForItems($itemIds, $extension)) as $transition) {
            $byItem[$transition->itemId] = $transition;
        }

        return $byItem;
    }

    /**
     * Loads the candidate row for a set of items
     *
     * @param int[] $itemIds The content item ids.
     * @param string $extension The workflow extension.
     *
     * @return object[]
     *
     * @since __DEPLOY_VERSION__
     */
    private function fetchRowsForItems(array $itemIds, string $extension): array
    {
        $db                 = $this->database;
        $scheduledRowsQuery = $this->baseRowsQuery()
            ->whereIn($db->quoteName('wis.item_id'), $itemIds)
            ->where($db->quoteName('wis.extension') . ' = :extension')
            ->bind(':extension', $extension);

        return $db->setQuery($scheduledRowsQuery)->loadObjectList() ?: [];
    }
}
