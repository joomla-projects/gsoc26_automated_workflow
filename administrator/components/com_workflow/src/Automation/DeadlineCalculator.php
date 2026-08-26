<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_workflow
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Workflow\Administrator\Automation;

use Cron\CronExpression;
use DateTime;
use Joomla\CMS\Factory;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Works out when an automation rule fires for a given stage-entry time.
 *
 * Shared by the scheduler task plugin (which asks "has this fired yet?") and the
 * com_workflow upcoming-transitions view (which asks "when will this fire?"), so both
 * compute the deadline the exact same way. Stateless and dependency-light: one static
 * entry point, no instance to build.
 *
 * @since  __DEPLOY_VERSION__
 */
final class DeadlineCalculator
{
    /**
     * Computes the datetime a rule fires for a given entry time.
     *
     * For a delay rule it adds the delay to the entry time. For a cron rule it returns the
     * next scheduled run on or after the entry time.
     *
     * Returns null whenever a deadline cannot be worked out, which covers an empty cron
     * expression, an unknown delay unit, and any stored value too malformed to parse. The
     * caller decides what that means: the scheduler skips the rule, the upcoming-transitions
     * views show the item as not scheduled.
     *
     * @param   string  $enteredAt  When the item entered the stage (SQL datetime, UTC).
     * @param   object  $rule       The rule row (rule_type, delay_value, delay_unit, cron_expression).
     *
     * @return  \DateTime|null  The fire time in UTC, or null if it cannot be computed.
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function forRule(string $enteredAt, object $rule): ?\DateTime
    {
        // Everything below parses values that came out of the database, and every one of them
        // throws on input it cannot make sense of: CronExpression on a malformed expression,
        // DateTime on a bad entry time, DateInterval on a negative or non-numeric delay. The
        // form validates a rule on save, but a row restored from a backup or written by SQL
        // never passed through it, and one bad row must not take down the page rendering an
        // article list or abandon a whole scheduler run part way through.
        try {
            if ($rule->rule_type === 'cron') {
                if (empty($rule->cron_expression)) {
                    return null;
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

            $date  = new \DateTime($enteredAt, new \DateTimeZone('UTC'));
            $delay = match ($rule->delay_unit) {
                'minutes' => new \DateInterval('PT' . $rule->delay_value . 'M'),
                'hours'   => new \DateInterval('PT' . $rule->delay_value . 'H'),
                'days'    => new \DateInterval('P' . $rule->delay_value . 'D'),
                'months'  => new \DateInterval('P' . $rule->delay_value . 'M'),
                default   => null,
            };

            return $delay ? $date->add($delay) : null;
        } catch (\Throwable) {
            // A rule this cannot read is a rule that never becomes due, so the item simply
            // stays where it is. Nothing is reported from here because this method knows only
            // the rule, not the item it would have moved. The caller is where both are in
            // scope, and so is the only place a failure can be attributed to something an
            // administrator can act on.
            return null;
        }
    }
}
