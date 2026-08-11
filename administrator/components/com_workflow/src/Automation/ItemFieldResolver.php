<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_workflow
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Workflow\Administrator\Automation;

use Joomla\CMS\Event\Workflow\WorkflowResolveFieldsEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Resolves check values for items by asking the workflow plugins.
 *
 * This class knows nothing about tags, categories or dates. It knows how to ask, how to ask
 * about many items at once, and how not to ask twice. What a check means is entirely up to
 * whichever plugin declared it.
 *
 * Batching is lazy rather than eager: a caller announces which items it is about to evaluate,
 * and the first time any check is needed it is resolved for that whole set in one go. That
 * keeps a run at one round trip per check, without the caller having to work out in advance
 * which checks a stored rule happens to use.
 *
 * @since  __DEPLOY_VERSION__
 */
final class ItemFieldResolver
{
    /**
     * @var    DatabaseInterface
     * @since  __DEPLOY_VERSION__
     */
    private DatabaseInterface $database;

    /**
     * The items a caller announced through preload(), so a check can be resolved for all of
     * them at once the first time it is asked for.
     *
     * @var    int[]
     * @since  __DEPLOY_VERSION__
     */
    private array $batchItemIds = [];

    /**
     * Resolved values, keyed by check and moment, then by item id. The moment is part of the
     * key because a check that describes the clock gives a different answer at a different
     * time, which is how the upcoming-transitions views ask about future moments.
     *
     * @var    array<string, array<int, mixed>>
     * @since  __DEPLOY_VERSION__
     */
    private array $resolved = [];

    /**
     * Which items have already been asked about, keyed the same way as $resolved. A cached
     * key does not mean every item is covered: a batch resolved earlier will not include an
     * item that only came into play afterwards.
     *
     * @var    array<string, array<int, boolean>>
     * @since  __DEPLOY_VERSION__
     */
    private array $asked = [];

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
     * Announces the items about to be evaluated, so checks can be resolved for the whole set.
     *
     * Nothing is fetched here. The work happens the first time a check is actually needed,
     * which avoids loading checks that the stored rules never mention.
     *
     * @param   int[]   $itemIds    The content item ids.
     * @param   string  $extension  The workflow extension. Unused, kept so callers read clearly.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function preload(array $itemIds, string $extension): void
    {
        $this->batchItemIds = array_values(array_unique(
            array_merge($this->batchItemIds, array_map('intval', $itemIds))
        ));
    }

    /**
     * Builds a resolver callback bound to one item, for use by the ConditionEvaluator.
     *
     * @param   integer         $itemId          The content item id.
     * @param   string          $extension       The workflow extension, e.g. com_content.article.
     * @param   \DateTime|null  $evaluationTime  The moment clock-based checks should describe,
     *                                           or null for now.
     *
     * @return  callable  fn(string $fieldName): mixed
     *
     * @since   __DEPLOY_VERSION__
     */
    public function forItem(int $itemId, string $extension, ?\DateTime $evaluationTime = null): callable
    {
        return function (string $fieldName) use ($itemId, $extension, $evaluationTime) {
            return $this->valueFor($fieldName, $itemId, $extension, $evaluationTime);
        };
    }

    /**
     * One item's value for one check, resolving the whole batch on first use.
     *
     * @param   string          $fieldName       The check.
     * @param   integer         $itemId          The item.
     * @param   string          $extension       The workflow extension.
     * @param   \DateTime|null  $evaluationTime  The moment to describe, or null for now.
     *
     * @return  mixed
     *
     * @throws  ConditionEvaluationException  When no plugin claims the check, or the plugin that
     *                                        does had no value for this item.
     *
     * @since   __DEPLOY_VERSION__
     */
    private function valueFor(string $fieldName, int $itemId, string $extension, ?\DateTime $evaluationTime)
    {
        $cacheKey = $fieldName . '|' . ($evaluationTime ? $evaluationTime->getTimestamp() : 'now');

        if (!isset($this->asked[$cacheKey][$itemId])) {
            // Resolve for the announced batch when this item belongs to it, otherwise just for
            // this one item, so single-item callers still work without preloading.
            $itemIds = \in_array($itemId, $this->batchItemIds, true) ? $this->batchItemIds : [$itemId];

            // Merge rather than replace: an earlier call may have resolved other items under
            // this same key, and those answers are still good.
            $this->resolved[$cacheKey] = ($this->resolved[$cacheKey] ?? [])
                + $this->askPlugins($fieldName, $itemIds, $extension, $evaluationTime);

            foreach ($itemIds as $askedItemId) {
                $this->asked[$cacheKey][$askedItemId] = true;
            }
        }

        // The check exists but its provider had no value for this item, which is not the same
        // as an empty one. Comparing against a missing value would be a guess, so the item is
        // reported and skipped instead. A remote source that timed out lands here.
        if (!\array_key_exists($itemId, $this->resolved[$cacheKey])) {
            throw new ConditionEvaluationException(\sprintf(
                'The extension providing the "%s" check returned no value for item %s.%d.',
                $fieldName,
                $extension,
                $itemId
            ));
        }

        return $this->resolved[$cacheKey][$itemId];
    }

    /**
     * Asks the workflow plugins to resolve one check for a set of items.
     *
     * @param   string          $fieldName       The check.
     * @param   int[]           $itemIds         The items.
     * @param   string          $extension       The workflow extension.
     * @param   \DateTime|null  $evaluationTime  The moment to describe, or null for now.
     *
     * @return  array<int, mixed>
     *
     * @throws  ConditionEvaluationException  When no plugin claims the check.
     *
     * @since   __DEPLOY_VERSION__
     */
    private function askPlugins(string $fieldName, array $itemIds, string $extension, ?\DateTime $evaluationTime): array
    {
        PluginHelper::importPlugin('workflow');

        $app   = Factory::getApplication();
        $event = new WorkflowResolveFieldsEvent(
            'onWorkflowResolveConditionFields',
            [
                'field'          => $fieldName,
                'itemIds'        => $itemIds,
                'context'        => $extension,
                'evaluationTime' => $evaluationTime,
            ]
        );

        $app->getDispatcher()->dispatch($event->getName(), $event);

        // Nobody claimed the check at all, so the rule refers to something this site no longer
        // has. Kept separate from a provider that answered for no items, because that is a
        // working extension having a bad day rather than a missing one, and sending someone to
        // the plugin manager to look for a timeout wastes their afternoon.
        if (!$event->isAnswered()) {
            throw new ConditionEvaluationException(\sprintf(
                'No installed extension provides the "%s" check that this rule uses on %s. '
                    . 'The extension that supplied it has probably been disabled or uninstalled.',
                $fieldName,
                $extension
            ));
        }

        return $event->getValues();
    }
}
