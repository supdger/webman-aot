<?php

declare(strict_types=1);

function main(): void
{
    $attribute = (object) ['nodeName' => 'href'];
    foreach ([$attribute] as $entry) {
        $localizable = in_array($entry->nodeName, ['href'], true);
        if (!$localizable) {
            throw new RuntimeException('attribute lookup changed');
        }
    }
}
