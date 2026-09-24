<?php

declare(strict_types=1);

namespace WebmanAot\Update;

final class UpdateManifest
{
    /**
     * @param array<string, array{version:string,url:string,sha256:string}> $targets
     */
    public function __construct(
        private readonly string $channel,
        private readonly array $targets,
        private readonly string $payloadSha256,
        private readonly string $verifiedKeyId
    ) {
    }

    /**
     * @return array{version:string,url:string,sha256:string}
     */
    public function target(string $name): array
    {
        if (!isset($this->targets[$name])) {
            throw new \InvalidArgumentException("update target is missing: {$name}");
        }

        return $this->targets[$name];
    }

    public function channel(): string
    {
        return $this->channel;
    }

    public function payloadSha256(): string
    {
        return $this->payloadSha256;
    }

    public function verifiedKeyId(): string
    {
        return $this->verifiedKeyId;
    }
}
