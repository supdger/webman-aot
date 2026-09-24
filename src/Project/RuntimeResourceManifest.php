<?php

declare(strict_types=1);

namespace WebmanAot\Project;

final class RuntimeResourceManifest
{
    /**
     * @param list<array{path:string,role:string,kind:string,sourceSha256:?string,mutable:bool}> $entries
     */
    public function __construct(private readonly array $entries)
    {
    }

    /**
     * @return list<array{path:string,role:string,kind:string,sourceSha256:?string,mutable:bool}>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * @return array<string,int>
     */
    public function counts(): array
    {
        $counts = [];
        foreach ($this->entries as $entry) {
            $counts[$entry['role']] = ($counts[$entry['role']] ?? 0) + 1;
        }
        ksort($counts, SORT_STRING);

        return $counts;
    }

    /**
     * @return array{schema:string,entries:list<array{path:string,role:string,kind:string,sourceSha256:?string,mutable:bool}>,counts:array<string,int>}
     */
    public function toArray(): array
    {
        return [
            'schema' => 'webman-aot-runtime-resources-v1',
            'entries' => $this->entries,
            'counts' => $this->counts(),
        ];
    }
}
