<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_workflow
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Workflow\Administrator\Automation;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Answers which items an item filter would match, before the rule is saved.
 *
 * A filter matching nothing looks exactly like one that is not due yet, so without this an
 * administrator cannot tell a mistake from a wait. Evaluation goes through the same
 * ConditionEvaluator and ItemFieldResolver the scheduler uses; a preview that disagreed with the
 * run would be worse than no preview at all.
 *
 * @since __DEPLOY_VERSION__
 */
final class FilterPreview
{
    /**
     * How many items are examined before the answer is reported as a partial one.
     *
     * @since __DEPLOY_VERSION__
     */
    public const DEFAULT_LIMIT = 200;

    /**
     * @var DatabaseInterface
     * @since __DEPLOY_VERSION__
     */
    private DatabaseInterface $database;

    /**
     * @param   DatabaseInterface  $database  The database driver.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(DatabaseInterface $database)
    {
        $this->database = $database;
    }

    /**
     * Evaluates an unsaved filter against the items a transition could act on.
     *
     * @param   integer      $transitionId  The transition the rule belongs to.
     * @param   string|null  $filterJson    The expression as the builder currently has it.
     * @param   integer      $limit         How many items to examine at most.
     *
     * @return  array{scanned:int, matched:int, titles:array<int,string>, capped:bool}
     *
     * @throws  ConditionEvaluationException  When the expression cannot be read.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function forTransition(
        int $transitionId,
        ?string $filterJson,
        int $limit = self::DEFAULT_LIMIT
    ): array {
        $empty      = ['scanned' => 0, 'matched' => 0, 'titles' => [], 'capped' => false];
        $transition = $this->loadTransition($transitionId);

        if ($transition === null) {
            return $empty;
        }

        $extension    = $transition->extension;
        $candidateIds = $this->itemsInScope($transition, $limit);

        if ($candidateIds === []) {
            return $empty;
        }

        $itemStorage = new ItemStorage($this->database);
        $scanIds     = array_values(
            array_diff($candidateIds, $itemStorage->trashedOrArchivedIds($candidateIds, $extension))
        );

        $fieldResolver = new ItemFieldResolver($this->database);
        $fieldResolver->preload($scanIds, $extension);

        $evaluator  = new ConditionEvaluator();
        $matchedIds = [];

        foreach ($scanIds as $itemId) {
            if ($evaluator->evaluate($filterJson, $fieldResolver->forItem($itemId, $extension))) {
                $matchedIds[] = $itemId;
            }
        }

        return [
            'scanned' => \count($scanIds),
            'matched' => \count($matchedIds),
            'titles'  => array_values($itemStorage->titlesFor(\array_slice($matchedIds, 0, 10), $extension)),
            'capped'  => \count($candidateIds) >= $limit,
        ];
    }

    /**
     * The transition's source stage and the extension its workflow belongs to.
     *
     * The extension is read from the workflow rather than accepted from the caller: com_workflow
     * URLs carry it as either "com_content" or "com_content.article", and only the longer form
     * matches what #__workflow_associations stores.
     *
     * @param   integer  $transitionId  The transition to describe.
     *
     * @return  object|null
     *
     * @since   __DEPLOY_VERSION__
     */
    private function loadTransition(int $transitionId): ?object
    {
        $db = $this->database;

        $query = $db->getQuery(true)
            ->select(
                [
                    $db->quoteName('t.from_stage_id'),
                    $db->quoteName('t.workflow_id'),
                    $db->quoteName('w.extension'),
                ]
            )
            ->from($db->quoteName('#__workflow_transitions', 't'))
            ->join(
                'INNER',
                $db->quoteName('#__workflows', 'w'),
                $db->quoteName('w.id') . ' = ' . $db->quoteName('t.workflow_id')
            )
            ->where($db->quoteName('t.id') . ' = :transitionId')
            ->bind(':transitionId', $transitionId, ParameterType::INTEGER);

        return $db->setQuery($query)->loadObject();
    }

    /**
     * The items a transition could act on, newest first.
     *
     * @param   object   $transition  A row from loadTransition().
     * @param   integer  $limit       How many ids to return at most.
     *
     * @return  int[]
     *
     * @since   __DEPLOY_VERSION__
     */
    private function itemsInScope(object $transition, int $limit): array
    {
        $db          = $this->database;
        $fromStageId = (int) $transition->from_stage_id;
        $workflowId  = (int) $transition->workflow_id;
        $extension   = $transition->extension;

        $query = $db->getQuery(true)
            ->select($db->quoteName('wa.item_id'))
            ->from($db->quoteName('#__workflow_associations', 'wa'))
            ->where($db->quoteName('wa.extension') . ' = :extension')
            ->bind(':extension', $extension, ParameterType::STRING)
            ->order($db->quoteName('wa.item_id') . ' DESC');

        if ($fromStageId === -1) {
            // A wildcard transition starts from any stage in its own workflow.
            $query->join(
                'INNER',
                $db->quoteName('#__workflow_stages', 'ws'),
                $db->quoteName('ws.id') . ' = ' . $db->quoteName('wa.stage_id')
            )
                ->where($db->quoteName('ws.workflow_id') . ' = :workflowId')
                ->bind(':workflowId', $workflowId, ParameterType::INTEGER);
        } else {
            $query->where($db->quoteName('wa.stage_id') . ' = :stageId')
                ->bind(':stageId', $fromStageId, ParameterType::INTEGER);
        }

        return array_map('intval', $db->setQuery($query, 0, $limit)->loadColumn());
    }
}
