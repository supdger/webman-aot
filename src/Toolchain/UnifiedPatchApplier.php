<?php

declare(strict_types=1);

namespace WebmanAot\Toolchain;

final class UnifiedPatchApplier
{
    public function apply(string $patchPath, string $root): void
    {
        $contents = file_get_contents($patchPath);
        if ($contents === false) {
            throw new \RuntimeException("unable to read patch: {$patchPath}");
        }

        $root = rtrim(str_replace('\\', '/', $root), '/');
        if ($root === '' || !is_dir($root)) {
            throw new \RuntimeException("patch root does not exist: {$root}");
        }

        $lines = explode("\n", str_replace("\r\n", "\n", $contents));
        $index = 0;
        while ($index < count($lines)) {
            if ($lines[$index] === '') {
                $index++;
                continue;
            }
            if (!str_starts_with($lines[$index], '--- a/')) {
                throw new \RuntimeException(
                    sprintf('unsupported patch header at line %d in %s', $index + 1, $patchPath)
                );
            }

            $oldPath = substr($lines[$index], strlen('--- a/'));
            $index++;
            if (!isset($lines[$index]) || !str_starts_with($lines[$index], '+++ b/')) {
                throw new \RuntimeException(
                    sprintf('missing new-file header at line %d in %s', $index + 1, $patchPath)
                );
            }
            $newPath = substr($lines[$index], strlen('+++ b/'));
            $index++;
            if ($oldPath !== $newPath) {
                throw new \RuntimeException('file creation, deletion, and rename patches are not supported');
            }
            $this->assertSafeRelativePath($oldPath);

            $target = $root . '/' . $oldPath;
            $source = file_get_contents($target);
            if ($source === false) {
                throw new \RuntimeException("patch target does not exist: {$target}");
            }
            $trailingNewline = str_ends_with($source, "\n");
            $sourceLines = explode("\n", str_replace("\r\n", "\n", $source));
            if ($trailingNewline) {
                array_pop($sourceLines);
            }

            $offset = 0;
            $hunkCount = 0;
            while ($index < count($lines) && !str_starts_with($lines[$index], '--- a/')) {
                if ($lines[$index] === '') {
                    $index++;
                    continue;
                }
                if (!preg_match(
                    '/^@@ -([0-9]+)(?:,([0-9]+))? \+([0-9]+)(?:,([0-9]+))? @@(?: .*)?$/D',
                    $lines[$index],
                    $matches
                )) {
                    throw new \RuntimeException(
                        sprintf('unsupported hunk header at line %d in %s', $index + 1, $patchPath)
                    );
                }
                $oldStart = (int) $matches[1];
                $oldCount = $matches[2] === '' ? 1 : (int) $matches[2];
                $newCount = $matches[4] === '' ? 1 : (int) $matches[4];
                $index++;

                $before = [];
                $after = [];
                while ($index < count($lines)
                    && !str_starts_with($lines[$index], '@@ ')
                    && !str_starts_with($lines[$index], '--- a/')
                ) {
                    $line = $lines[$index];
                    if ($line === '\\ No newline at end of file') {
                        throw new \RuntimeException('patches without a final newline are not supported');
                    }
                    if ($line === '') {
                        break;
                    }
                    $marker = $line[0];
                    $value = substr($line, 1);
                    if ($marker === ' ') {
                        $before[] = $value;
                        $after[] = $value;
                    } elseif ($marker === '-') {
                        $before[] = $value;
                    } elseif ($marker === '+') {
                        $after[] = $value;
                    } else {
                        throw new \RuntimeException(
                            sprintf('unsupported patch line at %d in %s', $index + 1, $patchPath)
                        );
                    }
                    $index++;
                }

                if (count($before) !== $oldCount || count($after) !== $newCount) {
                    throw new \RuntimeException(
                        sprintf('hunk line count mismatch for %s at old line %d', $oldPath, $oldStart)
                    );
                }
                $position = $oldStart - 1 + $offset;
                if ($position < 0 || array_slice($sourceLines, $position, $oldCount) !== $before) {
                    throw new \RuntimeException(
                        sprintf('hunk context mismatch for %s at old line %d', $oldPath, $oldStart)
                    );
                }
                array_splice($sourceLines, $position, $oldCount, $after);
                $offset += $newCount - $oldCount;
                $hunkCount++;
            }

            if ($hunkCount === 0) {
                throw new \RuntimeException("patch contains no hunks for {$oldPath}");
            }
            $result = implode("\n", $sourceLines) . ($trailingNewline ? "\n" : '');
            if (file_put_contents($target, $result) === false) {
                throw new \RuntimeException("unable to write patched file: {$target}");
            }
        }
    }

    private function assertSafeRelativePath(string $path): void
    {
        $normalized = str_replace('\\', '/', $path);
        if ($normalized === ''
            || str_starts_with($normalized, '/')
            || preg_match('/^[A-Za-z]:\//D', $normalized) === 1
            || in_array('..', explode('/', $normalized), true)
        ) {
            throw new \RuntimeException("unsafe patch path: {$path}");
        }
    }
}
