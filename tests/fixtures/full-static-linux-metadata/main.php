<?php

function main(): void
{
    if (DIRECTORY_SEPARATOR !== '/'
        || PATH_SEPARATOR !== ':'
        || PHP_OS_FAMILY !== 'Linux'
        || PHP_SHLIB_SUFFIX !== 'so'
        || !function_exists('sys_getloadavg')
    ) {
        throw new RuntimeException('Linux target metadata differs from runtime');
    }
    echo "linux-target-metadata-ok\n";
}
