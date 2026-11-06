<?php

declare(strict_types=1);

namespace ClientFence\Mod\Clientfence;

/**
 * ClientFence: the enforced script policy's violation reports (report-uri /csp-report).
 * Kept as up to MAX kinds of violation in data/csp-reports.json, each with a count; System Health lists
 * those of the last 7 days. Only paths and origins are kept, never query strings.
 */
final class CspReports
{
    public const MAX = 100;

    public static function file(): string
    {
        return PATH_DATA . '/csp-reports.json';
    }

    /** Records one browser report (application/csp-report body); false when it is not one or is noise. */
    public static function record(string $body, ?string $file = null, ?int $now = null): bool
    {
        if (strlen($body) > 16384) {
            return false;
        }
        $data = json_decode($body, true);
        $report = is_array($data) ? ($data['csp-report'] ?? null) : null;
        if (!is_array($report)) {
            return false;
        }
        $blocked = (string) ($report['blocked-uri'] ?? '');
        $source = (string) ($report['source-file'] ?? '');
        // Browser extensions inject their own scripts; nothing the portal can fix.
        if (preg_match('#(chrome|moz|safari|safari-web|ms-browser)-extension:#i', $blocked . ' ' . $source)) {
            return false;
        }
        // Cloudflare injects its own scripts under /cdn-cgi/ (bot detection); the portal never serves that path.
        if (str_starts_with(self::path($blocked), '/cdn-cgi/') || str_starts_with(self::path($source), '/cdn-cgi/')) {
            return false;
        }
        $entry = [
            'page' => self::path((string) ($report['document-uri'] ?? '')),
            'directive' => mb_substr((string) ($report['effective-directive'] ?? $report['violated-directive'] ?? ''), 0, 60),
            'blocked' => self::origin($blocked),
            'source' => self::path($source),
            'line' => (int) ($report['line-number'] ?? 0),
        ];
        $key = sha1((string) json_encode($entry));
        $now ??= time();

        $handle = @fopen($file ?? self::file(), 'c+');
        if ($handle === false) {
            return false;
        }

        try {
            flock($handle, LOCK_EX);
            $all = json_decode((string) stream_get_contents($handle), true);
            $all = is_array($all) ? $all : [];
            if (!isset($all[$key]) && count($all) >= self::MAX) {
                uasort($all, static fn (array $a, array $b): int => $a['last'] <=> $b['last']);
                array_shift($all);
            }
            $all[$key] = $entry + ['count' => (int) ($all[$key]['count'] ?? 0) + 1, 'first' => (int) ($all[$key]['first'] ?? $now), 'last' => $now];
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($all, JSON_UNESCAPED_SLASHES));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return true;
    }

    /**
     * The kinds reported in the last $days days, most frequent first.
     *
     * @return list<array{page: string, directive: string, blocked: string, source: string, line: int, count: int, first: int, last: int}>
     */
    public static function recent(?string $file = null, ?int $now = null, int $days = 7): array
    {
        $all = is_file($file ?? self::file()) ? json_decode((string) file_get_contents($file ?? self::file()), true) : [];
        $since = ($now ?? time()) - $days * 86400;
        $recent = array_values(array_filter(is_array($all) ? $all : [], static fn ($entry): bool => is_array($entry) && (int) ($entry['last'] ?? 0) >= $since));
        usort($recent, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $recent;
    }

    private static function path(string $uri): string
    {
        return mb_substr((string) (parse_url($uri, PHP_URL_PATH) ?? ''), 0, 200);
    }

    // A keyword (inline, eval) as it is, a URL as its origin.
    private static function origin(string $uri): string
    {
        $host = parse_url($uri, PHP_URL_HOST);

        return mb_substr(is_string($host) ? parse_url($uri, PHP_URL_SCHEME) . '://' . $host : $uri, 0, 100);
    }
}
