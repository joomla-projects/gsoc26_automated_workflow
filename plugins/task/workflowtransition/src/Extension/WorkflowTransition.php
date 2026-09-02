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
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\CMS\Workflow\TransitionStatus;
use Joomla\CMS\Workflow\Workflow;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status as TaskStatus;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Component\Workflow\Administrator\Automation\ConditionEvaluationException;
use Joomla\Component\Workflow\Administrator\Automation\ConditionEvaluator;
use Joomla\Component\Workflow\Administrator\Automation\DeadlineCalculator;
use Joomla\Component\Workflow\Administrator\Automation\ItemFieldResolver;
use Joomla\Component\Workflow\Administrator\Automation\ItemStorage;
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
     * How much of a failure reason is kept. Matches the width of
     * #__workflow_item_state.last_failure_reason.
     *
     * The message is cut to this length at the point it is built, not at the point it is
     * written, so that what a later run compares against is exactly what was stored. Cutting
     * it on the way into the database instead would make every run compare a full message
     * against a truncated one, find them different, and send another email.
     *
     * @var integer
     * @since __DEPLOY_VERSION__
     */
    private const MAX_FAILURE_REASON_LENGTH = 500;

    /**
     * Outcomes recorded in #__workflow_automation_log.exit_code.
     *
     * Kept as constants because the numbers appear at every call site and mean nothing on
     * sight. The column's own comment lists them too; these are the authority and that comment
     * follows them.
     *
     * @var integer
     * @since __DEPLOY_VERSION__
     */
    private const EXIT_OK        = 0;
    private const EXIT_REFUSED   = 1;
    private const EXIT_EXCEPTION = 3;

    /**
     * The item moved on before the transition could fire.
     *
     * Separate from EXIT_REFUSED because it is not a fault. A candidate is fetched at the start
     * of a run and acted on later; if somebody transitions the item by hand in between, the
     * candidate describes a stage the item has already left. Nothing went wrong and nobody needs
     * telling, but it belongs in the log so the run can be accounted for.
     *
     * @var integer
     * @since __DEPLOY_VERSION__
     */
    private const EXIT_NO_LONGER_APPLICABLE = 4;

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
     * Main task routine - called by the scheduler when the task is due.
     *
     * Groups the due candidates by item, then for each item fires a single rule: it must be
     * past its deadline, pass its filter, and meet its live condition, with the highest-priority
     * survivor winning.
     *
     * Assumptions about the operating environment:
     * - The scheduler holds a per-task lock and will not run this task concurrently with itself,
     *   so no per-rule or per-item locking is done here.
     *  - Candidates are read once at the start of the run, so an item may be moved by a manual
     *   transition while the run is still in progress. Workflow::attemptTransition() re-checks
     *   the item's current stage association before acting and reports STAGE_MISMATCH, so a
     *   stale candidate cannot fire a transition that no longer applies.
     *
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
        $candidates  = $this->fetchCandidates();

        if (empty($candidates)) {
            return TaskStatus::OK;
        }

        // Stamped before anything is evaluated, not after, so a row whose condition throws
        // an exception still moves to the back of the queue instead of being re-picked every run.
        $this->markCandidatesChecked($candidates, $now);

        // Group the candidate rules by the item they might act on, so we pick one winner per item.
        $candidatesByItem = [];

        foreach ($candidates as $candidate) {
            $candidatesByItem[$candidate->extension . '.' . $candidate->item_id][] = $candidate;
        }

        $conditionEvaluator = new ConditionEvaluator();
        $itemFieldResolver  = new ItemFieldResolver($this->getDatabase());

        // Fetch every item's fields up front so evaluating filters across the batch costs a
        // fixed number of queries rather than one lookup per item.
        $itemIdsByExtension = [];

        foreach ($candidates as $candidate) {
            $itemIdsByExtension[$candidate->extension][] = (int) $candidate->item_id;
        }

        foreach ($itemIdsByExtension as $extension => $itemIds) {
            $itemFieldResolver->preload($itemIds, $extension);
        }

        $app                = Factory::getApplication();
        $failures           = [];

        // Item state rows that could not be evaluated this run, keyed by row id, with the
        // reason as the value. Collected here and written in bulk after the loop, because a
        // broken rule usually breaks for every item it covers and one query per item would
        // turn a single misconfiguration into hundreds of writes.
        $failureReasonsByItemState = [];

        // Item state rows carrying a stored reason that no longer applies, because they read
        // cleanly this time. Cleared for the same reason, in one query.
        $recoveredItemStates = [];

        foreach ($candidatesByItem as $itemCandidates) {
            // Set by selectRuleForItem() only when a rule's stored expression cannot be read.
            $evaluationFailure = null;

            // Safe without a guard: $candidatesByItem is built by appending, so a group only
            // exists because at least one candidate was pushed into it, and the keys are always
            // zero-based. Every candidate for one item shares its item state row, so any of
            // them can speak for the item.
            $firstCandidate = $itemCandidates[0];

            $winningRule = $this->selectRuleForItem(
                $itemCandidates,
                $nowDateTime,
                $conditionEvaluator,
                $itemFieldResolver,
                $evaluationFailure
            );

            if ($evaluationFailure !== null) {
                $failureReasonsByItemState[$firstCandidate->item_state_id] = $evaluationFailure;

                // Only a reason the administrator has not already been told about earns an
                // email. A broken rule fails again on every single run, and mailing someone
                // every run about a fault they already know about is how notifications end up
                // in a folder nobody reads. Comparing against the stored reason still catches
                // a fault that returns after being fixed, because the column was cleared in
                // between, and still catches the same rule failing for a new reason.
                if ($evaluationFailure !== $firstCandidate->last_failure_reason) {
                    $failures[] = $this->notificationLine(
                        (int) $firstCandidate->item_id,
                        $evaluationFailure,
                        $this->itemEditLink($firstCandidate)
                    );
                }
            } elseif ($firstCandidate->last_failure_reason !== null) {
                // It read cleanly this time, so the stored fault is stale. Left in place it
                // would keep warning about a rule that has since been fixed.
                $recoveredItemStates[] = $firstCandidate->item_state_id;
            }

            if ($winningRule !== null) {
                $this->fireRule($winningRule, $app, $failures, $failureReasonsByItemState);
            }
        }

        // Cleared first, then recorded. An item can read cleanly and still fail to fire, in
        // which case fireRule() writes a reason for a row that is already on the clear list.
        // The other order wipes the reason a moment after writing it.
        $this->clearEvaluationFailures($recoveredItemStates);
        $this->recordEvaluationFailures($failureReasonsByItemState, $now);

        if (!empty($failures)) {
            $summary = Text::plural('PLG_TASK_WORKFLOWTRANSITION_N_TRANSITIONS_FAILED', \count($failures))
                . "\n" . implode("\n", $failures);
            $this->logTask($summary, 'error');
            $this->snapshot['output_body'] = $summary;

            return TaskStatus::KNOCKOUT;
        }

        return TaskStatus::OK;
    }

    /**
     * Fetches every (item, rule) pair this run could act on.
     *
     * Joins the item state, transition, and rule tables so each row carries both the item's
     * schedule data and a rule that could fire it. Deliberately not named for overdue-ness:
     * nothing here filters by time. Whether a rule is due is worked out live from entered_at,
     * because a stored deadline goes stale the moment a rule, an item, or a stage's automation
     * changes. Rows are taken least-recently-checked first and capped, so a large backlog is
     * worked through over successive runs rather than in one.
     *
     * @return  DueAutomation[]
     *
     * @since   __DEPLOY_VERSION__
     */
    private function fetchCandidates(): array
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
                $db->quoteName('wt.ordering'),
                $db->quoteName('wis.item_id'),
                $db->quoteName('wis.extension'),
                $db->quoteName('wis.id', 'item_state_id'),
                $db->quoteName('wis.entered_at'),
                $db->quoteName('war.run_as_user_id'),
                $db->quoteName('wis.last_failure_reason'),
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
            ->join(
                'INNER',
                $db->quoteName('#__workflows', 'w'),
                $db->quoteName('w.id') . ' = ' . $db->quoteName('wt.workflow_id')
            )

            ->where($db->quoteName('wis.requires_intervention') . ' = 0')
            // Every level has to be on: switching off a workflow, a transition or a single
            // rule should each stop the automation below it.
            ->where($db->quoteName('w.published') . ' = 1')
            ->where($db->quoteName('wt.published') . ' = 1')
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
            ->order($db->quoteName('wt.ordering') . ' ASC')
            ->setLimit(self::MAX_CANDIDATES_PER_RUN);

        $candidates = array_map(
            [DueAutomation::class, 'fromRow'],
            $db->setQuery($overduePairsQuery)->loadObjectList() ?: []
        );

        return $this->withoutTrashedOrArchived($candidates);
    }

    /**
     * Drops candidates whose item an editor has trashed or archived.
     *
     * This is done after the query rather than inside it because each extension keeps its
     * items on its own table, and one query cannot join a different table per row.
     * Filtering here instead costs one query per extension in the run, not one per item,
     * and it works for any extension rather than only com_content.
     *
     * @param DueAutomation[] $candidates Every candidate row the query returned.
     *
     * @returned DueAutomation[]
     *
     * @since __DEPLOY__VERSION__
     */
    private function withoutTrashedOrArchived(array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $itemIdsByExtension = [];

        foreach ($candidates as $candidate) {
            $itemIdsByExtension[$candidate->extension][] = (int) $candidate->item_id;
        }

        $itemStorage = new ItemStorage($this->getDatabase());
        $excluded    = [];

        foreach ($itemIdsByExtension as $extension => $itemIds) {
            foreach ($itemStorage->trashedOrArchivedIds($itemIds, $extension) as $itemId) {
                // Keyed by extension and id together because an item id is only unique
                // within its own extension.
                $excluded[$extension . '.' . $itemId] = true;
            }
        }

        if ($excluded === []) {
            return $candidates;
        }

        return array_values(array_filter(
            $candidates,
            static fn (DueAutomation $candidate): bool
            => !isset($excluded[$candidate->extension . '.' . $candidate->item_id])
        ));
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

        // insertObject() returns true or throws; there is no falsy failure to check for, which
        // is why the return was discarded. The catch is what actually matters here. This row is
        // a record of what happened, not part of making it happen, and letting a logging failure
        // escape into fireRule()'s catch would report a transition that fired perfectly well as
        // a failure, flag the item for intervention, and stop it being retried, all because an
        // audit row could not be written.
        try {
            $db->insertObject('#__workflow_automation_log', $row);
        } catch (\Throwable $error) {
            $this->logTask(
                'Could not write the automation log row for item ' . (int) $item->item_id . ': ' . $error->getMessage(),
                'warning'
            );
        }
    }

    /**
     * Builds an absolute backend edit link for the item, for use in notification.
     *
     * @param object $item The overdue (item, rule) pair.
     *
     * @return string Absolute URL, or empty string if no link can be built.
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

        $app  = Factory::getApplication();
        $base = (string) $app->get('live_site');

        // Uri::root() works the address out from the current HTTP request. A run started from
        // cron has no request, so asking it there raises a warning into the cron log and then
        // throws. Catching the exception is not enough on its own, because the warning is
        // emitted first and would still fill the log on every failing item.
        if ($base === '' && !$app->isClient('cli')) {
            $base = Uri::root();
        }

        if ($base === '') {
            // Site URL is empty by default in Joomla, so a cron-driven site lands here as a
            // matter of course rather than as an edge case. The link is a convenience in a
            // notification: the report goes out without one, and an administrator who wants
            // links in their notifications fills in Site URL in Global Configuration.
            return '';
        }

        return rtrim($base, '/') . '/administrator/index.php?option=' . $option
            . '&task=' . $type . '.edit&id=' . (int) $item->item_id;
    }

    /**
     * Builds one line of the run's failure report.
     *
     * The sentence structure is translated; the reason inside it is not. A reason is stored on
     * the item and compared on the next run to decide whether to notify again, so it has to be
     * the same string every time, whoever is logged in and whatever language the site runs in.
     * Translating it would make a change of site language look like a new fault and mail an
     * administrator about every affected item at once. Its core is usually an exception message
     * from the evaluator or a third-party check in any case, which no language file covers.
     *
     * @param   integer  $itemId  The content item id.
     * @param   string   $reason  Why the rule could not fire, as stored on the item.
     * @param   string   $link    Absolute edit link, or '' when none could be built.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private function notificationLine(int $itemId, string $reason, string $link): string
    {
        if ($link === '') {
            return Text::sprintf('PLG_TASK_WORKFLOWTRANSITION_FAILURE_LINE', $itemId, $reason);
        }

        return Text::sprintf('PLG_TASK_WORKFLOWTRANSITION_FAILURE_LINE_LINKED', $itemId, $reason, $link);
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
            ->bind(':now', $now, ParameterType::STRING);

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
     * Stores why these item states could not be evaluated, so the reason survives the run.
     *
     * Grouped by reason rather than written row by row. One misconfigured rule fails with the
     * same message for every item it covers, so the usual case is a single query no matter how
     * many items are affected, and the worst case is one query per distinct fault rather than
     * one per item.
     *
     * This is not the same thing as requires_intervention. That flag takes an item out of the
     * scheduler until a human clears it, and is set when a transition actually failed to run.
     * This is a note about a rule that could not be read: the item keeps being retried, and the
     * note clears itself as soon as the rule is fixed.
     *
     * @param string[] $reasonsByItemStateId  Reason text, keyed by #__workflow_item_state id.
     * @param string $now The run's timestamp, in SQL format.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    private function recordEvaluationFailures(array $reasonsByItemStateId, string $now): void
    {
        if ($reasonsByItemStateId === []) {
            return;
        }

        $itemStateIdsByReason = [];

        foreach ($reasonsByItemStateId as $itemStateId => $reason) {
            $itemStateIdsByReason[$reason][] = (int) $itemStateId;
        }

        $db = $this->getDatabase();

        foreach ($itemStateIdsByReason as $reason => $itemStateIds) {
            // Cast because PHP silently turns an array key that looks like a whole number into
            // an integer, and bind() expects the string it was given.
            $reasonText = (string) $reason;

            $updateQuery = $db->getQuery(true)
                ->update($db->quoteName('#__workflow_item_state'))
                ->set($db->quoteName('last_failure_at') . ' = :now')
                ->set($db->quoteName('last_failure_reason') . ' = :reason')
                ->whereIn($db->quoteName('id'), $itemStateIds)
                ->bind(':now', $now)
                ->bind(':reason', $reasonText);

            $db->setQuery($updateQuery)->execute();
        }
    }

    /**
     * Removes the stored failure note from item states that evaluated cleanly this run.
     *
     * Self-clearing is what separates this note from requires_intervention: fixing the rule is
     * enough, nobody has to go and dismiss anything. It is also what makes the notification
     * rule work, because a fault that returns after being cleared compares against null and so
     * counts as new.
     *
     * @param   integer[]  $itemStateIds  The #__workflow_item_state row ids to clear.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    private function clearEvaluationFailures(array $itemStateIds): void
    {
        if ($itemStateIds === []) {
            return;
        }

        $db          = $this->getDatabase();
        $updateQuery = $db->getQuery(true)
            ->update($db->quoteName('#__workflow_item_state'))
            ->set($db->quoteName('last_failure_at') . ' = NULL')
            ->set($db->quoteName('last_failure_reason') . ' = NULL')
            ->whereIn($db->quoteName('id'), $itemStateIds);

        $db->setQuery($updateQuery)->execute();
    }

    /**
     * Chooses which single rule, if any, should fire for one item this run.
     *
     * Considers every candidate rule for the item: it must be past its own deadline, its
     * filter must scope the item in, and its live condition must currently hold. The survivor
     * that came due soonest wins, with the administrator's ordering as the tiebreak. When
     * nothing fires there is nothing to record: the next run recomputes from entered_at and
     * reaches the same conclusion, or a different one if the rule or the item changed in the
     * meantime.
     *
     * @param   object[]            $itemCandidates      Candidate rows for a single item.
     * @param   \DateTime           $nowDateTime         Current time (UTC).
     * @param   ConditionEvaluator  $conditionEvaluator  The expression evaluator.
     * @param   ItemFieldResolver   $itemFieldResolver   The field resolver factory.
     * @param string|null $evaluationFailure Set by reference to why this item could
     * not be evaluated or left null if it could
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
        ?string &$evaluationFailure
    ): ?object {
        $firstCandidate = $itemCandidates[0];
        $fieldResolver  = $itemFieldResolver->forItem((int) $firstCandidate->item_id, $firstCandidate->extension);

        // The deadline is kept with each survivor because it is what decides the winner, and
        // recomputing it during the sort would run the cron parser once per comparison.
        $eligibleRules = [];

        foreach ($itemCandidates as $candidate) {
            $deadline = DeadlineCalculator::forRule($candidate->entered_at, $candidate);

            if ($deadline === null) {
                continue;
            }

            // Not due yet by this rule's own timing.
            if ($deadline > $nowDateTime) {
                continue;
            }

            try {
                if (!$conditionEvaluator->evaluate($candidate->item_filter, $fieldResolver)) {
                    continue;
                }

                // Due and in scope, but is the live condition satisfied right now?
                if (!$conditionEvaluator->evaluate($candidate->fire_condition, $fieldResolver)) {
                    continue;
                }
            } catch (ConditionEvaluationException $invalidCondition) {
                $evaluationFailure ??= mb_substr(
                    'Rule ' . $candidate->rule_id . ' could not be evaluated: ' . $invalidCondition->getMessage(),
                    0,
                    self::MAX_FAILURE_REASON_LENGTH
                );

                continue;
            }
            $eligibleRules[] = ['rule' => $candidate, 'deadline' => $deadline];
        }

        if (!empty($eligibleRules)) {
            usort(
                $eligibleRules,
                static fn (array $a, array $b): int => [$a['deadline'], (int) $a['rule']->ordering, (int) $a['rule']->rule_id]
                    <=> [$b['deadline'], (int) $b['rule']->ordering, (int) $b['rule']->rule_id]
            );

            return $eligibleRules[0]['rule'];
        }

        return null;
    }

    /**
     * Records one rule failure in all the places it has to appear.
     *
     * The same things happen every time a rule cannot fire, and keeping them together means a
     * new failure path cannot accidentally do only some of them: the run's report gains a line
     * so it reaches an administrator, the automation log gains a row so there is a history, and
     * the item's own state row gains the reason so the screens can explain the delay.
     *
     *
     * @param   DueAutomation  $rule            The rule that could not fire.
     * @param   string[]       $failures        Collected failure messages (by reference).
     * @param   string[]       $failureReasons  Reasons keyed by item state row id (by reference).
     * @param   string         $note            What went wrong, in plain words. Deliberately not
     * translated: it is stored, and stored text has to be stable. See notificationLine().
     * @param   integer        $exitCode        A #__workflow_automation_log exit code.
     * @param   boolean        $blockRetries    Whether to take the item out of the scheduler.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    private function recordRuleFailure(
        DueAutomation $rule,
        array &$failures,
        array &$failureReasons,
        string $note,
        int $exitCode = self::EXIT_REFUSED,
        bool $blockRetries = false
    ): void {
        $reason = mb_substr(
            'Rule ' . $rule->rule_id . ': ' . $note,
            0,
            self::MAX_FAILURE_REASON_LENGTH
        );

        $failureReasons[$rule->item_state_id] = $reason;

        if ($reason !== $rule->last_failure_reason) {
            $failures[] = $this->notificationLine((int) $rule->item_id, $reason, $this->itemEditLink($rule));

            $this->logAutomationRun($rule, $exitCode, $note);
        }

        if ($blockRetries) {
            $this->markRequiresIntervention($rule->item_state_id);
        }
    }

    /**
     * Executes the winning transition for an item, as its configured run-as user.
     *
     * @param   DueAutomation            $rule            The winning candidate row.
     * @param   CMSApplicationInterface  $app             The application, for the identity swap.
     * @param   string[]                 $failures        Collected failure messages (by reference).
     * @param   string[]                 $failureReasons  Reasons keyed by item state row id (by reference).
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    private function fireRule(
        DueAutomation $rule,
        CMSApplicationInterface $app,
        array &$failures,
        array &$failureReasons
    ): void {
        $transitionId = (int) $rule->transition_id;
        $runAsUserId  = $rule->run_as_user_id;
        $originalUser = $app->getIdentity();

        if ($runAsUserId <= 0) {
            $this->recordRuleFailure(
                $rule,
                $failures,
                $failureReasons,
                'This rule has no Run As user, so there is no identity to execute it with.'
            );

            return;
        }

        $runAsUser = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($runAsUserId);

        if ((int) $runAsUser->id !== $runAsUserId) {
            $this->recordRuleFailure(
                $rule,
                $failures,
                $failureReasons,
                'The Run As user (id ' . $runAsUserId . ') no longer exists.'
            );

            return;
        }

        try {
            $app->loadIdentity($runAsUser);

            $workflow = new Workflow($rule->extension);
            $outcome  = $workflow->attemptTransition([$rule->item_id], $transitionId, 'automation');

            if ($outcome === TransitionStatus::SUCCESS) {
                $this->logAutomationRun($rule, self::EXIT_OK);
            } elseif ($outcome === TransitionStatus::STAGE_MISMATCH) {
                // Reported by the same read that refused the transition, so an item moved by hand
                // mid-run cannot be mistaken for a fault and blocked from future retries.
                $this->logAutomationRun(
                    $rule,
                    self::EXIT_NO_LONGER_APPLICABLE,
                    'The item left this stage before the transition ran, so it no longer applies.'
                );
            } else {
                $this->recordRuleFailure(
                    $rule,
                    $failures,
                    $failureReasons,
                    match ($outcome) {
                        TransitionStatus::INVALID_TRANSITION => 'The transition no longer exists, or the Run As user may not execute it.',
                        TransitionStatus::STOPPED_BY_PLUGIN  => 'A plugin stopped this transition.',
                        TransitionStatus::UPDATE_FAILED      => 'The stage change could not be saved.',
                        default                              => 'The transition was refused.',
                    },
                    self::EXIT_REFUSED,
                    true
                );
            }
        } catch (\Throwable $error) {
            $this->recordRuleFailure($rule, $failures, $failureReasons, $error->getMessage(), self::EXIT_EXCEPTION, true);
        } finally {
            $app->loadIdentity($originalUser);
        }
    }
}
