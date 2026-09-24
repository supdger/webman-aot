<?php

declare(strict_types=1);

namespace WebmanAot\Cli;

enum StageStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
