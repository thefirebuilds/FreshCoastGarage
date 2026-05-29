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

$json = file_get_contents($file);
if ($json === false) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to read availability snapshot',
    ]);
    exit;
}

$data = json_decode($json, true);
if (!is_array($data)) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Invalid availability snapshot',
    ]);
    exit;
}

function slugifyAssetName(string $value): string {
    $slug = strtolower(trim($value));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');

    return $slug !== '' ? $slug : 'vehicle';
}

function getVehicleImageFolder(array $vehicle): ?string {
    $folder = $vehicle['imageFolder'] ?? $vehicle['imageDirectory'] ?? $vehicle['imagePath'] ?? null;
    if (!is_string($folder) || trim($folder) === '') {
        return null;
    }

    $folder = trim($folder);
    $folder = ltrim($folder, '/');
    $folder = rtrim($folder, '/');

    if (substr($folder, 0, 7) !== 'images/') {
        return null;
    }

    return $folder;
}

function enrichVehicleImageFromFolder(array $vehicle): array {
    $imageFolder = getVehicleImageFolder($vehicle);
    if ($imageFolder === null) {
        return $vehicle;
    }

    $assetName =
        $vehicle['imageSlug'] ??
        $vehicle['nickname'] ??
        $vehicle['displayName'] ??
        $vehicle['name'] ??
        basename($imageFolder);

    $slug = slugifyAssetName((string) $assetName);
    $folderPath = dirname(__DIR__) . '/' . $imageFolder;
    $extensionMap = [
        'avif' => 'imageAvif',
        'webp' => 'imageWebp',
        'jpg' => 'imageUrl',
        'jpeg' => 'imageUrl',
        'png' => 'imageUrl',
    ];

    foreach ($extensionMap as $extension => $field) {
        $relativePath = $imageFolder . '/' . $slug . '.' . $extension;
        $absolutePath = $folderPath . '/' . $slug . '.' . $extension;

        if (is_file($absolutePath)) {
            $vehicle[$field] = '/' . $relativePath;

            if (empty($vehicle['primaryImageUrl'])) {
                $vehicle['primaryImageUrl'] = '/' . $relativePath;
            }
        }
    }

    return $vehicle;
}

$vehicles = $data['payload']['vehicles'] ?? $data['vehicles'] ?? null;
if (is_array($vehicles)) {
    foreach ($vehicles as $index => $vehicle) {
        if (!is_array($vehicle)) {
            continue;
        }

        $vehicles[$index] = enrichVehicleImageFromFolder($vehicle);
    }

    if (isset($data['payload']['vehicles']) && is_array($data['payload']['vehicles'])) {
        $data['payload']['vehicles'] = $vehicles;
    } else {
        $data['vehicles'] = $vehicles;
    }
}

echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
