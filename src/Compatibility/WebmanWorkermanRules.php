<?php

declare(strict_types=1);

namespace WebmanAot\Compatibility;

final class WebmanWorkermanRules
{
    /**
     * These are source-shape-specific rules, not a global Webman version gate.
     *
     * @return list<CompatibilityRule>
     */
    public static function knownRules(): array
    {
        return [
            new BoundedTextRule(
                'webman-405-handler-variadic',
                'workerman/webman-framework',
                'vendor/workerman/webman-framework/src/App.php',
                new VersionRange('2.2.4', '2.2.5'),
                [
                    '$allowHeader = implode(',
                    "return new Response(405, ['Allow' => \$allowHeader], '405 Method Not Allowed');",
                ],
                'return static function () use ($allowHeader) {',
                'return static function (...$arguments) use ($allowHeader) {',
                1,
                ['return static function (...$arguments) use ($allowHeader) {']
            ),
            new BoundedTextRule(
                'webman-fallback-handler-variadic',
                'workerman/webman-framework',
                'vendor/workerman/webman-framework/src/App.php',
                new VersionRange('2.2.4', '2.2.5'),
                ['function getFallback(', 'throw new PageNotFoundException();'],
                'return Route::getFallback($plugin, $status) ?: function () {',
                'return Route::getFallback($plugin, $status) ?: function (...$arguments) {',
                1,
                ['return Route::getFallback($plugin, $status) ?: function (...$arguments) {']
            ),
            new BoundedTextRule(
                'webman-include-handler-variadic',
                'workerman/webman-framework',
                'vendor/workerman/webman-framework/src/App.php',
                new VersionRange('2.2.4', '2.2.5'),
                ['function findFile(', 'return static::execPhpFile($file);'],
                'static::collectCallbacks($key, [function () use ($file) {',
                'static::collectCallbacks($key, [function (...$arguments) use ($file) {',
                1,
                ['static::collectCallbacks($key, [function (...$arguments) use ($file) {']
            ),
            new BoundedTextRule(
                'webman-file-error-handler-variadic',
                'workerman/webman-framework',
                'vendor/workerman/webman-framework/src/File.php',
                new VersionRange('2.2.4', '2.2.5'),
                ['public function move(string $destination): File', '$error = $msg;'],
                'set_error_handler(function ($type, $msg) use (&$error) {',
                'set_error_handler(function ($type, $msg, ...$__err) use (&$error) {',
                1,
                ['set_error_handler(function ($type, $msg, ...$__err) use (&$error) {']
            ),
            new BoundedTextRule(
                'workerman-timer-signal-variadic',
                'workerman/workerman',
                'vendor/workerman/workerman/src/Timer.php',
                new VersionRange('5.2.2', '5.2.3'),
                ['public static function init(?EventInterface $event = null): void', 'public static function signalHandle(): void'],
                'pcntl_signal(SIGALRM, self::signalHandle(...), false);',
                'pcntl_signal(SIGALRM, static fn (...$__sig) => self::signalHandle(), false);',
                1,
                ['pcntl_signal(SIGALRM, static fn (...$__sig) => self::signalHandle(), false);']
            ),
            new BoundedTextRule(
                'workerman-select-signal-variadic',
                'workerman/workerman',
                'vendor/workerman/workerman/src/Events/Select.php',
                new VersionRange('5.2.2', '5.2.3'),
                ['public function onSignal(int $signal, callable $func)', '$this->signalEvents[$signal]'],
                'pcntl_signal($signal, fn () => $this->safeCall($this->signalEvents[$signal], [$signal]));',
                'pcntl_signal($signal, fn (...$__sig) => $this->safeCall($this->signalEvents[$signal], [$signal]));',
                1,
                ['pcntl_signal($signal, fn (...$__sig) => $this->safeCall($this->signalEvents[$signal], [$signal]));']
            ),
            new BoundedTextRule(
                'workerman-worker-error-suppressor-variadic',
                'workerman/workerman',
                'vendor/workerman/workerman/src/Worker.php',
                new VersionRange('5.2.2', '5.2.3'),
                ['class Worker', 'restore_error_handler();'],
                'set_error_handler(static fn (): bool => true);',
                'set_error_handler(static fn (...$__err): bool => true);',
                7,
                ['set_error_handler(static fn (...$__err): bool => true);']
            ),
            new BoundedTextRule(
                'workerman-worker-error-handler-variadic',
                'workerman/workerman',
                'vendor/workerman/workerman/src/Worker.php',
                new VersionRange('5.2.2', '5.2.3'),
                ['class Worker', 'restore_error_handler();'],
                'set_error_handler(function ($code, $msg) {',
                'set_error_handler(function ($code, $msg, ...$__err) {',
                1,
                ['set_error_handler(function ($code, $msg, ...$__err) {']
            ),
            new BoundedTextRule(
                'workerman-worker-walk-stop-variadic',
                'workerman/workerman',
                'vendor/workerman/workerman/src/Worker.php',
                new VersionRange('5.2.2', '5.2.3'),
                ['class Worker', '$workers'],
                'array_walk($workers, static fn (Worker $worker) => $worker->stop(false));',
                'array_walk($workers, static fn (Worker $worker, ...$__walk) => $worker->stop(false));',
                1,
                ['array_walk($workers, static fn (Worker $worker, ...$__walk) => $worker->stop(false));']
            ),
            new BoundedTextRule(
                'workerman-worker-walk-pid-variadic',
                'workerman/workerman',
                'vendor/workerman/workerman/src/Worker.php',
                new VersionRange('5.2.2', '5.2.3'),
                ['class Worker', '$workerPidArray'],
                'array_walk($workerPidArray, static fn ($pid) => posix_kill($pid, $sig));',
                'array_walk($workerPidArray, static fn ($pid, ...$__walk) => posix_kill($pid, $sig));',
                1,
                ['array_walk($workerPidArray, static fn ($pid, ...$__walk) => posix_kill($pid, $sig));']
            ),
            new BoundedTextRule(
                'workerman-worker-signal-variadic',
                'workerman/workerman',
                'vendor/workerman/workerman/src/Worker.php',
                new VersionRange('5.2.2', '5.2.3'),
                ['class Worker', 'function signalHandler(int $signal)'],
                'pcntl_signal($signal, static::signalHandler(...), false);',
                'pcntl_signal($signal, static fn (...$__sig) => static::signalHandler($__sig[0]), false);',
                1,
                ['pcntl_signal($signal, static fn (...$__sig) => static::signalHandler($__sig[0]), false);']
            ),
            new BoundedTextRule(
                'workerman-tcp-error-handler-variadic',
                'workerman/workerman',
                'vendor/workerman/workerman/src/Connection/TcpConnection.php',
                new VersionRange('5.2.2', '5.2.3'),
                ['class TcpConnection', 'stream_socket_enable_crypto($socket, true, $type)'],
                'set_error_handler(static function (int $code, string $msg): bool {',
                'set_error_handler(static function (int $code, string $msg, ...$__err): bool {',
                1,
                ['set_error_handler(static function (int $code, string $msg, ...$__err): bool {']
            ),
            new BoundedTextRule(
                'workerman-async-tcp-error-handler-variadic',
                'workerman/workerman',
                'vendor/workerman/workerman/src/Connection/AsyncTcpConnection.php',
                new VersionRange('5.2.2', '5.2.3'),
                ['class AsyncTcpConnection', 'STATUS_CONNECTING'],
                'set_error_handler(fn() => false);',
                'set_error_handler(fn(...$__err) => false);',
                1,
                ['set_error_handler(fn(...$__err) => false);']
            ),
        ];
    }
}
