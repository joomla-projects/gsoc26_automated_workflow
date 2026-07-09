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
use Joomla\CMS\Application\CMSApplicationInterface;
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
use Joomla\Plugin\Task\WorkflowTransition\Condition\ConditionEvaluator;
use Joomla\Plugin\Task\WorkflowTransition\Condition\ItemFieldResolver;

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
        $now         = Factory::getDate()->toSql();
        $nowDateTime = new \DateTime($now, new \DateTimeZone('UTC'));
        $candidates  = $this->fetchOverduePairs($now);

        if (empty($candidates)) {
            return TaskStatus::OK;
        }

        // Group the candidate rules by the item they might act on, so we pick one winner per item.
        $candidatesByItem = [];

        foreach ($candidates as $candidate) {
            $candidatesByItem[$candidate->extension . '.' . $candidate->item_id][] = $candidate;
        }

        $conditionEvaluator = new ConditionEvaluator();
        $itemFieldResolver  = new ItemFieldResolver($this->getDatabase());
        $app                = Factory::getApplication();
        $failures           = [];

        foreach ($candidatesByItem as $itemCandidates) {
            $winningRule = $this->selectRuleForItem($itemCandidates, $nowDateTime, $conditionEvaluator, $itemFieldResolver);

            if ($winningRule !== null) {
                $this->fireRule($winningRule, $app, $failures);
            }
        }

        if (!empty($failures)) {
            $summary = \count($failures) . ' automated transition(s) failed:' . "\n" . implode("\n", $failures);

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
                $db->quoteName('wta.item_filter'),
                $db->quoteName('wta.fire_condition'),
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

            ->bind(':now', $now)
            ->order($db->quoteName('wta.ordering') . ' ASC')
            ->setLimit(50);

        return $db->setQuery($overduePairsQuery)->loadObjectList() ?: [];
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

    /**
     * Chooses which single rule, if any, should fire for one item this run.
     *
     * Considers every candidate rule for the item: it must be past its own deadline, its
     * filter must scope the item in, and its live condition must currently hold. The highest
     * priority survivor (lowest ordering) wins. If nothing fires, the stored deadline is
     * corrected forward — unless a rule is only waiting on its condition, in which case the
     * schedule is left so the item is re-checked next run.
     *
     * @param   object[]            $itemCandidates      Candidate rows for a single item.
     * @param   \DateTime           $nowDateTime         Current time (UTC).
     * @param   ConditionEvaluator  $conditionEvaluator  The expression evaluator.
     * @param   ItemFieldResolver   $itemFieldResolver   The field resolver factory.
     *
     * @return  object|null  The winning candidate, or null if none should fire.
     *
     * @since   __DEPLOY_VERSION__
     */
    private function selectRuleForItem(
        array $itemCandidates,
        \DateTime $nowDateTime,
        ConditionEvaluator $conditionEvaluator,
        ItemFieldResolver $itemFieldResolver
    ): ?object {
        $firstCandidate = $itemCandidates[0];
        $resolveField   = $itemFieldResolver->forItem((int) $firstCandidate->item_id, $firstCandidate->extension);

        $eligibleRules       = [];
        $hasConditionBlocked = false;
        $earliestFutureDue   = null;

        foreach ($itemCandidates as $candidate) {
            $deadline = $this->computeDeadline($candidate->entered_at, $candidate);

            if ($deadline === null) {
                continue;
            }

            // Not due yet by this rule's own timing: track the soonest future deadline.
            if ($deadline > $nowDateTime) {
                if ($earliestFutureDue === null || $deadline < $earliestFutureDue) {
                    $earliestFutureDue = $deadline;
                }

                continue;
            }

            // Due, but does the rule's filter scope in this item at all?
            if (!$conditionEvaluator->evaluate($candidate->item_filter, $resolveField)) {
                continue;
            }

            // Due and in scope, but is the live condition satisfied right now?
            if (!$conditionEvaluator->evaluate($candidate->fire_condition, $resolveField)) {
                $hasConditionBlocked = true;

                continue;
            }

            $eligibleRules[] = $candidate;
        }

        if (!empty($eligibleRules)) {
            // Highest priority wins: the lowest ordering value.
            usort($eligibleRules, static fn (object $a, object $b): int => (int) $a->ordering <=> (int) $b->ordering);

            return $eligibleRules[0];
        }

        // Nothing fires this run. Only push the deadline forward when no rule is waiting on its
        // condition — a condition-blocked item must stay due so it is re-checked next run.
        if (!$hasConditionBlocked && $earliestFutureDue !== null) {
            $this->rescheduleItem((int) $firstCandidate->schedule_id, $earliestFutureDue);
        }

        return null;
    }

    /**
     * Executes the winning transition for an item, as its configured run-as user.
     *
     * @param   object                   $rule      The winning candidate row.
     * @param   CMSApplicationInterface  $app       The application, for the identity swap.
     * @param   string[]                 $failures  Collected failure messages (by reference).
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    private function fireRule(object $rule, CMSApplicationInterface $app, array &$failures): void
    {
        $transitionId = (int) $rule->transition_id;
        $runAsUserId  = (int) ($rule->run_as_user_id ?? 0);
        $originalUser = $app->getIdentity();

        // Run as the configured user so the ACL check passes in a background context.
        if ($runAsUserId > 0) {
            $runAsUser = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($runAsUserId);

            if ($runAsUser->id > 0) {
                $app->loadIdentity($runAsUser);
            }
        }

        try {
            $workflow = new Workflow($rule->extension);

            if ($workflow->executeTransition([(int) $rule->item_id], $transitionId, 'automation')) {
                $this->logAutomationRun($rule, 0);
            } else {
                $failureNote = 'Transition could not be executed; permission denied, invalid transition, or stopped by a plugin.';
                $editLink    = $this->itemEditLink($rule);
                $failures[]  = 'Item ' . (int) $rule->item_id . ' (transition ' . $transitionId . '): '
                    . $failureNote . ($editLink !== '' ? ' - ' . $editLink : '');

                $this->logAutomationRun($rule, 1, $failureNote);
                $this->markRequiresIntervention((int) $rule->schedule_id);
            }
        } catch (\Throwable $error) {
            $editLink   = $this->itemEditLink($rule);
            $failures[] = 'Item ' . (int) $rule->item_id . ' (transition ' . $transitionId . '): '
                . $error->getMessage() . ($editLink !== '' ? ' - ' . $editLink : '');

            $this->logAutomationRun($rule, 3, $error->getMessage());
            $this->markRequiresIntervention((int) $rule->schedule_id);
        } finally {
            // Always restore the scheduler's original identity.
            $app->loadIdentity($originalUser);
        }
    }
}
