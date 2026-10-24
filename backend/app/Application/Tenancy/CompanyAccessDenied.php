<?php

declare(strict_types=1);

namespace App\Application\Tenancy;

use App\Domain\DomainError;
use App\Domain\ErrorKind;

/**
 * The user is not a member of the company. Also raised for a nonexistent company, so existence is not revealed.
 */
final class CompanyAccessDenied extends DomainError
{
    private function __construct(
        string $message,
        private readonly int $userId,
        private readonly int $companyId,
    ) {
        parent::__construct($message);
    }

    public static function forCompany(int $userId, int $companyId): self
    {
        return new self("User {$userId} cannot access company {$companyId}.", $userId, $companyId);
    }

    public function errorCode(): string
    {
        return 'company_access_denied';
    }

    public function kind(): ErrorKind
    {
        return ErrorKind::Forbidden;
    }

    public function context(): array
    {
        return ['user_id' => $this->userId, 'company_id' => $this->companyId];
    }
}
