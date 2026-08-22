<?php

namespace Joomla\Plugin\Workflow\Automation\Extension;

use Joomla\CMS\Event\Model\AfterSaveEvent;
use Joomla\CMS\Event\Model\PrepareFormEvent;
use Joomla\CMS\Event\Workflow\WorkflowTransitionEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Workflow\Workflow;
use Joomla\CMS\Workflow\WorkflowServiceInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects
final class Automation extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return [
            'onWorkflowAfterTransition' => 'logStageEntry',
            'onContentAfterSave'        => 'reevaluateOnChange',
            'onContentPrepareForm'      => 'injectUpcomingTransitionField',
        ];
    }

    /**
     * Adds the read-only "next automated transition" field to a workflow-enabled item form
     *
     * The com_content article edit template prints this field in its sidebar. On any other form
     * it stays inert because the field is simply not added.
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

        $form->load(
            '<form>'
                . '<field name="upcoming_transition"'
                . ' type="upcomingtransition"'
                . ' addfieldprefix="Joomla\Component\Workflow\Administrator\Field"'
                . ' label="COM_WORKFLOW_UPCOMING_ARTICLE_LABEL" />'
                . '</form>'
        );
    }

    /**
     * Whether a form context is a content item form under an active workflow.
     *
     * @param string $context The form name, e.g. com_content.article.
     *
     * @return boolean
     *
     * @since __DEPLOY_VERSION__
     */
    private function isWorkflowContentForm(string $context): bool
    {
        $parts = explode('.', $context);

        if (\count($parts) < 2) {
            return false;
        }

        $component = Factory::getApplication()->bootComponent($parts[0]);

        return $component instanceof WorkflowServiceInterface && $component->isWorkflowActive($context);
    }

    public function logStageEntry(WorkflowTransitionEvent $event): void
    {
        $pks         = $event->getPks();
        $context     = $event->getExtension();
        $transition  = $event->getTransition();
        $toStageId   = (int) $transition->to_stage_id;
        $now         = Factory::getDate()->toSql();
        $triggeredBy = $event->getTriggeredBy();

        $db = $this->getDatabase();

        $pksInt = array_map('intval', $pks);

        // One query to find which items already have a log entry
        $findExistingLogQuery = $db->getQuery(true)
            ->select($db->quoteName(['item_id', 'id']))
            ->from($db->quoteName('#__workflow_item_state'))
            ->whereIn($db->quoteName('item_id'), $pksInt)
            ->where($db->quoteName('extension') . ' = :extension')
            ->bind(':extension', $context);

        $existingLogs = $db->setQuery($findExistingLogQuery)->loadAssocList('item_id', 'id');

        $toInsert = [];
        $toUpdate = [];

        foreach ($pksInt as $pk) {
            if (isset($existingLogs[$pk])) {
                $toUpdate[$pk] = (int) $existingLogs[$pk];
            } else {
                $toInsert[] = $pk;
            }
        }

        // Batch UPDATE existing rows
        if (!empty($toUpdate)) {
            $logIds = array_values($toUpdate);

            $updateStageLogQuery = $db->getQuery(true)
                ->update($db->quoteName('#__workflow_item_state'))
                ->bind(':stageId', $toStageId, ParameterType::INTEGER)
                ->bind(':enteredAt', $now)
                ->bind(':triggeredBy', $triggeredBy);

            $updateStageLogQuery
                ->set($db->quoteName('stage_id') . ' = :stageId')
                ->set($db->quoteName('entered_at') . ' = :enteredAt')
                ->set($db->quoteName('triggered_by') . ' = :triggeredBy')

                // Everything below describes the stage the item has just left, so none of it
                // survives the move.
                //
                // requires_intervention keeps an item out of the scheduler until a person
                // clears it. Reaching this method means a transition has just succeeded, so
                // whatever was stuck no longer is. Leaving the flag set silently excludes the
                // item from a stage where nothing has ever gone wrong, and nothing would ever
                // clear it, because the scheduler never looks at a flagged item again.
                ->set($db->quoteName('requires_intervention') . ' = 0')

                // The scheduler takes candidates least-recently-checked first, treating null as
                // never. An item carrying a stamp from its old stage sorts as though it had just
                // been looked at, so it waits a full rotation before anyone considers it here.
                ->set($db->quoteName('last_checked_at') . ' = NULL')

                // The recorded fault names a rule on the old stage. Beyond showing a warning
                // about a rule this item can no longer reach, a stale reason would suppress the
                // notification for a genuinely new fault, because the scheduler only reports a
                // reason that differs from the one already stored.
                ->set($db->quoteName('last_failure_at') . ' = NULL')
                ->set($db->quoteName('last_failure_reason') . ' = NULL');

            $updateStageLogQuery->whereIn($db->quoteName('id'), $logIds);
            $db->setQuery($updateStageLogQuery)->execute();
        }

        // Batch INSERT new rows
        if (!empty($toInsert)) {
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
                        . $db->quote($context) . ', '
                        . $db->quote($toStageId) . ', '
                        . $db->quote($now) . ', '
                        . $db->quote($triggeredBy)
                );
            }

            $db->setQuery($insertStageLogQuery)->execute();
        }
    }

    /**
     * Seeds the item state row for a brand new content item.
     *
     * onWorkflowAfterTransition does not fire when an item is first created, so the row that
     * records which stage it is in, and since when, is created here instead. Existing items
     * need nothing on save: whether they are due is recomputed from entered_at on every
     * scheduler run, so a change to the item is picked up without any bookkeeping.
     *
     * @param   AfterSaveEvent  $event  The save event.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function reevaluateOnChange(AfterSaveEvent $event): void
    {
        if (!$event->getIsNew()) {
            return;
        }

        $context = $event->getContext();
        $table   = $event->getItem();
        $itemId  = (int) $table->id;

        $workflow = new Workflow($context);
        $stageId  = $workflow->getDefaultStageByCategory($table->catid ?? 0);

        if (!$stageId) {
            return;
        }

        $db          = $this->getDatabase();
        $now         = Factory::getDate()->toSql();
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
                    . $db->quote($context) . ', '
                    . $db->quote((int) $stageId) . ', '
                    . $db->quote($now) . ', '
                    . $db->quote('manual')
            );

        $db->setQuery($insertQuery)->execute();
    }
}
