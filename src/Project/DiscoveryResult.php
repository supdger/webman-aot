<?php

declare(strict_types=1);

namespace WebmanAot\Project;

final class DiscoveryResult
{
    /**
     * @param list<array{path:string,category:string,owner:string}> $files
     */
    public function __construct(private readonly array $files)
    {
    }

    /**
     * @return list<array{path:string,category:string,owner:string}>
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * @return list<string>
     */
    public function paths(string $category): array
    {
        $paths = [];
        foreach ($this->files as $file) {
            if ($file['category'] === $category) {
                $paths[] = $file['path'];
            }
        }

        return $paths;
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];
        foreach ($this->files as $file) {
            $counts[$file['category']] = ($counts[$file['category']] ?? 0) + 1;
        }
        ksort($counts, SORT_STRING);

        return $counts;
    }

    /**
     * @return array{files:list<array{path:string,category:string,owner:string}>,counts:array<string,int>}
     */
    public function toArray(): array
    {
        return [
            'files' => $this->files,
            'counts' => $this->counts(),
        ];
    }
}
