<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_workflow
 *
 * @copyright   (C) 2018 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 * @since       4.0.0
 */

namespace Joomla\Component\Workflow\Administrator\Model;

use Cron\CronExpression;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;
use Joomla\String\StringHelper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Model class for transition
 *
 * @since  4.0.0
 */
class TransitionModel extends AdminModel
{
    /**
     * Auto-populate the model state.
     *
     * Note. Calling getState in this method will result in recursion.
     *
     * @return  void
     *
     * @since   4.0.0
     */
    public function populateState()
    {
        parent::populateState();

        $app       = Factory::getApplication();
        $context   = $this->option . '.' . $this->name;
        $extension = $app->getUserStateFromRequest($context . '.filter.extension', 'extension', null, 'cmd');

        $this->setState('filter.extension', $extension);
    }

    /**
     * Method to test whether a record can be deleted.
     *
     * @param   object  $record  A record object.
     *
     * @return  boolean  True if allowed to delete the record. Defaults to the permission for the component.
     *
     * @since  4.0.0
     */
    protected function canDelete($record)
    {
        if (empty($record->id) || $record->published != -2) {
            return false;
        }

        $app       = Factory::getApplication();
        $extension = $app->getUserStateFromRequest('com_workflow.transition.filter.extension', 'extension', null, 'cmd');

        return $this->getCurrentUser()->authorise('core.delete', $extension . '.transition.' . (int) $record->id);
    }

    /**
     * Method to test whether a record can have its state changed.
     *
     * @param   object  $record  A record object.
     *
     * @return  boolean  True if allowed to change the state of the record. Defaults to the permission set in the component.
     *
     * @since   4.0.0
     */
    protected function canEditState($record)
    {
        $user      = $this->getCurrentUser();
        $app       = Factory::getApplication();
        $context   = $this->option . '.' . $this->name;
        $extension = $app->getUserStateFromRequest($context . '.filter.extension', 'extension', null, 'cmd');

        if (!property_exists($record, 'workflow_id')) {
            $workflowID          = $app->getUserStateFromRequest($context . '.filter.workflow_id', 'workflow_id', 0, 'int');
            $record->workflow_id = $workflowID;
        }

        // Check for existing workflow.
        if (!empty($record->id)) {
            return $user->authorise('core.edit.state', $extension . '.transition.' . (int) $record->id);
        }

        // Default to component settings if workflow isn't known.
        return $user->authorise('core.edit.state', $extension);
    }

    /**
     * Method to get a single record.
     *
     * @param   integer  $pk  The id of the primary key.
     *
     * @return  \stdClass|boolean  Object on success, false on failure.
     *
     * @since   4.0.0
     */
    public function getItem($pk = null)
    {
        $item = parent::getItem($pk);

        if (property_exists($item, 'options')) {
            $registry      = new Registry($item->options);
            $item->options = $registry->toArray();
        }

        if (!empty($item->id)) {
            $db                        = $this->getDatabase();
            $transitionAutomationQuery = $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__workflow_transition_automation'))
                ->where($db->quoteName('transition_id') . ' = :id')
                ->bind(':id', $item->id, ParameterType::INTEGER);

            $item->automation = $db->setQuery($transitionAutomationQuery)->loadAssoc() ?: [];
        }

        return $item;
    }

    /**
     * Method to save the form data.
     *
     * @param   array  $data  The form data.
     *
     * @return   boolean  True on success.
     *
     * @since  4.0.0
     */
    public function save($data)
    {
        $automationData = $data['automation'] ?? [];
        unset($data['automation']);

        if (!$this->validateAutomation($automationData)) {
            return false;
        }

        $table      = $this->getTable();
        $context    = $this->option . '.' . $this->name;
        $app        = Factory::getApplication();
        $user       = $app->getIdentity();
        $input      = $app->getInput();

        $workflowID = $app->getUserStateFromRequest($context . '.filter.workflow_id', 'workflow_id', 0, 'int');

        if (empty($data['workflow_id'])) {
            $data['workflow_id'] = $workflowID;
        }

        $workflow = $this->getTable('Workflow');

        $workflow->load($data['workflow_id']);

        $parts = explode('.', $workflow->extension);

        if (isset($data['rules']) && !$user->authorise('core.admin', $parts[0])) {
            unset($data['rules']);
        }

        // Make sure we use the correct workflow_id when editing an existing transition
        $key = $table->getKeyName();
        $pk  = $data[$key] ?? (int) $this->getState($this->getName() . '.id');

        if ($pk > 0) {
            $table->load($pk);

            if ((int) $table->workflow_id) {
                $data['workflow_id'] = (int) $table->workflow_id;
            }
        }

        if ($input->get('task') == 'save2copy') {
            $origTable = $this->getTable();

            // Alter the title for save as copy
            if ($origTable->load(['title' => $data['title']])) {
                [$title]       = $this->generateNewTitle(0, '', $data['title']);
                $data['title'] = $title;
            }

            $data['published'] = 0;
        }

        // return parent::save($data);
        if (!parent::save($data)) {
            return false;
        }

        $pk = (int) $this->getState($this->getName() . '.id');
        $this->saveAutomationRule($pk, $automationData);

        return true;
    }

