<?php

declare(strict_types=1);

function main(): void
{
    if (getenv('AOT_TEST_OPENSSL')) {
        openssl_pkey_export('not-a-key', $pem);
        openssl_pkey_export(key: 'not-a-key', output: $namedPem);
    }
}
