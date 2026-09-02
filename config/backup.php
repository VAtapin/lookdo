<?php

$path = env('BACKUP_PATH', storage_path('app/backups'));

return [
    'path' => $path,
    'keep' => (int) env('BACKUP_KEEP', 14),
    'tenant_path' => env('TENANT_BACKUP_PATH', rtrim($path, '/\\').DIRECTORY_SEPARATOR.'tenants'),
    'tenant_keep' => (int) env('TENANT_BACKUP_KEEP', 14),
    'mysqldump_path' => env('MYSQLDUMP_PATH', '/usr/bin/mysqldump'),
];
