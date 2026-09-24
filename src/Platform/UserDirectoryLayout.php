<?php

declare(strict_types=1);

namespace WebmanAot\Platform;

final class UserDirectoryLayout
{
    private function __construct(private readonly string $root)
    {
    }

    public static function detect(): self
    {
        $override = getenv('WEBMAN_AOT_HOME');
        if (is_string($override) && trim($override) !== '') {
            return new self(self::normalize($override));
        }

        if (PHP_OS_FAMILY === 'Darwin' && php_uname('m') === 'arm64') {
            $home = getenv('HOME');
            if (!is_string($home) || trim($home) === '') {
                throw new \RuntimeException('cannot resolve the current user home directory');
            }

            return new self(self::normalize($home . '/Library/Application Support/webman-aot'));
        }

        if (PHP_OS_FAMILY === 'Windows' && PHP_INT_SIZE === 8) {
            $localAppData = getenv('LOCALAPPDATA');
            if (!is_string($localAppData) || trim($localAppData) === '') {
                throw new \RuntimeException('cannot resolve the current user data directory');
            }

            return new self(self::normalize($localAppData . '/webman-aot'));
        }

        throw new \RuntimeException(sprintf(
            'unsupported build host: %s %s',
            PHP_OS_FAMILY,
            php_uname('m')
        ));
    }

    /**
     * @return array<string, string>
     */
    public function paths(): array
    {
        return [
            'home' => $this->root,
            'current' => $this->root . '/current',
            'versions' => $this->root . '/versions',
            'toolchains' => $this->root . '/toolchains',
            'artifacts' => $this->root . '/artifacts',
            'cache' => $this->root . '/cache',
            'logs' => $this->root . '/logs',
            'tmp' => $this->root . '/tmp',
        ];
    }

    public function root(): string
    {
        return $this->root;
    }

    public function path(string $name): string
    {
        $paths = $this->paths();
        if (!isset($paths[$name])) {
            throw new \InvalidArgumentException("unknown private directory: {$name}");
        }

        return $paths[$name];
    }

    private static function normalize(string $path): string
    {
        $normalized = str_replace('\\', '/', trim($path));
        if (preg_match('#^[A-Za-z]:/$#D', $normalized) === 1) {
            return $normalized;
        }

        return rtrim($normalized, '/');
    }
}
