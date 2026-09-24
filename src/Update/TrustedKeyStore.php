<?php

declare(strict_types=1);

namespace WebmanAot\Update;

use WebmanAot\Cli\ConfigurationException;

final class TrustedKeyStore
{
    /**
     * @return array<string, \OpenSSLAsymmetricKey>
     */
    public function read(string $path): array
    {
        if (!extension_loaded('openssl') || !defined('OPENSSL_KEYTYPE_ED25519')) {
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
                || !is_string($entry['publicKeyPem'] ?? null)
            ) {
                throw new ConfigurationException('trusted update key entry is invalid');
            }
            $publicKey = openssl_pkey_get_public($entry['publicKeyPem']);
            $details = $publicKey instanceof \OpenSSLAsymmetricKey
                ? openssl_pkey_get_details($publicKey)
                : false;
            if (!$publicKey instanceof \OpenSSLAsymmetricKey
                || !is_array($details)
                || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_ED25519
            ) {
                throw new ConfigurationException("trusted update key is invalid: {$entry['id']}");
            }
            if (isset($keys[$entry['id']])) {
                throw new ConfigurationException("duplicate trusted update key: {$entry['id']}");
            }
            $keys[$entry['id']] = $publicKey;
        }
        if ($keys === []) {
            throw new ConfigurationException('trusted update key store is empty');
        }

        return $keys;
    }
}
