<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_workflow
 *
 * @copyright (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Workflow\Administrator\Automation;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Evaluates a stored automation expression against one item's field values.
 *
 * Node shapes:
 *   check:      { "field": string, "operator": string, "value": mixed, "not": true? }
 *   expression: { "items": [ ...nodes ], "ops": [ "and"|"or", ... ], "not": true? }
 *
 * ops always holds exactly one connector fewer than items. Evaluation runs top to
 * bottom: the running result of the rows so far is combined with the next row
 * through the connector between them, so "A or B and C" means "(A or B) and C".
 * Nested expressions are the brackets.
 *
 * Field values are supplied by a resolver callback, so this class has no database
 * or Joomla dependency. An empty stored expression means "no restriction" and is
 * true; anything malformed throws a ConditionEvaluationException rather than
 * defaulting, so broken rules surface instead of silently passing or failing.
 *
 * @since  __DEPLOY_VERSION__
 */
final class ConditionEvaluator
{
    /**
     * Evaluates a stored expression in JSON form.
     *
     * @param   string|null  $expressionJson  The stored JSON tree, or null/empty for "no restriction".
     * @param   callable     $resolveField    fn(string $fieldName): mixed - the item's value for a field.
     *
     * @return  boolean
     *
     * @throws  ConditionEvaluationException  When the stored expression is malformed.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function evaluate(?string $expressionJson, callable $resolveField): bool
    {
        if ($expressionJson === null || trim($expressionJson) === '') {
            return true;
        }

        $decodedTree = json_decode($expressionJson, true);

        if (!\is_array($decodedTree)) {
            throw new ConditionEvaluationException('The stored condition is not valid JSON: ' . json_last_error_msg());
        }

        return $this->evaluateNode($decodedTree, $resolveField);
    }

    /**
     * Evaluates one already-decoded node. Public so unit tests can pass array trees directly.
     *
     * @param   array     $node          A check or expression node.
     * @param   callable  $resolveField  The field resolver.
     *
     * @return  boolean
     *
     * @throws  ConditionEvaluationException
     *
     * @since   __DEPLOY_VERSION__
     */
    public function evaluateNode(array $node, callable $resolveField): bool
    {
        if (\array_key_exists('items', $node)) {
            return $this->evaluateExpression($node, $resolveField);
        }

        if (\array_key_exists('field', $node)) {
            return $this->evaluateCheck($node, $resolveField);
        }

        if (\array_key_exists('op', $node) || \array_key_exists('children', $node)) {
            throw new ConditionEvaluationException(
                'The condition is stored in the retired group format; open the rule in the builder and save it again.'
            );
        }

        throw new ConditionEvaluationException('A condition node is neither a check nor an expression: ' . json_encode($node));
    }

    /**
     * Evaluates an expression: its rows combined top to bottom through the connectors.
     *
     * Every row is evaluated even when the running result is already decided, so a
     * malformed row always surfaces instead of hiding behind short-circuiting.
     *
     * @param   array     $expressionNode  The expression node.
     * @param   callable  $resolveField    The field resolver.
     *
     * @return  boolean
     *
     * @throws  ConditionEvaluationException
     *
     * @since   __DEPLOY_VERSION__
     */
    private function evaluateExpression(array $expressionNode, callable $resolveField): bool
    {
        $items      = $expressionNode['items'];
        $connectors = $expressionNode['ops'] ?? null;

        if (!\is_array($items) || $items === []) {
            throw new ConditionEvaluationException('An expression has no items; empty expressions must be pruned before saving.');
        }

        if (!\is_array($connectors) || \count($connectors) !== \count($items) - 1) {
            throw new ConditionEvaluationException(\sprintf(
                'An expression has %d items but %s connectors; expected exactly %d.',
                \count($items),
                \is_array($connectors) ? \count($connectors) : 'no',
                \count($items) - 1
            ));
        }

        $result = $this->evaluateNode($items[0], $resolveField);

        foreach ($connectors as $index => $connector) {
            $nextResult = $this->evaluateNode($items[$index + 1], $resolveField);

            $result = match ($connector) {
                'and'   => $result && $nextResult,
                'or'    => $result || $nextResult,
                default => throw new ConditionEvaluationException(
                    'Unknown connector "' . $connector . '" in an expression; expected "and" or "or".'
                ),
            };
        }

        return ($expressionNode['not'] ?? false) === true ? !$result : $result;
    }

    /**
     * Evaluates a single check by resolving the item's value and comparing it.
     *
     * @param   array     $checkNode     The check node.
     * @param   callable  $resolveField  The field resolver.
     *
     * @return  boolean
     *
     * @throws  ConditionEvaluationException
     *
     * @since   __DEPLOY_VERSION__
     */
    private function evaluateCheck(array $checkNode, callable $resolveField): bool
    {
        $fieldName    = (string) ($checkNode['field'] ?? '');
        $operatorName = strtolower(trim((string) ($checkNode['operator'] ?? '')));

        if ($fieldName === '') {
            throw new ConditionEvaluationException('A check has no field.');
        }

        if ($operatorName === '') {
            throw new ConditionEvaluationException('The check on "' . $fieldName . '" has no operator.');
        }

        if (!\array_key_exists('value', $checkNode)) {
            throw new ConditionEvaluationException('The check on "' . $fieldName . '" has no value.');
        }

        $result = $this->compare($resolveField($fieldName), $operatorName, $checkNode['value'], $fieldName);

        return ($checkNode['not'] ?? false) === true ? !$result : $result;
    }

    /**
     * Applies a single comparison operator.
     *
     * Types are compared like for like: a list is never silently narrowed to its
     * first element. Widening a single value into a one-element list (for the
     * membership operators) is allowed because it loses nothing.
     *
     * @param   mixed   $actualValue    The item's value.
     * @param   string  $operatorName   The comparison operator.
     * @param   mixed   $expectedValue  The value stored on the rule.
     * @param   string  $fieldName      The field name, for error messages.
     *
     * @return  boolean
     *
     * @throws  ConditionEvaluationException
     *
     * @since   __DEPLOY_VERSION__
     */
    private function compare($actualValue, string $operatorName, $expectedValue, string $fieldName): bool
    {
        switch ($operatorName) {
            case 'is':
                return $this->asScalarString($actualValue, $fieldName) === $this->asScalarString($expectedValue, $fieldName);

            case 'is not':
                return !$this->compare($actualValue, 'is', $expectedValue, $fieldName);

            case 'in':
                return \in_array($this->asScalarString($actualValue, $fieldName), $this->asStringList($expectedValue), true);

            case 'not in':
                return !$this->compare($actualValue, 'in', $expectedValue, $fieldName);

            case 'has any':
                return array_intersect($this->asStringList($actualValue), $this->asStringList($expectedValue)) !== [];

            case 'has all':
                return array_diff($this->asStringList($expectedValue), $this->asStringList($actualValue)) === [];

            case 'has none':
                return !$this->compare($actualValue, 'has any', $expectedValue, $fieldName);

            case 'before':
                return $this->toTimestamp($actualValue, $fieldName) < $this->toTimestamp($expectedValue, $fieldName);

            case 'after':
                return $this->toTimestamp($actualValue, $fieldName) > $this->toTimestamp($expectedValue, $fieldName);

            case 'on':
                // Dates compare at calendar-day granularity: the resolver returns a
                // full datetime while the form submits Y-m-d.
                return gmdate('Y-m-d', $this->toTimestamp($actualValue, $fieldName))
                    === gmdate('Y-m-d', $this->toTimestamp($expectedValue, $fieldName));

            case 'not on':
                return !$this->compare($actualValue, 'on', $expectedValue, $fieldName);

            case 'greater than':
                return $this->asNumber($actualValue, $fieldName) > $this->asNumber($expectedValue, $fieldName);

            case 'less than':
                return $this->asNumber($actualValue, $fieldName) < $this->asNumber($expectedValue, $fieldName);

            default:
                throw new ConditionEvaluationException('Unknown operator "' . $operatorName . '" on the "' . $fieldName . '" check.');
        }
    }

    /**
     * Asserts a value is a single scalar and returns it as a string.
     *
     * @param   mixed   $value      The value to check.
     * @param   string  $fieldName  The field name, for error messages.
     *
     * @return  string
     *
     * @throws  ConditionEvaluationException  When the value is a list or missing.
     *
     * @since   __DEPLOY_VERSION__
     */
    private function asScalarString($value, string $fieldName): string
    {
        if (\is_array($value)) {
            throw new ConditionEvaluationException(
                'The "' . $fieldName . '" check expected a single value but got a list; use a membership operator instead.'
            );
        }

        if ($value === null) {
            throw new ConditionEvaluationException('The "' . $fieldName . '" check has no value to compare.');
        }

        return (string) $value;
    }

    /**
     * Asserts a value is numeric and returns it as a float.
     *
     * Floats compare both whole and fractional numbers, and the loss of precision only
     * matters far beyond any quantity a content item carries.
     *
     * @param   mixed   $value      The value to check.
     * @param   string  $fieldName  The field name, for error messages.
     *
     * @return  float
     *
     * @throws  ConditionEvaluationException  When the value is not a number.
     *
     * @since   __DEPLOY_VERSION__
     */
    private function asNumber($value, string $fieldName): float
    {
        if (\is_array($value)) {
            throw new ConditionEvaluationException(
                'The "' . $fieldName . '" check expected a single number but got a list.'
            );
        }

        // Guarding here rather than casting keeps a check on a field that turned out to hold
        // text from quietly reading as zero and comparing true against every negative number.
        if (!is_numeric($value)) {
            throw new ConditionEvaluationException(
                'The "' . $fieldName . '" check expected a number but got "' . var_export($value, true) . '".'
            );
        }

        return (float) $value;
    }

    /**
     * Normalises a value into a list of strings. A single value widens to a
     * one-element list; an existing list keeps every element.
     *
     * @param   mixed  $value  A scalar or a list.
     *
     * @return  string[]
     *
     * @since   __DEPLOY_VERSION__
     */
    private function asStringList($value): array
    {
        return array_map('strval', array_values((array) $value));
    }

    /**
     * Parses a datetime string into a Unix timestamp, failing loudly on garbage.
     *
     * @param   mixed   $value      The datetime string.
     * @param   string  $fieldName  The field name, for error messages.
     *
     * @return  integer
     *
     * @throws  ConditionEvaluationException  When the value cannot be parsed.
     *
     * @since   __DEPLOY_VERSION__
     */
    private function toTimestamp($value, string $fieldName): int
    {
        if (\is_array($value)) {
            throw new ConditionEvaluationException('The "' . $fieldName . '" check expected a date but got a list.');
        }

        $parsedTimestamp = strtotime((string) $value);

        if ($parsedTimestamp === false) {
            throw new ConditionEvaluationException(
                'Cannot parse "' . $value . '" as a date/time on the "' . $fieldName . '" check.'
            );
        }

        return $parsedTimestamp;
    }
}
