<?php

declare(strict_types=1);

header('Content-Type: application/json');

if (empty($_SERVER['HTTP_AUTHORIZATION'])) {
    if (function_exists('getallheaders')) {
        $headers = getallheaders();

        if (!empty($headers['Authorization'])) {
            $_SERVER['HTTP_AUTHORIZATION'] = $headers['Authorization'];
        } elseif (!empty($headers['authorization'])) {
            $_SERVER['HTTP_AUTHORIZATION'] = $headers['authorization'];
        }
    }
}

$config = require __DIR__ . '/api_config.php';

$sharedToken = $config['sharedToken'] ?? '';
$hmacSecret = $config['hmacSecret'] ?? '';
$maxSkewSeconds = 300; // 5 minutes

function fail(int $status, string $message): void {
    http_response_code($status);
    echo json_encode([
        'ok' => false,
        'error' => $message,
    ]);
    exit;
}

function slugifyVehicleFolder(string $value): string {
    $slug = strtolower(trim($value));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');

    return $slug !== '' ? $slug : 'vehicle';
}

function getVehicleFolderSlug(array $vehicle, int $index): string {
    $name =
        $vehicle['nickname'] ??
        $vehicle['displayName'] ??
        $vehicle['name'] ??
        $vehicle['vehicleId'] ??
        'vehicle-' . ($index + 1);

    $slug = slugifyVehicleFolder((string) $name);

    if ($slug === 'vehicle' && !empty($vehicle['vehicleId'])) {
        return 'vehicle-' . slugifyVehicleFolder((string) $vehicle['vehicleId']);
    }

    return $slug;
}

function prepareVehicleImageFolders(array $vehicles, string $imagesDir): array {
    if (!is_dir($imagesDir) && !mkdir($imagesDir, 0755, true) && !is_dir($imagesDir)) {
        fail(500, 'Failed to create images directory');
    }

    $usedSlugs = [];

    foreach ($vehicles as $index => $vehicle) {
        if (!is_array($vehicle)) {
            continue;
        }

        $baseSlug = getVehicleFolderSlug($vehicle, $index);
        $slug = $baseSlug;
        $suffix = 2;

        while (isset($usedSlugs[$slug])) {
            $slug = $baseSlug . '-' . $suffix;
            $suffix++;
        }

        $usedSlugs[$slug] = true;
        $vehicleDir = $imagesDir . '/' . $slug;

        if (!is_dir($vehicleDir) && !mkdir($vehicleDir, 0755, true) && !is_dir($vehicleDir)) {
            fail(500, 'Failed to create vehicle image directory');
        }

        $gitkeepFile = $vehicleDir . '/.gitkeep';
        if (!is_file($gitkeepFile) && file_put_contents($gitkeepFile, '') === false) {
            fail(500, 'Failed to create vehicle image placeholder');
        }

        $vehicles[$index]['imageFolder'] = 'images/' . $slug;
    }

    return $vehicles;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'Method not allowed');
}

$authHeader =
    $_SERVER['HTTP_AUTHORIZATION'] ??
    $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ??
    '';
if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
    fail(401, 'Missing bearer token');
}

$providedToken = trim($matches[1]);
if (!hash_equals($sharedToken, $providedToken)) {
    fail(403, 'Invalid bearer token');
}

$timestamp = $_SERVER['HTTP_X_DENMARK_TIMESTAMP'] ?? '';
$signatureHeader = $_SERVER['HTTP_X_DENMARK_SIGNATURE'] ?? '';

if ($timestamp === '' || $signatureHeader === '') {
    fail(401, 'Missing signature headers');
}

$timestampUnix = strtotime($timestamp);
if ($timestampUnix === false) {
    fail(400, 'Invalid timestamp');
}

if (abs(time() - $timestampUnix) > $maxSkewSeconds) {
    fail(401, 'Stale request');
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || $rawBody === '') {
    fail(400, 'Empty body');
}

if (!preg_match('/^sha256=(.+)$/', $signatureHeader, $matches)) {
    fail(401, 'Invalid signature format');
}

$providedSignature = trim($matches[1]);
$signingString = $timestamp . '.' . $rawBody;
$expectedSignature = hash_hmac('sha256', $signingString, $hmacSecret);

if (!hash_equals($expectedSignature, $providedSignature)) {
    fail(403, 'Invalid signature');
}

$data = json_decode($rawBody, true);
if (!is_array($data)) {
    fail(400, 'Invalid JSON');
}

if (!isset($data['vehicles']) || !is_array($data['vehicles'])) {
    fail(400, 'Missing vehicles array');
}

$data['vehicles'] = prepareVehicleImageFolders($data['vehicles'], dirname(__DIR__) . '/images');

$storageDir = __DIR__ . '/data';
if (!is_dir($storageDir) && !mkdir($storageDir, 0755, true) && !is_dir($storageDir)) {
    fail(500, 'Failed to create storage directory');
}

$tempFile = $storageDir . '/vehicle-availability.json.tmp';
$finalFile = $storageDir . '/vehicle-availability.json';

$payload = [
    'receivedAt' => gmdate('c'),
    'payload' => $data,
];

$jsonToStore = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($jsonToStore === false) {
    fail(500, 'Failed to encode payload');
}

if (file_put_contents($tempFile, $jsonToStore, LOCK_EX) === false) {
    fail(500, 'Failed to write temp file');
}

if (!rename($tempFile, $finalFile)) {
    fail(500, 'Failed to finalize file');
}

echo json_encode([
    'ok' => true,
    'storedAt' => gmdate('c'),
    'vehicleCount' => count($data['vehicles']),
]);
