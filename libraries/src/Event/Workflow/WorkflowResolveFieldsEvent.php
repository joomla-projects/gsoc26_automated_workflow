<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Event\Workflow;

use Joomla\Event\Event;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Asks plugins for the value of one check across a set of items.
 *
 * Deliberately batched: a whole scheduler run resolves a check once for every item it is
 * evaluating, so a plugin answers with a map rather than being called per item. A per-item
 * contract would make one database query per item unavoidable for every plugin ever written,
 * which is the exact cost this design exists to prevent.
 *
 * A moment check does not depend on the item at all, so it answers with the same value for
 * every id, computed at the event's evaluation time rather than at "now". That is what lets
 * the upcoming-transitions views ask "would this condition hold next Tuesday?".
 *
 * @since  __DEPLOY_VERSION__
 */
class WorkflowResolveFieldsEvent extends Event
{
    /**
     * Constructor.
     *
     * @param   string  $name       The event name.
     * @param   array   $arguments  Must contain 'field', 'itemIds', 'context' and may contain
     *                              'evaluationTime'.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct($name, array $arguments = [])
    {
        $arguments['values']   = [];
        $arguments['answered'] = false;

        parent::__construct($name, $arguments);
    }

    /**
     * The check being resolved, as named by addField().
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getField(): string
    {
        return (string) ($this->arguments['field'] ?? '');
    }

    /**
     * The items to resolve it for.
     *
     * @return  int[]
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getItemIds(): array
    {
        return (array) ($this->arguments['itemIds'] ?? []);
    }

    /**
     * The workflow context, e.g. com_content.article.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getContext(): string
    {
        return (string) ($this->arguments['context'] ?? '');
    }

    /**
     * The moment to describe, for checks that depend on the clock. Null means now.
     *
     * @return  \DateTime|null
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getEvaluationTime(): ?\DateTime
    {
        return $this->arguments['evaluationTime'] ?? null;
    }

    /**
     * Supplies the resolved values, and claims the check.
     *
     * Call this even with an empty array when the check is yours. That is what separates
     * "this check belongs to me and I could not answer for those items" from "nobody here
     * knows this check", which the two cases are reported very differently for.
     *
     * Omitting an item is allowed and means the check cannot be evaluated for it: no rule
     * will fire on that item, rather than a comparison being made against a guess. A source
     * that is temporarily unreachable should omit rather than substitute a default.
     *
     * @param   array  $values  Item id to value. A value may be a scalar or a list, depending
     *                          on the check.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function setValues(array $values): void
    {
        $this->arguments['values']   = $values + $this->arguments['values'];
        $this->arguments['answered'] = true;

        $this->stopPropagation();
    }

    /**
     * Whether any plugin claimed this check, regardless of how many items it answered for.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    public function isAnswered(): bool
    {
        return $this->arguments['answered'] === true;
    }

    /**
     * The resolved values, keyed by item id.
     *
     * @return  array<int, mixed>
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getValues(): array
    {
        return $this->arguments['values'];
    }
}
