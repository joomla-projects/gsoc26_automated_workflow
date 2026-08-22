<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Workflow.Automation
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Workflow\Automation\Extension;

use Joomla\CMS\Event\Model\AfterSaveEvent;
use Joomla\CMS\Event\Model\PrepareFormEvent;
use Joomla\CMS\Event\Workflow\WorkflowTransitionEvent;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Workflow\Workflow;
use Joomla\CMS\Workflow\WorkflowServiceInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;
use Joomla\Event\SubscriberInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Keeps #__workflow_item_state in step with what the rest of Joomla does to an item.
 *
 * The automation engine decides whether an item is due from two facts: which stage it is in, and
 * when it arrived there. Something has to record those, and this plugin is that something. It
 * writes the row when an item is created and rewrites it whenever the item changes stage. It
 * never fires a transition itself; that is the scheduler task's job.
 *
 * Every method here runs on an after-event, so the article is already saved and the transition
 * has already happened by the time it is called. An exception cannot undo either, it can only
 * turn a completed action into an error the user has no way to act on. Failures are therefore
 * logged and swallowed rather than allowed to escape into work the user was already told worked.
 *
 * @since  __DEPLOY_VERSION__
 */
final class Automation extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    /**
     * @var boolean
     * @since __DEPLOY_VERSION__
     */
    protected $autoloadLanguage = true;

    /**
     * Returns the events this plugin listens to.
     *
     * @return array
     *
     * @since __DEPLOY_VERSION__
     */
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            'onWorkflowAfterTransition' => 'logStageEntry',
            'onContentAfterSave'        => 'seedNewItemState',
            'onContentPrepareForm'      => 'injectUpcomingTransitionField',
        ];
    }

    /**
     * Adds the read-only "next automated transition" panel to a workflow-enabled item form.
     *
     * The com_content article edit template prints this field in its sidebar. On any other form
     * it stays inert, because the field is simply not added.
     *
     * @param PrepareFormEvent $event The form event.
     *
     * @return void
     *
     * @since __DEPLOY_VERSION__
     */
    public function injectUpcomingTransitionField(PrepareFormEvent $event): void
    {
        $form = $event->getForm();

        if (!$this->isWorkflowContentForm((string) $form->getName())) {
            return;
        }

        Form::addFormPath(JPATH_PLUGINS . '/workflow/automation/forms');

        // The return value matters. load() answers false for a fragment it could not parse, and
        // unchecked, a mistake in the XML produces an edit screen with no panel and nothing
        // anywhere saying why.
        if (!$form->loadFile('upcoming_transition')) {
            Log::add(
                'Workflow automation could not load its upcoming transition field into ' . $form->getName() . '.',
                Log::WARNING,
                'workflow'
            );
        }
    }

    /**
     * Returns the component behind an extension when it offers workflow and has it switched on.
     *
     * This is the half of the question that is true everywhere. isWorkflowActive(), used by
     * isWorkflowContentForm() below, finishes by building a model named after the running
     * application, so it can only answer honestly inside the site and administrator apps: from
     * the CLI or the web services API it says no for every extension, however it is configured.
     * Anything that has to work outside a browser request asks this instead.
     *
     * @param string $extension The extension, e.g. com_content.article.
     *
     * @return WorkflowServiceInterface|null The component, or null when workflow does not apply.
     *
     * @since __DEPLOY_VERSION__
     */
    private function workflowComponent(string $extension): ?WorkflowServiceInterface
    {
        $parts = explode('.', $extension);

        if (\count($parts) < 2) {
            return null;
        }

        // bootComponent() throws for a component that is not installed, which any extension name
        // pointing at something since removed will do. An extension this cannot resolve is simply
        // not a workflow extension, so the answer is no rather than a fatal on somebody's page.
        try {
            $component = Factory::getApplication()->bootComponent($parts[0]);
        } catch (\Throwable) {
            return null;
        }

        if (!$component instanceof WorkflowServiceInterface) {
            return null;
        }

        return ComponentHelper::getParams($parts[0])->get('workflow_enabled') ? $component : null;
    }

    /**
     * Whether a form belongs to a content item under an active workflow.
     *
     * Stricter than workflowComponent(), and safely so: a form only ever renders inside the site
     * or administrator application, which is exactly where isWorkflowActive() can answer.
     *
     * @param string $extension The form name, which for a content form is the workflow
     * extension e.g. com_content.article.
     *
     * @return boolean
     *
     * @since __DEPLOY_VERSION__
     */
    private function isWorkflowContentForm(string $extension): bool
    {
        $component = $this->workflowComponent($extension);

        return $component !== null && $component->isWorkflowActive($extension);
    }

    /**
     * Records the stage an item has just moved into.
     *
     * One state row per item per extension, so an existing row is updated and a missing one
     * inserted. Each happens in a single query for the whole batch, because a transition run from
     * a list view can carry hundreds of items at once.
     *
     * @param WorkflowTransitionEvent $event The transition event.
     *
     * @return void
     *
     * @since __DEPLOY_VERSION__
     */
    public function logStageEntry(WorkflowTransitionEvent $event): void
    {
        $transition = $event->getTransition();

        // There is nothing to record without a transition to read the destination from. Guarded
        // rather than assumed because this is a public event: any extension can dispatch it, and
        // one that dispatches it badly should not produce a fatal inside com_content.
        if ($transition === null || !isset($transition->to_stage_id)) {
            return;
        }

        $extension     = $event->getExtension();
        $toStageId   = (int) $transition->to_stage_id;
        $now         = Factory::getDate()->toSql();
        $triggeredBy = $event->getTriggeredBy();
        $pksInt      = array_map('intval', $event->getPks());

        if ($pksInt === []) {
            return;
        }

        $db = $this->getDatabase();

        // One query to find which of these items already have a state row.
        $findExistingLogQuery = $db->getQuery(true)
            ->select($db->quoteName(['item_id', 'id']))
            ->from($db->quoteName('#__workflow_item_state'))
            ->whereIn($db->quoteName('item_id'), $pksInt)
            ->where($db->quoteName('extension') . ' = :extension')
            ->bind(':extension', $extension, ParameterType::STRING);

        // loadAssocList() answers null rather than an empty array when nothing matched, and null
        // is not something the loop below can read.
        $existingLogs = $db->setQuery($findExistingLogQuery)->loadAssocList('item_id', 'id') ?: [];

        $toInsert = [];
        $stateIds = [];

        foreach ($pksInt as $pk) {
            if (isset($existingLogs[$pk])) {
                // Only the row ids are ever used, so a plain list is all this needs to be.
                $stateIds[] = (int) $existingLogs[$pk];
            } else {
                $toInsert[] = $pk;
            }
        }

        if ($stateIds !== []) {
            $updateStageLogQuery = $db->getQuery(true)
                ->update($db->quoteName('#__workflow_item_state'))
                ->set($db->quoteName('stage_id') . ' = :stageId')
                ->set($db->quoteName('entered_at') . ' = :enteredAt')
                ->set($db->quoteName('triggered_by') . ' = :triggeredBy')

                // Everything below describes the stage the item has just left, so none of it
                // survives the move.
                //
                // requires_intervention keeps an item out of the scheduler until a person clears
                // it. Reaching this method means a transition has just succeeded, so whatever was
                // stuck no longer is. Leaving the flag set silently excludes the item from a
                // stage where nothing has ever gone wrong, and nothing would ever clear it,
                // because the scheduler never looks at a flagged item again.
                ->set($db->quoteName('requires_intervention') . ' = 0')

                // The scheduler takes candidates least-recently-checked first, treating null as
                // never. An item carrying a stamp from its old stage sorts as though it had just
                // been looked at, so it waits a full rotation before anything considers it here.
                ->set($db->quoteName('last_checked_at') . ' = NULL')

                // The recorded fault names a rule on the old stage. Beyond warning about a rule
                // this item can no longer reach, a stale reason would suppress the notification
                // for a genuinely new fault, because the scheduler only reports a reason that
                // differs from the one already stored.
                ->set($db->quoteName('last_failure_at') . ' = NULL')
                ->set($db->quoteName('last_failure_reason') . ' = NULL')

                ->whereIn($db->quoteName('id'), $stateIds)
                ->bind(':stageId', $toStageId, ParameterType::INTEGER)
                ->bind(':enteredAt', $now, ParameterType::STRING)
                ->bind(':triggeredBy', $triggeredBy, ParameterType::STRING);

            $this->runBookkeepingQuery(
                $updateStageLogQuery,
                'the new stage for ' . \count($stateIds) . ' existing item(s) in ' . $extension
            );
        }

        if ($toInsert !== []) {
            $insertStageLogQuery = $db->getQuery(true)
                ->insert($db->quoteName('#__workflow_item_state'))
                ->columns($db->quoteName([
                    'item_id',
                    'extension',
                    'stage_id',
                    'entered_at',
                    'triggered_by',
                ]));

            foreach ($toInsert as $pk) {
                $insertStageLogQuery->values(
                    $db->quote($pk) . ', '
                        . $db->quote($extension) . ', '
                        . $db->quote($toStageId) . ', '
                        . $db->quote($now) . ', '
                        . $db->quote($triggeredBy)
                );
            }

            $this->runBookkeepingQuery(
                $insertStageLogQuery,
                'the first stage for ' . \count($toInsert) . ' new item(s) in ' . $extension
            );
        }
    }

    /**
     * Seeds the item state row for a brand new content item.
     *
     * onWorkflowAfterTransition does not fire when an item is first created, so the row recording
     * which stage it is in, and since when, is written here instead. Existing items need nothing
     * on save: whether they are due is recomputed from entered_at on every scheduler run, so a
     * change to the item is picked up without any bookkeeping.
     *
     * @param AfterSaveEvent $event The save event.
     *
     * @return void
     *
     * @since __DEPLOY_VERSION__
     */
    public function seedNewItemState(AfterSaveEvent $event): void
    {
        if (!$event->getIsNew()) {
            return;
        }

        $extension = $event->getContext();

        // Deliberately the cheap, app-independent check rather than isWorkflowContentForm().
        // Articles are created by import scripts and the web services API as well as from the
        // admin, and the stricter check answers no for every one of those.
        if ($this->workflowComponent($extension) === null) {
            return;
        }

        $table  = $event->getItem();
        $itemId = (int) $table->id;

        // The application is passed explicitly. Leaving it out makes Workflow fall back to
        // Factory::getApplication() and emit a deprecation notice, which from 6.0 is an error.
        $workflow = new Workflow($extension, $this->getApplication());
        $stageId  = (int) $workflow->getDefaultStageByCategory($table->catid ?? 0);

        if ($stageId <= 0) {
            // No default stage means the workflow cannot place this item, so there is nothing to
            // record. Logged rather than dropped silently: from outside, this is
            // indistinguishable from automation being switched off, and an administrator
            // wondering why new articles never enter a workflow has no other thread to pull.
            Log::add(
                'Workflow automation found no default stage for ' . $extension . ' item ' . $itemId
                    . ', so it will not be considered for automated transitions.',
                Log::WARNING,
                'workflow'
            );

            return;
        }

        $db = $this->getDatabase();
        $now = Factory::getDate()->toSql();
        $insertQuery = $db->getQuery(true)
            ->insert($db->quoteName('#__workflow_item_state'))
            ->columns($db->quoteName([
                'item_id',
                'extension',
                'stage_id',
                'entered_at',
                'triggered_by',
            ]))
            ->values(
                $db->quote($itemId) . ', '
                    . $db->quote($extension) . ', '
                    . $db->quote($stageId) . ', '
                    . $db->quote($now) . ', '
                    . $db->quote('manual')
            );

        $this->runBookkeepingQuery($insertQuery, 'the starting stage for ' . $extension . ' item ' . $itemId);
    }

    /**
     * Runs a bookkeeping query, turning a failure into a log entry instead of an exception.
     *
     * Everything in this plugin runs on an after-event: the article is saved, the transition has
     * happened. Letting a query failure escape cannot undo either of those, it only turns a
     * completed action into an error the user cannot act on and cannot retry out of. A duplicate
     * key on (item_id, extension) is enough to trigger it.
     *
     * The cost of swallowing is bounded and self-correcting. A missing or stale state row means
     * the item is not considered for automation until it next changes stage, at which point this
     * plugin writes the row again.
     *
     * @param QueryInterface $query The bookkeeping query to run.
     * @param string $description What was being recorded, for the log line.
     *
     * @return void
     *
     * @since __DEPLOY_VERSION__
     */
    private function runBookkeepingQuery(QueryInterface $query, string $description): void
    {
        try {
            $this->getDatabase()->setQuery($query)->execute();
        } catch (\Throwable $error) {
            Log::add(
                'Workflow automation could not record ' . $description . ': ' . $error->getMessage(),
                Log::WARNING,
                'workflow'
            );
        }
    }
}
