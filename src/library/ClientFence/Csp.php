<?php

declare(strict_types=1);

namespace ClientFence;

/**
 * ClientFence: the script policy's nonce for this request (clientfence-security-headers.php sends the header).
 */
final class Csp
{
    public static function nonce(): string
    {
        if (!defined('CLIENTFENCE_CSP_NONCE')) {
            define('CLIENTFENCE_CSP_NONCE', rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '='));
        }

        return (string) CLIENTFENCE_CSP_NONCE;
    }

    /**
     * Adds the nonce to every script tag of trusted HTML (code-made gateway forms, the staff footer script).
     * Never use it on HTML that holds user input: it would let an injected script run.
     */
    public static function withNonce(string $html): string
    {
        // Only the opening tags: "<script" written inside a script's own code (a loader's string) is left as it is.
        return (string) preg_replace_callback(
            '#(<script\b)([^>]*>)(.*?)(</script\s*>|$)#is',
            static fn (array $m): string => (preg_match('/\bnonce=/i', $m[2]) === 1 ? $m[1] : $m[1] . ' nonce="' . self::nonce() . '"') . $m[2] . $m[3] . $m[4],
            $html,
        );
    }
}
