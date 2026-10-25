<?php

declare(strict_types=1);

namespace App\Domain\ThirdParties;

// Valid values of third_party.person_type: a natural person (personal name columns) or a legal one (business_name).
enum PersonType: string
{
    case Natural = 'natural';
    case Legal = 'legal';
}
