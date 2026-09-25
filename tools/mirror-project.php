#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Cli/ConfigurationException.php';
require dirname(__DIR__) . '/src/Project/SourceTreeSnapshot.php';
require dirname(__DIR__) . '/src/Project/ProjectWorkspace.php';
require dirname(__DIR__) . '/src/Project/ProjectMirror.php';

use WebmanAot\Project\ProjectMirror;
use WebmanAot\Project\ProjectWorkspace;
use WebmanAot\Project\SourceTreeSnapshot;

try {
    if (count($argv) !== 2
        || preg_match('/^--project=(.+)$/D', $argv[1], $matches) !== 1
    ) {
        throw new InvalidArgumentException('usage: mirror-project.php --project=PATH');
    }
    $project = realpath($matches[1]);
    if (!is_string($project) || !is_dir($project) || is_link($matches[1])) {
        throw new InvalidArgumentException('project directory is missing or unsafe');
    }
    $source = (new SourceTreeSnapshot($project))->capture();
    $workspace = (new ProjectWorkspace($project))->prepare(
        hash('sha256', $source['sha256'] . "\0mirror.v1"),
        $source['sha256']
    );
    $mirror = (new ProjectMirror($project))->create($workspace['build']);
    fwrite(STDOUT, json_encode(
        ['source' => $source, 'mirror' => $mirror],
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
