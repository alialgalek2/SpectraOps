<?php
/**
 * Core Event Dispatcher Service (Umbrella Architecture)
 * Enables decoupled inter-module cross-talk and event listening.
 */

class EventDispatcher {
    /**
     * @var array<string, callable[]>
     */
    private static array $listeners = [];

    /**
     * @param string $eventName
     * @param callable $callback
     * @return void
     */
    public static function listen(string $eventName, callable $callback): void {
        if (!isset(self::$listeners[$eventName])) {
            self::$listeners[$eventName] = [];
        }
        self::$listeners[$eventName][] = $callback;
    }

    /**
     * @param string $eventName
     * @param mixed $data
     * @return array
     */
    public static function dispatch(string $eventName, mixed $data = null): array {
        if (!isset(self::$listeners[$eventName])) {
            return [];
        }

        $results = [];
        foreach (self::$listeners[$eventName] as $listener) {
            $results[] = call_user_func($listener, $data);
        }
        return $results;
    }

    /**
     * @param string $eventName
     * @return bool
     */
    public static function hasListeners(string $eventName): bool {
        return !empty(self::$listeners[$eventName]);
    }
}
