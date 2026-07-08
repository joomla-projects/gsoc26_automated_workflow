<?php

/**
 * @package Joomla.Plugin
 * @subpackage Task.WorkflowTransition
 *
 * @copyright (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Task\WorkflowTransition\Extension;

use Cron\CronExpression;
use DateTime;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\CMS\Workflow\Workflow;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status as TaskStatus;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects
/**
 * Scheduler task plugin that fires automated workflow transitions.
 *
 * When the Joomla Scheduler runs this task, it queries workflow_automation_schedule
 * for items whose next_transition_at deadline has passed, then fires the appropriate
 * workflow transition for each one using Joomla's existing transition engine.
 *
 * @since __DEPLOY_VERSION__
 */
final class WorkflowTransition extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;
    use TaskPluginTrait;

    /**
     * @var string[]
     * @since __DEPLOY_VERSION__
     */
    protected const TASKS_MAP = [
        'workflow.automation' => [
            'langConstPrefix' => 'PLG_TASK_WORKFLOWTRANSITION_WORKFLOWAUTOMATION',
            'method'          => 'fireOverdueTransitions',
        ],
    ];

    /**
     * @var boolean
     * @since __DEPLOY_VERSION__
     */
    protected $autoloadLanguage = true;

    /**
     * Returns the events the plugin listens to.
     *
     * @return array
     *
     * @since __DEPLOY_VERSION__
     */
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            'onTaskOptionsList'    => 'advertiseRoutines',
            'onExecuteTask'        => 'standardRoutineHandler',
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
     * @since   __DEPLOY_VERSION__
     */
    protected function fireOverdueTransitions(ExecuteTaskEvent $event): int
    {
        $now          = Factory::getDate()->toSql();
        $overduePairs = $this->fetchOverduePairs($now);

        if (empty($overduePairs)) {
            return TaskStatus::OK;
        }

        // Group by rule_id. Each rule is locked once regardless of how many items
        // are waiting on it. This avoids acquiring and releasing the same lock per item.
        $byRule = [];

        foreach ($overduePairs as $pair) {
            $byRule[$pair->rule_id][] = $pair;
        }

        $failures = [];

        foreach ($byRule as $ruleId => $items) {
            if (!$this->lockRule((int) $ruleId, $now)) {
                // Another scheduler instance grabbed this rule between our SELECT and now
                continue;
            }

            try {
                $nowDateTime  = new \DateTime($now, new \DateTimeZone('UTC'));
                $transitionId = (int) $items[0]->transition_id;
                $runAsUserId  = (int) ($items[0]->run_as_user_id ?? 0);
                $app          = Factory::getApplication();
                $originalUser = $app->getIdentity();

                if ($runAsUserId > 0) {
                    $runAsUser = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($runAsUserId);

                    if ($runAsUser->id > 0) {
                        $app->loadIdentity($runAsUser);
                    }
                }

                foreach ($items as $item) {
                    $deadline = $this->computeDeadline($item->entered_at, $item);

                    if ($deadline === null || $deadline > $nowDateTime) {
                        if ($deadline !== null) {
                            $this->rescheduleItem((int) $item->schedule_id, $deadline);
                        }

                        continue;
                    }

                    try {
                        $workflow = new Workflow($item->extension);

                        if ($workflow->executeTransition([(int) $item->item_id], $transitionId, 'automation')) {
                            $this->logAutomationRun($item, 0);
                        } else {
                            $link       = $this->itemEditLink($item);
                            $note       = 'Transition could not be executed; permission denied, invalid transition, or stopped by a plugin.';
                            $failures[] = 'Item ' . (int) $item->item_id . ' (transition ' . $transitionId . '): ' . $note . ($link !== '' ? ' - ' . $link : '');
                            $this->logAutomationRun($item, 1, $note);
                            $this->markRequiresIntervention((int) $item->schedule_id);
                        }
                    } catch (\Throwable $e) {
                        $link       = $this->itemEditLink($item);
                        $failures[] = 'Item ' . (int) $item->item_id . ' (transition ' . $transitionId . '): ' . $e->getMessage() . ($link !== '' ? ' - ' . $link : '');
                        $this->logAutomationRun($item, 3, $e->getMessage());
                        $this->markRequiresIntervention((int) $item->schedule_id);
                    }
                }
            } finally {
                $this->unlockRule((int) $ruleId);

                if (isset($app, $originalUser)) {
                    $app->loadIdentity($originalUser);
                }
            }
        }

        if (!empty($failures)) {
            $summary = \count($failures) . ' automated transition(s) failed:' . "\n" . implode("\n", $failures);

            // Logged to the scheduler log, and surfaced in the task-failure notification email.
            $this->logTask($summary, 'error');
            $this->snapshot['output_body'] = $summary;

            return TaskStatus::KNOCKOUT;
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
     * @since __DEPLOY_VERSION__
     */
    private function fetchOverduePairs(string $now): array
    {
        $db = $this->getDatabase();

        $overduePairsQuery = $db->getQuery(true)
            ->select([
                $db->quoteName('wta.id', 'rule_id'),
                $db->quoteName('wta.transition_id'),
                $db->quoteName('wt.from_stage_id'),
                $db->quoteName('wt.to_stage_id'),
                $db->quoteName('wta.interval_value'),
                $db->quoteName('wta.interval_unit'),
                $db->quoteName('wta.rule_type'),
                $db->quoteName('wta.cron_expression'),
                $db->quoteName('wta.ordering'),
                $db->quoteName('was.item_id'),
                $db->quoteName('was.extension'),
                $db->quoteName('was.id', 'schedule_id'),
                $db->quoteName('was.entered_at'),
                $db->quoteName('wta.run_as_user_id'),
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
            ->where($db->quoteName('was.requires_intervention') . ' = 0')
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
     * @since __DEPLOY_VERSION__
     */
    private function lockRule(int $ruleId, string $now): bool
    {
        $db = $this->getDatabase();
        // Set expiry to 30 minutes from now
        $lockedUntil = Factory::getDate($now)->add(new \DateInterval('PT30M'))->toSql();

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
     * @since __DEPLOY_VERSION__
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
     * @return \DateTime|null The deadline as a DateTime object, or null if uncomputable.
     *
     * @since __DEPLOY_VERSION__
     */
    private function computeDeadline(string $enteredAt, object $rule): ?\DateTime
    {
        if ($rule->rule_type === 'cron') {
            if (empty($rule->cron_expression)) {
                throw new \RuntimeException('Automation rule ' . $rule->rule_id . ' has rule_type cron but no cron_expression set.');
            }

            $cronExpression = new CronExpression($rule->cron_expression);
            $deadline       = $cronExpression->getNextRunDate(
                $enteredAt,
                0,
                false,
                Factory::getApplication()->get('offset', 'UTC')
            );
            $deadline->setTimezone(new \DateTimeZone('UTC'));

            return $deadline;
        }

        $date     = new \DateTime($enteredAt, new \DateTimeZone('UTC'));
        $interval = match ($rule->interval_unit) {
            'minutes' => new \DateInterval('PT' . $rule->interval_value . 'M'),
            'hours'   => new \DateInterval('PT' . $rule->interval_value . 'H'),
            'days'    => new \DateInterval('P' . $rule->interval_value . 'D'),
            'months'  => new \DateInterval('P' . $rule->interval_value . 'M'),
            default   => null,
        };

        return $interval ? $date->add($interval) : null;
    }

    /**
     * Corrects a stale next_transition_at value in the schedule table.
     *
     * Called when next_transition_at <= NOW() brought an item into the batch but the per-rule deadline computation shows it is not yet due. The stored
     * value was outdated; writing the real deadline prevents the scheduler
     * from re-fetching the same item on every run until the rule actually fires.
     *
     * @param integer $scheduleId The workflow_automation_schedule row.ID
     * @param \DateTime $deadline The correct next deadline for this rule.
     *
     * @return void
     *
     * @since __DEPLOY_VERSION__
     */
    private function rescheduleItem(int $scheduleId, \DateTime $deadline): void
    {
        $db          = $this->getDatabase();
        $nextRunTime = $deadline->format('Y-m-d H:i:s');

        $query = $db->getQuery(true)
            ->update($db->quoteName('#__workflow_automation_schedule'))
            ->set($db->quoteName('next_transition_at') . ' = :nextRunTime')
            ->where($db->quoteName('id') . ' =:id')
            ->bind(':nextRunTime', $nextRunTime)
            ->bind(':id', $scheduleId, ParameterType::INTEGER);

        $db->setQuery($query)->execute();
    }

    /**
     * Stops an item from being retried after its transition failed.
     *
     * Clearing next_transition_at removes the item from the scheduler's pickup query
     * so a permanently-failing transition does not re-notify on every run. The item
     * is re-armed when it next enters a stage (manual transition or re-save).
     *
     * @param integer $scheduledId The workflow_automation_schedule row ID.
     *
     * @return void
     *
     * @since __DEPLOY_VERSION__
     */
    private function markItemFailed(int $scheduledId): void
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__workflow_automation_schedule'))
            ->set($db->quoteName('next_transition_at') . ' = NULL')
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $scheduledId, ParameterType::INTEGER);

        $db->setQuery($query)->execute();
    }

    /**
     * Records the outcome of one automated transition attempt.
     *
     * @param object $item The overdue (item, rule) pair being processed.
     * @param integer $exitCode 0 = success; non-zero = failure (see
     * #__workflow_automation_log).
     * @param string $note Optional human-readable reason, truncated to the column
     * length.
     *
     * @return void
     *
     * @since __DEPLOY_VERSION__
     */
    private function logAutomationRun(object $item, int $exitCode, string $note = ''): void
    {
        $db  = $this->getDatabase();
        $row = (object) [
            'rule_id'        => (int) $item->rule_id,
            'item_id'        => (int) $item->item_id,
            'extension'      => $item->extension,
            'transition_id'  => (int) $item->transition_id,
            'from_stage_id'  => (int) ($item->from_stage_id ?? 0),
            'to_stage_id'    => (int) ($item->to_stage_id ?? 0),
            'run_as_user_id' => (int) ($item->run_as_user_id ?? 0),
            'trigger_type'   => 'rule',
            'exit_code'      => $exitCode,
            'note'           => $note !== '' ? substr($note, 0, 500) : null,
            'executed_at'    => Factory::getDate()->toSql(),
        ];

        $db->insertObject('#__workflow_automation_log', $row);
    }

    /**
     * Builds an absolute backend edit link for the item, for use in notification.
     *
     * @param object $item The overdue (item, rule) pair.
     *
     * @return string Absolute URL, or empty string if the extension can't be resolved.
     *
     * @since __DEPLOY_VERSION__
     */
    private function itemEditLink(object $item): string
    {
        $parts  = explode('.', (string) $item->extension);
        $option = $parts[0] ?? '';
        $type   = $parts[1] ?? '';

        if ($option === '' || $type === '') {
            return '';
        }

        return Uri::root() . 'administrator/index.php?option=' . $option . '&task=' . $type . '.edit&id=' . (int) $item->item_id;
    }

    /**
     * Flags an item as needing manual intervention after its transition failed.
     *
     * next_transition_at is intentionally left untouched, clearing requires_intervention
     * later makes the still-overdue item eligible again without a re-save
     *
     * @param integer $scheduleId The workflow_automation_schedule row ID.
     *
     * @return void
     *
     * @since __DEPLOY_VERSION__
     */
    private function markRequiresIntervention(int $scheduleId): void
    {
        $db          = $this->getDatabase();
        $updateQuery = $db->getQuery(true)
            ->update($db->quoteName('#__workflow_automation_schedule'))
            ->set($db->quoteName('requires_intervention') . ' = 1')
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $scheduleId, ParameterType::INTEGER);

        $db->setQuery($updateQuery)->execute();
    }
}
