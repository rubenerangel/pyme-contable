<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Infrastructure\Tenancy\CompanyMemberships;
use App\Infrastructure\Tenancy\DatabaseCompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enters the database company context (RLS) for the duration of a business request. The active company comes from
 * the session and the membership is revalidated on every request. Must run after authentication.
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
            return response()->json(['message' => 'No hay una empresa activa. Selecciona una empresa para continuar.'], 409);
        }

        $companyId = (int) $companyId;

        if (! $this->memberships->isMember((int) $request->user()->getAuthIdentifier(), $companyId)) {
            $session->forget('active_company_id');

            return response()->json(['message' => 'No tienes acceso a la empresa activa. Selecciona otra empresa.'], 403);
        }

        $this->context->enter($companyId);

        try {
            return $next($request);
        } finally {
            $this->context->leave();
        }
    }
}
