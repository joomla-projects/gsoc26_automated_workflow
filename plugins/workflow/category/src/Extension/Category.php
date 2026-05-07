<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Workflow.category
 *
 * @copyright   (C) 2025 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Workflow\Category\Extension;

use Doctrine\Inflector\InflectorFactory;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Event\Model\PrepareFormEvent;
use Joomla\CMS\Event\View\DisplayEvent;
use Joomla\CMS\Event\Workflow\WorkflowTransitionEvent;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Workflow\WorkflowPluginTrait;
use Joomla\CMS\Workflow\WorkflowServiceInterface;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Workflow Category Transition Plugin
 *
 * @since  __DEPLOY_VERSION__
 */
final class Category extends CMSPlugin implements SubscriberInterface
{
    use WorkflowPluginTrait;

    /**
     * Load the language file on instantiation.
     *
     * @var    boolean
     * @since  __DEPLOY_VERSION__
     */
    protected $autoloadLanguage = true;

    /**
     * @var  CMSApplication
     * @since  __DEPLOY_VERSION__
     */
    protected $app;

    /**
     * Returns an array of events this subscriber will listen to.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterDisplay'            => 'onAfterDisplay',
            'onContentPrepareForm'      => 'onContentPrepareForm',
            'onWorkflowAfterTransition' => 'onWorkflowAfterTransition',
        ];
    }

    /**
     * The form event.
     *
     * @param   PrepareFormEvent  $event  The event
     *
     * @since   __DEPLOY_VERSION__
     */
    public function onContentPrepareForm(PrepareFormEvent $event): void
    {
        $form    = $event->getForm();
        $data    = $event->getData();
        $context = $form->getName();

        // Extend the transition form
        if ($context === 'com_workflow.transition') {
            $this->enhanceTransitionForm($form, $data);

            return;
        }

        if ($context === 'com_content.article') {
            $this->disableCategoryField($form, $data);
        }
    }

    /**
     * Add different parameter options to the transition view, we need when executing the transition
     *
     * @param   Form       $form The form
     * @param   \stdClass  $data The data
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function enhanceTransitionForm(Form $form, $data): bool
    {
        $workflow = $this->enhanceWorkflowTransitionForm($form, $data);

        if (!$workflow) {
            return true;
        }

        $parts     = explode('.', $workflow->extension);
        $extension = $parts[0];

        $form->setFieldAttribute('category_id', 'extension', $extension, 'options');

        return true;
    }

    /**
     * Disable certain fields in the item form view, when we want to take over this function in the transition
     * Check also for the workflow implementation and if the field exists
     *
     * @param   Form      $form  The form
     * @param   \stdClass    $data  The data
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function disableCategoryField(Form $form, $data)
    {
        $context = $form->getName();

        if (!$this->isSupported($context)) {
            return true;
        }

        $parts = explode('.', $context);

        $component = $this->getApplication()->bootComponent($parts[0]);

        $modelName = $component->getModelName($context);

        $table = $component->getMVCFactory()->createModel($modelName, $this->getApplication()->getName(), ['ignore_request' => true])
            ->getTable();

        $fieldname = $table->getColumnAlias('catid');

        $value = $data->$fieldname ?? $form->getValue($fieldname, null, 0);

        $form->setFieldAttribute($fieldname, 'readonly', 'true');
        $form->setFieldAttribute($fieldname, 'value', $value);

        return true;
    }


    /**
     * Manipulate the generic list view
     *
     * @param   DisplayEvent  $event
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function onAfterDisplay(DisplayEvent $event)
    {
        if (!$this->getApplication()->isClient('administrator')) {
            return;
        }

        $component = $event->getArgument('extensionName');
        $section   = $event->getArgument('section');

        // We need the single model context for checking for workflow
        $singularsection = InflectorFactory::create()->build()->singularize($section);

        if (!$this->isSupported($component . '.' . $singularsection)) {
            return;
        }

        $this->getApplication()->getDocument()->getWebAssetManager()
            ->registerAndUseScript('plg_workflow_category.disableBatch', 'plg_workflow_category/disable-category-batch.js', [], ['defer' => true], ['core']);
    }

    /**
     * Method to handle the workflow transition event.
     *
     * @param   WorkflowTransitionEvent  $event  The event object
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function onWorkflowAfterTransition(WorkflowTransitionEvent $event): void
    {
        $context       = $event->getArgument('extension');
        $extensionName = $event->getArgument('extensionName');
        $transition    = $event->getArgument('transition');
        $pks           = $event->getArgument('pks');

        if (!$this->isSupported($context)) {
            return;
        }

        $component = $this->getApplication()->bootComponent($extensionName);

        $categoryId = $transition->options->get('category_id', 0);

        if (!is_numeric($categoryId)) {
            return;
        }

        $app = $this->getApplication();

        if (!\is_object($transition) || !($transition->options instanceof Registry)) {
            $app->enqueueMessage('PLG_WORKFLOW_CATEGORY_INVALID_TRANSITION');
            return;
        }

        if (empty($pks) || !\is_array($pks)) {
            $app->enqueueMessage(Text::_('PLG_WORKFLOW_CATEGORY_NO_PRIMARY_KEY'), 'error');
            return;
        }

        $errors = 0;

        foreach ($pks as $pk) {
            if (!self::processArticle($pk, $categoryId)) {
                $errors++;
            }
        }

        if ($errors > 0) {
            $app->enqueueMessage(Text::plural('PLG_WORKFLOW_CATEGORY_ERRORS', $errors), 'warning');
        }
    }

    /**
     * Process the article and update its category.
     *
     * @param   int                  $pk         The primary key
     * @param   int                  $categoryId The category ID
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private function processArticle($pk, $categoryId): bool
    {
        $app    = $this->getApplication();

        try {
            $component = $this->getApplication()->bootComponent('com_content');
            $modelName = $component->getModelName('com_workflow.article');

            $articleTable = $component->getMVCFactory()->createModel($modelName, $this->getApplication()->getName(), ['ignore_request' => true])
                ->getTable();
            if (!$articleTable->load($pk)) {
                $app->enqueueMessage(Text::sprintf('PLG_WORKFLOW_CATEGORY_ARTICLE_NOT_FOUND', $pk), 'warning');
                return false;
            }

            if ($articleTable->catid == $categoryId) {
                return true;
            }

            $originalData = clone $articleTable;
            if ($categoryId && $categoryId > 0) {
                $articleTable->catid = $categoryId;
            }

            $articleTable->modified    = $originalData->modified;
            $articleTable->modified_by = $originalData->modified_by;

            if (!$articleTable->store()) {
                $app->enqueueMessage(Text::sprintf('PLG_WORKFLOW_CATEGORY_ARTICLE_UPDATE_FAILED', $pk, $categoryId), 'error');
            } else {
                return true;
            }
        } catch (\RuntimeException $e) {
            $app->enqueueMessage(Text::sprintf('PLG_WORKFLOW_CATEGORY_ARTICLE_UPDATE_ERROR', $pk) . ': ' . $e->getMessage(), 'error');
        }

        return false;
    }

    /**
     * Check if the current plugin should execute workflow related activities
     *
     * @param   string  $context
     *
     * @return   boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function isSupported($context)
    {
        $parts = explode('.', $context);

        // We need at least the extension + view for loading the table fields
        if (\count($parts) < 2) {
            return false;
        }

        $component = $this->getApplication()->bootComponent($parts[0]);

        if (
            !$component instanceof WorkflowServiceInterface
            || !$component->isWorkflowActive($context)
        ) {
            return false;
        }
        return true;
    }
}
