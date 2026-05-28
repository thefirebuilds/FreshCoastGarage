<?php

declare(strict_types=1);

header('Content-Type: application/json');

$file = __DIR__ . '/data/vehicle-availability.json';

if (!is_file($file)) {
    http_response_code(404);
    echo json_encode([
        'ok' => false,
        'error' => 'Availability snapshot not found',
    ]);
    exit;
}

readfile($file);