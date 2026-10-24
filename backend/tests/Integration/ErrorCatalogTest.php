<?php

declare(strict_types=1);

use App\Application\Tenancy\ActiveCompanyAccessRevoked;
use App\Application\Tenancy\CompanyAccessDenied;
use App\Application\Tenancy\NoActiveCompany;
use App\Domain\DomainError;
use App\Domain\ErrorKind;
use PHPUnit\Framework\Assert;
use Tests\Support\ProblemAssertions;

// Error catalog: every concrete DomainError found under app/ has its Spanish message in lang/es/errors.php, every
// generic code of the renderer has one, and the titles cover the statuses the renderer can produce. Reads the lang
// file as a plain array and walks app/ with reflection, so a new error without message breaks this test.
// Needs the Laravel application only for base_path() and config(); it does not touch the database.

/**
 * Concrete DomainError subclasses declared under app/, found by walking the files (PSR-4: App\ is app/).
 *
 * @return list<ReflectionClass<object>>
 */
function concreteDomainErrorsUnderApp(): array
{
    Assert::assertTrue(class_exists(DomainError::class), 'App\Domain\DomainError must exist.');

    $root = base_path('app');
    $found = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($root) + 1, -strlen('.php'));
        $class = 'App\\'.str_replace('/', '\\', $relative);

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);
        if (! $reflection->isAbstract() && $reflection->isSubclassOf(DomainError::class)) {
            $found[] = $reflection;
        }
    }

    return $found;
}

it('has a Spanish message in errors.codes for every concrete DomainError', function (): void {
    $classes = concreteDomainErrorsUnderApp();
    $names = array_map(fn (ReflectionClass $class): string => $class->getName(), $classes);

    // The walk must not pass vacuously: the three errors of the company context are known.
    expect($names)->toContain(CompanyAccessDenied::class, NoActiveCompany::class, ActiveCompanyAccessRevoked::class);

    $codes = ProblemAssertions::errorsLang()['codes'];

    foreach ($classes as $class) {
        // The code is a constant of the class, so it must not depend on constructor arguments.
        $error = $class->newInstanceWithoutConstructor();
        $code = $error->errorCode();

        Assert::assertMatchesRegularExpression('/^[a-z][a-z0-9]*(_[a-z0-9]+)*$/D', $code, "{$class->getName()} code must be stable snake_case in English.");
        Assert::assertArrayHasKey($code, $codes, "{$class->getName()} has no message in lang/es/errors.php codes.");
        expect($codes[$code])->toBeString()->not->toBeEmpty();
        expect($error->kind())->toBeInstanceOf(ErrorKind::class);
    }
});

it('uses distinct codes for distinct DomainError classes', function (): void {
    $codes = array_map(
        fn (ReflectionClass $class): string => $class->newInstanceWithoutConstructor()->errorCode(),
        concreteDomainErrorsUnderApp(),
    );

    expect(array_unique($codes))->toHaveCount(count($codes));
});

it('assigns the kind and code specified to the company context errors', function (): void {
    Assert::assertTrue(class_exists(DomainError::class), 'App\Domain\DomainError must exist.');

    $expected = [
        CompanyAccessDenied::class => [ErrorKind::Forbidden, 'company_access_denied'],
        NoActiveCompany::class => [ErrorKind::Conflict, 'no_active_company'],
        ActiveCompanyAccessRevoked::class => [ErrorKind::Forbidden, 'active_company_access_revoked'],
    ];

    foreach ($expected as $class => [$kind, $code]) {
        $error = (new ReflectionClass($class))->newInstanceWithoutConstructor();

        expect($error)->toBeInstanceOf(DomainError::class);
        expect([$class => [$error->kind(), $error->errorCode()]])->toBe([$class => [$kind, $code]]);
    }
});

it('has the Spanish messages specified for the company context errors', function (): void {
    expect(ProblemAssertions::errorsLang()['codes'])->toMatchArray([
        'company_access_denied' => 'No tienes acceso a la empresa solicitada.',
        'no_active_company' => 'No hay una empresa activa. Selecciona una empresa para continuar.',
        'active_company_access_revoked' => 'No tienes acceso a la empresa activa. Selecciona otra empresa.',
    ]);
});

it('has a message for every generic code the renderer can produce', function (string $code): void {
    $codes = ProblemAssertions::errorsLang()['codes'];

    expect($codes)->toHaveKey($code);
    expect($codes[$code])->toBeString()->not->toBeEmpty();
})->with([
    'validation_failed',
    'unauthenticated',
    'forbidden',
    'not_found',
    'method_not_allowed',
    'csrf_token_mismatch',
    'too_many_requests',
    'bad_request',
    'service_unavailable',
    'http_error',
    'internal_error',
]);

it('has a title for every status the renderer maps, and a default', function (int|string $key): void {
    $titles = ProblemAssertions::errorsLang()['titles'];

    expect($titles)->toHaveKey($key);
    expect($titles[$key])->toBeString()->not->toBeEmpty();
})->with([400, 401, 403, 404, 405, 409, 419, 422, 429, 500, 503, 'default']);

it('runs in Spanish with English as fallback', function (): void {
    expect(config('app.locale'))->toBe('es');
    expect(config('app.fallback_locale'))->toBe('en');
});

it('forces Spanish where the environment may not set it: phpunit.xml, .env.example and the config default', function (): void {
    $backend = base_path();

    expect(file_get_contents($backend.'/phpunit.xml'))
        ->toMatch('/<env\s+name="APP_LOCALE"\s+value="es"\s+force="true"\s*\/>/');
    expect(file_get_contents($backend.'/.env.example'))->toMatch('/^APP_LOCALE=es$/m');
    expect(file_get_contents($backend.'/config/app.php'))->toMatch("/env\('APP_LOCALE',\s*'es'\)/");
});

it('has a hand-written Spanish validation file with the rules in use', function (): void {
    $path = base_path('lang/es/validation.php');
    expect($path)->toBeFile();

    $messages = require $path;

    expect($messages)->toBeArray();
    expect($messages)->toHaveKeys(['required', 'integer', 'min']);
    expect($messages['required'])->toBeString()->not->toBeEmpty();
    expect($messages['integer'])->toBeString()->not->toBeEmpty();
    expect($messages['min'])->toBeArray()->toHaveKey('numeric');
    expect($messages['min']['numeric'])->toBeString()->not->toBeEmpty();
});
