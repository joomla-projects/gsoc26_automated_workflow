<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_workflow
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Workflow\Administrator\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Component\Workflow\Administrator\Automation\UpcomingTransitionsCalculator;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Model for the extension-wide upcoming automated transitions view.
 *
 * A list model for the paging and the filter bar, but the rows are computed rather than queried,
 * so getItems() and getTotal() do their own work instead of going through getListQuery().
 *
 * @since  __DEPLOY_VERSION__
 */
class UpcomingModel extends ListModel
{
    /**
     * Shared by the page and the count, so both see one instance.
     *
     * @var    UpcomingTransitionsCalculator|null
     * @since  __DEPLOY_VERSION__
     */
    private ?UpcomingTransitionsCalculator $calculator = null;

    /**
     * Auto-populate the model state from the request.
     *
     * @param   string  $ordering   Unused: the order is fixed, attention first then longest waiting.
     * @param   string  $direction  Unused, for the same reason.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function populateState($ordering = null, $direction = null)
    {
        $extension = Factory::getApplication()->getUserStateFromRequest(
            $this->context . '.filter.extension',
            'extension',
            'com_content.article',
            'cmd'
        );

        $this->setState('filter.extension', $extension);

        // list.limit, list.start and list.workflow_id all arrive through the parent.
        parent::populateState($ordering, $direction);
    }

    /**
     * The filter form, with its workflow list narrowed to the current extension.
     *
     * @param   array    $data      Data for the form.
     * @param   boolean  $loadData  Whether to load the form data.
     *
     * @return  Form|null
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getFilterForm($data = [], $loadData = true)
    {
        $form = parent::getFilterForm($data, $loadData);

        if ($form instanceof Form) {
            $db = $this->getDatabase();

            // Narrowed here rather than in the XML, because the extension is only known at runtime.
            $form->setFieldAttribute(
                'workflow_id',
                'sql_where',
                $db->quoteName('published') . ' = 1 AND ' . $db->quoteName('extension') . ' = '
                    . $db->quote((string) $this->getState('filter.extension')),
                'list'
            );
        }

        return $form;
    }

    /**
     * Returns one page of upcoming automated transitions.
     *
     * @return  \Joomla\Component\Workflow\Administrator\Automation\UpcomingTransition[]
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getItems(): array
    {
        $extension = (string) $this->getState('filter.extension');

        if ($extension === '') {
            return [];
        }

        $limit      = (int) $this->getState('list.limit');
        $start      = (int) $this->getStart();
        $workflowId = (int) $this->getState('list.workflow_id');

        return $workflowId > 0
            ? $this->getCalculator()->forWorkflow($workflowId, $limit, $start)
            : $this->getCalculator()->forExtension($extension, $limit, $start);
    }

    /**
     * The number of items the list can page through.
     *
     * @return  integer
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getTotal(): int
    {
        $extension = (string) $this->getState('filter.extension');

        if ($extension === '') {
            return 0;
        }

        $workflowId = (int) $this->getState('list.workflow_id');

        return $workflowId > 0
            ? $this->getCalculator()->countForWorkflow($workflowId)
            : $this->getCalculator()->countForExtension($extension);
    }

    /**
     * @return  UpcomingTransitionsCalculator
     *
     * @since   __DEPLOY_VERSION__
     */
    private function getCalculator(): UpcomingTransitionsCalculator
    {
        return $this->calculator ??= new UpcomingTransitionsCalculator($this->getDatabase());
    }
}
