<?php

/**
 * @package Joomla.Administrator
 * @subpackage com_workflow
 *
 * @copyright (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Workflow\Administrator\Field;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Event\Workflow\WorkflowConditionFieldsEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Component\Workflow\Administrator\Automation\BuiltinConditionFields;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Event\DispatcherInterface;

/**
 * Renders one automation condition builder.
 *
 * A single instance drives either the filter or the condition, chosen by the
 * field's `mode` attribute: `filter` exposes item properties (tag, category,
 * author group), `condition` exposes moment properties (day of week, date). The
 * whole interface is built client side, so this class only outputs a hidden
 * input carrying the stored JSON expression tree plus a configuration blob
 * describing the fields, operators and value choices the builder may offer.
 *
 * @since  __DEPLOY_VERSION__
 */
class ConditionbuilderField extends FormField
{
    /**
     * The form field type.
     *
     * @var    string
     * @since  __DEPLOY_VERSION__
     */
    protected $type = 'Conditionbuilder';

    /**
     * Builds the field markup: a hidden input for the JSON value, plus a config
     * blob (scoped to this field's mode) that the client-side builder reads.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function getInput()
    {
        $document = Factory::getApplication()->getDocument();
        $document->getWebAssetManager()
            ->useStyle('com_workflow.condition-builder')
            ->useScript('com_workflow.condition-builder')
            ->usePreset('choicesjs')
            ->useScript('webcomponent.field-fancy-select');

        Text::script('JGLOBAL_SELECT_NO_RESULTS_MATCH');
        Text::script('JGLOBAL_SELECT_PRESS_TO_SELECT');

        $storedValue = $this->value;

        // The value round trips as a JSON string; normalise anything else back to one.
        if (\is_array($storedValue) || \is_object($storedValue)) {
            $storedValue = json_encode($storedValue);
        }

        $availableFields = $this->getAvailableFields();

        $configuration = json_encode([
            'fields'       => $this->getFieldChoices($availableFields),
            'operators'    => $this->getOperatorChoices($availableFields),
            'valueTypes'   => $this->getValueTypes($availableFields),
            'valueOptions' => $this->getValueChoices($availableFields),
            'text'         => $this->getInterfaceText(),
        ]);

        return '<div class="condition-builder" data-condition-builder data-config="'
            . htmlspecialchars($configuration, ENT_QUOTES, 'UTF-8') . '">'
            . '<input type="hidden" name="' . $this->name . '" id="' . $this->id
            . '" value="' . htmlspecialchars((string) $storedValue, ENT_QUOTES, 'UTF-8') . '">'
            . '</div>';
    }

    /**
     * The checks that may be offered here.
     *
     * Joomla's own checks come from BuiltinConditionFields directly. The event is then
     * dispatched so installed extensions can add their own, and the two lists are
     * merged with the built-in ones taking precedence.
     *
     * @return array<string, array> Check definitions keyed by name.
     *
     * @since __DEPLOY_VERSION__
     */
    private function getAvailableFields(): array
    {
        $app       = Factory::getApplication();
        $extension = $this->resolveExtension($app);
        $database  = Factory::getContainer()->get(DatabaseInterface::class);

        $builtInFields = (new BuiltinConditionFields($database))->declarations($extension);

        PluginHelper::importPlugin('workflow');

        $event = new WorkflowConditionFieldsEvent(
            'onWorkflowListConditionFields',
            ['extension' => $extension]
        );

        // From the container, not $app->getDispatcher(): that lives on EventAwareInterface,
        // which is part of the 3.x compatibility layer and goes away in 7.0.
        Factory::getContainer()->get(DispatcherInterface::class)->dispatch($event->getName(), $event);

        // Union rather than array_merge: where both declare the same name, the left
        // operand's entry survives, so a built-in cannot be shadowed by an installed extension.
        $fields = $builtInFields + $event->getFields();

        // A filter says which items a rule covers, so it offers item checks. A
        // condition says when a due rule may run, so it offers moment checks.
        $wantedScope = (string) $this->element['mode'] === 'filter'
            ? WorkflowConditionFieldsEvent::SCOPE_ITEM
            : WorkflowConditionFieldsEvent::SCOPE_MOMENT;

        return array_filter(
            $fields,
            static fn (array $field): bool => $field['scope'] === $wantedScope
        );
    }

