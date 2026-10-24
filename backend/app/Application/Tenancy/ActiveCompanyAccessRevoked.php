<?php

declare(strict_types=1);

namespace App\Application\Tenancy;

use App\Domain\DomainError;
use App\Domain\ErrorKind;

/**
 * The user was a member of the active company when it was selected and no longer is.
 */
final class ActiveCompanyAccessRevoked extends DomainError
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
        return new self("User {$userId} lost access to active company {$companyId}.", $userId, $companyId);
    }

    public function errorCode(): string
    {
        return 'active_company_access_revoked';
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
