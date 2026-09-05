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
use Joomla\CMS\Access\Access;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\UserFactoryInterface;
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
            $db    = $this->getDatabase();
            $query = $db->getQuery(true)
                ->select($db->quoteName([
                    'rule_type',
                    'delay_value',
                    'delay_unit',
                    'cron_expression',
                    'run_as_user_id',
                    'item_filter',
                    'fire_condition',
                ]))
                ->from($db->quoteName('#__workflow_automation_rules'))
                ->where($db->quoteName('transition_id') . ' = :id')
                ->bind(':id', $item->id, ParameterType::INTEGER)
                ->setLimit(1);

            $rule = $db->setQuery($query)->loadAssoc();

            if ($rule) {
                $item->automation = [
                    'automation_enabled' => 1,
                    'run_as_user_id'     => (int) ($rule['run_as_user_id'] ?? 0),
                    'automation_rules'   => $rule,
                ];
            } else {
                $item->automation = ['automation_enabled' => 0, 'run_as_user_id' => 0, 'automation_rules' => []];
            }
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
        // Pull automation data out. The transition-level toggle decides whether a rule is kept:
        // when it is off, an empty rule clears any existing one.
        $automationData    = $data['automation'] ?? [];
        $automationEnabled = !empty($automationData['automation_enabled']);
        $automationRule    = $automationEnabled ? ($automationData['automation_rules'] ?? []) : [];
        unset($data['automation']);

        if (!empty($automationRule)) {
            $automationRule['run_as_user_id'] = (int) ($automationData['run_as_user_id'] ?? 0);
        }

        // Resolved here as well as at line 207, because the permission check below authorises
        // against the transition's own asset and cannot wait.
        $transitionId = (int) ($data['id'] ?? $this->getState($this->getName() . '.id'));

        if (!$this->validateAutomation($automationRule, $transitionId)) {
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

        if (!parent::save($data)) {
            return false;
        }

        $pk = (int) $this->getState($this->getName() . '.id');

        try {
            $this->saveAutomationRule($pk, $automationRule);
        } catch (\Throwable $error) {
            // parent::save() has already committed the transition, so there is nothing to undo
            // here. saveAutomationRule() rolls its own transaction back, so the previously
            // stored rule survives intact. What must not survive is the exception: unhandled it
            // reaches the user as a 500 page that says nothing about which half of the save
            // went through.
            $app->enqueueMessage(
                Text::sprintf('COM_WORKFLOW_AUTOMATION_RULE_SAVE_FAILED', $error->getMessage()),
                'error'
            );

            return false;
        }

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

        // The picker only offers accounts this editor could legitimately delegate to, so an
        // impossible choice is never on the menu. validateAutomation() enforces the same rule on
        // save, because a filtered picker is only HTML and the field can still be posted directly.
        $user = $this->getCurrentUser();

        if (!$user->authorise('core.admin', $extension)) {
            $reachable = $this->groupsWithNoMorePermission(Access::getGroupsByUser((int) $user->id, false));

            // 0 is not a real group id, so an account somehow in no groups at all sees nobody
            // rather than everybody.
            $form->setFieldAttribute(
                'run_as_user_id',
                'groups',
                implode(',', $reachable ?: [0]),
                'automation'
            );
        }

        // Import the appropriate plugin group.
        PluginHelper::importPlugin('workflow');

        parent::preprocessForm($form, $data, $group);
    }

    /**
     * Persists the automation rule for a transition, replacing whatever was there.
     *
     * Delete-then-insert rather than update, because a transition carries at most one rule and
     * the same call has to handle three cases: no rule before and one now, one before and a
     * different one now, and one before and none now when the administrator switches automation
     * off. A unique key on transition_id enforces the "at most one" so this cannot quietly
     * discard a second rule that should never have existed.
     *
     * @param   integer  $transitionId    The transition id.
     * @param   array    $automationRule  The submitted rule, or empty to clear.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    private function saveAutomationRule(int $transitionId, array $automationRule): void
    {
        $db   = $this->getDatabase();
        $user = Factory::getApplication()->getIdentity();
        $now  = Factory::getDate()->toSql();

        // Replace: clear this transition's rule, then insert the submitted one if there is content.
        // Wrapped in a transaction so a failure mid-save can't wipe the existing configuration.
        try {
            $db->transactionStart();

            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName('#__workflow_automation_rules'))
                    ->where($db->quoteName('transition_id') . ' = :id')
                    ->bind(':id', $transitionId, ParameterType::INTEGER)
            )->execute();

            if (!empty($automationRule)) {
                $ruleRow = (object) [
                    'transition_id'   => $transitionId,
                    'published'       => 1,
                    'ordering'        => 0,
                    'rule_type'       => $automationRule['rule_type'] ?? 'delay',
                    'delay_value'     => (int) ($automationRule['delay_value'] ?? 0),
                    'delay_unit'      => $automationRule['delay_unit'] ?? 'minutes',
                    'cron_expression' => $automationRule['cron_expression'] ?? '',
                    'run_as_user_id'  => (int) ($automationRule['run_as_user_id'] ?? 0),
                    'item_filter'     => ($automationRule['item_filter'] ?? '') !== '' ? $automationRule['item_filter'] : null,
                    'fire_condition'  => ($automationRule['fire_condition'] ?? '') !== '' ? $automationRule['fire_condition'] : null,
                    'created'         => $now,
                    'created_by'      => $user->id,
                    'modified'        => $now,
                    'modified_by'     => $user->id,
                ];

                $db->insertObject('#__workflow_automation_rules', $ruleRow);
            }

            $db->transactionCommit();
        } catch (\Throwable $error) {
            $db->transactionRollback();

            throw $error;
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
    private function validateAutomationRule(array $data): bool
    {
        $app      = Factory::getApplication();
        $ruleType = $data['rule_type'] ?? 'delay';

        if (!\in_array($ruleType, ['delay', 'cron'], true)) {
            $app->enqueueMessage(Text::_('COM_WORKFLOW_AUTOMATION_ERROR_RULE_TYPE'), 'error');

            return false;
        }

        if ($ruleType === 'delay') {
            if ((int) ($data['delay_value'] ?? 0) < 0) {
                $app->enqueueMessage(Text::_('COM_WORKFLOW_AUTOMATION_ERROR_DELAY_VALUE'), 'error');

                return false;
            }

            if (!\in_array($data['delay_unit'] ?? '', ['minutes', 'hours', 'days', 'months'], true)) {
                $app->enqueueMessage(Text::_('COM_WORKFLOW_AUTOMATION_ERROR_DELAY_UNIT'), 'error');

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

    private function validateAutomation(array $automationRule, int $transitionId): bool
    {
        // No rule submitted (automation disabled or empty) is valid.
        if (empty($automationRule)) {
            return true;
        }

        if (!$this->validateAutomationRule($automationRule)) {
            return false;
        }

        $app         = Factory::getApplication();
        $runAsUserId = (int) ($automationRule['run_as_user_id'] ?? 0);

        // Non-blocking: a rule with no run-as user cannot execute.
        if ($runAsUserId === 0) {
            $app->enqueueMessage(Text::_('COM_WORKFLOW_AUTOMATION_WARNING_NO_RUN_AS'), 'warning');

            return true;
        }

        // Only a change is restricted. Someone editing a delay on a rule an administrator set up
        // must not be blocked by a run-as user who outranks them, or the people who maintain the
        // rest of a workflow cannot touch its automation at all.
        if ($runAsUserId !== $this->storedRunAsUserId($transitionId) && !$this->mayDelegateTo($runAsUserId)) {
            $app->enqueueMessage(Text::_('COM_WORKFLOW_AUTOMATION_ERROR_RUN_AS_TOO_HIGH'), 'error');

            return false;
        }

        // A warning rather than a refusal: the permission is often granted after the rule is
        // written, and refusing the save is the more disruptive of the two mistakes.
        if ($transitionId > 0 && !$this->canExecuteTransition($runAsUserId, $transitionId)) {
            $app->enqueueMessage(Text::_('COM_WORKFLOW_AUTOMATION_WARNING_RUN_AS_CANNOT_EXECUTE'), 'warning');
        }

        return true;
    }

    /**
     * The run-as user already stored for this transition, or 0 when there is no rule yet.
     *
     * @param   integer  $transitionId  The transition being saved.
     *
     * @return  integer
     *
     * @since   __DEPLOY_VERSION__
     */
    private function storedRunAsUserId(int $transitionId): int
    {
        if ($transitionId <= 0) {
            return 0;
        }

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('run_as_user_id'))
            ->from($db->quoteName('#__workflow_automation_rules'))
            ->where($db->quoteName('transition_id') . ' = :transitionId')
            ->bind(':transitionId', $transitionId, ParameterType::INTEGER);

        return (int) $db->setQuery($query)->loadResult();
    }

    /**
     * Whether the current user may hand execution to this account.
     *
     * Joomla's group tree runs the opposite way to intuition: a child group inherits its parent's
     * permissions and adds to them, so descendants hold more rather than less. An account is only
     * safe to delegate to when every group it belongs to is one of the editor's own groups or an
     * ancestor of one.
     *
     * A proxy rather than a proof, since an explicit Deny can leave a descendant with fewer rights
     * than its parent. It stops the obvious escalation; canExecuteTransition() decides whether the
     * rule can actually run.
     *
     * @param   integer  $candidateUserId  The account being named as the run-as user.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private function mayDelegateTo(int $candidateUserId): bool
    {
        $user = Factory::getApplication()->getIdentity();

        if ((int) $user->id === $candidateUserId) {
            return true;
        }

        $parts     = explode('.', (string) Factory::getApplication()->getInput()->get('extension'));
        $extension = array_shift($parts);

        if ($user->authorise('core.admin', $extension)) {
            return true;
        }

        // Normally redundant: a child inherits its parent's permissions, so anything an ancestor
        // group holds, this editor holds too, and there would be nothing to escalate to. An
        // explicit Deny breaks that.
        $candidate = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($candidateUserId);

        if ((int) $candidate->id === $candidateUserId && $candidate->authorise('core.admin', $extension)) {
            return false;
        }

        $candidateGroups = Access::getGroupsByUser($candidateUserId, false);

        if ($candidateGroups === []) {
            return true;
        }

        $reachable = $this->groupsWithNoMorePermission(Access::getGroupsByUser((int) $user->id, false));

        return array_diff($candidateGroups, $reachable) === [];
    }

    /**
     * The groups that hold no more permission than the given ones, by tree position.
     *
     * @param   int[]  $groupIds  The editor's own groups.
     *
     * @return  int[]
     *
     * @since   __DEPLOY_VERSION__
     */
    private function groupsWithNoMorePermission(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('DISTINCT ' . $db->quoteName('ancestor.id'))
            ->from($db->quoteName('#__usergroups', 'own'))
            ->join(
                'INNER',
                $db->quoteName('#__usergroups', 'ancestor'),
                $db->quoteName('ancestor.lft') . ' <= ' . $db->quoteName('own.lft')
                    . ' AND ' . $db->quoteName('ancestor.rgt') . ' >= ' . $db->quoteName('own.rgt')
            )
            ->whereIn($db->quoteName('own.id'), $groupIds);

        return array_map('intval', $db->setQuery($query)->loadColumn());
    }

    /**
     * Whether an account holds the permission the scheduler will need at run time.
     *
     * Asks exactly what Workflow::getValidTransition() asks, so a rule that saves cleanly is one
     * that can actually fire.
     *
     * @param   integer  $userId        The run-as account.
     * @param   integer  $transitionId  The transition it would execute.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private function canExecuteTransition(int $userId, int $transitionId): bool
    {
        $runAsUser = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($userId);

        if ((int) $runAsUser->id !== $userId) {
            return false;
        }

        $parts = explode('.', (string) Factory::getApplication()->getInput()->get('extension'));

        return $runAsUser->authorise('core.execute.transition', array_shift($parts) . '.transition.' . $transitionId);
    }
}
