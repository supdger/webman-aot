<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Cli/ProcessOutput.php';

use WebmanAotBuilder\Cli\ProcessOutput;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    fwrite(STDOUT, "PASS: {$message}\n");
}

$null = ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'];
$started = microtime(true);
$early = false;
$heartbeat = false;
$output = [1 => '', 2 => ''];
fwrite(STDOUT, "Testing stderr-only output, silent child and late failure (about 6 seconds)...\n");
$code = ProcessOutput::run(
    [PHP_BINARY, '-r', 'fwrite(STDERR,"early-error\n"); usleep(5700000); fwrite(STDOUT,$argv[1]); fwrite(STDERR,"late-error\n"); exit(29);', '中文 argument with spaces'],
    null, null, $null,
    static function (int $index, string $chunk) use (&$output, &$early, $started): void {
        $output[$index] .= $chunk;
        if (str_contains($chunk, 'early-error') && microtime(true) - $started < 2) {
            $early = true;
        }
    },
    static function (float $elapsed, float $silent) use (&$heartbeat): void {
        $heartbeat = $elapsed >= 5 && $elapsed < 5.7 && $silent >= 5;
        fwrite(STDOUT, sprintf("Observed live heartbeat at %.1fs\n", $elapsed));
    }
);
check($early, 'stderr is visible while stdout is silent');
check($heartbeat, 'heartbeat arrives while quiet child is alive');
check($code === 29 && str_contains($output[2], 'late-error'), 'nonzero exit and final error survive');
check($output[1] === '中文 argument with spaces', 'arguments survive without shell interpretation');
$output = [1 => '', 2 => ''];
$code = ProcessOutput::run(
    [PHP_BINARY, '-r', 'for($i=0;$i<32;$i++){fwrite(STDOUT,str_repeat("x",65536)); fwrite(STDERR,str_repeat("y",65536));}'],
    null, null, $null,
    static function (int $index, string $chunk) use (&$output): void { $output[$index] .= $chunk; },
    static function (float $elapsed, float $silent): void {}
);
check($code === 0 && strlen($output[1]) === 2097152 && strlen($output[2]) === 2097152, 'large simultaneous stdout/stderr drains completely');
$output = [1 => '', 2 => ''];
ProcessOutput::run(
    [PHP_BINARY, '-r', 'fwrite(STDERR,"diagnostic\\n"); fwrite(STDOUT, json_encode(["ok"=>true]));'],
    null, null, $null,
    static function (int $index, string $chunk) use (&$output): void { $output[$index] .= $chunk; },
    static function (float $elapsed, float $silent): void {}
);
check(json_decode($output[1], true, flags: JSON_THROW_ON_ERROR) === ['ok' => true] && str_contains($output[2], 'diagnostic'), 'machine JSON is isolated from stderr');
$started = microtime(true);
$caught = false;
try {
    ProcessOutput::run(
        [PHP_BINARY, '-r', 'fwrite(STDOUT,"ready"); sleep(10);'],
        null, null, $null,
        static function (int $index, string $chunk): void { throw new RuntimeException('consumer failed'); },
        static function (float $elapsed, float $silent): void {}
    );
} catch (RuntimeException $failure) {
    $caught = $failure->getMessage() === 'consumer failed';
}
check($caught && microtime(true) - $started < 3, 'callback failure terminates and reaps the child');
fwrite(STDOUT, "Process output regression passed on " . PHP_OS_FAMILY . ".\n");
