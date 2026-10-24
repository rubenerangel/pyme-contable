<?php

declare(strict_types=1);

// Only the rules in use. Anything missing falls back to the English file. 'custom' and 'attributes' are read by the
// validator on every message.
return [
    'required' => 'El campo :attribute es obligatorio.',
    'integer' => 'El campo :attribute debe ser un número entero.',
    'min' => [
        'numeric' => 'El campo :attribute debe ser al menos :min.',
    ],

    'custom' => [],
    'attributes' => [],
];
