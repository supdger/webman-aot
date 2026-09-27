<?php

declare(strict_types=1);

namespace app\Controller;

final class HealthController
{
    public function index(\support\Request $request): string
    {
        return 'ok';
    }
}
