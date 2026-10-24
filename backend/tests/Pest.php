<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Unit no extiende TestCase de Laravel: el dominio se prueba sin framework, facades ni base de datos.

// Integration elige su trait por test: RefreshDatabase para cuadre e inmutabilidad,
// DatabaseTruncation para concurrencia con commits reales.
pest()->extend(TestCase::class)->in('Integration');

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');