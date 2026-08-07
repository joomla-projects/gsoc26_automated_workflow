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
     * Builds a resolver callback bound to one item, for use by the ConditionEvaluator.
     *
     * Looked-up values are cached per field so a rule that references the same field in
     * both its filter and its condition only hits the database once.
     *
     * @params integer $itemId The content item id.
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
        $loadTagsQuery = $this->database->getQuery(true)
            ->select($this->database->quoteName('tag_id'))
            ->from($this->database->quoteName('#__contentitem_tag_map'))
            ->where($this->database->quoteName('type_alias') . ' = :extension')
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
