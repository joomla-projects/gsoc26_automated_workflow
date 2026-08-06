<?php

namespace Joomla\Plugin\Workflow\Automation\Extension;

use Cron\CronExpression;
use Joomla\CMS\Event\Model\AfterSaveEvent;
use Joomla\CMS\Event\Workflow\WorkflowTransitionEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Workflow\Workflow;
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
        ];
    }

    public function logStageEntry(WorkflowTransitionEvent $event): void
    {
        $pks         = $event->getPks();
        $context     = $event->getExtension();
        $transition  = $event->getTransition();
        $toStageId   = (int) $transition->to_stage_id;
        $now         = Factory::getDate()->toSql();
        $triggeredBy = $event->getTriggeredBy();

        $rules            = $this->findRulesForStage($toStageId);
        $nextTransitionAt = $this->computeEarliestNextTransitionAt($now, $rules);

        $db = $this->getDatabase();

        $pksInt = array_map('intval', $pks);

        // One query to find which items already have a log entry
        $findExistingLogQuery = $db->getQuery(true)
            ->select($db->quoteName(['item_id', 'id']))
            ->from($db->quoteName('#__workflow_automation_schedule'))
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
                ->update($db->quoteName('#__workflow_automation_schedule'))
                ->bind(':stageId', $toStageId, ParameterType::INTEGER)
                ->bind(':enteredAt', $now)
                ->bind(':triggeredBy', $triggeredBy);

            if ($nextTransitionAt === null) {
                $updateStageLogQuery
                    ->set($db->quoteName('stage_id') . ' = :stageId')
                    ->set($db->quoteName('entered_at') . ' = :enteredAt')
                    ->set($db->quoteName('next_transition_at') . ' = NULL')
                    ->set($db->quoteName('triggered_by') . ' = :triggeredBy');
            } else {
                $updateStageLogQuery
                    ->set($db->quoteName('stage_id') . ' = :stageId')
                    ->set($db->quoteName('entered_at') . ' = :enteredAt')
                    ->set($db->quoteName('next_transition_at') . ' = :nextTransitionAt')
                    ->set($db->quoteName('triggered_by') . ' = :triggeredBy')
                    ->bind(':nextTransitionAt', $nextTransitionAt);
            }

            $updateStageLogQuery->whereIn($db->quoteName('id'), $logIds);
            $db->setQuery($updateStageLogQuery)->execute();
        }

        // Batch INSERT new rows
        if (!empty($toInsert)) {
            $insertStageLogQuery = $db->getQuery(true)
                ->insert($db->quoteName('#__workflow_automation_schedule'))
                ->columns($db->quoteName([
                    'item_id',
                    'extension',
                    'stage_id',
                    'entered_at',
                    'next_transition_at',
                    'triggered_by',
                ]));

            foreach ($toInsert as $pk) {
                if ($nextTransitionAt === null) {
                    $insertStageLogQuery->values(
                        $db->quote($pk) . ', '
                            . $db->quote($context) . ', '
                            . $db->quote($toStageId) . ', '
                            . $db->quote($now) . ', '
                            . 'NULL, '
                            . $db->quote($triggeredBy)
                    );
                } else {
                    $insertStageLogQuery->values(
                        $db->quote($pk) . ', '
                            . $db->quote($context) . ', '
                            . $db->quote($toStageId) . ', '
                            . $db->quote($now) . ', '
                            . $db->quote($nextTransitionAt) . ', '
                            . $db->quote($triggeredBy)
                    );
                }
            }

            $db->setQuery($insertStageLogQuery)->execute();
        }
    }

    /**
     * Re-evaluates the scheduled next transition when a content item is saved.
     *
     * If an item's conditions (e.g tags, category) change after it enters a stage,
     * this hook checks whether any automation rule's deadline has already passed.
     * If so, it sets next_transition_at to NOW() so the scheduler picks up the
     * item on its next cycle rather than waiting for the original deadline.
     */
    public function reevaluateOnChange(AfterSaveEvent $event): void
    {
        $context = $event->getContext();
        $table   = $event->getItem();
        $isNew   = $event->getIsNew();
        $itemId  = (int) $table->id;
        $db      = $this->getDatabase();

        // Seed the schedule row for brand new articles
        // onWorkflowAfterTransition does not fire on initial article creation
        // We detect the stage here through the category and create the row.

        if ($isNew) {
            $workflow = new Workflow($context);
            $stageId  = $workflow->getDefaultStageByCategory($table->catid ?? 0);

            if (!$stageId) {
                return;
            }

            $rules = $this->findRulesForStage((int) $stageId);

            if (empty($rules)) {
                return;
            }

            $now              = Factory::getDate()->toSql();
            $nextTransitionAt = $this->computeEarliestNextTransitionAt($now, $rules);

            $insertQuery = $db->getQuery(true)
                ->insert($db->quoteName('#__workflow_automation_schedule'))
                ->columns($db->quoteName([
                    'item_id',
                    'extension',
                    'stage_id',
                    'entered_at',
                    'next_transition_at',
                    'triggered_by',
                ]))
                ->values(
                    $db->quote($itemId) . ', '
                        . $db->quote($context) . ', '
                        . $db->quote((int) $stageId) . ', '
                        . $db->quote($now) . ', '
                        . ($nextTransitionAt !== null ? $db->quote($nextTransitionAt) : 'NULL') . ', '
                        . $db->quote('manual')
                );

            $db->setQuery($insertQuery)->execute();

            return;
        }

        // load this item's stage log entry
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'stage_id', 'entered_at', 'next_transition_at']))
            ->from($db->quoteName('#__workflow_automation_schedule'))
            ->where($db->quoteName('item_id') . ' = :itemId')
            ->where($db->quoteName('extension') . ' = :extension')
            ->bind(':itemId', $itemId, ParameterType::INTEGER)
            ->bind(':extension', $context);

        $log = $db->setQuery($query)->loadObject();

        // Nothing to do if item is not being automated
        if (!$log || $log->next_transition_at === null) {
            return;
        }

        $now = Factory::getDate()->toSql();

        // Already flagged for immediate check, scheduler will pick it up
        if ($log->next_transition_at <= $now) {
            return;
        }

        $rules = $this->findRulesForStage((int) $log->stage_id);

        foreach ($rules as $rule) {
            $deadline = $this->computeNextTransitionAt($log->entered_at, $rule);

            if ($deadline !== null && $deadline <= $now) {
                $update = $db->getQuery(true)
                    ->update($db->quoteName('#__workflow_automation_schedule'))
                    ->set($db->quoteName('next_transition_at') . ' = :now')
                    ->where($db->quoteName('id') . ' = :logId')
                    ->bind(':now', $now)
                    ->bind(':logId', $log->id, ParameterType::INTEGER);

                $db->setQuery($update)->execute();

                return;
            }
        }
    }

    private function findRulesForStage(int $stageId): array
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName([
                'wta.delay_value',
                'wta.delay_unit',
                'wta.rule_type',
                'wta.cron_expression',
                'wta.ordering',
            ]))
            ->from($db->quoteName('#__workflow_transition_automation', 'wta'))
            ->join(
                'INNER',
                $db->quoteName('#__workflow_transitions', 'wt')
                    . ' ON ' . $db->quoteName('wta.transition_id')
                    . ' = ' . $db->quoteName('wt.id')
            )
            ->where($db->quoteName('wt.from_stage_id') . ' = :stageId')
            ->where($db->quoteName('wta.published') . ' = 1')
            ->bind(':stageId', $stageId, ParameterType::INTEGER)
            ->order($db->quoteName('wta.ordering') . ' ASC');

        return $db->setQuery($query)->loadObjectList() ?: [];
    }

    private function computeNextTransitionAt(string $enteredAt, object $rule): ?string
    {
        if ($rule->rule_type === 'cron') {
            if (empty($rule->cron_expression)) {
                return null;
            }

            $cron     = new CronExpression($rule->cron_expression);
            $deadline = $cron->getNextRunDate(
                $enteredAt,
                0,
                false,
                Factory::getApplication()->get('offset', 'UTC')
            );
            $deadline->setTimezone(new \DateTimeZone('UTC'));

            return $deadline->format('Y-m-d H:i:s');
        }

        $date     = new \DateTime($enteredAt, new \DateTimeZone('UTC'));
        $delay    = match ($rule->delay_unit) {
            'minutes' => new \DateInterval('PT' . $rule->delay_value . 'M'),
            'hours'   => new \DateInterval('PT' . $rule->delay_value . 'H'),
            'days'    => new \DateInterval('P' . $rule->delay_value . 'D'),
            'months'  => new \DateInterval('P' . $rule->delay_value . 'M'),
            default   => null,
        };

        return $delay ? $date->add($delay)->format('Y-m-d H:i:s') : null;
    }

    private function computeEarliestNextTransitionAt(string $enteredAt, array $rules): ?string
    {
        $earliest = null;

        foreach ($rules as $rule) {
            $candidate = $this->computeNextTransitionAt($enteredAt, $rule);

            if ($candidate === null) {
                continue;
            }

            if ($earliest === null || $candidate < $earliest) {
                $earliest = $candidate;
            }
        }

        return $earliest;
    }
}
