<?php

declare(strict_types=1);

namespace App\Ai;

enum Effort: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case XHigh = 'xhigh';
    case Max = 'max';
}
