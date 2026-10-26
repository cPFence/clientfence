<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence;

class ErrorPage
{
    /**
     * Returns the list of error codes and their specialized messages. All Error code parameters are optional.
     */
    private static function getCodes(): array
    {
        return [
            '1' => [
                'title' => 'Unable to find Composer Packages',
                'message' => 'The composer packages appear to be missing. This shouldn\'t happen if you are using a release version of ClientFence. If you are developer, you will need to install dependencies using <code>composer install</code>.',
                'link' => [
                    'label' => 'View more info on the composer website',
                    'href' => 'https://getcomposer.org/doc/01-basic-usage.md#installing-dependencies',
                ],
                'report' => false,
            ],
            '3' => [
                'title' => 'Your Configuration is Empty',
                'message' => 'Your ClientFence configuration seems to either be empty or non-existent. You may need to re-install ClientFence, or re-create the <code>config.php</code> file based on the example config.',
                'link' => [
                    'label' => 'See the example config.',
                    'href' => 'https://github.com/cPFence/clientfence/blob/main/src/config-sample.php',
                ],
                'report' => false,
            ],
            '5' => [
                'title' => 'Missing .htaccess file',
                'message' => 'You appear to be running an Apache or LiteSpeed based webserver without a valid <b><em>.htaccess</em></b> file. Please create one using the default ClientFence .htaccess file.',
                'link' => [
                    'label' => 'Check the default .htaccess',
                    'href' => 'https://github.com/cPFence/clientfence/blob/main/src/.htaccess',
                ],
                'report' => false,
            ],
            // Incomplete server manager configuration. Is listed here so it's not forwarded to Sentry.io
            2001 => [
                'report' => false,
            ],
            // Incomplete registrar configuration. Is listed here so it's not forwarded to Sentry.io
            3001 => [
                'report' => false,
            ],
            // Incomplete payment gateway configuration. Is listed here so it's not forwarded to Sentry.io
            4001 => [
                'report' => false,
            ],
            // Unavailable/misconfigured cache backend (e.g. a missing PHP extension, or an
            // unreachable Redis/Memcached server). Surfaced to the admin directly when testing a
            // cache driver before saving it, so it's listed here to keep it out of Sentry.io.
            5001 => [
                'report' => false,
            ],
            // The Twig template cache directory (data/cache) couldn't be created or written
            // to. This is a host file permission issue we can't fix from within the app, so
            // it's listed here to keep it out of Sentry.io.
            5002 => [
                'report' => false,
            ],
        ];
    }

    /* List of code categories. The "start" and "end" values are considered valid for a category.
     * (Example: an error code of 50 will match the "FOSSBilling Loader" category)
     */
    private static array $codeCategories = [
        'ClientFence Loader' => [
            'start' => 1,
            'end' => 50,
        ],
        'HTTP Error Codes' => [
            'start' => 400,
            'end' => 599,
        ],
        'Server Managers' => [
            'start' => 2000,
            'end' => 2999,
        ],
        'Domain Registration' => [
            'start' => 3000,
            'end' => 3999,
        ],
        'Payment Gateway' => [
            'start' => 4000,
            'end' => 4999,
        ],
        'Cache' => [
            'start' => 5000,
            'end' => 5999,
        ],
    ];

    /**
     * Gets info for a specified error code, using placeholders for anything undefined.
     *
     * @param int $code The error code
     */
    public static function getCodeInfo(int|string $code): array
    {
        $code = intval($code);
        $errorDetails = [
            'title' => 'An error has occurred.',
            'link' => [
                'label' => 'View the ClientFence documentation',
                'href' => 'https://github.com/cPFence/clientfence#readme',
            ],
            'category' => 'None',
            'report' => true,
        ];

        $codes = self::getCodes();

        if (key_exists($code, $codes)) {
            $codeInfo = $codes[$code];
            $errorDetails = array_merge($errorDetails, $codeInfo);
        }

        $errorDetails['category'] = 'Generic';
        foreach (self::$codeCategories as $categoryName => $categoryRange) {
            if ($code >= $categoryRange['start'] && $code <= $categoryRange['end']) {
                $errorDetails['category'] = $categoryName;

                break;
            }
        }

        return $errorDetails;
    }

