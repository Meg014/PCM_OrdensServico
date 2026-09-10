<?php

use function Cake\Core\env;

/*
 * Local configuration file to provide any overrides to your app.php configuration.
 * Copy and save this file as app_local.php and make changes as required.
 * Note: It is not recommended to commit files with credentials such as app_local.php
 * into source code version control.
 */
return [
    /*
     * Debug Level:
     *
     * Production Mode:
     * false: No error messages, errors, or warnings shown.
     *
     * Development Mode:
     * true: Errors and warnings shown.
     */
    'debug' => filter_var(env('DEBUG', true), FILTER_VALIDATE_BOOLEAN),

    /*
     * Security and encryption configuration
     *
     * - salt - A random string used in security hashing methods.
     *   The salt value is also used as the encryption key.
     *   You should treat it as extremely sensitive data.
     */
    'Security' => [
        'salt' => env('SECURITY_SALT', '__SALT__'),
    ],

    /*
     * Connection information used by the ORM to connect
     * to your application's datastores.
     *
     * See app.php for more configuration options.
     */
    'Datasources' => [
        // Datasource default centralizado em app.php: configure DB_* em config/.env.

        /*
         * The test connection is used during the test suite.
         */
        'test' => [
            'host' => 'localhost',
            //'port' => 'non_standard_port_number',
            'username' => 'my_app',
            'password' => env('DB_TEST_PASSWORD', ''),
            'database' => 'test_myapp',
            //'schema' => 'myapp',
            'url' => env('DATABASE_TEST_URL', 'sqlite://127.0.0.1/tmp/tests.sqlite'),
        ],
    ],

    'Pcm' => [
        'reports' => [
            'incoming' => env(
                'PCM_REPORT_PATH',
                env('PCM_REPORTS_INCOMING', ROOT . DS . 'relatorios' . DS . 'entrada'),
            ),
            'processed' => env('PCM_REPORTS_PROCESSED', ROOT . DS . 'relatorios' . DS . 'processados'),
            'error' => env('PCM_REPORTS_ERROR', ROOT . DS . 'relatorios' . DS . 'erro'),
            'staging' => env('PCM_REPORTS_STAGING', ROOT . DS . 'tmp' . DS . 'pcm-import-staging'),
            'operationalFile' => env('PCM_OPERATIONAL_REPORT_FILE', ''),
            'sheet' => env('PCM_REPORT_SHEET', 'sclxd280'),
            'maxUploadBytes' => (int)env('PCM_MAX_UPLOAD_BYTES', 20 * 1024 * 1024),
            'minimumFileAgeSeconds' => (int)env('PCM_MINIMUM_FILE_AGE_SECONDS', 60),
            'scheduleIntervalSeconds' => (int)env('PCM_SCHEDULE_INTERVAL_SECONDS', 300),
        ],
    ],

    /*
     * Email configuration.
     *
     * Host and credential configuration in case you are using SmtpTransport
     *
     * See app.php for more configuration options.
     */
    'EmailTransport' => [
        'default' => [
            'host' => 'localhost',
            'port' => 25,
            'username' => null,
            'password' => null,
            'client' => null,
            'url' => env('EMAIL_TRANSPORT_DEFAULT_URL', null),
        ],
    ],
];
