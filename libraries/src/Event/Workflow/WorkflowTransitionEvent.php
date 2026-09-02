<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2020 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Event\Workflow;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Event class for Workflow Functionality Used events
 *
 * @since  4.0.0
 */
class WorkflowTransitionEvent extends AbstractEvent
{
    /**
     * Constructor.
     *
     * @param   string  $name       The event name.
     * @param   array   $arguments  The event arguments.
     *
     * @throws  \BadMethodCallException
     *
     * @since   4.0.0
     */
    public function __construct($name, array $arguments = [])
    {
        $arguments['stopTransition'] = false;

        parent::__construct($name, $arguments);
    }

    /**
     * Set used parameter to true
     *
     * @param   bool  $value  The value to set
     *
     * @return void
     *
     * @since   4.0.0
     */
    public function setStopTransition($value = true)
    {
        $this->arguments['stopTransition'] = $value;

        if ($value === true) {
            $this->stopPropagation();
        }
    }

    /**
     * The following methods come in pairs for each event argument.
     *
     * onGet{Name}($value) — called internally by AbstractEvent::getArgument() to
     * pre-process and type-cast the raw argument value before returning it. Must
     * not call getArgument() itself to avoid infinite recursion.
     *
     * get{Name}() — the public typed getter for plugin and external code to call.
     * Delegates to getArgument() which routes through the onGet pre-processor above.
     */

    /**
     * Pre-process the pks argument.
     *
     * @param   mixed  $value  The raw argument value.
     *
     * @return  array
     *
     * @since   6.2.0
     */
    protected function onGetPks($value): array
    {
        return (array) $value;
    }

    /**
     * Get the primary keys of the items being transitioned.
     *
     * @return  array
     *
     * @since   6.2.0
     */
    public function getPks(): array
    {
        return $this->getArgument('pks');
    }

    /**
     * Pre-process the extension argument.
     *
     * @param   mixed  $value  The raw argument value.
     *
     * @return  string
     *
     * @since   6.2.0
     */
    protected function onGetExtension($value): string
    {
        return (string) $value;
    }

    /**
     * Get the extension context (e.g. com_content.article).
     *
     * @return  string
     *
     * @since   6.2.0
     */
    public function getExtension(): string
    {
        return $this->getArgument('extension');
    }

    /**
     * Pre-process the transition argument.
     *
     * @param   mixed  $value  The raw argument value.
     *
     * @return  object
     *
     * @since   6.2.0
     */
    protected function onGetTransition($value): object
    {
        return (object) $value;
    }

    /**
     * Get the transition object being executed.
     *
     * @return  object
     *
     * @since   6.2.0
     */
    public function getTransition(): object
    {
        return $this->getArgument('transition');
    }

    /**
     * Pre-process the triggeredBy argument.
     *
     * @param mixed $value The raw argument value
     *
     * @return string
     *
     * @since __DEPLOY_VERSION__
     */
    protected function onGetTriggeredBy($value): string
    {
        return (string) ($value ?? 'manual');
    }

    /**
     * Get the trigger source - 'manual' or 'automation'
     *
     * @return string
     *
     * @since 6.2.0
     */
    public function getTriggeredBy(): string
    {
        return $this->getArgument('triggeredBy');
    }
}
