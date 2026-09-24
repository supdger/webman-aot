<?php

declare(strict_types=1);

namespace app\Controller;

final class HealthController
{
    public function index(): string
    {
        return 'ok';
    }
}