    /**
     * Works out which workflow extension the builder is being drawn for.
     *
     * The request usually carries it, but a link can arrive with the parameter empty, so fall
     * back to the workflow that owns this transition before giving up on a sensible default.
     *
     * @param   \Joomla\CMS\Application\CMSApplicationInterface  $app  The application.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private function resolveExtension($app): string
    {
        // The workflow row carries the full extension including the section, e.g.
        // com_content.article. The request only carries the component part, because
        // com_workflow splits the two, so prefer the stored value.
        $workflowId = (int) $app->getInput()->getInt('workflow_id');

        if ($workflowId > 0) {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select($db->quoteName('extension'))
                ->from($db->quoteName('#__workflows'))
                ->where($db->quoteName('id') . ' = :workflowId')
                ->bind(':workflowId', $workflowId, ParameterType::INTEGER);

            $extension = (string) $db->setQuery($query)->loadResult();

            if ($extension !== '') {
                return $extension;
            }
        }

        $requestedExtension = (string) $app->getInput()->getCmd('extension', '');

        return $requestedExtension !== '' ? $requestedExtension : 'com_content.article';
    }

    /**
     * The checks the builder may offer, as value and label pairs.
     *
     * @param   array  $fields  The available check definitions.
     *
     * @return  array<int, array<string, string>>
     *
     * @since   __DEPLOY_VERSION__
     */
    private function getFieldChoices(array $fields): array
    {
        $choices = [];

        foreach ($fields as $field) {
            $choices[] = ['value' => $field['name'], 'label' => $field['label']];
        }

        return $choices;
    }

    /**
     * The operators each check supports.
     *
     * A plugin names the operators it wants but never implements them: the meaning of every
     * operator stays here and in the evaluator, so the same comparison behaves identically
     * whichever extension supplied the check.
     *
     * @param   array  $fields  The available check definitions.
     *
     * @return  array<string, array<int, array<string, string>>>
     *
     * @since   __DEPLOY_VERSION__
     */
    private function getOperatorChoices(array $fields): array
    {
        $operatorLabels = [
            WorkflowConditionFieldsEvent::OPERATOR_IS           => Text::_('COM_WORKFLOW_AUTOMATION_OP_IS'),
            WorkflowConditionFieldsEvent::OPERATOR_IS_NOT       => Text::_('COM_WORKFLOW_AUTOMATION_OP_IS_NOT'),
            WorkflowConditionFieldsEvent::OPERATOR_IN           => Text::_('COM_WORKFLOW_AUTOMATION_OP_IN'),
            WorkflowConditionFieldsEvent::OPERATOR_NOT_IN       => Text::_('COM_WORKFLOW_AUTOMATION_OP_NOT_IN'),
            WorkflowConditionFieldsEvent::OPERATOR_HAS_ANY      => Text::_('COM_WORKFLOW_AUTOMATION_OP_HAS_ANY'),
            WorkflowConditionFieldsEvent::OPERATOR_HAS_ALL      => Text::_('COM_WORKFLOW_AUTOMATION_OP_HAS_ALL'),
            WorkflowConditionFieldsEvent::OPERATOR_HAS_NONE     => Text::_('COM_WORKFLOW_AUTOMATION_OP_HAS_NONE'),
            WorkflowConditionFieldsEvent::OPERATOR_BEFORE       => Text::_('COM_WORKFLOW_AUTOMATION_OP_BEFORE'),
            WorkflowConditionFieldsEvent::OPERATOR_AFTER        => Text::_('COM_WORKFLOW_AUTOMATION_OP_AFTER'),
            WorkflowConditionFieldsEvent::OPERATOR_ON           => Text::_('COM_WORKFLOW_AUTOMATION_OP_ON'),
            WorkflowConditionFieldsEvent::OPERATOR_NOT_ON       => Text::_('COM_WORKFLOW_AUTOMATION_OP_NOT_ON'),
            WorkflowConditionFieldsEvent::OPERATOR_GREATER_THAN => Text::_('COM_WORKFLOW_AUTOMATION_OP_GREATER_THAN'),
            WorkflowConditionFieldsEvent::OPERATOR_LESS_THAN    => Text::_('COM_WORKFLOW_AUTOMATION_OP_LESS_THAN'),
        ];

        $choices = [];

        foreach ($fields as $field) {
            foreach ($field['operators'] as $operator) {
                // Silently skip an operator this build does not know, so a plugin written
                // against a newer Joomla degrades rather than breaking the whole builder.
                if (!isset($operatorLabels[$operator])) {
                    continue;
                }

                $choices[$field['name']][] = ['value' => $operator, 'label' => $operatorLabels[$operator]];
            }
        }

        return $choices;
    }

