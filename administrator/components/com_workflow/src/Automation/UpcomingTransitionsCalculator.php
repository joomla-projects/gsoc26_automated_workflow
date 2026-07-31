<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_workflow
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Workflow\Administrator\Automation;

use DateTime;
use DateTimeZone;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

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
     * @param   DatabaseInterface  $database  The database driver.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(DatabaseInterface $database)
    {
        $this->database           = $database;
        $this->conditionEvaluator = new ConditionEvaluator();
        $this->itemFieldResolver  = new ItemFieldResolver($database);
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
        $rows = $this->fetchRows($workflowId);

        // Keep the soonest in-scope rule per item. An item can have several automated exits
        // from its stage; we only show the one that fires next.
        $bestByItem = [];

        foreach ($rows as $row) {
            $resolveField = $this->itemFieldResolver->forItem((int) $row->item_id, $row->extension);

            // The filter decides whether this rule applies to this item at all.
            if (!$this->conditionEvaluator->evaluate($row->item_filter, $resolveField)) {
                continue;
            }

            $firesAt = DeadlineCalculator::forRule($row->entered_at, $row);
            $itemKey = $row->extension . '.' . $row->item_id;

            if (!isset($bestByItem[$itemKey]) || $this->isSooner($firesAt, $bestByItem[$itemKey]['firesAt'])) {
                $bestByItem[$itemKey] = ['row' => $row, 'firesAt' => $firesAt, 'resolveField' => $resolveField];
            }
        }

        $now      = new DateTime('now', new DateTimeZone('UTC'));
        $upcoming = [];

        foreach ($bestByItem as $winner) {
            $upcoming[] = $this->buildUpcomingTransition($winner['row'], $winner['firesAt'], $winner['resolveField'], $now);
        }

        usort($upcoming, [$this, 'compareByFiresAt']);

        return $upcoming;
    }

    /**
     * Loads the candidate (item, transition, rule) rows for a workflow.
     *
     * @param   integer  $workflowId  The workflow id.
     *
     * @return  object[]
     *
     * @since   __DEPLOY_VERSION__
     */
    private function fetchRows(int $workflowId): array
    {
        $db    = $this->database;
        $query = $db->getQuery(true)
            ->select(
                [
                    $db->quoteName('was.item_id'),
                    $db->quoteName('was.extension'),
                    $db->quoteName('was.entered_at'),
                    $db->quoteName('was.requires_intervention'),
                    $db->quoteName('wt.id', 'transition_id'),
                    $db->quoteName('wt.from_stage_id'),
                    $db->quoteName('wt.to_stage_id'),
                    $db->quoteName('sfrom.title', 'from_stage_title'),
                    $db->quoteName('sto.title', 'to_stage_title'),
                    $db->quoteName('wta.rule_type'),
                    $db->quoteName('wta.delay_value'),
                    $db->quoteName('wta.delay_unit'),
                    $db->quoteName('wta.cron_expression'),
                    $db->quoteName('wta.item_filter'),
                    $db->quoteName('wta.fire_condition'),
                    $db->quoteName('wta.ordering'),
                    $db->quoteName('c.title', 'item_title'),
                ]
            )
            ->from($db->quoteName('#__workflow_automation_schedule', 'was'))
            ->join(
                'INNER',
                $db->quoteName('#__workflow_transitions', 'wt'),
                $db->quoteName('wt.from_stage_id') . ' = ' . $db->quoteName('was.stage_id')
            )
            ->join(
                'INNER',
                $db->quoteName('#__workflow_transition_automation', 'wta'),
                $db->quoteName('wta.transition_id') . ' = ' . $db->quoteName('wt.id')
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
                $db->quoteName('c.id') . ' = ' . $db->quoteName('was.item_id')
                    . ' AND ' . $db->quoteName('was.extension') . ' = ' . $db->quote('com_content.article')
            )
            ->where($db->quoteName('wt.workflow_id') . ' = :workflowId')
            ->where($db->quoteName('wta.published') . ' = 1')
            ->bind(':workflowId', $workflowId, ParameterType::INTEGER)
            ->order($db->quoteName('wta.ordering') . ' ASC');

        return $db->setQuery($query)->loadObjectList() ?: [];
    }

    /**
     * Builds one upcoming transition from a winning row.
     *
     * @param   object         $row           The chosen (item, transition, rule) row.
     * @param   DateTime|null  $firesAt       The computed fire time, or null.
     * @param   callable       $resolveField  The item's field resolver.
     * @param   DateTime       $now           Current time (UTC).
     *
     * @return  UpcomingTransition
     *
     * @since   __DEPLOY_VERSION__
     */
    private function buildUpcomingTransition(object $row, ?DateTime $firesAt, callable $resolveField, DateTime $now): UpcomingTransition
    {
        $requiresIntervention = (int) $row->requires_intervention === 1;
        $hasCondition         = $row->fire_condition !== null && trim((string) $row->fire_condition) !== '';

        return new UpcomingTransition(
            itemId: (int) $row->item_id,
            extension: (string) $row->extension,
            itemTitle: (string) ($row->item_title ?? ''),
            editUrl: $this->buildEditUrl($row),
            fromStage: (string) ($row->from_stage_title ?? ''),
            toStage: (string) ($row->to_stage_title ?? ''),
            firesAt: $firesAt,
            status: $this->resolveStatus($row, $firesAt, $hasCondition, $resolveField, $now, $requiresIntervention),
            ruleType: (string) $row->rule_type,
            delayValue: $row->delay_value !== null ? (int) $row->delay_value : null,
            delayUnit: $row->delay_unit,
            cronExpression: $row->cron_expression,
            hasCondition: $hasCondition
        );
    }

    /**
     * Decides the display status for one upcoming move.
     *
     * @param   object         $row                   The rule row.
     * @param   DateTime|null  $firesAt               The computed fire time.
     * @param   boolean        $hasCondition          Whether a fire condition exists.
     * @param   callable       $resolveField          The item's field resolver.
     * @param   DateTime       $now                   Current time (UTC).
     * @param   boolean        $requiresIntervention  Whether the item is flagged stuck.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private function resolveStatus(
        object $row,
        ?DateTime $firesAt,
        bool $hasCondition,
        callable $resolveField,
        DateTime $now,
        bool $requiresIntervention
    ): string {
        if ($requiresIntervention) {
            return 'needs_attention';
        }

        if ($firesAt === null) {
            return 'not_scheduled';
        }

        // The delay has passed but a condition is still holding the move back.
        if ($hasCondition && $firesAt <= $now && !$this->conditionEvaluator->evaluate($row->fire_condition, $resolveField)) {
            return 'waiting_condition';
        }

        return 'scheduled';
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
     * @param   DateTime|null  $candidate  The candidate time.
     * @param   DateTime|null  $current    The current best time.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private function isSooner(?DateTime $candidate, ?DateTime $current): bool
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
}
