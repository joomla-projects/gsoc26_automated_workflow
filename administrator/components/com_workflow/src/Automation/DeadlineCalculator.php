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
     * next scheduled run on or after the entry time. Returns null when the rule cannot be
     * computed: an empty cron expression, or an unknown delay unit.
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
    }
}
