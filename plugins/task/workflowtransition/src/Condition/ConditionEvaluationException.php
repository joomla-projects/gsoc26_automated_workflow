<?php

/**
 * @package Joomla.Plugin
 * @subpackage Task.WorkflowTransition
 *
 * @copyright (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Task\WorkflowTransition\Condition;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Thrown when a stored automation condition cannot be evaluated.
 *
 * Raised for malformed JSON, unknown fields or operators, type mismatches, and
 * structurally invalid expressions. Callers catch this where an item and rule are
 * in scope, so the failure is logged with enough context to fix the rule, instead
 * of being silently swallowed as true or false.
 *
 * @since  __DEPLOY_VERSION__
 */
final class ConditionEvaluationException extends \RuntimeException
{
}