    /**
     * Method to change the title
     *
     * @param   integer  $categoryId  The id of the category.
     * @param   string   $alias       The alias.
     * @param   string   $title       The title.
     *
     * @return  array  Contains the modified title and alias.
     *
     * @since   4.0.0
     */
    protected function generateNewTitle($categoryId, $alias, $title)
    {
        // Alter the title & alias
        $table = $this->getTable();

        while ($table->load(['title' => $title])) {
            $title = StringHelper::increment($title);
        }

        return [$title, $alias];
    }

    /**
     * Abstract method for getting the form from the model.
     *
     * @param   array    $data      Data for the form.
     * @param   boolean  $loadData  True if the form is to load its own data (default case), false if not.
     *
     * @return  Form  A Form object
     *
     * @since   4.0.0
     * @throws  \Exception on failure
     */
    public function getForm($data = [], $loadData = true)
    {
        // Get the form.
        $form = $this->loadForm(
            'com_workflow.transition',
            'transition',
            [
                'control'   => 'jform',
                'load_data' => $loadData,
            ]
        );

        $id = $data['id'] ?? $form->getValue('id');

        $item = $this->getItem($id);

        $canEditState = $this->canEditState((object) $item);

        // Modify the form based on access controls.
        if (!$canEditState) {
            $form->setFieldAttribute('published', 'disabled', 'true');
            $form->setFieldAttribute('published', 'required', 'false');
            $form->setFieldAttribute('published', 'filter', 'unset');
        }

        if (!empty($item->workflow_id)) {
            $data['workflow_id'] = (int) $item->workflow_id;
        }

        if (empty($data['workflow_id'])) {
            $context = $this->option . '.' . $this->name;

            $data['workflow_id'] = (int) Factory::getApplication()->getUserStateFromRequest(
                $context . '.filter.workflow_id',
                'workflow_id',
                0,
                'int'
            );
        }

        $where = $this->getDatabase()->quoteName('workflow_id') . ' = ' . (int) $data['workflow_id'];
        $where .= ' AND ' . $this->getDatabase()->quoteName('published') . ' = 1';

        $form->setFieldAttribute('from_stage_id', 'sql_where', $where);
        $form->setFieldAttribute('to_stage_id', 'sql_where', $where);

        return $form;
    }

    /**
     * Method to get the data that should be injected in the form.
     *
     * @return mixed  The data for the form.
     *
     * @since  4.0.0
     */
    protected function loadFormData()
    {
        // Check the session for previously entered form data.
        $data = Factory::getApplication()->getUserState(
            'com_workflow.edit.transition.data',
            []
        );

        if (empty($data)) {
            $data = $this->getItem();
        }

        return $data;
    }

    public function getWorkflow()
    {
        $app = Factory::getApplication();

        $context = $this->option . '.' . $this->name;

        $workflow_id = (int) $app->getUserStateFromRequest($context . '.filter.workflow_id', 'workflow_id', 0, 'int');

        $workflow = $this->getTable('Workflow');

        $workflow->load($workflow_id);

        return (object) $workflow->getProperties();
    }

    /**
     * Trigger the form preparation for the workflow group
     *
     * @param   Form    $form   A Form object.
     * @param   mixed   $data   The data expected for the form.
     * @param   string  $group  The name of the plugin group to import (defaults to "content").
     *
     * @return  void
     *
     * @see     FormField
     * @since   4.0.0
     * @throws  \Exception if there is an error in the form event.
     */
    protected function preprocessForm(Form $form, $data, $group = 'content')
    {
        $extension = Factory::getApplication()->getInput()->get('extension');

        $parts = explode('.', $extension);

        $extension = array_shift($parts);

        // Set the access control rules field component value.
        $form->setFieldAttribute('rules', 'component', $extension);

        // Import the appropriate plugin group.
        PluginHelper::importPlugin('workflow');

        parent::preprocessForm($form, $data, $group);
    }

