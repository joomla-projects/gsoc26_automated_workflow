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

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Helper\UserGroupsHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;

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

        $configuration = json_encode([
            'fields'       => $this->getFieldChoices(),
            'operators'    => $this->getOperatorChoices(),
            'valueTypes'   => $this->getValueTypes(),
            'valueOptions' => $this->getValueChoices(),
            'text'         => $this->getInterfaceText(),
        ]);

        return '<div class="condition-builder" data-condition-builder data-config="'
            . htmlspecialchars($configuration, ENT_QUOTES, 'UTF-8') . '">'
            . '<input type="hidden" name="' . $this->name . '" id="' . $this->id
            . '" value="' . htmlspecialchars((string) $storedValue, ENT_QUOTES, 'UTF-8') . '">'
            . '</div>';
    }

    private function getValueTypes(): array
    {
        $all = [
            'day_of_week'  => 'multiselect',
            'date'         => 'date',
            'tag'          => 'multiselect',
            'category'     => 'select',
            'author_group' => 'select',
        ];

        return array_intersect_key($all, array_flip($this->allowedFields()));
    }

    /**
     * The properties conditions or filters may be built on.
     *
     * @return  array<int, array<string, string>>
     *
     * @since   __DEPLOY_VERSION__
     */
    private function getFieldChoices(): array
    {
        $labels = [
            'day_of_week'  => Text::_('COM_WORKFLOW_AUTOMATION_FIELD_DAY_OF_WEEK'),
            'date'         => Text::_('COM_WORKFLOW_AUTOMATION_FIELD_DATE'),
            'tag'          => Text::_('COM_WORKFLOW_AUTOMATION_FIELD_TAG'),
            'category'     => Text::_('COM_WORKFLOW_AUTOMATION_FIELD_CATEGORY'),
            'author_group' => Text::_('COM_WORKFLOW_AUTOMATION_FIELD_AUTHOR_GROUP'),
        ];

        $choices = [];

        foreach ($this->allowedFields() as $field) {
            $choices[] = ['value' => $field, 'label' => $labels[$field]];
        }

        return $choices;
    }

    /**
     * The operators each field may use. List valued fields get membership
     * operators; scalar and date fields get their own comparisons.
     *
     * @return  array<string, array<int, array<string, string>>>
     *
     * @since   __DEPLOY_VERSION__
     */
    private function getOperatorChoices(): array
    {
        $operatorLabels = [
            'is'      => Text::_('COM_WORKFLOW_AUTOMATION_OP_IS'),
            'is not'  => Text::_('COM_WORKFLOW_AUTOMATION_OP_IS_NOT'),
            'in'      => Text::_('COM_WORKFLOW_AUTOMATION_OP_IN'),
            'not in'  => Text::_('COM_WORKFLOW_AUTOMATION_OP_NOT_IN'),
            'has'     => Text::_('COM_WORKFLOW_AUTOMATION_OP_HAS'),
            'not has' => Text::_('COM_WORKFLOW_AUTOMATION_OP_NOT_HAS'),
            'before'  => Text::_('COM_WORKFLOW_AUTOMATION_OP_BEFORE'),
            'after'   => Text::_('COM_WORKFLOW_AUTOMATION_OP_AFTER'),
            'on'      => Text::_('COM_WORKFLOW_AUTOMATION_OP_ON'),
            'not on'  => Text::_('COM_WORKFLOW_AUTOMATION_OP_NOT_ON'),
        ];

        $operatorsByField = [
            'day_of_week'  => ['in', 'not in'],
            'date'         => ['after', 'before', 'on', 'not on'],
            'tag'          => ['has', 'not has'],
            'category'     => ['is', 'is not'],
            'author_group' => ['has', 'not has'],
        ];

        $allowed = $this->allowedFields();
        $choices = [];

        foreach ($operatorsByField as $fieldName => $operators) {
            if (!\in_array($fieldName, $allowed, true)) {
                continue;
            }

            foreach ($operators as $operator) {
                $choices[$fieldName][] = ['value' => $operator, 'label' => $operatorLabels[$operator]];
            }
        }

        return $choices;
    }

    /**
     * The selectable values for each field, resolved once here so the builder
     * needs no further requests.
     *
     * @return  array<string, array<int, array<string, string>>>
     *
     * @since   __DEPLOY_VERSION__
     */
    private function getValueChoices(): array
    {
        $allowed = $this->allowedFields();
        $choices = [];

        if (\in_array('day_of_week', $allowed, true)) {
            $choices['day_of_week'] = $this->getWeekdayChoices();
        }
        if (\in_array('tag', $allowed, true)) {
            $choices['tag'] = $this->getTagChoices();
        }
        if (\in_array('category', $allowed, true)) {
            $choices['category'] = $this->getCategoryChoices();
        }
        if (\in_array('author_group', $allowed, true)) {
            $choices['author_group'] = $this->getUserGroupChoices();
        }

        return $choices;
    }

    /**
     * Weekday options, Sunday (0) through Saturday (6).
     *
     * @return  array<int, array<string, string>>
     * @since   __DEPLOY_VERSION__
     */
    private function getWeekdayChoices(): array
    {
        $weekdayKeys = ['SUNDAY', 'MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY'];
        $choices     = [];

        foreach ($weekdayKeys as $dayNumber => $languageKey) {
            $choices[] = ['value' => (string) $dayNumber, 'label' => Text::_($languageKey)];
        }

        return $choices;
    }

    /**
     * Published content tags.
     *
     * @return  array<int, array<string, string>>
     * @since   __DEPLOY_VERSION__
     */
    private function getTagChoices(): array
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'title']))
            ->from($db->quoteName('#__tags'))
            ->where($db->quoteName('published') . ' = 1')
            ->where($db->quoteName('id') . ' > 1')
            ->order($db->quoteName('title') . ' ASC');

        $choices = [];

        foreach ($db->setQuery($query)->loadObjectList() ?: [] as $tag) {
            $choices[] = ['value' => (string) $tag->id, 'label' => $tag->title];
        }

        return $choices;
    }

    /**
     * com_content category options.
     *
     * @return  array<int, array<string, string>>
     * @since   __DEPLOY_VERSION__
     */
    private function getCategoryChoices(): array
    {
        $choices = [];

        foreach (HTMLHelper::_('category.options', 'com_content') as $category) {
            $choices[] = ['value' => (string) $category->value, 'label' => $category->text];
        }

        return $choices;
    }

    /**
     * All user groups.
     *
     * @return  array<int, array<string, string>>
     * @since   __DEPLOY_VERSION__
     */
    private function getUserGroupChoices(): array
    {
        $choices = [];

        foreach (UserGroupsHelper::getInstance()->getAll() as $group) {
            $choices[] = ['value' => (string) $group->id, 'label' => $group->title];
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
            'addCheck'    => Text::_('COM_WORKFLOW_AUTOMATION_BUILDER_ADD_CHECK'),
            'addGroup'    => Text::_('COM_WORKFLOW_AUTOMATION_BUILDER_ADD_GROUP'),
            'remove'      => Text::_('COM_WORKFLOW_AUTOMATION_BUILDER_REMOVE'),
            'check'       => Text::_('COM_WORKFLOW_AUTOMATION_BUILDER_CHECK'),
            'group'       => Text::_('COM_WORKFLOW_AUTOMATION_BUILDER_GROUP'),
            'negate'      => Text::_('COM_WORKFLOW_AUTOMATION_BUILDER_NEGATE'),
            'match'       => Text::_('COM_WORKFLOW_AUTOMATION_BUILDER_MATCH'),
            'matchAll'    => Text::_('COM_WORKFLOW_AUTOMATION_MATCH_ALL'),
            'matchAny'    => Text::_('COM_WORKFLOW_AUTOMATION_MATCH_ANY'),
            'empty'       => Text::_($emptyKey),
            'placeholder' => Text::_('JGLOBAL_TYPE_OR_SELECT_SOME_OPTIONS'),
            'matchChoose' => Text::_('COM_WORKFLOW_AUTOMATION_BUILDER_MATCH_CHOOSE'),
            'emptyGroup'  => Text::_('COM_WORKFLOW_AUTOMATION_BUILDER_EMPTY_GROUP'),
        ];
    }

    /**
     * The fields this builder instance may offer, based on the field's mode.
     * Filter mode exposes item properties; condition mode exposes moment properties.
     *
     * @return string[]
     * @since __DEPLOY_VERSION__
     */
    private function allowedFields(): array
    {
        $mode = (string) $this->element['mode'];

        if ($mode === 'filter') {
            return ['tag', 'category', 'author_group'];
        }

        if ($mode === 'condition') {
            return ['day_of_week', 'date'];
        }

        return ['day_of_week', 'date', 'tag', 'category', 'author_group'];
    }
}
