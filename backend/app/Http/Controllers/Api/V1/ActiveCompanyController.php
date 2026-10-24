<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Tenancy\SwitchActiveCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SwitchActiveCompanyRequest;
use Illuminate\Http\Response;

final class ActiveCompanyController extends Controller
{
    public function __construct(private readonly SwitchActiveCompany $switchActiveCompany) {}

    public function __invoke(SwitchActiveCompanyRequest $request): Response
    {
        $companyId = $request->integer('company_id');

        // Throws CompanyAccessDenied (rendered as 403) before touching the session.
        ($this->switchActiveCompany)((int) $request->user()->getAuthIdentifier(), $companyId);

        $request->session()->put('active_company_id', $companyId);

        return response()->noContent();
    }
}
