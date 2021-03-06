<?php
/**
 * This file is part of Jarves.
 *
 * (c) Marc J. Schmidt <marc@marcjschmidt.de>
 *
 *     J.A.R.V.E.S - Just A Rather Very Easy [content management] System.
 *
 *     http://jarves.io
 *
 * To get the full copyright and license information, please view the
 * LICENSE file, that was distributed with this source code.
 */
namespace Jarves;

use Jarves\Cache\Cacher;
use Jarves\Configuration\Bundle;
use Jarves\Configuration\Event;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\GenericEvent;

/**
 * Middleware for custom event hooking in jarves configuration files and real event dispatcher.
 */
class JarvesEventDispatcher
{
    /**
     * @var array
     */
    protected $attachedEvents = [];

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @var ConditionOperator
     */
    private $conditionOperator;

    /**
     * @var Cacher
     */
    private $cacher;

    /**
     * @param ContainerInterface $container
     * @param ConditionOperator $conditionOperator
     * @param Cacher $cacher
     */
    public function __construct(EventDispatcherInterface $eventDispatcher, ContainerInterface $container, ConditionOperator $conditionOperator, Cacher $cacher)
    {
        $this->container = $container;
        $this->conditionOperator = $conditionOperator;
        $this->cacher = $cacher;
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * @param Configuration\Configs $configs
     */
    public function registerBundleEvents(Configuration\Configs $configs)
    {
        $this->detachEvents();

        foreach ($configs->getConfigs() as $bundleConfig) {

            //register custom listener through config, like cache clearing, service calls etc
            if ($events = $bundleConfig->getListeners()) {
                foreach ($events as $event) {
                    $this->attachEvent($event);
                }
            }

            //clear storage caches when object changes
            if ($objects = $bundleConfig->getObjects()) {
                foreach ($objects as $object) {

                    $fn = function () use ($object) {
                        $storage = $this->container->get($object->getStorageService());
                        $storage->configure($object->getKey(), $object);
                        $storage->clearCache();
                    };

                    $event = new Event();
                    $event->setSubject($object->getKey());
                    $event->setKey('core/object/modify');
                    $event->setCalls([$fn]);
                    $this->attachEvent($event);
                }
            }
        }
    }

    public function attachEvent(Event $event)
    {
        $fn = function (GenericEvent $genericEvent) use ($event) {
            if ($this->isCallable($event, $genericEvent)) {
                $this->call($event, $genericEvent);
            }
        };

        $this->eventDispatcher->addListener($event->getKey(), $fn);
        $this->attachedEvents[] = [
            'key' => $event->getKey(),
            'event' => $event,
            'callback' => $fn
        ];
    }


    /**
     * @param Event $eventConfig
     * @param GenericEvent $event
     */
    public function call(Event $eventConfig, $event)
    {
        if ($eventConfig->getCalls()) {
            foreach ($eventConfig->getCalls() as $call) {
                call_user_func_array($call, [$event]);
            }
        }

        if ($eventConfig->getClearCaches()) {
            foreach ($eventConfig->getClearCaches() as $cacheKey) {
                $this->cacher->invalidateCache($cacheKey);
            }
        }

        if ($eventConfig->getServiceCalls()) {
            foreach ($eventConfig->getServiceCalls() as $serviceCall) {
                list($service, $method) = explode('::', $serviceCall);
                if ($this->container->has($service)) {
                    $service = $this->container->get($service);
                    $service->$method($event);
                }
            }
        }
    }

    /**
     * Checks whether a eventConfig is appropriate to be called (subject fits, condition fits)
     *
     * @param Event $eventConfig
     * @param GenericEvent $event
     * @return bool
     */
    public function isCallable(Event $eventConfig, GenericEvent $event)
    {
        if ($eventConfig->getSubject() && $event->getSubject() != $eventConfig->getSubject()) {
            return false;
        }

        if ($eventConfig->getCondition()) {
            $args = $event->getArguments() ?: [];
            if ($eventConfig->getCondition() && !$this->conditionOperator->satisfy($eventConfig->getCondition(), $args)) {
                return false;
            }
        }

        return true;
    }

    public function detachEvents()
    {
        foreach ($this->attachedEvents as $eventInfo) {
            $this->eventDispatcher->removeListener($eventInfo['key'], $eventInfo['callback']);
        }

        $this->attachedEvents = [];
    }

    /**
     * @return array
     */
    public function getAttachedEvents()
    {
        return $this->attachedEvents;
    }
}