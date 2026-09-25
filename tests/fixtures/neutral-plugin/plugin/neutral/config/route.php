<?php

declare(strict_types=1);

use Webman\Route;

Route::get('/neutral/ping', [\plugin\neutral\app\controller\PingController::class, 'index']);
