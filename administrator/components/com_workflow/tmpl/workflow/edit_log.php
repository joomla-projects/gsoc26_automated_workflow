<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_workflow
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \Joomla\Component\Workflow\Administrator\View\Workflow\HtmlView $this */

if (empty($this->automationLog)) : ?>
    <div class="alert alert-info" role="alert">
        <span class="icon-info-circle" aria-hidden="true"></span>
        <?php echo Text::_('COM_WORKFLOW_LOG_EMPTY'); ?>
    </div>
    <?php
    return;
endif;
?>
<table class="table">
    <caption class="visually-hidden"><?php echo Text::_('COM_WORKFLOW_LOG_TAB'); ?></caption>
    <thead>
        <tr>
            <th scope="col"><?php echo Text::_('COM_WORKFLOW_LOGS_EXECUTED_AT'); ?></th>
            <th scope="col"><?php echo Text::_('COM_WORKFLOW_LOGS_ITEM_ID'); ?></th>
            <th scope="col"><?php echo Text::_('COM_WORKFLOW_LOGS_TRANSITION'); ?></th>
            <th scope="col"><?php echo Text::_('COM_WORKFLOW_LOGS_STAGES'); ?></th>
            <th scope="col"><?php echo Text::_('COM_WORKFLOW_LOGS_RUN_AS'); ?></th>
            <th scope="col" class="text-center"><?php echo Text::_('COM_WORKFLOW_LOGS_RESULT'); ?></th>
            <th scope="col"><?php echo Text::_('COM_WORKFLOW_LOGS_NOTE'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($this->automationLog as $entry) : ?>
            <tr>
                <td><?php echo HTMLHelper::_('date', $entry->executed_at, Text::_('DATE_FORMAT_LC2')); ?></td>
                <td><?php echo (int) $entry->item_id; ?></td>
                <td><?php echo $this->escape(Text::_((string) $entry->transition_title)); ?></td>
                <td>
                    <span class="badge bg-secondary"><?php echo $this->escape(Text::_((string) $entry->from_stage)); ?></span>
                    <span class="icon-arrow-right icon-fw" aria-hidden="true"></span>
                    <span class="badge bg-secondary"><?php echo $this->escape(Text::_((string) $entry->to_stage)); ?></span>
                </td>
                <td><?php echo $this->escape((string) $entry->run_as_name); ?></td>
                <td class="text-center">
                    <?php if ((int) $entry->exit_code === 0) : ?>
                        <span class="badge bg-success"><?php echo Text::_('JYES'); ?></span>
                    <?php else : ?>
                        <span class="badge bg-danger"><?php echo Text::_('JNO'); ?></span>
                    <?php endif; ?>
                </td>
                <td><?php echo $this->escape((string) $entry->note); ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<p>
    <a href="<?php echo Route::_('index.php?option=com_workflow&view=logs&extension=' . $this->escape($this->extension) . ($this->section ? '.' . $this->section : '')); ?>">
        <?php echo Text::_('COM_WORKFLOW_LOG_VIEW_ALL'); ?>
    </a>
</p>
