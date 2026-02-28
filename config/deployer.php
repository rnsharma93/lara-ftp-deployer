<?php

return [

    'default_artisan_commands' => [
        //'down',
        'migrate --force',
        'config:clear',
        'cache:clear',
        'config:cache',
        'up',
    ],

    'environments' => [

        'default' => [
            'remote_base_url' => env('DEPLOYER_REMOTE_BASE_URL'),
            'ftp_host'        => env('DEPLOYER_FTP_HOST'),
            'ftp_port'        => env('DEPLOYER_FTP_PORT', 21),
            'ftp_username'    => env('DEPLOYER_FTP_USERNAME'),
            'ftp_password'    => env('DEPLOYER_FTP_PASSWORD'),
            'ftp_path'        => env('DEPLOYER_FTP_PATH'),
            'deploy_token'    => env('DEPLOYER_TOKEN'),
            'branch'          => env('DEPLOYER_BRANCH', 'main'),
            'artisan_commands' => null,
        ],

        'production' => [
            'remote_base_url' => env('DEPLOYER_PRODUCTION_REMOTE_BASE_URL'),
            'ftp_host'        => env('DEPLOYER_PRODUCTION_FTP_HOST'),
            'ftp_port'        => env('DEPLOYER_PRODUCTION_FTP_PORT', 21),
            'ftp_username'    => env('DEPLOYER_PRODUCTION_FTP_USERNAME'),
            'ftp_password'    => env('DEPLOYER_PRODUCTION_FTP_PASSWORD'),
            'ftp_path'        => env('DEPLOYER_PRODUCTION_FTP_PATH'),
            'deploy_token'    => env('DEPLOYER_PRODUCTION_DEPLOY_TOKEN'),
            'branch'          => env('DEPLOYER_PRODUCTION_BRANCH', 'main'),
            'artisan_commands' => [
                'down',
                'migrate --force',
                'config:cache',
                'up',
            ],
        ],

        'staging' => [
            'remote_base_url' => env('DEPLOYER_STAGING_REMOTE_BASE_URL'),
            'ftp_host'        => env('DEPLOYER_STAGING_FTP_HOST'),
            'ftp_port'        => env('DEPLOYER_STAGING_FTP_PORT', 21),
            'ftp_username'    => env('DEPLOYER_STAGING_FTP_USERNAME'),
            'ftp_password'    => env('DEPLOYER_STAGING_FTP_PASSWORD'),
            'ftp_path'        => env('DEPLOYER_STAGING_FTP_PATH'),
            'deploy_token'    => env('DEPLOYER_STAGING_DEPLOY_TOKEN'),
            'branch'          => env('DEPLOYER_STAGING_BRANCH', 'develop'),
            'artisan_commands' => null,
        ],

    ],

    'exclude' => [
        '.git',
        '.env',
        '.env.*',
        'node_modules',
        'tests',
        'storage/logs',
        'storage/framework/cache',
        'storage/framework/sessions',
        'storage/framework/views',
        '*.log',
        '*.map',
        '.DS_Store',
        'Thumbs.db',
        '.idea',
        '.vscode',
        'public/hot'
    ],

    'incremental' => [
        'enabled' => env('DEPLOY_INCREMENTAL', true),
        'git_enabled' => env('DEPLOY_INCREMENTAL_GIT', false),
        'smart_dependencies' => true,
    ],
];
