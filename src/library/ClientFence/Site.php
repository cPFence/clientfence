<?php

declare(strict_types=1);

namespace ClientFence;

use Symfony\Component\HttpFoundation\Request;

/**
 * ClientFence: the site a web request is for. The primary site is the portal URL in config.php (SYSTEM_URL). A
 * switched-on module can serve the portal on more hostnames: its manifest.json declares "sites": true and its
 * Service has site(string $host): ?\ClientFence\Site, which answers for a host it serves and null for any other.
 * $di['site'] is the request's site: the links, redirects, assets and origin checks of the request follow it. The
 * console, and a host no module serves, get the primary site. site() runs while $di['site'] is being worked out, so it
 * must not read it, nor what is built on it ($di['url'], System\Service::getCompany()).
 */
final readonly class Site
{
    /**
     * @param string      $url  the base URL with a trailing slash: scheme, host[:port] and path, as SYSTEM_URL
     * @param string      $host host[:port] as the request shows it
     * @param string|null $key  null for the primary site, else "<module>/<the module's own identifier of the site>"
     *                          (a module answers with its identifier, at most 50 characters; the core stores the key,
     *                          for example with each passkey)
     */
    public function __construct(public string $url, public string $host, public ?string $key = null)
    {
    }

    public static function primary(): self
    {
        return new self(SYSTEM_URL, self::hostOf(SYSTEM_URL));
    }

    /** The request's site, or the primary one in a container without it. */
    public static function current(?\Pimple\Container $di): self
    {
        $site = isset($di['site']) ? $di['site'] : null;

        return $site instanceof self ? $site : self::primary();
    }

    /** scheme://host[:port], as the browser's Origin header names the site. */
    public function origin(): string
    {
        return strtolower((string) parse_url($this->url, PHP_URL_SCHEME)) . '://' . $this->host;
    }

    /** The site of `$request` (null: no request, as in the console): the primary one unless a module serves its host. */
    public static function resolve(\Pimple\Container $di, ?Request $request): self
    {
        $primary = self::primary();
        try {
            $host = $request === null ? '' : strtolower($request->getHttpHost());
        } catch (\UnexpectedValueException) {
            // A malformed Host header gets the primary site, as before.
            return $primary;
        }
        if ($host === '' || $host === $primary->host) {
            return $primary;
        }

        foreach (self::providers($di) as $module) {
            try {
                $site = $di['mod_service']($module)->site($host);
                if ($site === null) {
                    continue;
                }
                if ($site instanceof self && self::serves($site, $host)) {
                    // The stored key names the module too, so two modules' sites never share one.
                    return new self($site->url, $site->host, $module . '/' . $site->key);
                }
                $problem = 'it answered with a site that is not on that host';
            } catch (\Throwable $e) {
                $problem = $e->getMessage();
            }
            $di['logger']->warning(sprintf('The %s module could not answer for the host %s: %s', $module, $host, $problem));
        }

        return $primary;
    }

    /** @return list<string> the switched-on modules (sorted by name) whose manifest declares "sites": true */
    private static function providers(\Pimple\Container $di): array
    {
        try {
            $modules = $di['mod_service']('extension')->getCoreAndActiveModules();
        } catch (\Throwable) {
            // Without the module list the request keeps the primary site, as before.
            return [];
        }

        return array_values(array_filter(
            $modules,
            static function (string $name) use ($di): bool {
                // A broken manifest leaves out only that module, as in the settings search (SettingsIndex).
                try {
                    $mod = $di['mod']($name);

                    return $mod->hasManifest() && ($mod->getManifest()['sites'] ?? null) === true;
                } catch (\Throwable) {
                    return false;
                }
            },
        ));
    }

    /** Whether `$site` is an http(s) site on `$host`, with a base URL and its own identifier. */
    private static function serves(self $site, string $host): bool
    {
        $parts = parse_url($site->url);

        return is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && !isset($parts['query']) && !isset($parts['fragment'])
            && str_ends_with($site->url, '/') && $site->host === $host && $site->key !== null && $site->key !== '' && mb_strlen($site->key) <= 50
            // The exact authority, as a browser reads it: no user info, backslash or default port in front of the host.
            && str_starts_with(strtolower($site->url), strtolower($parts['scheme']) . '://' . $host . '/');
    }

    /** host[:port] of `$url` in lower case, without the scheme's default port (as Request::getHttpHost() shows it). */
    private static function hostOf(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower(is_array($parts) ? ($parts['scheme'] ?? '') : '');
        $port = is_array($parts) ? ($parts['port'] ?? null) : null;
        $default = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);

        return strtolower((is_array($parts) ? ($parts['host'] ?? '') : '') . ($port === null || $default ? '' : ':' . $port));
    }
}
