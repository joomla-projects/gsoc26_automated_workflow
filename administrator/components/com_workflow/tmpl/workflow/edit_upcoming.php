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

if (empty($this->upcomingTransitions)) : ?>
    <div class="alert alert-info" role="alert">
        <span class="icon-info-circle" aria-hidden="true"></span>
        <?php echo Text::_('COM_WORKFLOW_UPCOMING_EMPTY'); ?>
    </div>
    <?php
    return;
endif;

$unitKeys = [
    'minutes' => 'COM_WORKFLOW_AUTOMATION_UNIT_MINUTES',
    'hours'   => 'COM_WORKFLOW_AUTOMATION_UNIT_HOURS',
    'days'    => 'COM_WORKFLOW_AUTOMATION_UNIT_DAYS',
    'months'  => 'COM_WORKFLOW_AUTOMATION_UNIT_MONTHS',
];
?>
<table class="table">
    <caption class="visually-hidden"><?php echo Text::_('COM_WORKFLOW_UPCOMING_TAB'); ?></caption>
    <thead>
        <tr>
            <th scope="col"><?php echo Text::_('COM_WORKFLOW_UPCOMING_COL_ITEM'); ?></th>
            <th scope="col"><?php echo Text::_('COM_WORKFLOW_UPCOMING_COL_MOVE'); ?></th>
            <th scope="col"><?php echo Text::_('COM_WORKFLOW_UPCOMING_COL_FIRES'); ?></th>
            <th scope="col"><?php echo Text::_('COM_WORKFLOW_UPCOMING_COL_TRIGGER'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($this->upcomingTransitions as $transition) : ?>
            <tr>
                <td>
                    <?php
                    $itemLabel = $transition->itemTitle !== ''
                        ? $transition->itemTitle
                        : Text::sprintf('COM_WORKFLOW_UPCOMING_ITEM_FALLBACK', $transition->itemId);
                    ?>
                    <?php if ($transition->editUrl !== '') : ?>
                        <a href="<?php echo Route::_($transition->editUrl); ?>">
                            <?php echo htmlspecialchars($itemLabel, ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    <?php else : ?>
                        <?php echo htmlspecialchars($itemLabel, ENT_QUOTES, 'UTF-8'); ?>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="badge bg-secondary"><?php echo htmlspecialchars($transition->fromStage, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="icon-arrow-right icon-fw" aria-hidden="true"></span>
                    <span class="badge bg-secondary"><?php echo htmlspecialchars($transition->toStage, ENT_QUOTES, 'UTF-8'); ?></span>
                </td>
                <td>
                    <?php if ($transition->status === 'needs_attention') : ?>
                        <span class="badge bg-danger"><?php echo Text::_('COM_WORKFLOW_UPCOMING_STATUS_ATTENTION'); ?></span>
                    <?php elseif ($transition->status === 'not_scheduled') : ?>
                        <span class="badge bg-secondary"><?php echo Text::_('COM_WORKFLOW_UPCOMING_STATUS_NOT_SCHEDULED'); ?></span>
                    <?php elseif ($transition->status === 'waiting_condition') : ?>
                        <span class="badge bg-warning text-dark"><?php echo Text::_('COM_WORKFLOW_UPCOMING_STATUS_WAITING'); ?></span>
                    <?php else : ?>
                        <?php echo HTMLHelper::_('date', $transition->firesAt->format('Y-m-d H:i:s'), Text::_('DATE_FORMAT_LC2')); ?>
                        <?php if ($transition->hasCondition) : ?>
                            <span class="badge bg-info"><?php echo Text::_('COM_WORKFLOW_UPCOMING_SUBJECT_CONDITION'); ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($transition->ruleType === 'cron') : ?>
                        <?php echo Text::sprintf('COM_WORKFLOW_UPCOMING_TRIGGER_CRON', '<code>' . htmlspecialchars((string) $transition->cronExpression, ENT_QUOTES, 'UTF-8') . '</code>'); ?>
                    <?php else : ?>
                        <?php
                        $unitKey = $unitKeys[$transition->delayUnit] ?? '';
                        $unit    = $unitKey !== '' ? Text::_($unitKey) : (string) $transition->delayUnit;
                        echo Text::sprintf('COM_WORKFLOW_UPCOMING_TRIGGER_DELAY', (int) $transition->delayValue, $unit);
                        ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
