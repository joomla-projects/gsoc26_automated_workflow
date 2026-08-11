<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Workflow.ConditionFields
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Workflow\ConditionFields\Extension;

use Joomla\CMS\Event\Workflow\WorkflowConditionFieldsEvent;
use Joomla\CMS\Event\Workflow\WorkflowResolveFieldsEvent;
use Joomla\CMS\Helper\UserGroupsHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Supplies the checks that ship with Joomla for automation filters and conditions.
 *
 * These used to be hardcoded inside com_workflow. They live here so that core's own checks
 * arrive through exactly the same contract a third party would use, which means the contract
 * is exercised by default rather than only by whoever writes the first extension.
 *
 * @since  __DEPLOY_VERSION__
 */
final class ConditionFields extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    /**
     * @var    boolean
     * @since  __DEPLOY_VERSION__
     */
    protected $autoloadLanguage = true;

    /**
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onWorkflowListConditionFields'    => 'listConditionFields',
            'onWorkflowResolveConditionFields' => 'resolveConditionFields',
        ];
    }

    /**
     * Offers the built-in checks to the condition builder.
     *
     * @param   WorkflowConditionFieldsEvent  $event  The collecting event.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function listConditionFields(WorkflowConditionFieldsEvent $event): void
    {
        // Labels are read the moment the builder asks, which can be before the plugin's
        // language has been auto-loaded.
        $this->loadLanguage();

        // Moment checks work for any context, because they describe the clock and not the item.
        $event->addField(
            'day_of_week',
            Text::_('PLG_WORKFLOW_CONDITIONFIELDS_FIELD_DAY_OF_WEEK'),
            WorkflowConditionFieldsEvent::SCOPE_MOMENT,
            ['in', 'not in'],
            'multiselect',
            $this->getWeekdayOptions()
        );

        $event->addField(
            'date',
            Text::_('PLG_WORKFLOW_CONDITIONFIELDS_FIELD_DATE'),
            WorkflowConditionFieldsEvent::SCOPE_MOMENT,
            ['after', 'before', 'on', 'not on'],
            'date'
        );

        // Tags are available to anything that uses Joomla's tagging.
        $event->addField(
            'tag',
            Text::_('PLG_WORKFLOW_CONDITIONFIELDS_FIELD_TAG'),
            WorkflowConditionFieldsEvent::SCOPE_ITEM,
            ['has any', 'has all', 'has none'],
            'multiselect',
            $this->getTagOptions()
        );

        // Category and author are com_content specific, so they are only offered there.
        if ($event->getContext() !== 'com_content.article') {
            return;
        }

        $event->addField(
            'category',
            Text::_('PLG_WORKFLOW_CONDITIONFIELDS_FIELD_CATEGORY'),
            WorkflowConditionFieldsEvent::SCOPE_ITEM,
            ['is', 'is not'],
            'select',
            $this->getCategoryOptions()
        );

        $event->addField(
            'author_group',
            Text::_('PLG_WORKFLOW_CONDITIONFIELDS_FIELD_AUTHOR_GROUP'),
            WorkflowConditionFieldsEvent::SCOPE_ITEM,
            ['has any', 'has all', 'has none'],
            'select',
            $this->getUserGroupOptions()
        );

        // There is no list of hit counts to choose from, so the number is typed rather than
        // picked. A check does not have to offer options at all.
        $event->addField(
            'hits',
            // Joomla's own word for this, used by the article list column and its sort options.
            // A different one here would leave the filter and the column it filters on disagreeing.
            Text::_('JGLOBAL_HITS'),
            WorkflowConditionFieldsEvent::SCOPE_ITEM,
            ['greater than', 'less than', 'is', 'is not'],
            'number'
        );
    }

    /**
     * Resolves one built-in check for a whole batch of items.
     *
     * @param   WorkflowResolveFieldsEvent  $event  The resolving event.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function resolveConditionFields(WorkflowResolveFieldsEvent $event): void
    {
        $itemIds = array_map('intval', $event->getItemIds());

        switch ($event->getField()) {
            case 'day_of_week':
                $event->setValues($this->sameForEveryItem($itemIds, (int) $this->momentOf($event)->format('w')));

                return;

            case 'date':
                $event->setValues($this->sameForEveryItem($itemIds, $this->momentOf($event)->format('Y-m-d H:i:s')));

                return;

            case 'tag':
                $event->setValues($this->loadTagIds($itemIds, $event->getContext()));

                return;

            case 'category':
                $event->setValues($this->loadCategoryIds($itemIds, $event->getContext()));

                return;

            case 'author_group':
                $event->setValues($this->loadAuthorGroupIds($itemIds, $event->getContext()));

                return;

            case 'hits':
                $event->setValues($this->loadHits($itemIds, $event->getContext()));

                return;
        }

        // Not one of ours, so leave the event alone for another plugin to answer.
    }

    /**
     * The moment a check should describe: the one supplied by the caller, or now.
     *
     * @param   WorkflowResolveFieldsEvent  $event  The resolving event.
     *
     * @return  \DateTime
     *
     * @since   __DEPLOY_VERSION__
     */
    private function momentOf(WorkflowResolveFieldsEvent $event): \DateTime
    {
        return $event->getEvaluationTime() ?? new \DateTime('now', new \DateTimeZone('UTC'));
    }

    /**
     * Gives every item the same value, for checks that describe the clock rather than the item.
     *
     * @param   int[]  $itemIds  The items being resolved.
     * @param   mixed  $value    The value they all share.
     *
     * @return  array<int, mixed>
     *
     * @since   __DEPLOY_VERSION__
     */
    private function sameForEveryItem(array $itemIds, $value): array
    {
        return $itemIds === [] ? [] : array_fill_keys($itemIds, $value);
    }

    /**
     * Tag ids per item, in one query.
     *
     * @param   int[]   $itemIds  The items.
     * @param   string  $context  The workflow context, used as the tag type alias.
     *
     * @return  array<int, int[]>
     *
     * @since   __DEPLOY_VERSION__
     */
    private function loadTagIds(array $itemIds, string $context): array
    {
        if ($itemIds === []) {
            return [];
        }

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['content_item_id', 'tag_id']))
            ->from($db->quoteName('#__contentitem_tag_map'))
            ->where($db->quoteName('type_alias') . ' = :context')
            ->whereIn($db->quoteName('content_item_id'), $itemIds)
            ->bind(':context', $context);

        $values = array_fill_keys($itemIds, []);

        foreach ($db->setQuery($query)->loadObjectList() ?: [] as $row) {
            $values[(int) $row->content_item_id][] = (int) $row->tag_id;
        }

        return $values;
    }

    /**
     * Category id per item, in one query.
     *
     * @param   int[]   $itemIds  The items.
     * @param   string  $context  The workflow context.
     *
     * @return  array<int, integer>
     *
     * @since   __DEPLOY_VERSION__
     */
    private function loadCategoryIds(array $itemIds, string $context): array
    {
        if ($itemIds === [] || $context !== 'com_content.article') {
            return [];
        }

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'catid']))
            ->from($db->quoteName('#__content'))
            ->whereIn($db->quoteName('id'), $itemIds);

        $values = array_fill_keys($itemIds, 0);

        foreach ($db->setQuery($query)->loadObjectList() ?: [] as $row) {
            $values[(int) $row->id] = (int) $row->catid;
        }

        return $values;
    }

    /**
     * Hit count per item, in one query.
     *
     * @param   int[]   $itemIds  The items.
     * @param   string  $context  The workflow context.
     *
     * @return  array<int, integer>
     *
     * @since   __DEPLOY_VERSION__
     */
    private function loadHits(array $itemIds, string $context): array
    {
        if ($itemIds === [] || $context !== 'com_content.article') {
            return [];
        }

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'hits']))
            ->from($db->quoteName('#__content'))
            ->whereIn($db->quoteName('id'), $itemIds);

        $values = array_fill_keys($itemIds, 0);

        foreach ($db->setQuery($query)->loadObjectList() ?: [] as $row) {
            $values[(int) $row->id] = (int) $row->hits;
        }

        return $values;
    }

    /**
     * The author's user groups per item, in two queries: the authors, then their groups.
     *
     * @param   int[]   $itemIds  The items.
     * @param   string  $context  The workflow context.
     *
     * @return  array<int, int[]>
     *
     * @since   __DEPLOY_VERSION__
     */
    private function loadAuthorGroupIds(array $itemIds, string $context): array
    {
        if ($itemIds === [] || $context !== 'com_content.article') {
            return [];
        }

        $db     = $this->getDatabase();
        $values = array_fill_keys($itemIds, []);

        $authorQuery = $db->getQuery(true)
            ->select($db->quoteName(['id', 'created_by']))
            ->from($db->quoteName('#__content'))
            ->whereIn($db->quoteName('id'), $itemIds);

        $itemIdsByAuthor = [];

        foreach ($db->setQuery($authorQuery)->loadObjectList() ?: [] as $row) {
            if ((int) $row->created_by > 0) {
                $itemIdsByAuthor[(int) $row->created_by][] = (int) $row->id;
            }
        }

        if ($itemIdsByAuthor === []) {
            return $values;
        }

        // Groups are fetched per author, not per item, so many articles by one author cost
        // a single row in this result.
        $groupQuery = $db->getQuery(true)
            ->select($db->quoteName(['user_id', 'group_id']))
            ->from($db->quoteName('#__user_usergroup_map'))
            ->whereIn($db->quoteName('user_id'), array_keys($itemIdsByAuthor));

        $groupsByAuthor = [];

        foreach ($db->setQuery($groupQuery)->loadObjectList() ?: [] as $row) {
            $groupsByAuthor[(int) $row->user_id][] = (int) $row->group_id;
        }

        foreach ($itemIdsByAuthor as $authorId => $authoredItemIds) {
            foreach ($authoredItemIds as $authoredItemId) {
                $values[$authoredItemId] = $groupsByAuthor[$authorId] ?? [];
            }
        }

        return $values;
    }

    /**
     * Weekday options, Sunday (0) through Saturday (6).
     *
     * @return  array<int, array<string, string>>
     *
     * @since   __DEPLOY_VERSION__
     */
    private function getWeekdayOptions(): array
    {
        $weekdayKeys = ['SUNDAY', 'MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY'];
        $options     = [];

        foreach ($weekdayKeys as $dayNumber => $languageKey) {
            $options[] = ['value' => (string) $dayNumber, 'label' => Text::_($languageKey)];
        }

        return $options;
    }

    /**
     * Published content tags.
     *
     * @return  array<int, array<string, string>>
     *
     * @since   __DEPLOY_VERSION__
     */
    private function getTagOptions(): array
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'title']))
            ->from($db->quoteName('#__tags'))
            ->where($db->quoteName('published') . ' = 1')
            ->where($db->quoteName('id') . ' > 1')
            ->order($db->quoteName('title') . ' ASC');

        $options = [];

        foreach ($db->setQuery($query)->loadObjectList() ?: [] as $tag) {
            $options[] = ['value' => (string) $tag->id, 'label' => $tag->title];
        }

        return $options;
    }

    /**
     * com_content category options.
     *
     * @return  array<int, array<string, string>>
     *
     * @since   __DEPLOY_VERSION__
     */
    private function getCategoryOptions(): array
    {
        $options = [];

        foreach (HTMLHelper::_('category.options', 'com_content') as $category) {
            $options[] = ['value' => (string) $category->value, 'label' => $category->text];
        }

        return $options;
    }

    /**
     * All user groups.
     *
     * @return  array<int, array<string, string>>
     *
     * @since   __DEPLOY_VERSION__
     */
    private function getUserGroupOptions(): array
    {
        $options = [];

        foreach (UserGroupsHelper::getInstance()->getAll() as $group) {
            $options[] = ['value' => (string) $group->id, 'label' => $group->title];
        }

        return $options;
    }
}
