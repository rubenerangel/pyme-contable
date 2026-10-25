<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

enum NormalBalance: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
