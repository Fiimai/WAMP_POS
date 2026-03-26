<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Core\RateLimiter;

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$limit = RateLimiter::hit('api:translate:' . Auth::clientIp(), 120, 60);
if (!$limit['allowed']) {
    header('Retry-After: ' . $limit['retry_after']);
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many translation requests']);
    exit;
}

$payload = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($payload)) {
    $payload = [];
}

$source = strtolower(trim((string) ($payload['source'] ?? 'en')));
$target = strtolower(trim((string) ($payload['target'] ?? '')));
$texts = $payload['texts'] ?? [];

if (!is_array($texts) || $texts === []) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'No text payload provided']);
    exit;
}

if ($source === $target) {
    echo json_encode(['success' => true, 'translations' => $texts]);
    exit;
}

$apiKey = trim((string) (getenv('GHANANLP_API_KEY') ?: ''));
if ($apiKey === '') {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => 'Translation service is not configured. Set GHANANLP_API_KEY.',
    ]);
    exit;
}

/**
 * @return array<string, string>
 */
function fetchSupportedLanguages(string $apiKey): array
{
    $cacheDir = __DIR__ . '/../storage/cache';
    $cachePath = $cacheDir . '/ghananlp_languages.json';
    $cacheTtlSeconds = 21600;

    if (is_file($cachePath)) {
        $age = time() - (int) @filemtime($cachePath);
        if ($age >= 0 && $age < $cacheTtlSeconds) {
            $cached = json_decode((string) @file_get_contents($cachePath), true);
            if (is_array($cached['languages'] ?? null)) {
                return $cached['languages'];
            }
        }
    }

    $ch = curl_init('https://translation-api.ghananlp.org/v1/languages');
    if ($ch === false) {
        return [];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Ocp-Apim-Subscription-Key: ' . $apiKey,
            'x-api-key: ' . $apiKey,
        ],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
    ]);

    $raw = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($raw) || $raw === '' || $httpCode < 200 || $httpCode >= 300) {
        return [];
    }

    $decoded = json_decode($raw, true);
    $languages = is_array($decoded['languages'] ?? null) ? $decoded['languages'] : [];

    if ($languages !== []) {
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }
        @file_put_contents($cachePath, json_encode(['languages' => $languages], JSON_THROW_ON_ERROR));
    }

    return $languages;
}

$maxEntries = 120;
$maxLength = 240;
$filtered = [];

foreach ($texts as $key => $value) {
    if (count($filtered) >= $maxEntries) {
        break;
    }

    $itemKey = trim((string) $key);
    if ($itemKey === '') {
        continue;
    }

    $text = trim((string) $value);
    if ($text === '') {
        continue;
    }

    $filtered[$itemKey] = substr($text, 0, $maxLength);
}

if ($filtered === []) {
    echo json_encode(['success' => true, 'translations' => []]);
    exit;
}

$supportedLanguageMap = fetchSupportedLanguages($apiKey);
$allowedLanguages = array_keys($supportedLanguageMap);
if ($allowedLanguages === []) {
    $allowedLanguages = ['en', 'tw', 'ee', 'gaa', 'fat', 'dag', 'gur', 'kus'];
}
if (!in_array('en', $allowedLanguages, true)) {
    $allowedLanguages[] = 'en';
}

if (!in_array($source, $allowedLanguages, true) || !in_array($target, $allowedLanguages, true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Unsupported language code']);
    exit;
}

$endpoint = 'https://translation-api.ghananlp.org/v1/translate';
$pair = $source . '-' . $target;
$translations = [];
$failures = 0;

foreach ($filtered as $key => $text) {
    $ch = curl_init($endpoint);
    if ($ch === false) {
        $failures++;
        continue;
    }

    $body = json_encode([
        'in' => $text,
        'lang' => $pair,
    ]);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Ocp-Apim-Subscription-Key: ' . $apiKey,
            'x-api-key: ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
    ]);

    $raw = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $httpCode < 200 || $httpCode >= 300) {
        $failures++;
        continue;
    }

    $decoded = json_decode($raw, true);

    if (is_string($decoded) && trim($decoded) !== '') {
        $translations[$key] = trim($decoded);
        continue;
    }

    if (is_array($decoded)) {
        $candidate = (string) ($decoded['out'] ?? $decoded['translation'] ?? '');
        if ($candidate !== '') {
            $translations[$key] = $candidate;
            continue;
        }
    }

    $fallback = trim((string) $raw, " \t\n\r\0\x0B\"");
    if ($fallback !== '') {
        $translations[$key] = $fallback;
    } else {
        $failures++;
    }
}

echo json_encode([
    'success' => true,
    'translations' => $translations,
    'requested' => count($filtered),
    'translated' => count($translations),
    'failed' => $failures,
]);
