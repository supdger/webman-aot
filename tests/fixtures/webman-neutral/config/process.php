<?php

declare(strict_types=1);

return [
    'webman' => [
        'handler' => \app\process\Http::class,
        'listen' => 'http://127.0.0.1:18781',
        'count' => 1,
        'constructor' => [
            'requestClass' => \support\Request::class,
            'logger' => new \Psr\Log\NullLogger(),
            'appPath' => app_path(),
            'publicPath' => public_path(),
        ],
    ],
];
