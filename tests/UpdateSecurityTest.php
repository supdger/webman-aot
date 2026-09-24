<?php

declare(strict_types=1);

use WebmanAot\Cli\UnavailableException;
use WebmanAot\Toolchain\Downloader;
use WebmanAot\Update\SignedManifestVerifier;
use WebmanAot\Update\TrustedKeyStore;
use WebmanAot\Update\VerifiedDownloader;

final class UpdateSecurityTest
{
    public function run(): void
    {
        $directory = sys_get_temp_dir() . '/webman-aot-update-security-' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('unable to create update security fixture');
        }
        try {
            $privateKey = @openssl_pkey_new([
                'private_key_type' => OPENSSL_KEYTYPE_ED25519,
            ]);
            $this->assert(
                $privateKey instanceof OpenSSLAsymmetricKey,
                'unable to create fixture Ed25519 key'
            );
            $details = openssl_pkey_get_details($privateKey);
            $this->assert(is_array($details), 'unable to read fixture Ed25519 public key');
            $publicKeyPem = $details['key'];
            $keysPath = $directory . '/trusted-keys.json';
            $keysDocument = [
                'schema' => 'webman-aot-trusted-update-keys-v1',
                'keys' => [
                    [
                        'id' => 'fixture-key',
                        'algorithm' => 'ed25519',
                        'publicKeyPem' => $publicKeyPem,
                    ],
                ],
            ];
            file_put_contents(
                $keysPath,
                json_encode($keysDocument, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n"
            );
            $trusted = (new TrustedKeyStore())->read($keysPath);
            $this->assert(isset($trusted['fixture-key']), 'trusted key was not loaded');

            $verifier = new SignedManifestVerifier();
            $payload = [
                'channel' => 'stable',
                'targets' => [
                    'cli' => [
                        'version' => '0.2.0',
                        'url' => 'https://example.com/webman-aot-0.2.0.zip',
                        'sha256' => hash('sha256', 'cli archive'),
                    ],
                    'toolchain' => [
                        'version' => '2026.09.24',
                        'url' => 'https://example.com/toolchain.lock.json',
                        'sha256' => hash('sha256', 'toolchain lock'),
                    ],
                ],
            ];
            $signed = openssl_sign(
                $verifier->canonicalJson($payload),
                $signature,
                $privateKey,
                0
            );
            $this->assert($signed, 'unable to sign fixture update manifest');
            $manifest = [
                'schema' => 'webman-aot-update-manifest-v1',
                'payload' => $payload,
                'signatures' => [
                    [
                        'keyId' => 'fixture-key',
                        'algorithm' => 'ed25519',
                        'signature' => base64_encode($signature),
                    ],
                ],
            ];
            $contents = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            $verified = $verifier->verify($contents, $trusted);
            $this->assert($verified->channel() === 'stable', 'verified channel drifted');
            $this->assert(
                $verified->verifiedKeyId() === 'fixture-key',
                'verified signature key drifted'
            );
            $this->assert(
                $verified->target('cli')['version'] === '0.2.0',
                'verified CLI target drifted'
            );

            $tampered = $manifest;
            $tampered['payload']['targets']['cli']['version'] = '9.9.9';
            $this->assertVerificationFails(
                $verifier,
                json_encode($tampered, JSON_THROW_ON_ERROR),
                $trusted,
                'tampered manifest was accepted'
            );
            $this->assertVerificationFails(
                $verifier,
                $contents,
                ['other-key' => openssl_pkey_get_public($publicKeyPem)],
                'untrusted signature was accepted'
            );

            $this->assertVerifiedDownloads($directory);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    /**
     * @param array<string, \OpenSSLAsymmetricKey> $trusted
     */
    private function assertVerificationFails(
        SignedManifestVerifier $verifier,
        string $contents,
        array $trusted,
        string $message
    ): void {
        try {
            $verifier->verify($contents, $trusted);
            throw new RuntimeException($message);
        } catch (UnavailableException $exception) {
            $this->assert(
                str_contains($exception->getMessage(), 'signature'),
                'signature failure was not explicit'
            );
        }
    }

    private function assertVerifiedDownloads(string $directory): void
    {
        $contents = "verified update\n";
        $destination = $directory . '/verified.bin';
        (new VerifiedDownloader(new UpdateFixtureDownloader($contents)))->fetch(
            'https://example.com/verified.bin',
            hash('sha256', $contents),
            $destination
        );
        $this->assert(file_get_contents($destination) === $contents, 'verified download drifted');

        $mismatch = $directory . '/mismatch.bin';
        try {
            (new VerifiedDownloader(new UpdateFixtureDownloader("wrong\n")))->fetch(
                'https://example.com/mismatch.bin',
                hash('sha256', $contents),
                $mismatch
            );
            throw new RuntimeException('digest mismatch download unexpectedly succeeded');
        } catch (UnavailableException $exception) {
            $this->assert(
                str_contains($exception->getMessage(), 'digest mismatch'),
                'download digest failure was not explicit'
            );
        }
        $this->assert(!file_exists($mismatch), 'digest mismatch promoted a destination');
        $this->assert(!file_exists($mismatch . '.partial'), 'digest mismatch left a partial file');

        $interrupted = $directory . '/interrupted.bin';
        try {
            (new VerifiedDownloader(new UpdateFixtureDownloader("partial\n", true)))->fetch(
                'https://example.com/interrupted.bin',
                hash('sha256', $contents),
                $interrupted
            );
            throw new RuntimeException('interrupted download unexpectedly succeeded');
        } catch (RuntimeException $exception) {
            $this->assert(
                str_contains($exception->getMessage(), 'interrupted'),
                'interrupted download failure drifted'
            );
        }
        $this->assert(!file_exists($interrupted), 'interrupted download promoted a destination');
        $this->assert(
            !file_exists($interrupted . '.partial'),
            'interrupted download left a partial file'
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($directory);
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}

final class UpdateFixtureDownloader implements Downloader
{
    public function __construct(
        private readonly string $contents,
        private readonly bool $interrupt = false
    ) {
    }

    public function download(string $url, string $destination): void
    {
        file_put_contents($destination, $this->contents);
        if ($this->interrupt) {
            throw new RuntimeException('injected interrupted download');
        }
    }
}
