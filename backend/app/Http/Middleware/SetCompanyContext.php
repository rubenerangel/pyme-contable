<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Tenancy\ActiveCompanyAccessRevoked;
use App\Application\Tenancy\NoActiveCompany;
use App\Infrastructure\Tenancy\CompanyMemberships;
use App\Infrastructure\Tenancy\DatabaseCompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enters the database company and user context (RLS) for the duration of a business request. The active company
 * comes from the session, the user is the authenticated one, and the membership is revalidated on every request.
 * Must run after authentication.
 */
final class SetCompanyContext
{
    public function __construct(
        private readonly DatabaseCompanyContext $context,
        private readonly CompanyMemberships $memberships,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();
        $companyId = $session->get('active_company_id');

        if ($companyId === null) {
            throw NoActiveCompany::inSession();
        }

        $companyId = (int) $companyId;
        $userId = (int) $request->user()->getAuthIdentifier();

        if (! $this->memberships->isMember($userId, $companyId)) {
            $session->forget('active_company_id');

            throw ActiveCompanyAccessRevoked::forCompany($userId, $companyId);
        }

        $this->context->enter($companyId, $userId);

        try {
            return $next($request);
        } finally {
            $this->context->leave();
        }
    }
}
