<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * What kind of failure a domain error is. The entry layer decides how each kind is shown (HTTP status); the domain
 * knows nothing about HTTP.
 */
enum ErrorKind: string
{
    case Invalid = 'invalid';
    case NotFound = 'not_found';
    case Forbidden = 'forbidden';
    case Conflict = 'conflict';
}