    /**
     * How each check's value is entered.
     *
     * @param   array  $fields  The available check definitions.
     *
     * @return  array<string, string>
     *
     * @since   __DEPLOY_VERSION__
     */
    private function getValueTypes(array $fields): array
    {
        $types = [];

        foreach ($fields as $field) {
            $types[$field['name']] = $field['valueType'];
        }

        return $types;
    }

    /**
     * The selectable values for each check, resolved once here so the builder needs no
     * further requests.
     *
     * @param   array  $fields  The available check definitions.
     *
     * @return  array<string, array<int, array<string, string>>>
     *
     * @since   __DEPLOY_VERSION__
     */
    private function getValueChoices(array $fields): array
    {
        $choices = [];

        foreach ($fields as $field) {
            if ($field['options'] !== []) {
                $choices[$field['name']] = $field['options'];
            }
        }

        return $choices;
    }

    /**
     * Translated strings the builder renders in its own markup.
     *
     * @return  array<string, string>
     *
     * @since   __DEPLOY_VERSION__
     */
    private function getInterfaceText(): array
    {
        $emptyKey = (string) $this->element['mode'] === 'filter'
            ? 'COM_WORKFLOW_AUTOMATION_BUILDER_EMPTY_FILTER'
            : 'COM_WORKFLOW_AUTOMATION_BUILDER_EMPTY_CONDITION';
        return [
            'addCheck'        => Text::_('COM_WORKFLOW_AUTOMATION_BUILDER_ADD_CHECK'),
            'addExpression'   => Text::_('COM_WORKFLOW_AUTOMATION_BUILDER_ADD_EXPRESSION'),
            'remove'          => Text::_('COM_WORKFLOW_AUTOMATION_BUILDER_REMOVE'),
            'check'           => Text::_('COM_WORKFLOW_AUTOMATION_BUILDER_CHECK'),
            'expression'      => Text::_('COM_WORKFLOW_AUTOMATION_BUILDER_EXPRESSION'),
            'negate'          => Text::_('COM_WORKFLOW_AUTOMATION_BUILDER_NEGATE'),
            'joinWith'        => Text::_('COM_WORKFLOW_AUTOMATION_BUILDER_JOIN_WITH'),
            'opAnd'           => Text::_('COM_WORKFLOW_AUTOMATION_BUILDER_OP_AND'),
            'opOr'            => Text::_('COM_WORKFLOW_AUTOMATION_BUILDER_OP_OR'),
            'cancel'          => Text::_('JCANCEL'),
            'empty'           => Text::_($emptyKey),
            'placeholder'     => Text::_('JGLOBAL_TYPE_OR_SELECT_SOME_OPTIONS'),
            'emptyExpression' => Text::_('COM_WORKFLOW_AUTOMATION_BUILDER_EMPTY_EXPRESSION'),
        ];
    }
}
