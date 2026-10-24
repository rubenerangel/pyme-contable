<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

enum PeriodStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
