<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_workflow
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Layout\LayoutHelper;

/** @var \Joomla\Component\Workflow\Administrator\View\Workflow\HtmlView $this */

// The workflow is already the context here, so the workflow column would repeat itself.
echo LayoutHelper::render(
    'upcoming.table',
    ['items' => $this->upcomingTransitions, 'showWorkflow' => false],
    JPATH_ADMINISTRATOR . '/components/com_workflow/layouts'
);
