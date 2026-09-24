<?php

declare(strict_types=1);

namespace WebmanAot\Update;

use WebmanAot\Cli\ConfigurationException;

final class TrustedKeyStore
{
    /**
     * @return array<string, string>
     */
    public function read(string $path): array
    {
        if (!defined('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES')) {
            throw new ConfigurationException('Ed25519 verification is unavailable');
        }
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new ConfigurationException('trusted update key store is missing');
        }
        try {
            $document = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ConfigurationException(
                'trusted update key store is invalid JSON',
                previous: $exception
            );
        }
        if (!is_array($document)
            || ($document['schema'] ?? null) !== 'webman-aot-trusted-update-keys-v1'
            || !is_array($document['keys'] ?? null)
        ) {
            throw new ConfigurationException('trusted update key store is invalid');
        }

        $keys = [];
        foreach ($document['keys'] as $entry) {
            if (!is_array($entry)
                || !is_string($entry['id'] ?? null)
                || preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $entry['id']) !== 1
                || ($entry['algorithm'] ?? null) !== 'ed25519'
                || !is_string($entry['publicKey'] ?? null)
            ) {
                throw new ConfigurationException('trusted update key entry is invalid');
            }
            $decoded = base64_decode($entry['publicKey'], true);
            if (!is_string($decoded) || strlen($decoded) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new ConfigurationException("trusted update key is invalid: {$entry['id']}");
            }
            if (isset($keys[$entry['id']])) {
                throw new ConfigurationException("duplicate trusted update key: {$entry['id']}");
            }
            $keys[$entry['id']] = $decoded;
        }
        if ($keys === []) {
            throw new ConfigurationException('trusted update key store is empty');
        }

        return $keys;
    }
}
