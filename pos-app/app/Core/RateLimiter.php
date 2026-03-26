<?php

declare(strict_types=1);

namespace App\Core;

final class RateLimiter
{
    private const STORAGE_DIR = __DIR__ . '/../../storage/cache/rate_limit';

    /**
     * @return array{allowed: bool, retry_after: int, remaining: int}
     */
    public static function hit(string $bucket, int $maxAttempts, int $windowSeconds): array
    {
        if ($maxAttempts < 1 || $windowSeconds < 1) {
            return ['allowed' => true, 'retry_after' => 0, 'remaining' => $maxAttempts];
        }

        if (!is_dir(self::STORAGE_DIR)) {
            mkdir(self::STORAGE_DIR, 0775, true);
        }

        $safeBucket = preg_replace('/[^a-zA-Z0-9:_\-.]/', '_', $bucket) ?? 'default';
        $path = self::STORAGE_DIR . '/' . sha1($safeBucket) . '.json';

        $fp = fopen($path, 'c+');
        if ($fp === false) {
            return ['allowed' => true, 'retry_after' => 0, 'remaining' => $maxAttempts];
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                fclose($fp);
                return ['allowed' => true, 'retry_after' => 0, 'remaining' => $maxAttempts];
            }

            $raw = stream_get_contents($fp);
            $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
            $hits = is_array($decoded['hits'] ?? null) ? $decoded['hits'] : [];

            $now = time();
            $cutoff = $now - $windowSeconds;
            $hits = array_values(array_filter($hits, static fn ($ts): bool => is_int($ts) && $ts > $cutoff));

            $allowed = count($hits) < $maxAttempts;
            $retryAfter = 0;

            if ($allowed) {
                $hits[] = $now;
            } elseif ($hits !== []) {
                $oldest = (int) min($hits);
                $retryAfter = max(1, $windowSeconds - ($now - $oldest));
            }

            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, json_encode(['hits' => $hits], JSON_THROW_ON_ERROR));
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);

            return [
                'allowed' => $allowed,
                'retry_after' => $retryAfter,
                'remaining' => max(0, $maxAttempts - count($hits)),
            ];
        } catch (\Throwable $throwable) {
            if (is_resource($fp)) {
                flock($fp, LOCK_UN);
                fclose($fp);
            }

            return ['allowed' => true, 'retry_after' => 0, 'remaining' => $maxAttempts];
        }
    }

    public static function clear(string $bucket): void
    {
        $safeBucket = preg_replace('/[^a-zA-Z0-9:_\-.]/', '_', $bucket) ?? 'default';
        $path = self::STORAGE_DIR . '/' . sha1($safeBucket) . '.json';

        if (is_file($path)) {
            @unlink($path);
        }
    }
}

