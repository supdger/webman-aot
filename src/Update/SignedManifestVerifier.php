<?php

declare(strict_types=1);

namespace WebmanAot\Update;

use WebmanAot\Cli\UnavailableException;

final class SignedManifestVerifier
{
    /**
     * @param array<string, string> $trustedKeys Raw Ed25519 public keys indexed by id.
     */
    public function verify(string $contents, array $trustedKeys): UpdateManifest
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new UnavailableException('Ed25519 verification is unavailable');
        }
        try {
            $document = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new UnavailableException('update manifest is invalid JSON', previous: $exception);
        }
        if (!is_array($document)
            || ($document['schema'] ?? null) !== 'webman-aot-update-manifest-v1'
            || !is_array($document['payload'] ?? null)
            || !is_array($document['signatures'] ?? null)
        ) {
            throw new UnavailableException('update manifest structure is invalid');
        }
        $payload = $document['payload'];
        $canonical = $this->canonicalJson($payload);
        $verifiedKeyId = null;
        foreach ($document['signatures'] as $signature) {
            if (!is_array($signature)
                || ($signature['algorithm'] ?? null) !== 'ed25519'
                || !is_string($signature['keyId'] ?? null)
                || !is_string($signature['signature'] ?? null)
            ) {
                continue;
            }
            $publicKey = $trustedKeys[$signature['keyId']] ?? null;
            $decoded = base64_decode($signature['signature'], true);
            if (!is_string($publicKey)
                || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                || !is_string($decoded)
                || strlen($decoded) !== SODIUM_CRYPTO_SIGN_BYTES
            ) {
                continue;
            }
            if (sodium_crypto_sign_verify_detached($decoded, $canonical, $publicKey)) {
                $verifiedKeyId = $signature['keyId'];
                break;
            }
        }
        if ($verifiedKeyId === null) {
            throw new UnavailableException('update manifest has no valid trusted signature');
        }

        $channel = $payload['channel'] ?? null;
        $targets = $payload['targets'] ?? null;
        if (!is_string($channel) || $channel === '' || !is_array($targets)) {
            throw new UnavailableException('signed update payload is invalid');
        }
        $validatedTargets = [];
        foreach (['cli', 'toolchain'] as $name) {
            $target = $targets[$name] ?? null;
            if (!is_array($target)
                || !is_string($target['version'] ?? null)
                || $target['version'] === ''
                || !is_string($target['url'] ?? null)
                || !str_starts_with($target['url'], 'https://')
                || !is_string($target['sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $target['sha256']) !== 1
            ) {
                throw new UnavailableException("signed update target is invalid: {$name}");
            }
            $validatedTargets[$name] = [
                'version' => $target['version'],
                'url' => $target['url'],
                'sha256' => $target['sha256'],
            ];
        }

        return new UpdateManifest(
            $channel,
            $validatedTargets,
            hash('sha256', $canonical),
            $verifiedKeyId
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function canonicalJson(array $payload): string
    {
        $this->sortRecursively($payload);

        return json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * @param array<mixed> $value
     */
    private function sortRecursively(array &$value): void
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sortRecursively($item);
            }
        }
    }
}
