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
 * Collects the checks that automation filters and conditions may be built on.
 *
 * Plugins answer this event by calling addField() once per check they provide, which is how
 * the condition builder learns what to offer. Core ships its own checks through a plugin that
 * answers this event too, so nothing is special-cased.
 *
 * A check declares whether it describes the item or the moment. Item checks (a tag, a
 * category) belong in a rule's filter, because they say which items a rule applies to. Moment
 * checks (the day of the week) belong in a rule's condition, because they say when a due rule
 * may run. Keeping them apart is what lets the upcoming-transitions views work out when a
 * gated rule will actually fire.
 *
 * @since  __DEPLOY_VERSION__
 */
class WorkflowConditionFieldsEvent extends Event
{
    /**
     * A check that describes the item, offered in a rule's filter.
     *
     * @var    string
     * @since  __DEPLOY_VERSION__
     */
    public const SCOPE_ITEM = 'item';

    /**
     * A check that describes the current moment, offered in a rule's condition.
     *
     * @var    string
     * @since  __DEPLOY_VERSION__
     */
    public const SCOPE_MOMENT = 'moment';

    /**
     * Constructor.
     *
     * @param   string  $name       The event name.
     * @param   array   $arguments  Must contain 'context', e.g. com_content.article.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct($name, array $arguments = [])
    {
        $arguments['fields'] = [];

        parent::__construct($name, $arguments);
    }

    /**
     * The workflow context the builder is being drawn for, e.g. com_content.article.
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
     * Offers one check to the condition builder.
     *
     * @param   string    $name       Unique key for the check, stored in the saved rule. Prefix
     *                                it with your extension to avoid clashing, e.g. "fields.priority".
     * @param   string    $label      Translated label shown in the field dropdown.
     * @param   string    $scope      self::SCOPE_ITEM or self::SCOPE_MOMENT.
     * @param   string[]  $operators  Operator keys this check supports, e.g. ['is', 'is not'].
     *                                Only keys com_workflow knows are offered, so a check written
     *                                against a newer Joomla loses that operator rather than
     *                                breaking the builder.
     * @param   string    $valueType  How the value is entered: select or multiselect to pick from
     *                                $options, or date, text or number to type a value freely.
     * @param   array     $options    Selectable values as ['value' => ..., 'label' => ...] pairs.
     *                                Ignored by the typed value types.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function addField(
        string $name,
        string $label,
        string $scope,
        array $operators,
        string $valueType = 'select',
        array $options = []
    ): void {
        $fields = $this->arguments['fields'];

        $fields[$name] = [
            'name'      => $name,
            'label'     => $label,
            'scope'     => $scope === self::SCOPE_MOMENT ? self::SCOPE_MOMENT : self::SCOPE_ITEM,
            'operators' => $operators,
            'valueType' => $valueType,
            'options'   => $options,
        ];

        $this->arguments['fields'] = $fields;
    }

    /**
     * Every check offered, keyed by name.
     *
     * @return  array<string, array>
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getFields(): array
    {
        return $this->arguments['fields'];
    }
}
