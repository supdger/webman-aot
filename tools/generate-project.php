#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Cli/ConfigurationException.php';
require dirname(__DIR__) . '/src/Cli/UnavailableException.php';
require dirname(__DIR__) . '/src/Project/ProjectProfile.php';
require dirname(__DIR__) . '/src/Project/DiscoveryResult.php';
require dirname(__DIR__) . '/src/Project/ProjectDiscovery.php';
require dirname(__DIR__) . '/src/Compatibility/UpstreamGeneratorArchive.php';
require dirname(__DIR__) . '/src/Compatibility/UpstreamGeneratorBoundary.php';
require dirname(__DIR__) . '/src/Compatibility/CompatibilityRule.php';
require dirname(__DIR__) . '/src/Compatibility/VersionRange.php';
require dirname(__DIR__) . '/src/Compatibility/BoundedTextRule.php';
require dirname(__DIR__) . '/src/Compatibility/WebmanWorkermanRules.php';
require dirname(__DIR__) . '/src/Compatibility/GeneratedProjectAdapter.php';
require dirname(__DIR__) . '/src/Compatibility/PluginSourceCompletion.php';
require dirname(__DIR__) . '/src/Compatibility/UpstreamProjectGenerator.php';

use WebmanAot\Compatibility\UpstreamProjectGenerator;

try {
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/^--([a-z-]+)=(.+)$/D', $argument, $matches) !== 1
            || isset($options[$matches[1]])
        ) {
            throw new InvalidArgumentException("invalid or duplicate option: {$argument}");
        }
        $options[$matches[1]] = $matches[2];
    }
    foreach (['mirror', 'archive', 'cache', 'lock', 'profile', 'name'] as $required) {
        if (!isset($options[$required])) {
            throw new InvalidArgumentException("missing --{$required}=...");
        }
    }
    $result = (new UpstreamProjectGenerator())->generate(
        $options['mirror'],
        $options['archive'],
        $options['cache'],
        $options['lock'],
        $options['profile'],
        $options['name']
    );
    fwrite(STDOUT, json_encode(
        $result,
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
