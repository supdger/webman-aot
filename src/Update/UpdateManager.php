<?php

declare(strict_types=1);

namespace WebmanAot\Update;

use WebmanAot\Cli\ConfigurationException;
use WebmanAot\Platform\UserDirectoryLayout;
use WebmanAot\Toolchain\Downloader;
use WebmanAot\Toolchain\ToolchainLocator;
use WebmanAot\Toolchain\ToolchainPreparer;
use WebmanAot\Toolchain\ToolchainRepairer;

final class UpdateManager
{
    public function __construct(
        private readonly UserDirectoryLayout $layout,
        private readonly string $currentApp,
        private readonly string $currentVersion,
        private readonly string $host,
        private readonly Downloader $downloader,
        private readonly PackageExtractor $extractor,
        private readonly CliSelfChecker $selfChecker,
        private readonly ?ToolchainPreparer $toolchainPreparer = null
    ) {
    }

    /**
     * @return array{previous:string,new:string,version:string}
     */
    public function selfUpdate(
        string $manifestUrl,
        string $trustedKeysPath,
        ?\Closure $progress = null
    ): array
    {
        $manifest = $this->loadManifest($manifestUrl, $trustedKeysPath, $progress);

        return (new SelfUpdater(
            $this->layout,
            $this->currentApp,
            $this->currentVersion,
            new VerifiedDownloader($this->downloader),
            $this->extractor,
            $this->selfChecker
        ))->update(
            $manifest->target('cli'),
            $manifest->payloadSha256(),
            $manifest->verifiedKeyId(),
            static fn (int $bytes, ?int $total = null) => $progress?->__invoke('CLI update package', $bytes, $total)
        );
    }

    public function rollbackSelf(): string
    {
        return (new CliVersionStore($this->layout))->rollback();
    }

    /**
     * @return array{
     *     generation:string,
     *     copied:list<string>,
     *     downloaded:list<string>,
     *     lockSha256:string,
     *     changed:bool,
     *     version:string,
     *     verifiedKeyId:string,
     *     manifestPayloadSha256:string
     * }
     */
    public function updateToolchain(
        string $manifestUrl,
        string $trustedKeysPath,
        ?\Closure $progress = null,
        ?\Closure $stage = null
    ): array
    {
        $manifest = $this->loadManifest($manifestUrl, $trustedKeysPath, $progress);
        $target = $manifest->target('toolchain');
        $candidate = $this->temporaryPath('toolchain-lock') . '.json';
        try {
            (new VerifiedDownloader($this->downloader))->fetch(
                $target['url'],
                $target['sha256'],
                $candidate,
                static fn (int $bytes, ?int $total = null) => $progress?->__invoke('Toolchain lock', $bytes, $total)
            );
            $result = (new ToolchainRepairer(
                $candidate,
                $this->layout,
                $this->host,
                $this->downloader,
                $this->toolchainPreparer
            ))->repair(
                $stage,
                static fn (string $step, int $bytes, ?int $total) => $progress?->__invoke($step, $bytes, $total)
            );
        } finally {
            if (is_file($candidate)) {
                unlink($candidate);
            }
            if (is_file($candidate . '.partial')) {
                unlink($candidate . '.partial');
            }
        }

        return $result + [
            'version' => $target['version'],
            'verifiedKeyId' => $manifest->verifiedKeyId(),
            'manifestPayloadSha256' => $manifest->payloadSha256(),
        ];
    }

    public function rollbackToolchain(): string
    {
        return (new ToolchainLocator($this->layout))->rollback($this->host);
    }

    private function loadManifest(
        string $url,
        string $trustedKeysPath,
        ?\Closure $progress = null
    ): UpdateManifest
    {
        if (!str_starts_with($url, 'https://')) {
            throw new ConfigurationException('update manifest URL must use HTTPS');
        }
        $path = $this->temporaryPath('update-manifest') . '.json';
        try {
            $this->downloader->download(
                $url,
                $path,
                static fn (int $bytes, ?int $total = null) => $progress?->__invoke('Update manifest', $bytes, $total)
            );
            $contents = file_get_contents($path);
            if (!is_string($contents)) {
                throw new ConfigurationException('downloaded update manifest is unreadable');
            }

            return (new SignedManifestVerifier())->verify(
                $contents,
                (new TrustedKeyStore())->read($trustedKeysPath)
            );
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function temporaryPath(string $prefix): string
    {
        $directory = $this->layout->path('tmp');
        if (!is_dir($directory)
            && !mkdir($directory, 0700, true)
            && !is_dir($directory)
        ) {
            throw new ConfigurationException('unable to create update temporary directory');
        }

        return $directory . '/' . $prefix . '-' . bin2hex(random_bytes(8));
    }
}
