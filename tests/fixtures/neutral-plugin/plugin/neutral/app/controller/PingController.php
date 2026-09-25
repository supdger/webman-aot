<?php

declare(strict_types=1);

namespace plugin\neutral\app\controller;

final class PingController
{
    public function index(\support\Request $request): string
    {
        return 'neutral-ok';
    }
}
