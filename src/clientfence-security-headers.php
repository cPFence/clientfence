<?php

/**
 * Security headers for every PHP response of the portal.
 *
 * OpenLiteSpeed ignores Header directives in .htaccess and some PHP setups ignore .user.ini,
 * so load.php requires this file (a core change; the .htaccess answers 404 to any direct
 * request for it).
 *
 * Scripts: every script tag carries this request's nonce (\ClientFence\Csp). The script policy is
 * enforced: blocked scripts are reported to /csp-report and System Health counts them.
 */
if (!defined('CLIENTFENCE_CSP_NONCE')) {
    define('CLIENTFENCE_CSP_NONCE', rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '='));
}

/**
 * The framing and script policy headers. Only this site may frame a page, unless $origins lists other
 * websites (Embed Parts' /embed/ pages): then X-Frame-Options is left out, as it cannot list them.
 *
 * @param list<string> $origins
 *
 * @return list<string>
 */
function clientfence_frame_headers(array $origins = []): array
{
    // 'strict-dynamic' lets a trusted script load others; https: and 'unsafe-inline' only serve browsers without CSP 3.
    $cspReport = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/') . '/csp-report';
    $csp = 'Content-Security-Policy: frame-ancestors ' . implode(' ', ["'self'", ...$origins]) . "; script-src 'nonce-" . CLIENTFENCE_CSP_NONCE . "' 'strict-dynamic' https: 'unsafe-inline'; object-src 'none'; base-uri 'self'; report-uri " . $cspReport;

    return $origins === [] ? ['X-Frame-Options: SAMEORIGIN', $csp] : [$csp];
}

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header_remove('X-Powered-By');
    header('Strict-Transport-Security: max-age=31536000');
    header('X-Content-Type-Options: nosniff');
    foreach (clientfence_frame_headers() as $line) {
        header($line);
    }
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}
