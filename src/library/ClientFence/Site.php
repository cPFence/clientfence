<?php

declare(strict_types=1);

namespace ClientFence;

use ClientFence\Http\ApiResponseFactory;
use ClientFence\Http\RequestFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ClientFence: the site the portal works for. The primary site is the portal URL in config.php (SYSTEM_URL). A
 * switched-on module can serve the portal on more hostnames: its manifest.json declares "sites": true and its Service
 * has site(string $host): ?\ClientFence\Site, which for the request's host (host[:port], lower case) answers:
 * - null: not its host (the next module by name is asked; a host no module serves gets the primary site);
 * - a Site on exactly that host: an http(s) base URL with a trailing slash and no query, `host` as asked, and its own
 *   identifier of the site as `key` (at most 50 characters); the core keeps it as "<module>/<identifier>", so two
 *   modules' sites never share a key (the 50 characters keep it short enough to store with a record);
 * - throws SiteUnavailableException: its host, but it cannot serve it now; that request gets a 503 page
 *   (unavailable()), never the primary site, so a visitor never sees another site in its place.
 * Any other exception or answer is logged and the next module is asked. While a switched-on site module cannot run on
 * this PHP (Module::cannotRun()), every host but the primary one gets the 503. The primary host and the console (cron, the
 * command line) never ask a module. site() runs while $di['site'] is being worked out, so it must not read it, nor
 * what is built on it ($di['url'], System\Service::getCompany()).
 *
 * $di['site'] is the request's site, worked out once at the start of each web request (load.php). Read it with:
 * - ofRequest(): the site the browser is on; what must match the browser (origin and Referer checks, passkeys,
 *   routing paths) uses it;
 * - current(): the site the portal is working for (links, addresses, assets, gateway return and notice URLs); today
 *   the request's site; a later core version can let it name another site while work is done for that site's record.
 */
final readonly class Site
{
    /**
     * @param string      $url  the base URL with a trailing slash: scheme, host[:port] and path, as SYSTEM_URL
     * @param string      $host host[:port] as the request shows it
     * @param string|null $key  null for the primary site, else "<module>/<the module's own identifier of the site>"
     */
    public function __construct(public string $url, public string $host, public ?string $key = null)
    {
    }

    public static function primary(): self
    {
        return new self(SYSTEM_URL, self::hostOf(SYSTEM_URL));
    }

    /** The request's site (the browser is on it), or the primary one in a container without it. */
    public static function ofRequest(?\Pimple\Container $di): self
    {
        $site = isset($di['site']) ? $di['site'] : null;

        return $site instanceof self ? $site : self::primary();
    }

    /** The site the portal is working for: today the request's site (ofRequest()). */
    public static function current(?\Pimple\Container $di): self
    {
        return self::ofRequest($di);
    }

    /**
     * The 503 answer for a web request whose host a module cannot serve now (SiteUnavailableException), or null. Run
     * first thing on every web request (load.php), so nothing is worked out on such a host before the answer.
     */
    public static function unavailable(\Pimple\Container $di, Request $request): ?Response
    {
        try {
            $site = self::ofRequest($di);
        } catch (SiteUnavailableException) {
            // The host is a module's, but it cannot serve it now.
            $site = null;
        }
        if ($site !== null) {
            return null;
        }
        $message = 'This site is not available right now. Please try again later.';
        // API calls get the API's own error format, as during maintenance.
        if (preg_match('#^/api(/|$)#', RequestFactory::getRoutePath($request)) === 1) {
            return (new ApiResponseFactory())->error(new SiteUnavailableException($message, 503));
        }

        // The update page's style (load.php): no link, name or asset of another site.
        return new Response('<!doctype html><html><head><meta charset="utf-8"><title>Not available</title></head>'
            . '<body style="font-family:sans-serif;text-align:center;padding:4rem 1rem;"><h1>Not available</h1><p>' . $message . '</p></body></html>', 503, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** scheme://host[:port], as the browser's Origin header names the site. */
    public function origin(): string
    {
        return strtolower((string) parse_url($this->url, PHP_URL_SCHEME)) . '://' . $this->host;
    }

    /**
     * The site of `$request` (null: no request, as in the console): the primary one unless a module serves its host.
     *
     * @throws SiteUnavailableException when the module that serves the host cannot serve it now
     */
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

        // A site module that cannot run here cannot say which hosts are its own: no host but the primary one gets a site
        // that may be another's.
        $providers = self::providers($di);
        foreach ($providers as $module) {
            if (($reason = $di['mod']($module)->cannotRun()) !== null) {
                $di['logger']->warning(sprintf('The %s module cannot answer for the host %s: %s', $module, $host, strtr(...$reason)));

                throw new SiteUnavailableException(strtr(...$reason));
            }
        }
        foreach ($providers as $module) {
            try {
                $service = $di['mod_service']($module);
            } catch (\Throwable $e) {
                // Only site() may say a host is unavailable: a module that cannot load is skipped, as before.
                $di['logger']->warning(sprintf('The %s module could not answer for the host %s: %s', $module, $host, $e->getMessage()));

                continue;
            }
            try {
                $site = $service->site($host);
                if ($site === null) {
                    continue;
                }
                if ($site instanceof self && self::serves($site, $host)) {
                    // The stored key names the module too, so two modules' sites never share one.
                    return new self($site->url, $site->host, $module . '/' . $site->key);
                }
                $problem = 'it answered with a site that is not on that host';
            } catch (SiteUnavailableException $e) {
                $di['logger']->warning(sprintf('The %s module cannot serve the host %s now: %s', $module, $host, $e->getMessage()));

                throw $e;
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
