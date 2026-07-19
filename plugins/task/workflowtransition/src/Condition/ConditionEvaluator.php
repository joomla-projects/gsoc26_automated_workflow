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
 * Evaluates a recursive boolean expression tree used by automation filters and conditions
 *
 * Field values are supplied by a resolver callback, so this class has no database or
 * Joomla dependency and can be unit-tested on its own.
 *
 * Node shapes:
 * group: { "op": "and" | "or" | "not", "children": [ ...nodes ] }
 * leaf: { "field": string, "operator": string, "value": mixed }
 *
 * @since __DEPLOY_VERSION__
 */
final class ConditionEvaluator
{
    /**
     * Evaluates a stored expression free in JSON form.
     *
     * An empty tree means "no restriction" and returns true. A malformed tree fails
     * closed (returns false) so a broken filter never scopes in unintended items and a
     * broken condition never fires unexpectedly.
     *
     * @param string|null $expressionJson The stored JSON tree, or null/empty.
     * @param callable $resolveField fn(string $fieldName): mixed - the item's value for a field
     *
     * @return boolean
     *
     * @since __DEPLOY_VERSION__
     */

    public function evaluate(?string $expressionJson, callable $resolveField): bool
    {
        if ($expressionJson === null || trim($expressionJson) === '') {
            return true;
        }

        $decodedTree = json_decode($expressionJson, true);
        if (!\is_array($decodedTree)) {
            return false;
        }

        return $this->evaluateNode($decodedTree, $resolveField);
    }

    /**
     * Evaluate a single already-decoded node. Public so unit tests can pass array trees
     * directly
     *
     * @param array $node A group or leaf node.
     * @param callable $resolveField The field resolver.
     *
     * @return boolean
     *
     * @since __DEPLOY_VERSION__
     */
    public function evaluateNode(array $node, callable $resolveField): bool
    {
        // A node with an "op" key is a group that combines its children.
        if (isset($node['op'])) {
            $childNodes = $node['children'] ?? [];

            switch (strtolower((string) $node['op'])) {
                case 'and':
                    foreach ($childNodes as $childNode) {
                        if (!$this->evaluateNode($childNode, $resolveField)) {
                            return false;
                        }
                    }

                    return true;

                case 'or':
                    foreach ($childNodes as $childNode) {
                        if ($this->evaluateNode($childNode, $resolveField)) {
                            return true;
                        }
                    }

                    return false;

                case 'not':
                    foreach ($childNodes as $childNode) {
                        if ($this->evaluateNode($childNode, $resolveField)) {
                            return false;
                        }
                    }

                    return true;

                default:
                    return false;
            }
        }
        // Otherwise it is a leaf: a single field/operator/value comparison.
        return $this->evaluateLeaf($node, $resolveField);
    }

    /**
     * Evaluates a leaf node by resolving the item's value for the field and comparing it
     *
     * @param array $leafNode A leaf node.
     * @param callable $resolveField  The field resolver.
     *
     * @return boolean
     *
     * @since __DEPLOY_VERSION__
     */
    private function evaluateLeaf(array $leafNode, callable $resolveField): bool
    {
        $fieldName = (string) ($leafNode['field'] ?? '');

        if ($fieldName === '') {
            return false;
        }

        $operatorName  = strtolower((string) ($leafNode['operator'] ?? ''));
        $expectedValue = $leafNode['value'] ?? null;
        $actualValue   = $resolveField($fieldName);

        return $this->compare($actualValue, $operatorName, $expectedValue);
    }

    /**
     * Applies a single comparison operator.
     *
     * @param mixed $actualValue The item's value (may be scalar or a list, e.g. tags).
     * @param string $operatorName One of: is, is not, in, not in, has, not has, before,
     * after.
     * @param mixed $expectedValue The value stored on the rule.
     *
     * @return boolean
     *
     * @since __DEPLOY_VERSION__
     */
    private function compare($actualValue, string $operatorName, $expectedValue): bool
    {
        switch ($operatorName) {
            case 'is':
            case 'eq':
                return (string) $this->firstScalar($actualValue) === (string) $this->firstScalar($expectedValue);

            case 'is not':
            case 'neq':
                return (string) $this->firstScalar($actualValue) !== (string) $this->firstScalar($expectedValue);

            case 'in':
                return \in_array((string) $this->firstScalar($actualValue), $this->toStringList($expectedValue), true);

            case 'not in':
                return !\in_array((string) $this->firstScalar($actualValue), $this->toStringList($expectedValue), true);

            case 'has':
                // the item's value is a list (tags, groups); true if it contains any wanted value.
                $actualValues = $this->toStringList($actualValue);

                foreach ($this->toStringList($expectedValue) as $wantedValue) {
                    if (\in_array($wantedValue, $actualValues, true)) {
                        return true;
                    }
                }

                return false;
            case 'not has':
                // Negation of "has": the list must contain none of the wanted values.
                return !$this->compare($actualValue, 'has', $expectedValue);

            case 'before':
                return $this->toTimestamp($actualValue) < $this->toTimestamp($expectedValue);

            case 'after':
                return $this->toTimestamp($actualValue) > $this->toTimestamp($expectedValue);

            default:
                return false;
        }
    }

    /**
     * Returns the first element if given a list, otherwise the value itself.
     * Lets scalar operators (is/in) work even if a resolver hands back a single-item array
     *
     * @param mixed $value A scalar or a list.
     *
     * @return mixed
     *
     * @since __DEPLOY_VERSION__
     */
    private function firstScalar($value)
    {
        return \is_array($value) ? reset($value) : $value;
    }

    /**
     * Normalizes any value into a list of strings, so membership checks compare like with like
     *
     * @param mixed $value A scalar or a list.
     *
     * @return string[]
     *
     * @since __DEPLOY_VERSION__
     */
    private function toStringList($value): array
    {
        return array_map('strval', array_values((array) $value));
    }

    /**
     * Converts a date string or numeric timestamp into a Unix timestamp for date comparisons.
     *
     * @param mixed $value A datetime string or numeric timestamp.
     *
     * @return integer
     *
     * @since __DEPLOY_VERSION__
     */
    private function toTimestamp($value): int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        $parsedTimestamp = strtotime((string) $value);

        return $parsedTimestamp === false ? 0 : $parsedTimestamp;
    }
}
