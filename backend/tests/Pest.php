<?php

declare(strict_types=1);

use Tests\RefreshDatabaseAsOwner;
use Tests\TestCase;

// Unit does not extend Laravel's TestCase: the domain is tested without framework, facades or database.

// Integration picks its trait per test: RefreshDatabaseAsOwner for balancing and immutability,
// DatabaseTruncation for concurrency with real commits.
pest()->extend(TestCase::class)->in('Integration');

pest()->extend(TestCase::class)->use(RefreshDatabaseAsOwner::class)->in('Feature');
