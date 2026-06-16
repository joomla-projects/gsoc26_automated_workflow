<?php

namespace Joomla\Plugin\Workflow\Automation\Extension;

use DateInterval;
use DateTime;
use Joomla\CMS\Event\Model\AfterSaveEvent;
use Joomla\CMS\Event\Workflow\WorkflowTransitionEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

final class Automation extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return [
            'onWorkflowAfterTransition' => 'logStageEntry',
            'onContentAfterSave' => 'reevaluateOnChange',
        ];
    }

    public function logStageEntry(WorkflowTransitionEvent $event): void
    {
        $pks         = $event->getArgument('pks');
        $context     = $event->getArgument('extension');
        $transition  = $event->getArgument('transition');
        $toStageId   = (int) $transition->to_stage_id;
        $now         = Factory::getDate()->toSql();
        $triggeredBy = Factory::getApplication()->get('workflow.triggered_by', 'manual');

        $rules            = $this->findRulesForStage($toStageId);
        $nextTransitionAt = $this->computeEarliestNextTransitionAt($now, $rules);

        $db = $this->getDatabase();

        foreach ($pks as $pk) {
            $pk = (int) $pk;

            $query = $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__workflow_stage_log'))
                ->where($db->quoteName('item_id') . ' = :itemId')
                ->where($db->quoteName('extension') . ' = :extension')
                ->bind(':itemId', $pk, ParameterType::INTEGER)
                ->bind(':extension', $context);

            $existingId = (int) $db->setQuery($query)->loadResult();

            if ($existingId) {
                $query = $db->getQuery(true)
                    ->update($db->quoteName('#__workflow_stage_log'))
                    ->where($db->quoteName('id') . ' = :id')
                    ->bind(':id', $existingId, ParameterType::INTEGER)
                    ->bind(':stageId', $toStageId, ParameterType::INTEGER)
                    ->bind(':enteredAt', $now)
                    ->bind(':triggeredBy', $triggeredBy);

                if ($nextTransitionAt === null) {
                    $query->set($db->quoteName('stage_id') . ' = :stageId')
                        ->set($db->quoteName('entered_at') . ' = :enteredAt')
                        ->set($db->quoteName('next_transition_at') . ' = NULL')
                        ->set($db->quoteName('triggered_by') . ' = :triggeredBy');
                } else {
                    $query->set($db->quoteName('stage_id') . ' = :stageId')
                        ->set($db->quoteName('entered_at') . ' = :enteredAt')
                        ->set($db->quoteName('next_transition_at') . ' = :nextTransitionAt')
                        ->set($db->quoteName('triggered_by') . ' = :triggeredBy')
                        ->bind(':nextTransitionAt', $nextTransitionAt);
                }
            } else {
                $query = $db->getQuery(true)
                    ->insert($db->quoteName('#__workflow_stage_log'))
                    ->columns($db->quoteName([
                        'item_id',
                        'extension',
                        'stage_id',
                        'entered_at',
                        'next_transition_at',
                        'triggered_by',
                    ]));

                if ($nextTransitionAt === null) {
                    $query->values(':itemId, :extension, :stageId, :enteredAt, NULL, :triggeredBy')
                        ->bind(':itemId', $pk, ParameterType::INTEGER)
                        ->bind(':extension', $context)
                        ->bind(':stageId', $toStageId, ParameterType::INTEGER)
                        ->bind(':enteredAt', $now)
                        ->bind(':triggeredBy', $triggeredBy);
                } else {
                    $query->values(':itemId, :extension, :stageId, :enteredAt, :nextTransitionAt, :triggeredBy')
                        ->bind(':itemId', $pk, ParameterType::INTEGER)
                        ->bind(':extension', $context)
                        ->bind(':stageId', $toStageId, ParameterType::INTEGER)
                        ->bind(':enteredAt', $now)
                        ->bind(':nextTransitionAt', $nextTransitionAt)
                        ->bind(':triggeredBy', $triggeredBy);
                }
            }

            $db->setQuery($query)->execute();
        }
    }

    public function reevaluateOnChange(AfterSaveEvent $event): void
    {
        $context = $event->getContext();
        $table = $event->getItem();
        $itemId = (int) $table->id;
        $db = $this->getDatabase();

        // load this item's stage log entry
        $query = $db->getQuery(true)
        ->select($db->quoteName(['id', 'stage_id', 'entered_at', 'next_transition_at']))
        ->from($db->quoteName('#__workflow_stage_log'))
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

        // Already flaged for immediate check, scheduler will pick it up
        if ($log->next_transition_at <= $now) {
            return;
        }

        $rules = $this->findRulesForStage((int) $log->stage_id);

        foreach ($rules as $rule) {
            $deadline = $this->computeNextTransitionAt($log->entered_at, $rule);

            if ($deadline !== null && $deadline <= $now) {
                $update = $db->getQuery(true)
                    ->update($db->quoteName('#__workflow_stage_log'))
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
                'wta.interval_value',
                'wta.interval_unit',
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
        // Cron-based rules cannot pre-compute a simple offset. So I'll return null for now and the task plugin will handle cron matching at run time
        if ($rule->rule_type === 'cron') {
            return null;
        }

        $date = new DateTime($enteredAt);
        $interval = match ($rule->interval_unit) {
            'minutes' => new DateInterval('PT' . $rule->interval_value . 'M'),
            'hours' => new DateInterval('PT' . $rule->interval_value . 'H'),
            'days' => new DateInterval('P' . $rule->interval_value . 'D'),
            'months' => new DateInterval('P' . $rule->interval_value . 'M'),
            default => null,
        };

        if ($interval === null) {
            return null;
        }

        return $date->add($interval)->format('Y-m-d H:i:s');
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