    /**
     * @param int    $code    Error code
     * @param string $message The original exception message
     */
    public function renderPage(int $code, string $message): string
    {
        $error = static::getCodeInfo($code);
        $error['message'] ??= "You've received a generic error message: <code>$message</code>";
        if (defined('INSTANCE_ID')) {
            $instanceID = INSTANCE_ID;
        } else {
            $instanceID = 'Unknown';
        }
        $home = htmlspecialchars(defined('SYSTEM_URL') ? SYSTEM_URL : '/');

        return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>' . $error['title'] . ' · ClientFence</title>
    <style>
        :root {
            color-scheme: light dark;
            --bg: #f4f6fa;
            --card: #ffffff;
            --border: #e3e7ee;
            --text: #1b2433;
            --muted: #66738a;
            --chip: #eef1f6;
            --code: #f1f3f8;
            --accent: #1f6feb;
            --accent-text: #ffffff;
            --danger: #d1435b;
            --shadow: 0 10px 30px rgba(17, 24, 39, .08);
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0b111b;
                --card: #121a27;
                --border: #233046;
                --text: #e6ebf3;
                --muted: #95a2b8;
                --chip: #1a2434;
                --code: #0e1521;
                --accent: #3b82f6;
                --danger: #f0627a;
                --shadow: 0 10px 30px rgba(0, 0, 0, .35);
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px 16px;
            background: var(--bg);
            color: var(--text);
            font: 15px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
        .error-card {
            width: 100%;
            max-width: 640px;
            padding: 32px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: var(--shadow);
        }
        .error-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 44px;
            height: 44px;
            margin-bottom: 16px;
            border-radius: 12px;
            background: color-mix(in srgb, var(--danger) 12%, transparent);
            color: var(--danger);
        }
        .error-title {
            margin: 0 0 12px;
            font-size: 1.6rem;
            font-weight: 700;
            line-height: 1.25;
            letter-spacing: -.01em;
        }
        .error-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 0 0 20px;
            padding: 0;
            list-style: none;
        }
        .error-meta li {
            padding: 3px 10px;
            border-radius: 999px;
            background: var(--chip);
            color: var(--muted);
            font-size: .8rem;
            overflow-wrap: anywhere;
        }
        .error-message {
            margin: 0 0 24px;
            color: var(--muted);
            overflow-wrap: anywhere;
        }
        .error-message code {
            padding: 2px 6px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--code);
            color: var(--text);
            font: .85em/1.5 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        }
        .error-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .button {
            display: inline-flex;
            align-items: center;
            padding: 9px 16px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: transparent;
            color: var(--text);
            font: inherit;
            font-size: .9rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }
        .button:hover { background: var(--chip); }
        .button-primary {
            border-color: var(--accent);
            background: var(--accent);
            color: var(--accent-text);
        }
        .button-primary:hover { background: var(--accent); filter: brightness(1.08); }
        .error-footer {
            margin-top: 28px;
            padding-top: 16px;
            border-top: 1px solid var(--border);
            color: var(--muted);
            font-size: .8rem;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 8px;
        }
        .error-footer a { color: var(--muted); }
        .error-footer a:hover { color: var(--text); }
        .error-footer nav { display: flex; gap: 14px; }
        @media (max-width: 480px) {
            .error-card { padding: 24px 20px; }
        }
    </style>
</head>
<body>
    <main class="error-card">
        <div class="error-badge" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M10.4 3.9 2.6 17.5A1.9 1.9 0 0 0 4.2 20.3h15.6a1.9 1.9 0 0 0 1.6-2.8L13.6 3.9a1.9 1.9 0 0 0-3.2 0z"/><path d="M12 16h.01"/></svg>
        </div>
        <h1 class="error-title">' . $error['title'] . '</h1>
        <ul class="error-meta">
            <li>' . "Error code #$code" . '</li>
            <li>' . $error['category'] . '</li>
            <li>' . "Instance $instanceID" . '</li>
        </ul>

        <p class="error-message" id="specialized">' . $error['message'] . '</p>
        <p class="error-message" id="original" style="display: none;">' . $message . '</p>

        <div class="error-actions">
            <a class="button button-primary" href="' . $home . '">Go to the home page</a>
            <button id="toggle" class="button" type="button">Show original message</button>
            <a class="button" target="_blank" rel="noopener" href="' . $error['link']['href'] . '">' . $error['link']['label'] . '</a>
        </div>

        <footer class="error-footer">
            <span>Powered by ClientFence</span>
            <nav>
                <a href="https://github.com/cPFence/clientfence">Source code</a>
                <a href="https://github.com/cPFence/clientfence/issues">Bug reports</a>
            </nav>
        </footer>
    </main>
    <script nonce="' . \ClientFence\Csp::nonce() . '">
        function toggle() {
            var og = document.getElementById("original");
            var specialized = document.getElementById("specialized");

            if (og.style.display === "none") {
                og.style.display = "block";
                specialized.style.display = "none";
                document.querySelector("#toggle").innerHTML = "Show specialized message";
            } else {
                og.style.display = "none";
                specialized.style.display = "block";
                document.querySelector("#toggle").innerHTML = "Show original message";
            }
        }
        document.getElementById("toggle")?.addEventListener("click", toggle);
    </script>
</body>
</html>';
    }
}
