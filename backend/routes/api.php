<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ActiveCompanyController;
use Illuminate\Support\Facades\Route;

// Prefix api/v1 is set in bootstrap/app.php. Business routes will add the company.context middleware.
Route::post('/active-company', ActiveCompanyController::class)->middleware('auth:sanctum');
