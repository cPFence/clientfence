<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence;
class EventManager implements \ClientFence\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public const GLOBAL_LISTENER_NAME = 'onEveryEvent';

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function fire($data)
    {
        if (!isset($data['event']) || empty($data['event'])) {
            if ($this->di !== null && isset($this->di['logger'])) {
                $this->di['logger']->warning('Invoked event call without providing event name');
            }

            return false;
        }

        $event = $data['event'];
        $subject = $data['subject'] ?? null;
        $params = $data['params'] ?? null;

        $this->di['logger']->setChannel('event')->debug('Fired event: ' . $event, $params);

        $e = new \ClientFence\Event($subject, $event, $params);
        $e->setDi($this->di);
        $disp = new \ClientFence\EventDispatcher();

        $eventName = $e->getName();

        $this->_connectDatabaseHooks($disp, $eventName);
        $this->_connectDatabaseHooks($disp, self::GLOBAL_LISTENER_NAME, $eventName); // Also connect global listeners (onEveryEvent) to the fired event

        // ClientFence core patch: an "isolated" fire (the cron's start and end) logs a throwing listener and runs the next
        // ones; elsewhere a listener throws to refuse the action (Antispam), so the exception goes on.
        $disp->notify($e, empty($data['isolated']) ? null : fn (\Throwable $error, mixed $listener) => $this->di['logger']->setChannel('event')->warning(sprintf(
            'Listener %s of %s failed: %s: %s',
            is_array($listener) ? implode('::', $listener) : get_debug_type($listener),
            $event,
            $error::class,
            $error->getMessage(),
        )));

        return $e->getReturnValue();
    }

    /**
     * @param \ClientFence\EventDispatcher $disp
     * @param string              $event
     */
    private function _connectDatabaseHooks(&$disp, $event, ?string $dispatchEventName = null): void
    {
        // ClientFence core patch: every listener is read once per request (a cron run fired about
        // 380 events, two lookups each); Hook\Service forgets them when it connects or disconnects.
        $all = \ClientFence\RequestCache::remember($this->di, 'hook.listeners', fn (): array => $this->di['db']->getAll("SELECT id, rel_id, meta_value
            FROM extension_meta
            WHERE extension = 'mod_hook'
            AND rel_type = 'mod'
            AND meta_key = 'listener'
            ORDER BY id
        "));
        // Case-insensitive, as the column's collation compared it.
        $list = array_filter($all, static fn (array $listener): bool => strcasecmp((string) $listener['meta_value'], (string) $event) === 0);

        // no need to connect listeners
        if (empty($list)) {
            return;
        }

        foreach ($list as $listener) {
            $mod = $listener['rel_id'];
            $event = $listener['meta_value'];
            $dispatchEvent = $dispatchEventName ?? $event;

            try {
                $s = $this->di['mod_service']($mod);

                if (method_exists($s, $event)) {
                    $disp->connect($dispatchEvent, [$s::class, $event]);
                }
            } catch (\Exception $e) {
                error_log($e->getMessage());
                // ClientFence core patch: a switched-on module that cannot run here (Module::cannotRun()) is not skipped: its
                // listener throws the reason, so a veto still refuses and a hook call is not taken as done. Not its
                // listener of every event (onEveryEvent): that one would refuse every action, staff sign-in included. Nor
                // when it is the module being deactivated or uninstalled (removing()): it must never block its own removal.
                if ($dispatchEventName === null && $this->di['mod']($mod)->cannotRun() !== null) {
                    $di = $this->di;
                    $disp->connect($dispatchEvent, static function (\ClientFence\Event $event) use ($di, $mod, $e): void {
                        if (!self::removing($di, $event, (string) $mod)) {
                            throw $e;
                        }
                    });
                }
            }
        }
    }

    /** ClientFence: whether `$event` deactivates or uninstalls module `$mod` (before deactivation it names the extension row). */
    private static function removing(\Pimple\Container $di, \ClientFence\Event $event, string $mod): bool
    {
        $name = $event->getName();
        if (!in_array($name, ['onBeforeAdminDeactivateExtension', 'onAfterAdminDeactivateExtension', 'onBeforeAdminUninstallExtension', 'onAfterAdminUninstallExtension'], true)) {
            return false;
        }
        // Read only for these four (Extension\Api\Admin fires them with an array); other events may pass an object.
        $params = $event->getParameters();
        $id = $params['id'] ?? null;
        if ($name === 'onBeforeAdminDeactivateExtension') {
            return is_numeric($id) && strcasecmp((string) $di['em']->getRepository(\ClientFence\Mod\Extension\Entity\Extension::class)->find((int) $id)?->getName(), $mod) === 0;
        }

        return ($params['type'] ?? null) === 'mod' && is_string($id) && strcasecmp($id, $mod) === 0;
    }
}
