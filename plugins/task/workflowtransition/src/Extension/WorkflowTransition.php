<?php

/**
 * @package Joomla.Plugin
 * @subpackage Task.WorkflowTransition
 *
 * @copyright (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Task\WorkflowTransition\Extension;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\CMS\Workflow\Workflow;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status as TaskStatus;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Component\Workflow\Administrator\Automation\ConditionEvaluationException;
use Joomla\Component\Workflow\Administrator\Automation\ConditionEvaluator;
use Joomla\Component\Workflow\Administrator\Automation\DeadlineCalculator;
use Joomla\Component\Workflow\Administrator\Automation\ItemFieldResolver;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;
use Joomla\Plugin\Task\WorkflowTransition\Dto\DueAutomation;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects
/**
 * Scheduler task plugin that fires automated workflow transitions.
 *
 * When the Joomla Scheduler runs this task, it queries workflow_item_state
 * for items sitting in stages that have automation, works out live which of them are due,
 * then fires the appropriate * workflow transition for each one using Joomla's existing transition engine.
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
     * How many (item, rule) rows to consider in one run.
     *
     * Without a stored deadline every item in an automated stage is a candidate, so the batch
     * is bounded to keep a run predictable.
     *
     * Rows are taken least-recently-checked first and every row considered is stamped, so the
     * window rotates and each row gets its turn. Ordering by entry time instead would let a
     * group that never becomes eligible, because a filter keeps excluding it, hold the front
     * of the queue forever: those rows never transition, so their entry time never changes,
     * so nothing behind them is ever reached.
     *
     * @var integer
     * @since __DEPLOY_VERSION__
     */
    private const MAX_CANDIDATES_PER_RUN = 500;

    /**
     * Stands in for "never checked" when sorting. The earliest datetime MySQL accepts, so a
     * row that has never been considered always sorts ahead of one that has.
     *
     * @var string
     * @since __DEPLOY_VERSION__
     */
    private const NEVER_CHECKED = '1000-01-01 00:00:00';


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
     * Main task routine — called by the scheduler when the task is due.
     *
     * Groups the due candidates by item, then for each item fires a single rule: it must be
     * past its deadline, pass its filter, and meet its live condition, with the highest-priority
     * survivor winning.
     *
     * Assumptions about the operating environment:
     * - The scheduler holds a per-task lock and will not run this task concurrently with itself,
     *   so no per-rule or per-item locking is done here.
     * - Candidates are read once at the start of the run, so an item may be moved by a manual
     *   transition while the run is still in progress. Workflow::executeTransition() re-checks
     *   the item's current stage association before acting, so a stale candidate cannot fire a
     *   transition that no longer applies. See fireRule() for how that outcome is reported.
     *
     * @param   ExecuteTaskEvent  $event  The scheduler event.
     *
     * @return  integer  A TaskStatus code (OK, or KNOCKOUT when a transition failed).
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function fireOverdueTransitions(ExecuteTaskEvent $event): int
    {
        $now         = Factory::getDate()->toSql();
        $nowDateTime = new \DateTime($now, new \DateTimeZone('UTC'));
        $candidates  = $this->fetchOverdueCandidates();

        if (empty($candidates)) {
            return TaskStatus::OK;
        }

        // Stamped before anything is evaluated, not after, so a row whose condition throws
        // still moves to the back of the queue instead of being re-picked every run.
        $this->markCandidatesChecked($candidates, $now);

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
            $winningRule = $this->selectRuleForItem($itemCandidates, $nowDateTime, $conditionEvaluator, $itemFieldResolver, $failures);

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
     * Fetches the automation rules that are due to be considered for their items this run.
     *
     * Joins the item state, transition, and rule tables so each row carries both the item's
     * schedule data and a rule that could fire it. Nothing is filtered by time here: whether a
     * rule is due is worked out live from entered_at, because a stored deadline goes stale the
     * moment a rule, an item, or a stage's automation changes. Rows are taken oldest first and
     * capped, so a large backlog is worked through over successive runs rather than in one.
     *
     *
     * @return  DueAutomation[]
     *
     * @since   __DEPLOY_VERSION__
     */
    private function fetchOverdueCandidates(): array
    {
        $db = $this->getDatabase();

        $overduePairsQuery = $db->getQuery(true)
            ->select([
                $db->quoteName('war.id', 'rule_id'),
                $db->quoteName('war.transition_id'),
                $db->quoteName('wt.from_stage_id'),
                $db->quoteName('wt.to_stage_id'),
                $db->quoteName('war.delay_value'),
                $db->quoteName('war.delay_unit'),
                $db->quoteName('war.rule_type'),
                $db->quoteName('war.cron_expression'),
                $db->quoteName('war.item_filter'),
                $db->quoteName('war.fire_condition'),
                $db->quoteName('war.ordering'),
                $db->quoteName('wis.item_id'),
                $db->quoteName('wis.extension'),
                $db->quoteName('wis.id', 'item_state_id'),
                $db->quoteName('wis.entered_at'),
                $db->quoteName('war.run_as_user_id'),
            ])
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
            ->where($db->quoteName('wis.requires_intervention') . ' = 0')
            ->where($db->quoteName('war.published') . ' = 1')
            // A row nobody has looked at yet sorts as though it were checked long ago, so a new
            // item is picked up on the next run rather than waiting a full rotation. Written as
            // COALESCE rather than NULLS FIRST because MySQL and PostgreSQL disagree on where
            // nulls belong in an ascending sort.
            ->order(
                'COALESCE(' . $db->quoteName('wis.last_checked_at') . ', '
                    . $db->quote(self::NEVER_CHECKED) . ') ASC'
            )
            // Among rows checked equally long ago, the one waiting longest in its stage first.
            ->order($db->quoteName('wis.entered_at') . ' ASC')
            ->order($db->quoteName('war.ordering') . ' ASC')
            ->setLimit(self::MAX_CANDIDATES_PER_RUN);

        return array_map(
            [DueAutomation::class, 'fromRow'],
            $db->setQuery($overduePairsQuery)->loadObjectList() ?: []
        );
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

        $base = (string) Factory::getApplication()->get('live_site');

        if ($base === '') {
            $base = Uri::root();
        }
        return rtrim($base, '/') . '/administrator/index.php?option=' . $option . '&task=' . $type . '.edit&id=' . $item->item_id;
    }

    /**
     * Records that this run considered these item states, in one query.
     *
     * This is what makes the candidate window rotate. Every row the run looked at moves to the
     * back of the queue, whether or not a rule fired for it, so the next run reaches the rows
     * behind it.
     *
     * @param   DueAutomation[]  $candidates  Every candidate row this run fetched.
     * @param   string           $now         The run's timestamp, in SQL format.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    private function markCandidatesChecked(array $candidates, string $now): void
    {
        // One item can appear once per rule on its stage, so the ids are deduplicated before
        // they reach the query.
        $itemStateIds = array_values(array_unique(
            array_map(static fn (DueAutomation $candidate): int => $candidate->item_state_id, $candidates)
        ));

        $db          = $this->getDatabase();
        $updateQuery = $db->getQuery(true)
            ->update($db->quoteName('#__workflow_item_state'))
            ->set($db->quoteName('last_checked_at') . ' = :now')
            ->whereIn($db->quoteName('id'), $itemStateIds)
            ->bind(':now', $now);

        $db->setQuery($updateQuery)->execute();
    }

    /**
     * Flags an item as needing manual intervention after its transition failed.
     *
     * Clearing requires_intervention later makes the item eligible again on the next run,
     * because its due-ness is recomputed from entered_at rather than read from a stored value.
     *
     * @param integer $itemStateId The #__workflow_item_state row id.
     *
     * @return void
     *
     * @since __DEPLOY_VERSION__
     */
    private function markRequiresIntervention(int $itemStateId): void
    {
        $db          = $this->getDatabase();
        $updateQuery = $db->getQuery(true)
            ->update($db->quoteName('#__workflow_item_state'))
            ->set($db->quoteName('requires_intervention') . ' = 1')
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $itemStateId, ParameterType::INTEGER);

        $db->setQuery($updateQuery)->execute();
    }

    /**
     * Chooses which single rule, if any, should fire for one item this run.
     *
     * Considers every candidate rule for the item: it must be past its own deadline, its
     * filter must scope the item in, and its live condition must currently hold. The highest
     * priority survivor (lowest ordering) wins. When nothing fires there is nothing to record:
     * the next run recomputes from entered_at and reaches the same conclusion, or a different
     * one if the rule or the item changed in the meantime.
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
        ItemFieldResolver $itemFieldResolver,
        array &$failures
    ): ?object {
        $firstCandidate = $itemCandidates[0];
        $fieldResolver  = $itemFieldResolver->forItem((int) $firstCandidate->item_id, $firstCandidate->extension);

        $eligibleRules       = [];

        foreach ($itemCandidates as $candidate) {
            $deadline = DeadlineCalculator::forRule($candidate->entered_at, $candidate);

            if ($deadline === null) {
                continue;
            }

            // Not due yet by this rule's own timing.
            if ($deadline > $nowDateTime) {
                continue;
            }

            // Due, but does the rule's filter scope in this item at all? A rule whose
            // stored expression cannot be evaluated is reported as a failure and
            // skipped, never silently treated as passing or failing.
            try {
                if (!$conditionEvaluator->evaluate($candidate->item_filter, $fieldResolver)) {
                    continue;
                }

                // Due and in scope, but is the live condition satisfied right now?
                if (!$conditionEvaluator->evaluate($candidate->fire_condition, $fieldResolver)) {
                    continue;
                }
            } catch (ConditionEvaluationException $invalidCondition) {
                $failures[] = 'Item ' . $candidate->item_id . ' (rule ' . $candidate->rule_id . '): invalid condition: '
                    . $invalidCondition->getMessage();

                continue;
            }

            $eligibleRules[] = $candidate;
        }

        if (!empty($eligibleRules)) {
            // Highest priority wins: the lowest ordering value. oldest rule wins on a tie
            usort(
                $eligibleRules,
                static fn (object $a, object $b): int => [(int) $a->ordering, (int) $a->rule_id] <=> [(int) $b->ordering, (int) $b->rule_id]
            );
            return $eligibleRules[0];
        }

        return null;
    }

    /**
     * Executes the winning transition for an item, as its configured run-as user.
     *
     * @param DueAutomation $rule      The winning candidate row.
     * @param   CMSApplicationInterface  $app       The application, for the identity swap.
     * @param   string[]                 $failures  Collected failure messages (by reference).
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    private function fireRule(DueAutomation $rule, CMSApplicationInterface $app, array &$failures): void
    {
        $transitionId = (int) $rule->transition_id;
        $runAsUserId  = $rule->run_as_user_id;
        $originalUser = $app->getIdentity();

        try {
            if ($runAsUserId > 0) {
                $runAsUser = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($runAsUserId);

                if ($runAsUser->id > 0) {
                    $app->loadIdentity($runAsUser);
                }
            }

            $workflow = new Workflow($rule->extension);

            // executeTransition() re-checks the item's current stage association, so a candidate
            // made stale by a manual transition earlier in this run returns false instead of
            // firing. Note that false is currently indistinguishable from a genuine failure, so
            // such a race is reported as one; see the follow-up issue on classifying exit codes.

            if ($workflow->executeTransition([$rule->item_id], $transitionId, 'automation')) {
                $this->logAutomationRun($rule, 0);
            } else {
                $failureNote = 'Transition could not be executed; permission denied, invalid transition, or stopped by a plugin.';
                $editLink    = $this->itemEditLink($rule);
                $failures[]  = 'Item ' . $rule->item_id . ' (transition ' . $transitionId . '): '
                    . $failureNote . ($editLink !== '' ? ' - ' . $editLink : '');

                $this->logAutomationRun($rule, 1, $failureNote);
                $this->markRequiresIntervention($rule->item_state_id);
            }
        } catch (\Throwable $error) {
            $editLink   = $this->itemEditLink($rule);
            $failures[] = 'Item ' . $rule->item_id . ' (transition ' . $transitionId . '): '
                . $error->getMessage() . ($editLink !== '' ? ' - ' . $editLink : '');

            $this->logAutomationRun($rule, 3, $error->getMessage());
            $this->markRequiresIntervention($rule->item_state_id);
        } finally {
            // Always restore the scheduler's original identity.
            $app->loadIdentity($originalUser);
        }
    }
}
