<?php

declare(strict_types=1);

/**
 * ClientFence: the script policy's violation reports (CspReports, /csp-report).
 */

use ClientFence\Mod\Clientfence\CspReports;

function cspReportBody(array $report): string
{
    return (string) json_encode(['csp-report' => $report + [
        'document-uri' => 'https://billing.example/invoice/abc?token=secret',
        'effective-directive' => 'script-src-elem',
        'blocked-uri' => 'https://cdn.example/x.js?v=1',
        'source-file' => 'https://billing.example/invoice/abc',
        'line-number' => 12,
    ]]);
}

test('a report is kept as one kind with a count, without query strings', function (): void {
    $file = sys_get_temp_dir() . '/cf-csp-' . bin2hex(random_bytes(4)) . '.json';

    try {
        expect(CspReports::record(cspReportBody([]), $file, 1000))->toBeTrue()
            ->and(CspReports::record(cspReportBody([]), $file, 2000))->toBeTrue()
            ->and(CspReports::record(cspReportBody(['blocked-uri' => 'inline', 'line-number' => 3]), $file, 2000))->toBeTrue();

        $recent = CspReports::recent($file, 2000);
        expect($recent)->toHaveCount(2)
            ->and($recent[0])->toBe(['page' => '/invoice/abc', 'directive' => 'script-src-elem', 'blocked' => 'https://cdn.example', 'source' => '/invoice/abc', 'line' => 12, 'count' => 2, 'first' => 1000, 'last' => 2000])
            ->and($recent[1]['blocked'])->toBe('inline')
            // Only the last 7 days count.
            ->and(CspReports::recent($file, 2000 + 8 * 86400))->toBe([]);
    } finally {
        @unlink($file);
    }
});

test('noise is not kept, and the list stays bounded', function (): void {
    $file = sys_get_temp_dir() . '/cf-csp-' . bin2hex(random_bytes(4)) . '.json';

    try {
        expect(CspReports::record('not json', $file))->toBeFalse()
            ->and(CspReports::record('123', $file))->toBeFalse()
            ->and(CspReports::record(cspReportBody(['source-file' => 'chrome-extension://abc/content.js']), $file))->toBeFalse()
            ->and(CspReports::record(cspReportBody(['blocked-uri' => 'https://portal.example/cdn-cgi/challenge-platform/scripts/jsd/main.js']), $file))->toBeFalse()
            ->and(CspReports::record(str_repeat('x', 20000), $file))->toBeFalse();

        for ($i = 0; $i <= CspReports::MAX; ++$i) {
            CspReports::record(cspReportBody(['line-number' => $i]), $file, 1000 + $i);
        }
        $lines = array_column(CspReports::recent($file, 1000 + CspReports::MAX), 'line');
        // The oldest kind made room for the newest.
        expect($lines)->toHaveCount(CspReports::MAX)
            ->and($lines)->not->toContain(0)
            ->and($lines)->toContain(CspReports::MAX);
    } finally {
        @unlink($file);
    }
});
