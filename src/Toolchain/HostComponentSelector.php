<?php

declare(strict_types=1);

namespace WebmanAot\Toolchain;

final class HostComponentSelector
{
    /**
     * @param list<mixed> $components
     * @return list<array<string, mixed>>
     */
    public function select(array $components, string $host): array
    {
        $selected = [];
        foreach ($components as $component) {
            if (!is_array($component)) {
                continue;
            }
            $id = (string) ($component['id'] ?? '');
            if (str_contains($id, '-macos-') && $host !== 'macos-arm64') {
                continue;
            }
            if (str_contains($id, '-windows-') && $host !== 'windows-x86_64') {
                continue;
            }
            $selected[] = $component;
        }

        return $selected;
    }
}
