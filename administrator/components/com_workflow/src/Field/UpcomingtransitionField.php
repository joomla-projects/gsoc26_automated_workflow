<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_workflow
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Workflow\Administrator\Field;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\Component\Workflow\Administrator\Automation\RelativeTime;
use Joomla\Component\Workflow\Administrator\Automation\UpcomingTransition;
use Joomla\Component\Workflow\Administrator\Automation\UpcomingTransitionsCalculator;
use Joomla\Database\DatabaseInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Read-only field that shows an item's next automated transition on its edit screen.
 *
 * Reads the item id and extension from the form it is attached to, asks the shared
 * calculator for that one item's next move, and renders a small Bootstrap card. Outputs
 * nothing when the item is unsaved or has no automated move pending, so it stays invisible
 * where it does not apply.
 *
 * @since  __DEPLOY_VERSION__
 */
class UpcomingtransitionField extends FormField
{
    /**
     * The form field type.
     *
     * @var string
     * @since __DEPLOY_VERSION__
     */
    protected $type = 'Upcomingtransition';

    /**
     * @return string
     *
     * @since __DEPLOY_VERSION__
     */
    protected function getInput()
    {
        $itemId    = (int) $this->form->getValue('id');
        $extension = (string) $this->form->getName();

        if ($itemId <= 0) {
            return '';
        }

        Factory::getApplication()->getLanguage()->load('com_workflow', JPATH_ADMINISTRATOR);

        $database   = Factory::getContainer()->get(DatabaseInterface::class);
        $calculator = new UpcomingTransitionsCalculator($database);
        $upcoming   = $calculator->forItem($itemId, $extension);

        if ($upcoming === null) {
            return '';
        }

        return $this->renderCard($upcoming);
    }

    /**
     * Renders the block for one upcoming transition
     *
     * @param UpcomingTransition $upcoming The item's next move.
     *
     * @return string
     *
     * @since __DEPLOY_VERSION__
     */
    private function renderCard(UpcomingTransition $upcoming): string
    {
        $fires = match ($upcoming->status) {
            'needs_attention'   => '<span class="badge bg-danger">' . Text::_('COM_WORKFLOW_UPCOMING_STATUS_ATTENTION') . '</span>',
            'not_scheduled'     => '<span class="badge bg-secondary">' . Text::_('COM_WORKFLOW_UPCOMING_STATUS_NOT_SCHEDULED') . '</span>',
            'waiting_condition' => '<span class="badge bg-warning text-dark">' . Text::_('COM_WORKFLOW_UPCOMING_STATUS_WAITING') . '</span>',
            default             => '<div>' . RelativeTime::until($upcoming->firesAt) . '</div>'
                . '<div class="small text-muted">' . HTMLHelper::_('date', $upcoming->firesAt->format('Y-m-d H:i:s'), Text::_('DATE_FORMAT_LC2')) . '</div>'
                . ($upcoming->hasCondition
                    ? '<span class="badge bg-info">' . Text::_('COM_WORKFLOW_UPCOMING_SUBJECT_CONDITION') . '</span>'
                    : ''),
        };

        if ($upcoming->ruleType === 'cron') {
            $trigger = Text::sprintf(
                'COM_WORKFLOW_UPCOMING_TRIGGER_CRON',
                '<code>' . htmlspecialchars((string) $upcoming->cronExpression, ENT_QUOTES, 'UTF-8') . '</code>'
            );
        } else {
            $unitKeys = [
                'minutes' => 'COM_WORKFLOW_AUTOMATION_UNIT_MINUTES',
                'hours'   => 'COM_WORKFLOW_AUTOMATION_UNIT_HOURS',
                'days'    => 'COM_WORKFLOW_AUTOMATION_UNIT_DAYS',
                'months'  => 'COM_WORKFLOW_AUTOMATION_UNIT_MONTHS',
            ];
            $unitKey = $unitKeys[$upcoming->delayUnit] ?? '';
            $unit    = $unitKey !== '' ? Text::_($unitKey) : (string) $upcoming->delayUnit;
            $trigger = Text::sprintf('COM_WORKFLOW_UPCOMING_TRIGGER_DELAY', (int) $upcoming->delayValue, $unit);
        }

        return '<div class="card mb-3">'
            . '<div class="card-body">'
            . '<h4 class="h6 text-uppercase text-muted mb-2">' . Text::_('COM_WORKFLOW_UPCOMING_ARTICLE_LABEL') . '</h4>'
            . '<div class="mb-2">'
            . '<span class="badge bg-secondary">' . htmlspecialchars(Text::_($upcoming->fromStage), ENT_QUOTES, 'UTF-8') . '</span> '
            . '<span class="icon-arrow-right" aria-hidden="true"></span> '
            . '<span class="badge bg-secondary">' . htmlspecialchars(Text::_($upcoming->toStage), ENT_QUOTES, 'UTF-8') . '</span>'
            . '</div>'
            . '<div class="mb-1">' . $fires . '</div>'
            . '<div class="small text-muted">' . $trigger . '</div>'
            . '</div></div>';
    }
}
