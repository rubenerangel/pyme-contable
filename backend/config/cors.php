<?php

// CORS is closed because SPA and API share one origin behind nginx. Opening it is a decision of
// docs/decisiones.md; if the origins are ever split, restrict it to the frontend domain.

return [

    'paths' => [],

    'allowed_methods' => [],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
