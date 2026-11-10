<?php

return [
    // Exact origins permitted for source endpoint writes, configured by operators.
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', env('LOGIN_SOURCE_ALLOWED_ORIGINS', ''))))),
];
