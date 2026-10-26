<?php

declare(strict_types=1);

use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTruncationAsOwner;

// The database drivers of Laravel (cache, cache locks, sessions) as pyme_app, the default connection. pyme_app has no
// table-level UPDATE on sessions and cache_locks, only UPDATE by column without the primary key, and cache keeps table-level
// UPDATE as a declared exception. These tests prove that those GRANTs are enough for what the framework really updates:
// the ON CONFLICT DO UPDATE of the cache upsert, the UPDATE of the session handler and the UPDATE of an expired lock.
// phpunit.xml forces the array drivers, so nothing else in the suite goes through these paths.
//
// DatabaseTruncationAsOwner (real commits, no wrapping transaction): the lock UPDATE path starts with an INSERT that fails
// on the primary key, and that error would abort the transaction of RefreshDatabase.
uses(DatabaseTruncationAsOwner::class);

it('overwrites a cache key through the upsert (ON CONFLICT DO UPDATE) with the second value', function (): void {
    $store = Cache::store('database');

    $store->put('overwritten', 'first value', 600);
    $store->put('overwritten', 'second value', 600);

    expect([
        'value' => $store->get('overwritten'),
        'rows' => DB::table('cache')->count(),
    ])->toBe([
        'value' => 'second value',
        'rows' => 1,
    ]);
});

it('updates an existing session row through the handler (second write is performUpdate)', function (): void {
    $handler = new DatabaseSessionHandler(DB::connection(), 'sessions', 120, $this->app);

    // First write: read() finds no row, so exists stays unset and performInsert runs. The same instance then knows the
    // session exists (write() sets it), so the second write goes straight to performUpdate.
    $handler->write('session-id', 'first payload');
    $handler->write('session-id', 'second payload');

    $row = DB::table('sessions')->where('id', 'session-id')->first();

    expect([
        'rows' => DB::table('sessions')->count(),
        'payload' => base64_decode($row->payload),
    ])->toBe([
        'rows' => 1,
        'payload' => 'second payload',
    ]);
});

it('updates a session row found by a new handler instance (read() marks it as existing)', function (): void {
    $first = new DatabaseSessionHandler(DB::connection(), 'sessions', 120, $this->app);
    $first->write('session-id', 'first payload');

    $second = new DatabaseSessionHandler(DB::connection(), 'sessions', 120, $this->app);
    $second->write('session-id', 'second payload');

    expect([
        'rows' => DB::table('sessions')->count(),
        'payload' => base64_decode(DB::table('sessions')->where('id', 'session-id')->value('payload')),
    ])->toBe([
        'rows' => 1,
        'payload' => 'second payload',
    ]);
});

it('takes over an expired lock of another owner with an UPDATE of owner and expiration, and refreshes it', function (): void {
    $store = Cache::store('database');

    // A short-lived lock whose expiration is then forced into the past (expiration is an updatable column).
    $previous = $store->lock('shared-resource', 10, 'previous-owner');
    $acquiredByPrevious = $previous->get();
    DB::table('cache_locks')->update(['expiration' => time() - 60]);

    $taker = $store->lock('shared-resource', 30, 'taking-owner');
    $acquiredByTaker = $taker->get();
    $rowAfterTakeover = DB::table('cache_locks')->first();

    // Now that the taker holds it and it is live, the previous owner is turned away (UPDATE touches no row).
    $previousRetry = $previous->get();

    $taker->refresh(300);
    $expirationAfterRefresh = (int) DB::table('cache_locks')->value('expiration');

    expect([
        'acquiredByPrevious' => $acquiredByPrevious,
        'acquiredByTaker' => $acquiredByTaker,
        'rows' => DB::table('cache_locks')->count(),
        'ownerAfterTakeover' => $rowAfterTakeover->owner,
        'expirationAfterTakeoverIsInTheFuture' => (int) $rowAfterTakeover->expiration > time(),
        'previousRetry' => $previousRetry,
        'expirationAfterRefreshIsAbout300s' => $expirationAfterRefresh >= time() + 299 && $expirationAfterRefresh <= time() + 301,
    ])->toBe([
        'acquiredByPrevious' => true,
        'acquiredByTaker' => true,
        'rows' => 1,
        'ownerAfterTakeover' => 'taking-owner',
        'expirationAfterTakeoverIsInTheFuture' => true,
        'previousRetry' => false,
        'expirationAfterRefreshIsAbout300s' => true,
    ]);
});
