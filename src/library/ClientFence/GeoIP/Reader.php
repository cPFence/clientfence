<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 *
 * ClientFence: a downloaded database replaces the old one only after checkDatabase() accepts it
 * (the staff country rule in Clientfence/SignInSecurity.php depends on the country database); a
 * failed download is tried again a day later. tools/update-geoip.sh refreshes the bundled copies.
 */

namespace ClientFence\GeoIP;

use ClientFence\Config;
use MaxMind\Db\Reader as MaxMindReader;
use MaxMind\Db\Reader\InvalidDatabaseException;
use Pimple\Container;
use PrinsFrank\Standards\Language\LanguageAlpha2;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

class Reader
{
    /** The database files are a few MB; a smaller download is an error page or cut short. */
    private const int MIN_DATABASE_BYTES = 1_000_000;
    private const int MIN_DATABASE_NODES = 100_000;
    /** After a failed download, the next try is a day later (instead of at every cron run). */
    private const int RETRY_SECONDS = 86400;

    protected ?Container $di = null;

    private readonly MaxMindReader $reader;
    private readonly LanguageAlpha2 $language;

    public function setDi(Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Container
    {
        return $this->di;
    }

    /**
     * Returns the path to the system's `country` database.
     */
    public static function getCountryDatabase(): string
    {
        return Path::join(PATH_LIBRARY, 'ClientFence', 'GeoIP', 'Databases', 'CC0-Country.mmdb');
    }

    /**
     * Returns the path to the system's `asn` database.
     */
    public static function getAsnDatabase(): string
    {
        return Path::join(PATH_LIBRARY, 'ClientFence', 'GeoIP', 'Databases', 'PDDL-ASN.mmdb');
    }

    /**
     * Handles updating the built-in, default databases.
     * The databases will only be updated if the files do not exist, or if they are over 7 days old.
     * This will only update 1 database per call as it's intended to be run in the background and have the work spread out VS all at once.
     *
     * @throws IOException
     */
    public function updateDefaultDatabases(): bool
    {
        $databases = [
            self::getCountryDatabase() => ['country', 'https://github.com/HostByBelle/IP-Geolocation-DB/releases/latest/download/cc0-both-country.mmdb'],
            self::getAsnDatabase() => ['asn', 'https://github.com/HostByBelle/IP-Geolocation-DB/releases/latest/download/pddl-asn-both.mmdb'],
        ];

        foreach ($databases as $path => [$kind, $url]) {
            if (self::shouldUpdate($path)) {
                try {
                    $this->downloadDb($path, $url, $kind);

                    return true;
                } catch (\Exception $e) {
                    error_log("There was an error while updating the IP address database: {$e->getMessage()}.");
                }
            }
        }

        return false;
    }

    /**
     * Constructs a new GeoIP reader instance.
     *
     * @param string|null $database (Optional) a path to the database to load> Will default to the system's country database
     * @param string|null $locale   (Optional) the locale to use for country names. Defaults to the configured default locale.
     *
     * @throws \InvalidArgumentException for invalid database path or unknown arguments
     * @throws InvalidDatabaseException  if the database is invalid or there is an error reading from it
     */
    public function __construct(?string $database = null, ?string $locale = null)
    {
        $database ??= self::getCountryDatabase();

        $this->reader = new MaxMindReader($database);

        $locale ??= Config::getProperty('i18n.locale', 'en_US');

        $original = $locale;
        if (str_contains((string) $locale, '_')) {
            $locale = explode('_', (string) $locale)[0];
        }
        $this->language = strlen((string) $locale) === 2 ? LanguageAlpha2::from($locale) : throw new \ValueError("No matching Alpha2 language found for {$original}");
    }

    /**
     * Retrieves the record for the IP address.
     *
     * @param string $ipAddress the IP address to look up
     *
     * @return array the record for the IP address
     *
     * @throws \InvalidArgumentException if something other than a single IP address is passed to the method
     * @throws InvalidDatabaseException  if the database is invalid or there is an error reading from it
     */
    public function get(string $ipAddress)
    {
        return $this->reader->get($ipAddress) ?? [];
    }

    /**
     * Retrieves the country for an IP address.
     *
     * @param string $ipAddress the IP address to look up
     *
     * @return Country A country object for the associated record
     *
     * @throws IncompleteRecord          if the record for the given IP address does not contain the needed information
     * @throws \InvalidArgumentException if something other than a single IP address is passed to the method
     * @throws InvalidDatabaseException  if the database is invalid or there is an error reading from it
     */
    public function country(string $ipAddress): Country
    {
        $record = $this->get($ipAddress);

        return new Country($record['country'] ?? [], $this->language);
    }

    /**
     * Returns the ASN information for an IP address.
     *
     * @param string $ipAddress the IP address to look up
     *
     * @return ASN the ASN object for the associated record
     *
     * @throws IncompleteRecord          if the record for the given IP address does not contain the needed information
     * @throws \InvalidArgumentException if something other than a single IP address is passed to the method
     * @throws InvalidDatabaseException  if the database is invalid or there is an error reading from it
     */
    public function asn(string $ipAddress): ASN
    {
        $record = $this->get($ipAddress);

        return new ASN($record);
    }

    /**
     * Checks if a database should be updated.
     *
     * @param string $path   The path to the database
     * @param int    $maxAge The maximum age in seconds. The default is 7 days.
     *
     * @throws IOException
     */
    private static function shouldUpdate(string $path, int $maxAge = 604800): bool
    {
        $filesystem = new Filesystem();
        if (!$filesystem->exists($path)) {
            return true;
        }

        $dbAge = time() - filemtime($path);

        return $dbAge >= $maxAge;
    }

    /**
     * Downloads a database and puts it in place of the old one once checkDatabase() accepts it.
     *
     * @throws \Exception when the download fails or is not a usable database (the old file stays)
     */
    public function downloadDb(string $path, string $url, string $kind): void
    {
        // ClientFence core patch: a downloaded GeoIP database is used only after checkDatabase() accepts it.
        $filesystem = new Filesystem();
        $download = $path . '.download';
        try {
            $response = $this->di['http_client']->request('GET', $url);
            if ($response->getStatusCode() !== 200) {
                throw new \Exception("Got a {$response->getStatusCode()} status code when attempting to download {$url}.");
            }
            $filesystem->dumpFile($download, $response->getContent());
            self::checkDatabase($download, $kind);
            $filesystem->rename($download, $path, true);
        } catch (\Throwable $e) {
            @unlink($download);
            // Keep the old database and try again in a day.
            if ($filesystem->exists($path)) {
                @touch($path, time() - 604800 + self::RETRY_SECONDS);
            }

            throw new \Exception("The downloaded {$kind} database was not used: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Checks that a file is a usable database of a kind ('country' or 'asn'): it opens, is of a
     * normal size, and finds well-known public addresses (Google's DNS, IPv4 and IPv6).
     *
     * @throws \RuntimeException when it is not
     */
    public static function checkDatabase(string $file, string $kind): void
    {
        if (!in_array($kind, ['country', 'asn'], true)) {
            throw new \InvalidArgumentException("Unknown database kind: {$kind}");
        }
        clearstatcache(true, $file);
        $size = @filesize($file);
        if ($size === false || $size < self::MIN_DATABASE_BYTES) {
            throw new \RuntimeException('The database file is missing or too small (' . (int) $size . ' bytes).');
        }

        $reader = null;
        try {
            $reader = new MaxMindReader($file);
            $metadata = $reader->metadata();
            if ($metadata->nodeCount < self::MIN_DATABASE_NODES || $metadata->ipVersion !== 6) {
                throw new \RuntimeException("The database is incomplete ({$metadata->nodeCount} nodes, IPv{$metadata->ipVersion}).");
            }
            foreach (['8.8.8.8', '2001:4860:4860::8888'] as $ip) {
                $record = $reader->get($ip);
                $ok = $kind === 'country'
                    ? is_string($record['country']['iso_code'] ?? null) && preg_match('/\A[A-Z]{2}\z/', $record['country']['iso_code']) === 1
                    : is_int($record['autonomous_system_number'] ?? null) && $record['autonomous_system_number'] > 0;
                if (!$ok) {
                    throw new \RuntimeException("The database has no {$kind} for {$ip}.");
                }
            }
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException('The database cannot be read: ' . $e->getMessage(), 0, $e);
        } finally {
            $reader?->close();
        }
    }
}
