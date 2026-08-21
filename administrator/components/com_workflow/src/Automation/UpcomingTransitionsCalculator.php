<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_workflow
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Workflow\Administrator\Automation;

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

        // A rule whose filter cannot be read tells us nothing about whether it applies to this
        // item, so it cannot compete for "fires next". The item is remembered separately
        // instead of being dropped: dropping it is what used to make a broken rule vanish from
        // the view completely, which is the one case an administrator most needs to see. The
        // first message wins, matching the scheduler, which also reports one fault per item.
        $liveFailureByItem = [];
        $fallbackRowByItem = [];

        foreach ($rows as $row) {
            $itemKey      = $row->extension . '.' . $row->item_id;
            $resolveField = $this->itemFieldResolver->forItem((int) $row->item_id, $row->extension);

            try {
                if (!$this->conditionEvaluator->evaluate($row->item_filter, $resolveField)) {
                    continue;
                }
            } catch (ConditionEvaluationException $invalidCondition) {
                $liveFailureByItem[$itemKey] ??= $invalidCondition->getMessage();
                $fallbackRowByItem[$itemKey] ??= $row;

                continue;
            }

            // Rules compete on when they become due, before any condition is considered, so
            // the item keeps the rule that comes up first.
            $deadline = DeadlineCalculator::forRule($row->entered_at, $row);

            if (!isset($bestByItem[$itemKey]) || $this->isSooner($deadline, $bestByItem[$itemKey]['deadline'])) {
                $bestByItem[$itemKey] = ['row' => $row, 'deadline' => $deadline];
            }
        }

        // An item whose every rule was unreadable has no winner to show, so the first rule that
        // failed stands in for it. Without this the item is still missing from the view.
        foreach ($fallbackRowByItem as $itemKey => $row) {
            $bestByItem[$itemKey] ??= ['row' => $row, 'deadline' => null];
        }

        $now      = new \DateTime('now', new \DateTimeZone('UTC'));
        $upcoming = [];

        foreach ($bestByItem as $itemKey => $winner) {
            $upcoming[] = $this->buildUpcomingTransition(
                $winner['row'],
                $winner['deadline'],
                $now,
                $liveFailureByItem[$itemKey] ?? ''
            );
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

        return $this->fetchRows($query);
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
            ->where($db->quoteName('wis.extension') . ' = :extension')
            ->bind(':extension', $extension);

        return $this->fetchRows($query);
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

        return $this->fetchRows($query);
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
                    $db->quoteName('wis.last_failure_at'),
                    $db->quoteName('wis.last_failure_reason'),
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
                'INNER',
                $db->quoteName('#__workflows', 'w'),
                $db->quoteName('w.id') . ' = ' . $db->quoteName('wt.workflow_id')
            )

            // Every level has to be on, and the scheduler applies the same three, so the
            // preview never advertises a transition the engine would refuse to fire.
            ->where($db->quoteName('w.published') . ' = 1')
            ->where($db->quoteName('wt.published') . ' = 1')
            ->where($db->quoteName('war.published') . ' = 1')
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
    private function buildUpcomingTransition(
        object $row,
        ?\DateTime $deadline,
        \DateTime $now,
        string $liveFailureReason
    ): UpcomingTransition {
        $requiresIntervention = (int) $row->requires_intervention === 1;
        $hasCondition         = $row->fire_condition !== null && trim((string) $row->fire_condition) !== '';

        [$firesAt, $status, $discoveredReason] = $this->resolveFireTime(
            $row,
            $deadline,
            $now,
            $hasCondition,
            $requiresIntervention,
            $liveFailureReason
        );

        $storedReason = (string) ($row->last_failure_reason ?? '');
        $storedAt     = $row->last_failure_at !== null
            ? new \DateTime((string) $row->last_failure_at, new \DateTimeZone('UTC'))
            : null;

        // What this render just discovered describes the rule as it stands right now. The
        // stored reason describes what the scheduler hit on its last real run, which is not
        // the same question: the rule may have been fixed since, and the scheduler evaluates
        // the fire condition at fire time, a moment this view never reaches. So a discovered
        // fault wins, and when only the stored one exists it is shown with its timestamp while
        // the status is left alone. The view has no business claiming a fault it cannot see.
        $failureReason = $discoveredReason !== '' ? $discoveredReason : $storedReason;
        $failedAt = $discoveredReason !== '' ? null : $storedAt;

        return new UpcomingTransition(
            itemId: (int) $row->item_id,
            extension: (string) $row->extension,
            itemTitle: (string) ($row->item_title ?? ''),
            editUrl: $this->buildEditUrl($row),
            fromStage: (string) ($row->from_stage_title ?? ''),
            toStage: (string) ($row->to_stage_title ?? ''),
            firesAt: $firesAt,
            status: $status,
            failureReason: $failureReason,
            failedAt: $failedAt,
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
     * @param   string          $liveFailureReason     A filter fault this render already found,
     * or '' if the filter read cleanly.
     *
     * @return  array{0: \DateTime|null, 1: string, 2: string}  Fire time, status, and the fault
     * this render found if any.
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
        bool $requiresIntervention,
        string $liveFailureReason
    ): array {
        // Blocked outranks everything: the item is out of the scheduler until a person clears
        // it, so nothing about its timing is worth showing.
        if ($requiresIntervention) {
            return [$deadline, 'needs_attention', ''];
        }

        // The filter could not be read, so whether this rule even applies is unknown, which
        // makes any fire time a guess.
        if ($liveFailureReason !== '') {
            return [null, 'rule_error', $liveFailureReason];
        }

        if ($deadline === null) {
            return [null, 'not_scheduled', ''];
        }

        if (!$hasCondition) {
            return [$deadline, 'scheduled', ''];
        }

        try {
            $firesAt = $this->conditionWindowCalculator->firstMatchAtOrAfter(
                $row->fire_condition,
                max($deadline, $now),
                (int) $row->item_id,
                (string) $row->extension
            );
        } catch (ConditionEvaluationException $invalidCondition) {
            // This was reported as needs_attention, which was misleading. That status means
            // the item is stopped and waiting on a person; this one keeps being retried every
            // run and clears itself the moment the rule is fixed. Telling an administrator to
            // go and unstick something that is not stuck wastes their time.
            return [null, 'rule_error', $invalidCondition->getMessage()];
        }

        // The condition never opens again within the search horizon, so there is no fire time
        // to promise: for example a rule gated on a date that has already passed.
        if ($firesAt === null) {
            return [null, 'not_scheduled', ''];
        }

        return [$firesAt, 'scheduled', ''];
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

        return $this->fetchRows($scheduledRowsQuery);
    }

    /**
     * Runs a rows query and finishes the two jobs the query cannot do itself.
     *
     * Both need the extension's own item table, and one query cannot join a different table
     * per row, so they happen here instead. Each costs one query per extension in the result
     * rather than one per item.
     *
     * Trashed and archived items are dropped: an editor has taken them out of use, and the
     * scheduler ignores them too, so previewing a transition for them would promise something
     * that will never happen. Titles are filled in so the views can name the item rather than
     * falling back to its id, which is what happened for every extension except com_content
     * while the title came from a hardcoded join.
     *
     * @param QueryInterface $query The prepared rows query.
     *
     * @return object[]
     *
     * @since __DEPLOY_VERSION__
     */
    private function fetchRows(QueryInterface $query): array
    {
        $rows = $this->database->setQuery($query)->loadObjectList() ?: [];

        if ($rows === []) {
            return [];
        }

        $itemIdsByExtension = [];

        foreach ($rows as $row) {
            $itemIdsByExtension[$row->extension][] = (int) $row->item_id;
        }

        $itemStorage = new ItemStorage($this->database);
        $excluded    = [];
        $titles      = [];

        foreach ($itemIdsByExtension as $extension => $itemIds) {
            foreach ($itemStorage->trashedOrArchivedIds($itemIds, $extension) as $itemId) {
                // Keyed by extension and id together, because an item id is only unique within
                // its own extension: article 5 and contact 5 are different items.
                $excluded[$extension . '.' . $itemId] = true;
            }

            foreach ($itemStorage->titlesFor($itemIds, $extension) as $itemId => $title) {
                $titles[$extension . '.' . $itemId] = $title;
            }
        }

        $kept = [];

        foreach ($rows as $row) {
            $key = $row->extension . '.' . $row->item_id;

            if (isset($excluded[$key])) {
                continue;
            }

            // The views expect this property whether or not a name was found; null means
            // "show the id instead" rather than "no row".
            $row->item_title = $titles[$key] ?? null;
            $kept[]          = $row;
        }

        return $kept;
    }
}
