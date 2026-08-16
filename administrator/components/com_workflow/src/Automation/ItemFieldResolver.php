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
 * Resolves check values for the items a rule is being evaluated against.
 *
 * Joomla's own checks are answered by BuiltinConditionFields directly; anything else is put to
 * the workflow plugins through an event. This class does not know what any check means. Its job
 * is knowing how to ask, how to ask about many items at once, and how not to ask twice.
 *
 * Batching is lazy rather than eager: a caller announces which items it is about to evaluate,
 * and the first time any check is needed it is resolved for that whole set in one go. That
 * keeps a run at one round trip per check, without the caller having to work out in advance
 * which checks a stored rule happens to use.
 *
 * Everything is kept per extension, because an item id is only unique within its own: article 5
 * and contact 5 are different items that must not share an answer.
 *
 * @since  __DEPLOY_VERSION__
 */
final class ItemFieldResolver
{
    /**
     * The checks Joomla ships with. Held for the resolver's lifetime so its content type
     * lookups are cached across every check a run evaluates.
     *
     * @var BuiltInConditionFields
     * @since __DEPLOY_VERSION__
     */
    private BuiltinConditionFields $builtinFields;

    /**
     * The items a caller announced through preload(), grouped by extension, so a check can be
     * resolved for a whole set at once the first time it is asked for.
     *
     * @var    array<string, int[]>
     * @since  __DEPLOY_VERSION__
     */
    private array $batchItemIds = [];

    /**
     * Resolved values, keyed by extension, check and moment, then by item id. The moment is part
     * of the key because a check that describes the clock gives a different answer at a different
     * time, which is how the upcoming-transitions views ask about future moments.
     *
     * @var    array<string, array<int, mixed>>
     * @since  __DEPLOY_VERSION__
     */
    private array $resolved = [];

    /**
     * Which items have already been asked about, keyed the same way as $resolved. A cached key
     * does not mean every item is covered: a batch resolved earlier will not include an item
     * that only came into play afterwards.
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
        $this->builtinFields = new BuiltinConditionFields($database);
    }

    /**
     * Announces the items about to be evaluated, so checks can be resolved for the whole set.
     *
     * Nothing is fetched here. The work happens the first time a check is actually needed,
     * which avoids loading checks that the stored rules never mention.
     *
     * @param   int[]   $itemIds    The content item ids.
     * @param   string  $extension  The workflow extension those ids belong to.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function preload(array $itemIds, string $extension): void
    {
        $this->batchItemIds[$extension] = array_values(array_unique(
            array_merge($this->batchItemIds[$extension] ?? [], array_map('intval', $itemIds))
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
     * @throws  ConditionEvaluationException  When nothing provides the check, or whatever does
     * had no value for this item.
     *
     * @since   __DEPLOY_VERSION__
     */
    private function valueFor(string $fieldName, int $itemId, string $extension, ?\DateTime $evaluationTime)
    {
        // The extension is part of the key because an item id is only unique within it. Without
        // it, article 5 and contact 5 would share an answer.
        $cacheKey = $extension . '|' . $fieldName . '|'
            . ($evaluationTime ? $evaluationTime->getTimestamp() : 'now');

        $announced = $this->batchItemIds[$extension] ?? [];

        if (!isset($this->asked[$cacheKey][$itemId])) {
            // Resolve for the announced batch when this item belongs to it, otherwise just for
            // this one item, so single-item callers still work without preloading.
            $itemIds = \in_array($itemId, $announced, true) ? $announced : [$itemId];

            // Merge rather than replace: an earlier call may have resolved other items under
            // this same key, and those answers are still good.
            $this->resolved[$cacheKey] = ($this->resolved[$cacheKey] ?? [])
                + $this->resolveBatch($fieldName, $itemIds, $extension, $evaluationTime);

            foreach ($itemIds as $askedItemId) {
                $this->asked[$cacheKey][$askedItemId] = true;
            }
        }

        // The check exists but whatever provides it had no value for this item, which is not the
        // same as an empty one. Comparing against a missing value would be a guess, so the item
        // is reported and skipped instead. A remote source that timed out lands here.
        if (!\array_key_exists($itemId, $this->resolved[$cacheKey])) {
            throw new ConditionEvaluationException(\sprintf(
                'Nothing could resolve a value for the "%s" check on item %s.%d.',
                $fieldName,
                $extension,
                $itemId
            ));
        }

        return $this->resolved[$cacheKey][$itemId];
    }

    /**
     * Resolves one check for a set of items, from Joomla's own checks or from a plugin.
     *
     * Built-in checks are answered directly, so a run that only uses them dispatches
     * nothing. The event is a fallback for names com_workflow does not recognize, which also means
     * a plugin cannot shadow a built-in and change what a saved rule means.
     *
     * @param string $fieldName The check.
     * @param int[] $itemIds The items.
     * @param string $extension The workflow extension.
     * @param \DateTime|null $evaluationTime The moment to describe, or null for now
     *
     * @return array<int, mixed>
     *
     * @throws ConditionEvaluationException When nothing provides the check.
     *
     * @since __DEPLOY_VERSION__
     */
    private function resolveBatch(
        string $fieldName,
        array $itemIds,
        string $extension,
        ?\DateTime $evaluationTime
    ): array {
        $builtinValues = $this->builtinFields->resolve($fieldName, $itemIds, $extension, $evaluationTime);

        // Null means the name is not one of Joomla's own, so it is worth asking the plugins.
        // An empty array means it is ours and we had no values, which is a different answer.
        if ($builtinValues !== null) {
            return $builtinValues;
        }

        return $this->askPlugins($fieldName, $itemIds, $extension, $evaluationTime);
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
                'extension'      => $extension,
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
