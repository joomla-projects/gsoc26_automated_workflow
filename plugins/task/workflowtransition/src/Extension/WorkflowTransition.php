<?php

/**
 * @package Joomla.Plugin
 * @subpackage Task.WorkflowTransition
 *
 * @copyright (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Task\WorkflowTransition\Extension;

use DateInterval;
use DateTime;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Workflow\Workflow;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;
use Override;
use Joomla\Component\Scheduler\Administrator\Task\Status as TaskStatus;

\defined('_JEXEC') or die;

/**
 * Scheduler task plugin that fires automated workflow transitions.
 *
 * When the Joomla Scheduler runs this task, it queries workflow_automation_schedule
 * for items whose next_transition_at deadline has passed, then fires the appropriate
 * workflow transition for each one using Joomla's existing transition engine.
 *
 * @since 6.2.0
 */
final class WorkflowTransition extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;
    use TaskPluginTrait;

    /**
     * @var string[]
     * @since 6.2.0
     */
    protected const TASKS_MAP = [
        'workflow.automation' => [
            'langConstPrefix' => 'PLG_TASK_WORKFLOWTRANSITION_WORKFLOWAUTOMATION',
            'method' => 'fireOverdueTransitions',
        ]
    ];

    /**
     * @var boolean
     * @since 6.2.0
     */
    protected $autoloadLanguage = true;

    /**
     * Returns the events the plugin listens to.
     *
     * @return array
     *
     * @since 6.2.0
     */
    #[Override]
    public static function getSubscribedEvents(): array
    {
        return[
            'onTaskOptionsList' => 'advertiseRoutines',
            'onExecuteTask' => 'standardRoutineHandler',
            'onContentPrepareForm' => 'enhanceTaskItemForm',
        ];
    }

    /**
     * Main task routine - called by the scheduler when the task is due.
     *
     * Groups overdue (item, rule) pairs by rule. Each rule is locked once before
     * processing all the items waiting on it, then unlocked in finally. Items
     * are verified individually because next_transition_at reflects the EARLIEST
     * rule deadline — a second rule on the same stage might not be due yet.
     *
     * @param   ExecuteTaskEvent  $event  The scheduler event.
     *
     * @return  integer  TaskStatus::OK on completion.
     *
     * @since   6.2.0
     */
    protected function fireOverdueTransitions(ExecuteTaskEvent $event): int
    {
        $now = Factory::getDate()->toSql();
        $overduePairs = $this->fetchOverduePairs($now);

        if (empty($overduePairs)) {
            return TaskStatus::OK;
        }

        // Group by rule_id. Each rule is locked once regardless of how many items
        // are waiting on it. This avoids acquiring and releasing the same lock per item.
        $byRule = [];

        foreach($overduePairs as $pair) {
            $byRule[$pair->rule_id][] = $pair;
        }

        foreach($byRule as $ruleId => $items) {
            if (!$this->lockRule((int) $ruleId, $now)) {
                // Another scheduler instance grabbed this rule between our SELECT and now
                continue;
            }

            try {
                Factory::getApplication()->set('workflow.triggered_by', 'automation');
                $transitionId = (int) $items[0]->transition_id;

                foreach ($items as $item) {
                    $deadline = $this->computeDeadline($item->entered_at, $item);

                    if ($deadline === null || $deadline > $now) {
                        continue;
                    }
                    $workflow = new Workflow($item->extension);
                    $workflow->executeTransition([(int) $item->item_id], $transitionId);
                }
            } finally {
                Factory::getApplication()->set('workflow.triggered_by', 'manual');
                $this->unlockRule((int) $ruleId);
            }
        }

        return TaskStatus::OK;
    }

    /**
     * Fetches all (item, rule) pairs that are overdue and ready to process.
     *
     * We join all three tables so that each returned row contains both the item's
     * schedule data and the rule that should fire it. The WHERE clause uses
     * next_transition_at for a fast indexed scan and also filters out rules that
     * are currently locked by another scheduler instance.
     *
     * @param string $now Current datetime in SQL format.
     *
     * @return object[]
     *
     * @since 6.2.0
     */
    private function fetchOverduePairs(string $now): array
    {
        $db = $this->getDatabase();

        $overduePairsQuery = $db->getQuery(true)
            ->select([
                $db->quoteName('wta.id', 'rule_id'),
                $db->quoteName('wta.transition_id'),
                $db->quoteName('wta.interval_value'),
                $db->quoteName('wta.interval_unit'),
                $db->quoteName('wta.rule_type'),
                $db->quoteName('wta.ordering'),
                $db->quoteName('was.item_id'),
                $db->quoteName('was.extension'),
                $db->quoteName('was.entered_at'),
            ])
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
            ->where($db->quoteName('was.next_transition_at') . ' <= :now')
            ->where($db->quoteName('wta.published') . ' = 1')
            ->where(
                '(' . $db->quoteName('wta.locked_until') . ' IS NULL'
                . ' OR ' . $db->quoteName('wta.locked_until') . ' < :now)'
            )
            ->bind(':now', $now)
            ->order($db->quoteName('wta.ordering') . ' ASC')
            ->setLimit(50);

        return $db->setQuery($overduePairsQuery)->loadObjectList() ?: [];
    }

    /**
     * Automatically locks a rule so no other scheduler instance processes it concurrently.
     *
     * locked_until stores the EXPIRY time (not start time). it reads as
     * "this rule is locked until this datetime." A row is considered unlocked if
     * locked_until IS NULL or its expiry has already passed.
     *
     * @param integer $ruleId The workflow_transition_automation row ID.
     * @param string $now Current datetime in SQL format.
     *
     * @return boolean True if we successfully acquired the lock.
     *
     * @since 6.2.0
     */
    private function lockRule(int $ruleId, string $now): bool
    {
        $db = $this->getDatabase();
        // Set expiry to 30 minutes from now
        $lockedUntil = Factory::getDate($now)->add(new DateInterval('PT30M'))->toSql();

        $lockedRuleQuery = $db->getQuery(true)
            ->update($db->quoteName('#__workflow_transition_automation'))
            ->set($db->quoteName('locked_until') . ' = :lockedUntil')
            ->where($db->quoteName('id') . ' = :id')
            ->where(
                '(' . $db->quoteName('locked_until') . ' IS NULL'
                . ' OR ' . $db->quoteName('locked_until') . ' < :now)'
            )
            ->bind(':lockedUntil', $lockedUntil)
            ->bind(':id', $ruleId, ParameterType::INTEGER)
            ->bind(':now', $now);

        $db->setQuery($lockedRuleQuery)->execute();

        return $db->getAffectedRows() > 0;

    }

    /**
     * Releases the lock on a rule after all its items have been processed.
     *
     * @param integer $ruleId The workflow_transition_automation row ID.
     *
     * @return void
     *
     * @since 6.2.0
     */
    private function unlockRule(int $ruleId): void
    {
        $db = $this->getDatabase();

        $unlockRuleQuery = $db->getQuery(true)
            ->update($db->quoteName('#__workflow_transition_automation'))
            ->set($db->quoteName('locked_until') . ' = NULL')
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $ruleId, ParameterType::INTEGER);

        $db->setQuery($unlockRuleQuery)->execute();
    }

    /**
     * Compute the datetime a rule fires for a given entry time.
     *
     * @param string $enteredAt When the item entered the stage (SQL datetime).
     * @param object $rule The automation rule row.
     *
     * @return string|null SQL datetime of the deadline, or null if uncomputable.
     *
     * @since 6.2.0
     */
    private function computeDeadline(string $enteredAt, object $rule): ?string
    {
        if ($rule->rule_type === 'cron') {
            return null;
        }

        $date = new DateTime($enteredAt);
        $interval = match($rule->interval_unit) {
            'minutes' => new DateInterval('PT' . $rule->interval_value . 'M'),
            'hours'   => new DateInterval('PT' . $rule->interval_value . 'H'),
            'days'    => new DateInterval('P' . $rule->interval_value . 'D'),
            'months'  => new DateInterval('P' . $rule->interval_value . 'M'),
            default   => null,
        };

        return $interval ? $date->add($interval)->format('Y-m-d H:i:s'): null;
    }
}
