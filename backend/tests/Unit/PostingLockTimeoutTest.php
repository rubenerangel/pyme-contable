<?php

declare(strict_types=1);

use App\Domain\DomainError;
use App\Domain\ErrorKind;
use App\Domain\Ledger\PostingLockTimeout;

// PostingLockTimeout: the domain error for a posting transaction that gave up waiting for a lock (SQLSTATE 55P03 raised
// by lock_timeout). It is a Conflict the client can retry. The domain knows nothing about HTTP or PDO. No Laravel, no
// database.

it('is a Conflict with code posting_lock_timeout and keeps the previous exception', function (): void {
    $previous = new RuntimeException('canceling statement due to lock timeout');

    $error = PostingLockTimeout::afterWaiting($previous);

    expect($error)->toBeInstanceOf(DomainError::class);
    expect([
        'code' => $error->errorCode(),
        'kind' => $error->kind(),
        'previous' => $error->getPrevious(),
    ])->toBe([
        'code' => 'posting_lock_timeout',
        'kind' => ErrorKind::Conflict,
        'previous' => $previous,
    ]);
});
