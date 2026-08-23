<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_workflow
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Workflow\Administrator\Automation;

use Joomla\CMS\Table\Table;
use Joomla\CMS\Workflow\Workflow;
use Joomla\Database\DatabaseInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Finds where an extension keeps its items, and which of them are trashed or archived.
 *
 * An editor who trashes or archives an item has taken it out of normal use. Automation
 * should leave both of them alone.
 * com_workflow has no business knowing the schema of every component that might use a
 * workflow, so everything here is asked rather than assumed.
 *
 * The result is one query per extension in a run, not one per item, which matches how the rest of
 * the automation engine batches its work.
 *
 * @since __DEPLOY_VERSION__
 */
final class ItemStorage
{
    /**
     * @var DatabaseInterface
     * @since __DEPLOY_VERSION__
     */
    private DatabaseInterface $database;

    /**
     * Content type rows already looked up, keyed by extension. Null records an extension that
     * is not registered as content type, so a second call does not repeat the query only to
     * reach the same dead end.
     *
     * @var array<string, object|null>
     * @since __DEPLOY_VERSION__
     */
    private array $contentTypes = [];

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
     * Which of these items are trashed or archived.
     *
     * Returns only the ids that are trashed or archived, so the caller can subtract them. An
     * extension this cannot answer for returns nothing, which deliberately means "carry on"
     * rather than "exclude everything": being unable to tell must not silently stop automation
     * for a whole component.
     *
     * @param   int[]   $itemIds    The items being considered.
     * @param   string  $extension  The workflow extension, e.g. com_content.article.
     *
     * @return  int[]
     *
     * @since   __DEPLOY_VERSION__
     */
    public function trashedOrArchivedIds(array $itemIds, string $extension): array
    {
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
        $storage = $this->locate($extension);

        if ($itemIds === [] || $storage === null) {
            return [];
        }

        $stateColumn = $this->columnFor($extension, 'published');

        if ($stateColumn === null) {
            return [];
        }

        $db                     = $this->database;
        $trashedOrArchivedQuery = $db->getQuery(true)
            ->select($db->quoteName($storage['key']))
            ->from($db->quoteName($storage['table']))
            ->whereIn($db->quoteName($storage['key']), $itemIds)
            ->whereIn($db->quoteName($stateColumn), [Workflow::CONDITION_TRASHED, Workflow::CONDITION_ARCHIVED]);

        return array_map('intval', $db->setQuery($trashedOrArchivedQuery)->loadColumn() ?: []);
    }

    /**
     * The display title of each item, keyed by id.
     *
     * The upcoming-transitions views name the item a rule will act on, and reading that name
     * needs the same two lookups as reading its state: which table the extension keeps its
     * items in, and which column that extension calls a title. Articles store it in "title"
     * while contacts, news feeds and banners store it in "name", and the extension's own Table
     * class is the thing that knows.
     *
     * An extension this cannot answer for returns nothing, and the caller falls back to showing
     * the item's id. A missing name is a cosmetic problem, never a reason to hide the row.
     *
     * @param   int[]   $itemIds    The items to name.
     * @param   string  $extension  The workflow extension, e.g. com_content.article.
     *
     * @return  array<int, string>  Item id to title, omitting any the query did not return.
     *
     * @since   __DEPLOY_VERSION__
     */

    public function titlesFor(array $itemIds, string $extension): array
    {
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
        $storage = $this->locate($extension);

        if ($itemIds === [] || $storage === null) {
            return [];
        }

        $titleColumn = $this->columnFor($extension, 'title');

        if ($titleColumn === null) {
            return [];
        }

        $db    = $this->database;
        $query = $db->getQuery(true)
            ->select($db->quoteName([$storage['key'], $titleColumn]))
            ->from($db->quoteName($storage['table']))
            ->whereIn($db->quoteName($storage['key']), $itemIds);

        $titles = [];

        foreach ($db->setQuery($query)->loadAssocList() ?: [] as $row) {
            $titles[(int) $row[$storage['key']]] = (string) $row[$titleColumn];
        }

        return $titles;
    }
    /**
     * Where an extension keeps its own items: the table and its key column.
     *
     * Null when the extension is not registered as a content type, or when the registration
     * names something that is not a plain table or column. Both values reach a query as
     * identifiers, and they come out of the database rather than from this component, so they
     * are checked rather than trusted.
     *
     * @param string $extension The workflow extension.
     *
     * @return array{table: string, key: string}|null
     *
     * @since   __DEPLOY_VERSION__
     */
    public function locate(string $extension): ?array
    {
        $contentType = $this->contentTypeFor($extension);

        if ($contentType === null) {
            return null;
        }

        $definition = json_decode((string) $contentType->table, true);

        // Only the "special" entry is the extension's own table. The "common" one is always
        // #__ucm_content, which is a different thing and is not reliably populated: this site
        // has 11 rows there against 123 articles.
        $table = $definition['special']['dbtable'] ?? '';
        $key   = $definition['special']['key'] ?? '';

        if (!\is_string($table) || !preg_match('/^#__[a-zA-Z0-9_]+$/', $table)) {
            return null;
        }

        if (!\is_string($key) || !preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
            return null;
        }

        return ['table' => $table, 'key' => $key];
    }

