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

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Works out when a rule's fire condition next opens its execution window.
 *
 * A delay or cron rule decides when a transition becomes *due*; its fire condition decides
 * when a due transition is *allowed* to run. So the moment a gated transition really fires is
 * the first moment, at or after the deadline, at which the condition holds. This class finds
 * that moment by asking the condition how it would evaluate at successive candidate times.
 *
 * That search is only tractable because fire conditions are restricted to moment properties
 * (day of week, date): they depend on the clock and nothing else, so the same item gives a
 * different answer purely as time passes. Item properties live in the rule's filter instead.
 *
 * Both moment fields change at most once per calendar day, so after testing the deadline
 * itself the search steps one UTC day at a time, and gives up after a year. Giving up is
 * reported as "no answer" rather than a guess: a condition such as a fixed past date can
 * never open again, and pretending otherwise would show a fire time that never arrives.
 *
 * @since  __DEPLOY_VERSION__
 */
final class ConditionWindowCalculator
{
    /**
     * How far ahead to search before reporting that the window never opens. A year covers
     * weekly and annual patterns while keeping the worst case bounded.
     *
     * @var    integer
     * @since  __DEPLOY_VERSION__
     */
    private const HORIZON_DAYS = 366;

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
        $this->conditionEvaluator = new ConditionEvaluator();
        $this->itemFieldResolver  = new ItemFieldResolver($database);
    }

    /**
     * Returns the first moment at or after $earliestExecutionTime at which the condition holds.
     *
     * @param   string|null  $conditionJson  The stored fire condition, or null/empty for none.
     * @param   \DateTime    $earliestExecutionTime      The deadline to start searching from (UTC).
     * @param   integer      $itemId         The content item id.
     * @param   string       $extension      The workflow extension.
     *
     * @return  \DateTime|null  The moment the rule may fire, or null if the window never opens
     *                          within the search horizon.
     *
     * @throws  ConditionEvaluationException  When the stored condition is malformed.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function firstMatchAtOrAfter(
        ?string $conditionJson,
        \DateTime $earliestExecutionTime,
        int $itemId,
        string $extension
    ): ?\DateTime {
        // No condition means no gate: the rule may fire the moment it is due.
        if ($conditionJson === null || trim($conditionJson) === '') {
            return $earliestExecutionTime;
        }

        if ($this->holdsAt($conditionJson, $earliestExecutionTime, $itemId, $extension)) {
            return $earliestExecutionTime;
        }

        // The deadline itself is blocked, so try the start of each following day. Midnight is
        // the earliest moment of any day that qualifies, which keeps the answer the earliest
        // correct one at day granularity.
        $candidate = (clone $earliestExecutionTime)->setTime(0, 0, 0)->modify('+1 day');

        for ($dayOffset = 0; $dayOffset < self::HORIZON_DAYS; $dayOffset++) {
            if ($this->holdsAt($conditionJson, $candidate, $itemId, $extension)) {
                return clone $candidate;
            }

            $candidate->modify('+1 day');
        }

        return null;
    }

    /**
     * Whether the condition would hold for this item at a given moment.
     *
     * @param   string     $conditionJson   The stored fire condition.
     * @param   \DateTime  $evaluationTime  The moment to test.
     * @param   integer    $itemId          The content item id.
     * @param   string     $extension       The workflow extension.
     *
     * @return  boolean
     *
     * @throws  ConditionEvaluationException
     *
     * @since   __DEPLOY_VERSION__
     */
    private function holdsAt(string $conditionJson, \DateTime $evaluationTime, int $itemId, string $extension): bool
    {
        return $this->conditionEvaluator->evaluate(
            $conditionJson,
            $this->itemFieldResolver->forItem($itemId, $extension, $evaluationTime)
        );
    }
}
