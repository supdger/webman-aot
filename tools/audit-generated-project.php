#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Cli/ConfigurationException.php';
require dirname(__DIR__) . '/src/Project/ProjectProfile.php';
require dirname(__DIR__) . '/src/Project/ProfileDetector.php';
require dirname(__DIR__) . '/src/Project/DiscoveryResult.php';
require dirname(__DIR__) . '/src/Project/ProjectDiscovery.php';
require dirname(__DIR__) . '/src/Project/CoverageLedger.php';
require dirname(__DIR__) . '/src/Project/CoveragePlanner.php';
require dirname(__DIR__) . '/src/Project/CompilerCoverageAudit.php';
require dirname(__DIR__) . '/src/Project/RuntimeResourceManifest.php';
require dirname(__DIR__) . '/src/Project/RuntimeResourcePlanner.php';
require dirname(__DIR__) . '/src/Project/GeneratedProjectCoveragePlanner.php';

use WebmanAot\Project\GeneratedProjectCoveragePlanner;
use WebmanAot\Project\ProfileDetector;

try {
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/^--(mirror|lock|profile)=(.+)$/D', $argument, $matches) !== 1
            || isset($options[$matches[1]])
        ) {
            throw new InvalidArgumentException("invalid or duplicate option: {$argument}");
        }
        $options[$matches[1]] = $matches[2];
    }
    if (!isset($options['mirror'], $options['lock'])) {
        throw new InvalidArgumentException(
            'usage: audit-generated-project.php --mirror=PATH --lock=PATH [--profile=webman|saiadmin]'
        );
    }
    $profile = (new ProfileDetector($options['mirror']))
        ->detect($options['profile'] ?? null);
    $result = (new GeneratedProjectCoveragePlanner())->plan(
        $options['mirror'],
        $profile,
        $options['lock']
    );
    fwrite(STDOUT, json_encode([
        'profile' => $profile->name(),
        'discoveredFiles' => count($result['discovery']->files()),
        'coverage' => $result['coverage']->counts(),
        'compiler' => $result['compiled'],
        'runtimeResources' => count($result['resources']->entries()),
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
