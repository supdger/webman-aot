<?php

declare(strict_types=1);

use Webman\Route;

Route::get('/health', [\app\Controller\HealthController::class, 'index']);
