<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_workflow
 *
 * @copyright (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Workflow\Administrator\Automation;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Resolves condition field values for a content item.
 *
 * The ConditionEvaluator is data-source agnostic; this class is the com_content-specific
 * half that knows how to look up an article's tags, category, and author groups. A later
 * version would replace this with an event so other extensions can supply field values.
 *
 * @since __DEPLOY_VERSION__
 */
final class ItemFieldResolver
{
    /**
     * @var DatabaseInterface
     * @since __DEPLOY_VERSION__
     */
    private DatabaseInterface $database;

    /**
     * @param DatabaseInterface $database The database driver.
     *
     * @since __DEPLOY_VERSION__
     */
    public function __construct(DatabaseInterface $database)
    {
        $this->database = $database;
    }

    /**
     * Preloaded field values, keyed by extension then item id. Populated by preload() so a run
     * of many items costs a fixed number of queries instead of one lookup per item.
     *
     * The extension is part of the key because an item id is only unique within its own
     * extension.
     *
     * @var    array<string, array<int, int[]>>
     * @since  __DEPLOY_VERSION__
     */
    private array $preloadedTags = [];

    /**
     * @var    array<string, array<int, integer>>
     * @since  __DEPLOY_VERSION__
     */
    private array $preloadedCategories = [];

    /**
     * @var    array<string, array<int, int[]>>
     * @since  __DEPLOY_VERSION__
     */
    private array $preloadedAuthorGroups = [];

    /**
     * Which item ids preload() has covered, per extension. Needed to tell "preloaded, and the
     * answer is empty" apart from "never preloaded, go and ask the database".
     *
     * @var    array<string, array<int, boolean>>
     * @since  __DEPLOY_VERSION__
     */
    private array $preloadedItems = [];

    /**
     * Loads every item field for a set of items up front, in a fixed number of queries.
     *
     * Without this, evaluating a filter across N items issues N lookups, because each item
     * asks the database for its own tags. Callers that process a batch should preload it
     * first; callers that handle a single item can skip this and the per-item queries below
     * still answer correctly.
     *
     * @param   int[]   $itemIds    The content item ids about to be evaluated.
     * @param   string  $extension  The workflow extension, e.g. com_content.article.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function preload(array $itemIds, string $extension): void
    {
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));

        if ($itemIds === []) {
            return;
        }

        $db = $this->database;

        // The tag map's primary key starts with type_id and nothing indexes type_alias, so
        // filtering by the alias scans the whole table. Resolving the alias to its id in a
        // subquery lets the primary key do the work, and the subquery uses the alias index.

        $tagTypeSubquery = '(SELECT ' . $db->quoteName('ct.type_id')
            . ' FROM ' . $db->quoteName('#__content_types', 'ct')
            . ' WHERE ' . $db->quoteName('ct.type_alias') . ' = :extension)';

        // Tags for the whole batch in one query.
        $tagQuery = $db->getQuery(true)
            ->select($db->quoteName(['content_item_id', 'tag_id']))
            ->from($db->quoteName('#__contentitem_tag_map'))
            ->where($db->quoteName('type_id') . ' = ' . $tagTypeSubquery)
            ->whereIn($db->quoteName('content_item_id'), $itemIds)
            ->bind(':extension', $extension);

        foreach ($db->setQuery($tagQuery)->loadObjectList() ?: [] as $tagRow) {
            $this->preloadedTags[$extension][(int) $tagRow->content_item_id][] = (int) $tagRow->tag_id;
        }

        // Category and author come from the same table, so one query answers both.
        $itemIdsByAuthor = [];

        if ($extension === 'com_content.article') {
            $contentQuery = $db->getQuery(true)
                ->select($db->quoteName(['id', 'catid', 'created_by']))
                ->from($db->quoteName('#__content'))
                ->whereIn($db->quoteName('id'), $itemIds);

            foreach ($db->setQuery($contentQuery)->loadObjectList() ?: [] as $contentRow) {
                $this->preloadedCategories[$extension][(int) $contentRow->id] = (int) $contentRow->catid;

                if ((int) $contentRow->created_by > 0) {
                    $itemIdsByAuthor[(int) $contentRow->created_by][] = (int) $contentRow->id;
                }
            }
        }

        // Group memberships for every author in the batch, then fanned back out to their items.
        if ($itemIdsByAuthor !== []) {
            $groupQuery = $db->getQuery(true)
                ->select($db->quoteName(['user_id', 'group_id']))
                ->from($db->quoteName('#__user_usergroup_map'))
                ->whereIn($db->quoteName('user_id'), array_keys($itemIdsByAuthor));

            $groupsByAuthor = [];

            foreach ($db->setQuery($groupQuery)->loadObjectList() ?: [] as $groupRow) {
                $groupsByAuthor[(int) $groupRow->user_id][] = (int) $groupRow->group_id;
            }

            foreach ($itemIdsByAuthor as $authorId => $authoredItemIds) {
                foreach ($authoredItemIds as $authoredItemId) {
                    $this->preloadedAuthorGroups[$extension][$authoredItemId] = $groupsByAuthor[$authorId] ?? [];
                }
            }
        }

        // Everything asked for is marked as preloaded, including ids that turned out to have no
        // tags, no category or no author. That is the point: a miss recorded here is the
        // difference between "looked and found nothing" and "never looked", and only the second
        // should fall back to a per-item query.
        $this->preloadedItems[$extension] = array_fill_keys($itemIds, true)
            + ($this->preloadedItems[$extension] ?? []);
    }

    /**
     * Builds a resolver callback bound to one item, for use by the ConditionEvaluator.
     *
     * Looked-up values are cached per field so a rule that references the same field in
     * both its filter and its condition only hits the database once.
     *
     * @param integer $itemId The content item id.
     * @param string $extension The workflow extension, e.g. com_content.article.
     * @param \DateTime|null $evaluationTime The moment the moment-fields (day of week, date)
     * should describe. Defaults to now. Passing a future moment lets a caller ask "would this condition hold
     * then?", which is how the upcoming-transitions views work out when a gated rule will actually fire.
     *
     * @return callable fn(string $fieldName): mixed
     *
     * @since __DEPLOY_VERSION__
     */

    public function forItem(int $itemId, string $extension, ?\DateTime $evaluationTime = null): callable
    {
        $loadedValues = [];

        return function (string $fieldName) use ($itemId, $extension, $evaluationTime, &$loadedValues) {
            if (!\array_key_exists($fieldName, $loadedValues)) {
                $loadedValues[$fieldName] = $this->resolveField($fieldName, $itemId, $extension, $evaluationTime);
            }

            return $loadedValues[$fieldName];
        };
    }

    /**
     * Resolves a single field to the item's value at the evaluation time.
     *
     * @param string $fieldName The field to resolve.
     * @param integer $itemId The content item id.
     * @param string $extension The workflow extension.
     * @param \DateTime|null $evaluationTime The moment to describe, or null for now.
     *
     * @return int|string|array|null
     *
     * @since __DEPLOY_VERSION__
     */
    private function resolveField(
        string $fieldName,
        int $itemId,
        string $extension,
        ?\DateTime $evaluationTime = null
    ): int|string|array|null {
        switch ($fieldName) {
            case 'day_of_week':
                return (int) ($evaluationTime ? $evaluationTime->format('w') : Factory::getDate('now')->format('w'));  // 0 = Sunday

            case 'now':
            case 'date':
                return $evaluationTime ? $evaluationTime->format('Y-m-d H:i:s') : Factory::getDate('now')->toSql();

                // Item fields below are com_content-specific for now; a later version will
                // expose them through an event so other extensions can resolve their own fields.
            case 'tag':
                return $this->loadTagIds($itemId, $extension);

            case 'category':
                return $this->loadCategoryId($itemId, $extension);

            case 'author_group':
                return $this->loadAuthorGroupIds($itemId, $extension);

            default:
                throw new ConditionEvaluationException('Unknown condition field "' . $fieldName . '".');
        }
    }

    /**
     * Loads the tag ids assigned to the item.
     *
     * @param integer $itemId The content id.
     * @param string $extension The workflow extension (used as the tag map type alias).
     *
     * @return integer[]
     *
     * @since __DEPLOY_VERSION__
     */
    private function loadTagIds(int $itemId, string $extension): array
    {
        if (isset($this->preloadedItems[$extension][$itemId])) {
            return $this->preloadedTags[$extension][$itemId] ?? [];
        }

        $tagTypeSubquery = '(SELECT ' . $this->database->quoteName('ct.type_id')
            . ' FROM ' . $this->database->quoteName('#__content_types', 'ct')
            . ' WHERE ' . $this->database->quoteName('ct.type_alias') . ' = :extension)';

        $loadTagsQuery = $this->database->getQuery(true)
            ->select($this->database->quoteName('tag_id'))
            ->from($this->database->quoteName('#__contentitem_tag_map'))
            ->where($this->database->quoteName('type_id') . ' = ' . $tagTypeSubquery)
            ->where($this->database->quoteName('content_item_id') . ' = :itemId')
            ->bind(':extension', $extension)
            ->bind(':itemId', $itemId, ParameterType::INTEGER);

        return array_map('intval', $this->database->setQuery($loadTagsQuery)->loadColumn() ?: []);
    }

    /**
     * Loads the item's category id. Only meaningful for com_content articles.
     *
     * @param integer $itemId The content item id.
     * @param string $extension The workflow extension.
     *
     * @return integer
     *
     * @since __DEPLOY_VERSION__
     */
    private function loadCategoryId(int $itemId, string $extension): int
    {
        if ($extension !== 'com_content.article') {
            return 0;
        }

        if (isset($this->preloadedItems[$extension][$itemId])) {
            return $this->preloadedCategories[$extension][$itemId] ?? 0;
        }

        $loadCategoryQuery = $this->database->getQuery(true)
            ->select($this->database->quoteName('catid'))
            ->from($this->database->quoteName('#__content'))
            ->where($this->database->quoteName('id') . ' = :itemId')
            ->bind(':itemId', $itemId, ParameterType::INTEGER);

        return (int) $this->database->setQuery($loadCategoryQuery)->loadResult();
    }

    /**
     * Loads the user group ids of the item's author
     *
     * @param integer $itemId The content item id.
     * @param string $extension The workflow extension.
     *
     * @return integer[]
     *
     * @since __DEPLOY_VERSION__
     */
    private function loadAuthorGroupIds(int $itemId, string $extension): array
    {
        if ($extension !== 'com_content.article') {
            return [];
        }

        if (isset($this->preloadedItems[$extension][$itemId])) {
            return $this->preloadedAuthorGroups[$extension][$itemId] ?? [];
        }

        $authorQuery = $this->database->getQuery(true)
            ->select($this->database->quoteName('created_by'))
            ->from($this->database->quoteName('#__content'))
            ->where($this->database->quoteName('id') . ' = :itemId')
            ->bind(':itemId', $itemId, ParameterType::INTEGER);
        $authorId = (int) $this->database->setQuery($authorQuery)->loadResult();

        if ($authorId === 0) {
            return [];
        }

        $groupQuery = $this->database->getQuery(true)
            ->select($this->database->quoteName('group_id'))
            ->from($this->database->quoteName('#__user_usergroup_map'))
            ->where($this->database->quoteName('user_id') . ' = :authorId')
            ->bind(':authorId', $authorId, ParameterType::INTEGER);

        return array_map('intval', $this->database->setQuery($groupQuery)->loadColumn() ?: []);
    }
}
