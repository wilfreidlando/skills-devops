<?php

return [
    // dev, staging, prod… (DEPLOYMENT dans le .env du serveur)
    'deployment' => env('DEPLOYMENT', 'local'),

    // Commit déployé : compose.prod.yaml le transmet (APP_VERSION = IMAGE_TAG de deploy.sh)
    'version' => env('APP_VERSION', 'dev'),
];
