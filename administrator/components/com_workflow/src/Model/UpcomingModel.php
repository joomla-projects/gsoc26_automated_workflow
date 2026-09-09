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
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\Component\Workflow\Administrator\Automation\UpcomingTransitionsCalculator;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Model for the extension-wide upcoming automated transitions view.
 *
 * Nothing here is stored or paginated: the list is computed on the fly from the items
 * currently waiting on an automation, which is naturally a small set. Compare the automation
 * log, which is a growing history and therefore a paginated list model.
 *
 * @since  __DEPLOY_VERSION__
 */
class UpcomingModel extends BaseDatabaseModel
{
    /**
     * Auto-populate the model state from the request.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function populateState()
    {
        parent::populateState();

        $app       = Factory::getApplication();
        $extension = $app->getUserStateFromRequest(
            $this->option . '.upcoming.filter.extension',
            'extension',
            'com_content.article',
            'cmd'
        );

        $this->setState('filter.extension', $extension);
    }

    /**
     * Returns every upcoming automated transition for the current extension.
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

        return (new UpcomingTransitionsCalculator($this->getDatabase()))->forExtension($extension);
    }
}
