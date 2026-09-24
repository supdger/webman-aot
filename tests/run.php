<?php

declare(strict_types=1);

require __DIR__ . '/StructureTest.php';
require dirname(__DIR__) . '/src/Version.php';
require dirname(__DIR__) . '/src/Platform/UserDirectoryLayout.php';
require dirname(__DIR__) . '/src/Cli/ExitCode.php';
require dirname(__DIR__) . '/src/Cli/UsageException.php';
require dirname(__DIR__) . '/src/Cli/UnavailableException.php';
require dirname(__DIR__) . '/src/Cli/ConfigurationException.php';
require dirname(__DIR__) . '/src/Cli/Application.php';
require __DIR__ . '/GlobalLauncherTest.php';
require __DIR__ . '/StagePipelineIntegrationTest.php';
require dirname(__DIR__) . '/src/Toolchain/LockValidator.php';
require __DIR__ . '/ToolchainLockTest.php';
require __DIR__ . '/TypePhpPatchManifestTest.php';
require __DIR__ . '/UnifiedPatchApplierTest.php';
require __DIR__ . '/ReproducibilityInputTest.php';
require __DIR__ . '/WindowsReplayContractTest.php';
require dirname(__DIR__) . '/src/Toolchain/ElfStaticVerifier.php';
require __DIR__ . '/ElfStaticVerifierTest.php';

try {
    $root = dirname(__DIR__);
    (new StructureTest($root))->run();
    (new GlobalLauncherTest())->run($root);
    (new StagePipelineIntegrationTest())->run($root);
    (new ToolchainLockTest())->run($root);
    (new TypePhpPatchManifestTest())->run($root);
    (new UnifiedPatchApplierTest())->run();
    (new ReproducibilityInputTest())->run($root);
    (new WindowsReplayContractTest())->run($root);
    (new ElfStaticVerifierTest())->run();
    fwrite(STDOUT, "[PASS] repository structure is independent and self-contained\n");
    fwrite(STDOUT, "[PASS] global launcher uses the private user layout without a project plugin\n");
    fwrite(STDOUT, "[PASS] CLI stages emit stable exits, logs, and diagnostic bundles\n");
    fwrite(STDOUT, "[PASS] toolchain lock schema and digest guards are valid\n");
    fwrite(STDOUT, "[PASS] TypePHP patch manifest and patch set are guarded\n");
    fwrite(STDOUT, "[PASS] unified patches apply exactly and fail closed on drift\n");
    fwrite(STDOUT, "[PASS] normalized reproducibility input is deterministic\n");
    fwrite(STDOUT, "[PASS] Windows replay remains CLI-only and lock-driven\n");
    fwrite(STDOUT, "[PASS] ELF static verifier rejects interpreter-bearing artifacts\n");
    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, '[FAIL] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
