#!/usr/bin/env php
<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use TypePhp\Generator\AnonClassGenerator;

if (count($argv) !== 3) {
    throw new InvalidArgumentException('usage: check-typephp-anonymous-class-codegen.php TYPEPHP_ROOT GENERATOR_FILE');
}
$typephp = realpath($argv[1]);
$generator = realpath($argv[2]);
if (!is_string($typephp) || !is_string($generator)) {
    throw new RuntimeException('TypePHP root or patched anonymous-class generator is missing');
}
require $typephp . '/vendor/autoload.php';
require $generator;

$parser = (new ParserFactory())->createForNewestSupportedVersion();
$source = <<<'PHP'
<?php
class Sample extends Base implements Contract
{
    public function get()
    {
        if (true) {
            return;
        }
        $nested = function () {
            return;
        };
        return $nested;
    }

    public function parentResult(): parent
    {
        return $this;
    }
}
PHP;
$statements = $parser->parse($source);
if (!is_array($statements) || !$statements[0] instanceof Node\Stmt\Class_) {
    throw new RuntimeException('minimal anonymous-class AST was not parsed');
}
$harness = new class {
    use AnonClassGenerator;

    public Standard $printer;

    public function __construct()
    {
        $this->printer = new Standard();
    }

    public function generate(Node\Stmt\Class_ $class): string
    {
        return $this->genEmbeddedCode($class);
    }

    public function resolveParent(): string
    {
        return $this->resolveTypeNode(new Node\Name('parent'))->toString();
    }

    public function shouldAddMixedReturnToEmbeddedClassMethod(
        Node\Stmt\Class_ $class,
        Node\Stmt\ClassMethod $method
    ): bool {
        return $method->name->toString() === 'get';
    }
};
$output = $harness->generate($statements[0]);
if ($harness->resolveParent() !== 'parent'
    || !str_contains($output, 'function get(): mixed')
    || substr_count($output, 'return null;') !== 2
    || substr_count($output, 'return;') !== 1
    || !str_contains($output, 'function parentResult(): parent')
) {
    throw new RuntimeException("anonymous-class codegen produced unexpected PHP:\n{$output}");
}
$file = tempnam(sys_get_temp_dir(), 'webman-aot-anon-');
if (!is_string($file)) {
    throw new RuntimeException('cannot create anonymous-class lint fixture');
}
try {
    if (file_put_contents($file, "<?php\n" . $output . "\n") === false) {
        throw new RuntimeException('cannot write anonymous-class lint fixture');
    }
    $process = proc_open(
        [PHP_BINARY, '-n', '-l', $file],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('cannot start PHP syntax check');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException("anonymous-class output is invalid:\n{$stdout}\n{$stderr}");
    }
} finally {
    unlink($file);
}
echo json_encode([
    'schema' => 'webman-aot-anonymous-codegen-check-v1',
    'bareReturnNormalized' => true,
    'nestedClosurePreserved' => true,
    'parentTypePreserved' => true,
    'phpLint' => 'pass',
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
