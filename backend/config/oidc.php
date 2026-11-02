<?php

return [
    'issuer' => env('OIDC_ISSUER', 'http://localhost:8081/auth/realms/organization-dev'),
    'internal_issuer' => env('OIDC_INTERNAL_ISSUER', 'http://keycloak:8080/auth/realms/organization-dev'),
    'client_id' => env('OIDC_CLIENT_ID', 'organization-backend'),
    'client_secret' => env('OIDC_CLIENT_SECRET'),
];
