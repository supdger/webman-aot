<?php

declare(strict_types=1);

namespace WebmanAot\Project;

use WebmanAot\Cli\ConfigurationException;

final class ProfileDetector
{
    private const WEBMAN_PACKAGE = 'workerman/webman-framework';
    private const SAIADMIN_PACKAGE = 'saithink/saiadmin';

    private const WEBMAN_STRUCTURE = [
        'composer.json' => 'file',
        'composer.lock' => 'file',
        'start.php' => 'file',
        'app' => 'directory',
    ];

    private const SAIADMIN_STRUCTURE = [
        'plugin/saiadmin/app' => 'directory',
        'plugin/saiadmin/config' => 'directory',
        'plugin/saiadmin/basic/BaseController.php' => 'file',
        'vendor/saithink/saiadmin/src/plugin/saiadmin/app' => 'directory',
        'vendor/saithink/saiadmin/src/plugin/saiadmin/basic/BaseController.php' => 'file',
    ];

    public function __construct(private readonly string $projectDirectory)
    {
    }

    public function detect(?string $explicitProfile = null): ProjectProfile
    {
        if ($explicitProfile !== null
            && !in_array($explicitProfile, [ProjectProfile::WEBMAN, ProjectProfile::SAIADMIN], true)
        ) {
            throw new ConfigurationException("unknown project profile: {$explicitProfile}");
        }

        $missingWebman = $this->missingStructure(self::WEBMAN_STRUCTURE);
        if ($missingWebman !== []) {
            throw new ConfigurationException(
                'Webman project structure is incomplete: ' . implode(', ', $missingWebman)
            );
        }

        $this->readJsonObject('composer.json');
        $lock = $this->readJsonObject('composer.lock');
        $packages = $this->lockedPackages($lock);
        $webmanVersion = $packages[self::WEBMAN_PACKAGE] ?? null;
        if ($webmanVersion === null) {
            throw new ConfigurationException(
                self::WEBMAN_PACKAGE . ' is absent from composer.lock'
            );
        }

        $saiAdminVersion = $packages[self::SAIADMIN_PACKAGE] ?? null;
        $saiAdminPresent = $saiAdminVersion !== null;
        $saiAdminMarkers = $this->presentStructure(self::SAIADMIN_STRUCTURE);
        if (!$saiAdminPresent && $saiAdminMarkers !== []) {
            throw new ConfigurationException(
                'SaiAdmin structure is present but saithink/saiadmin is absent from composer.lock'
            );
        }

        $profile = ProjectProfile::WEBMAN;
        $profilePackages = [self::WEBMAN_PACKAGE => $webmanVersion];
        $evidence = [
            'composer.lock:' . self::WEBMAN_PACKAGE . '@' . $webmanVersion,
            ...array_keys(self::WEBMAN_STRUCTURE),
        ];
        if ($saiAdminPresent) {
            $missingSaiAdmin = $this->missingStructure(self::SAIADMIN_STRUCTURE);
            if ($missingSaiAdmin !== []) {
                throw new ConfigurationException(
                    'SaiAdmin dependency evidence is incomplete; missing structure: '
                    . implode(', ', $missingSaiAdmin)
                );
            }
            $profile = ProjectProfile::SAIADMIN;
            $profilePackages[self::SAIADMIN_PACKAGE] = $saiAdminVersion;
            $evidence[] = 'composer.lock:' . self::SAIADMIN_PACKAGE . '@' . $saiAdminVersion;
            array_push($evidence, ...array_keys(self::SAIADMIN_STRUCTURE));
        }

        if ($explicitProfile !== null && $explicitProfile !== $profile) {
            throw new ConfigurationException(
                "explicit profile {$explicitProfile} conflicts with detected profile {$profile}"
            );
        }

        return new ProjectProfile($profile, $profilePackages, $evidence);
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonObject(string $relativePath): array
    {
        $path = $this->absolute($relativePath);
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new ConfigurationException("unable to read project {$relativePath}");
        }
        try {
            $value = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ConfigurationException(
                "project {$relativePath} is invalid JSON: {$exception->getMessage()}"
            );
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new ConfigurationException("project {$relativePath} root must be an object");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $lock
     * @return array<string, string>
     */
    private function lockedPackages(array $lock): array
    {
        $packages = [];
        $locked = [];
        foreach (['packages', 'packages-dev'] as $section) {
            $entries = $lock[$section] ?? [];
            if (!is_array($entries) || !array_is_list($entries)) {
                throw new ConfigurationException("composer.lock {$section} must be a list");
            }
            array_push($locked, ...$entries);
        }
        foreach ($locked as $package) {
            if (!is_array($package)
                || !is_string($package['name'] ?? null)
                || !is_string($package['version'] ?? null)
                || $package['name'] === ''
                || $package['version'] === ''
            ) {
                continue;
            }
            $name = $package['name'];
            $version = $package['version'];
            if (isset($packages[$name]) && $packages[$name] !== $version) {
                throw new ConfigurationException(
                    "composer.lock contains conflicting versions for {$name}"
                );
            }
            $packages[$name] = $version;
        }

        return $packages;
    }

    /**
     * @param array<string, 'file'|'directory'> $structure
     * @return list<string>
     */
    private function missingStructure(array $structure): array
    {
        $missing = [];
        foreach ($structure as $relativePath => $type) {
            $path = $this->absolute($relativePath);
            $present = $type === 'file' ? is_file($path) : is_dir($path);
            if (!$present) {
                $missing[] = $relativePath;
            }
        }

        return $missing;
    }

    /**
     * @param array<string, 'file'|'directory'> $structure
     * @return list<string>
     */
    private function presentStructure(array $structure): array
    {
        $present = [];
        foreach ($structure as $relativePath => $type) {
            $path = $this->absolute($relativePath);
            if ($type === 'file' ? is_file($path) : is_dir($path)) {
                $present[] = $relativePath;
            }
        }

        return $present;
    }

    private function absolute(string $relativePath): string
    {
        return rtrim($this->projectDirectory, '/\\')
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }
}
