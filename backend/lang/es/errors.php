<?php

declare(strict_types=1);

// Messages of the Problem Details responses. 'codes' is keyed by the stable error code (domain and generic ones),
// 'titles' by HTTP status. Nothing here may come from an exception message.
return [
    'codes' => [
        // Domain errors.
        'company_access_denied' => 'No tienes acceso a la empresa solicitada.',
        'no_active_company' => 'No hay una empresa activa. Selecciona una empresa para continuar.',
        'active_company_access_revoked' => 'No tienes acceso a la empresa activa. Selecciona otra empresa.',

        // Generic, by exception type or HTTP status.
        'validation_failed' => 'Los datos enviados no son válidos.',
        'unauthenticated' => 'Debes iniciar sesión para continuar.',
        'forbidden' => 'No tienes permiso para realizar esta acción.',
        'not_found' => 'El recurso solicitado no existe.',
        'method_not_allowed' => 'El método de la solicitud no está permitido para este recurso.',
        'csrf_token_mismatch' => 'La sesión expiró o el token de seguridad no es válido. Recarga la página e intenta de nuevo.',
        'too_many_requests' => 'Demasiadas solicitudes. Intenta de nuevo más tarde.',
        'bad_request' => 'La solicitud no es válida.',
        'service_unavailable' => 'El servicio no está disponible temporalmente.',
        'http_error' => 'No se pudo procesar la solicitud.',
        'internal_error' => 'Ocurrió un error inesperado. Si persiste, informa el identificador de la solicitud.',
    ],

    'titles' => [
        400 => 'Solicitud incorrecta',
        401 => 'No autenticado',
        403 => 'Acceso denegado',
        404 => 'No encontrado',
        405 => 'Método no permitido',
        409 => 'Conflicto',
        419 => 'Sesión expirada',
        422 => 'Datos no válidos',
        429 => 'Demasiadas solicitudes',
        500 => 'Error interno',
        503 => 'Servicio no disponible',
        'default' => 'Error',
    ],
];