    /**
     * Where an extension keeps its items and which column holds their titles.
     *
     * locate() answers the first half and columnFor() the second; this pairs them so a caller
     * that wants to search titles can build one subquery instead of pulling every matching id
     * into PHP first. Every value returned has already been checked against a strict pattern,
     * so it is safe to put through quoteName().
     *
     * @param   string  $extension  The workflow extension, e.g. com_content.article.
     *
     * @return  array|null  ['table' => ..., 'key' => ..., 'titleColumn' => ...], or null when
     *                      the extension does not describe itself well enough to be searched.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function titleLocation(string $extension): ?array
    {
        $location = $this->locate($extension);

        if ($location === null) {
            return null;
        }

        $titleColumn = $this->columnFor($extension, 'title');

        if ($titleColumn === null) {
            return null;
        }

        return $location + ['titleColumn' => $titleColumn];
    }

    /**
     * Which real column an extension uses for one of Joomla's logical field names.
     *
     * Asks the extension's own Table class through getColumnAlias(), because the answer cannot
     * be inferred from the columns present. #__contact_details has both a "published" column,
     * which is the condition, and a "state" column, which is a postal address; and the item's
     * name lives in "title" for articles but "name" for contacts. Only the component knows
     * which is which, and Joomla already provides the way to ask.
     *
     * @param   string  $extension  The workflow extension.
     * @param   string  $logicalName  A Joomla field name, for instance published or title.
     *
     * @return  string|null
     *
     * @since   __DEPLOY_VERSION__
     */
    private function columnFor(string $extension, string $logicalName): ?string
    {
        $contentType = $this->contentTypeFor($extension);

        if ($contentType === null) {
            return null;
        }

        $definition = json_decode((string) $contentType->table, true);
        $class      = ($definition['special']['prefix'] ?? '') . ($definition['special']['type'] ?? '');

        // A registration can outlive the extension that wrote it, for instance after a failed
        // uninstall, so the class is checked before it is built. Without this a stale row would
        // take down every caller.
        if (!\is_string($class) || $class === '' || !class_exists($class)) {
            return null;
        }

        try {
            $table = new $class($this->database);
        } catch (\Throwable) {
            // Table constructors vary between components and some take more than a driver.
            // One that will not build on these terms is one this cannot ask, not an error.
            return null;
        }

        if (!$table instanceof Table) {
            return null;
        }

        $column = $table->getColumnAlias($logicalName);

        // Same reasoning as locate(): this reaches a query as an identifier.
        return preg_match('/^[a-zA-Z0-9_]+$/', (string) $column) ? (string) $column : null;
    }

    /**
     * The content type row for an extension, or null when it is not registered as one.
     *
     * @param string $extension The workflow extension
     *
     * @return object|null
     *
     * @since __DEPLOY_VERSION__
     */
    private function contentTypeFor(string $extension): ?object
    {
        if (!\array_key_exists($extension, $this->contentTypes)) {
            $db    = $this->database;
            $query = $db->getQuery(true)
                ->select($db->quoteName('table'))
                ->from($db->quoteName('#__content_types'))
                ->where($db->quoteName('type_alias') . ' = :extension')
                ->bind(':extension', $extension);

            $this->contentTypes[$extension] = $db->setQuery($query)->loadObject() ?: null;
        }

        return $this->contentTypes[$extension];
    }
}