    private function saveAutomationRule(int $transitionId, array $data): void
    {
        $db   = $this->getDatabase();
        $user = Factory::getApplication()->getIdentity();
        $now  = Factory::getDate()->toSql();

        if (empty($data) || empty((int) ($data['published'] ?? 0))) {
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName('#__workflow_transition_automation'))
                    ->where($db->quoteName('transition_id') . ' = :id')
                    ->bind(':id', $transitionId, ParameterType::INTEGER)
            )->execute();

            return;
        }

        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__workflow_transition_automation'))
            ->where($db->quoteName('transition_id') . ' = :id')
            ->bind(':id', $transitionId, ParameterType::INTEGER);

        $existingId = (int) $db->setQuery($query)->loadResult();

        if ($existingId) {
            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName('#__workflow_transition_automation'))
                    ->set($db->quoteName('rule_type') . ' = ' . $db->quote($data['rule_type'] ?? 'interval'))
                    ->set($db->quoteName('interval_value') . ' = ' . (int) ($data['interval_value'] ?? 0))
                    ->set($db->quoteName('interval_unit') . ' = ' . $db->quote($data['interval_unit'] ?? 'minutes'))
                    ->set($db->quoteName('cron_expression') . ' = ' . $db->quote($data['cron_expression'] ?? ''))
                    ->set($db->quoteName('run_as_user_id') . ' = ' . (int) ($data['run_as_user_id'] ?? 0))
                    ->set($db->quoteName('loop_mode') . ' = ' . (int) ($data['loop_mode'] ?? 0))
                    ->set($db->quoteName('published') . ' = 1')
                    ->set($db->quoteName('modified') . ' = ' . $db->quote($now))
                    ->set($db->quoteName('modified_by') . ' = ' . $user->id)
                    ->where($db->quoteName('transition_id') . ' = :id')
                    ->bind(':id', $transitionId, ParameterType::INTEGER)
            )->execute();
        } else {
            $db->setQuery(
                $db->getQuery(true)
                    ->insert($db->quoteName('#__workflow_transition_automation'))
                    ->columns($db->quoteName([
                        'transition_id',
                        'rule_type',
                        'interval_value',
                        'interval_unit',
                        'cron_expression',
                        'run_as_user_id',
                        'loop_mode',
                        'published',
                        'created',
                        'created_by',
                        'modified',
                        'modified_by',
                    ]))
                    ->values(implode(', ', [
                        $transitionId,
                        $db->quote($data['rule_type'] ?? 'interval'),
                        (int) ($data['interval_value'] ?? 0),
                        $db->quote($data['interval_unit'] ?? 'minutes'),
                        $db->quote($data['cron_expression'] ?? ''),
                        (int) ($data['run_as_user_id'] ?? 0),
                        (int) ($data['loop_mode'] ?? 0),
                        1,
                        $db->quote($now),
                        $user->id,
                        $db->quote($now),
                        $user->id,
                    ]))
            )->execute();
        }
    }

    /**
     * Validates the automation rule data submitted with a transition.
     *
     * Form-level validation only runs in the browser; this guards the model when
     * data arrives from any other path. Only enforced when automation is enabled.
     *
     * @param   array  $data  The automation sub-form data.
     *
     * @return  boolean  True if valid, false (with a message enqueued) otherwise.
     *
     * @since   __DEPLOY_VERSION__
     */
    private function validateAutomation(array $data): bool
    {
        // Nothing to validate when automation is disabled.
        if (empty((int) ($data['published'] ?? 0))) {
            return true;
        }

        $app      = Factory::getApplication();
        $ruleType = $data['rule_type'] ?? 'interval';

        if (!\in_array($ruleType, ['interval', 'cron'], true)) {
            $app->enqueueMessage(Text::_('COM_WORKFLOW_AUTOMATION_ERROR_RULE_TYPE'), 'error');

            return false;
        }

        if ($ruleType === 'interval') {
            if ((int) ($data['interval_value'] ?? 0) < 1) {
                $app->enqueueMessage(Text::_('COM_WORKFLOW_AUTOMATION_ERROR_INTERVAL_VALUE'), 'error');

                return false;
            }

            if (!\in_array($data['interval_unit'] ?? '', ['minutes', 'hours', 'days', 'months'], true)) {
                $app->enqueueMessage(Text::_('COM_WORKFLOW_AUTOMATION_ERROR_INTERVAL_UNIT'), 'error');

                return false;
            }
        }

        if ($ruleType === 'cron') {
            $expression = trim((string) ($data['cron_expression'] ?? ''));

            if ($expression === '' || !CronExpression::isValidExpression($expression)) {
                $app->enqueueMessage(Text::_('COM_WORKFLOW_AUTOMATION_ERROR_CRON'), 'error');

                return false;
            }
        }

        return true;
    }
}
