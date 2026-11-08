<?php

declare(strict_types=1);

/**
 * ClientFence: the EU VAT reverse charge, as WHMCS (VAT Number Validation, Auto Tax Exempt, Your Home Country) and
 * Blesta (EU VIES VAT) do it (owner decision 2026-11-08). With "Check EU VAT numbers" on (Invoice > Tax > Settings), a
 * VAT number saved for a client in an EU country is checked with the EU's VIES service, and a wrong new number is
 * refused. With "Make valid businesses outside my country tax exempt" on and a tax home country chosen, a valid
 * number outside that country makes the client tax exempt; a number in it, or one found invalid later, takes the
 * exemption away. When VIES does not answer, a new number is saved, VAT stays charged and the daily cron checks it
 * again. An invoice to such a business, with no tax on it, carries the reverse-charge note (reverseCharge()).
 */

namespace ClientFence\Mod\Invoice;

use ClientFence\InformationException;
use ClientFence\Mod\Client\Entity\Client;

final class EuVat
{
    /** The EU member states, as ISO 3166 codes (VIES calls Greece EL). */
    public const COUNTRIES = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE',
        'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK'];

    private const VIES = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/%s/vat/%s';

    /** Days after a client's last change in which the cron still checks a number VIES did not answer for. */
    private const RECHECK_DAYS = 7;

    /** @return array{check: bool, exempt: bool, home: string} */
    public static function settings(\Pimple\Container $di): array
    {
        $system = $di['mod_service']('system');
        $check = (bool) $system->getParamValue('tax_enabled', false) && (bool) $system->getParamValue('eu_vat_check', false);
        $home = strtoupper((string) $system->getParamValue('tax_home_country', ''));

        return ['check' => $check, 'exempt' => $check && $home !== '' && (bool) $system->getParamValue('eu_vat_exempt', false), 'home' => $home];
    }

    /**
     * Checks the client's VAT number after a save set it; $recheck when the form sent the number again unchanged
     * (WHMCS checks it on every profile save). Call before the client is flushed.
     */
    public static function apply(\Pimple\Container $di, Client $client, ?string $oldVat, ?string $oldCountry, bool $recheck): void
    {
        $country = strtoupper((string) $client->getCountry());
        if (!in_array($country, self::COUNTRIES, true)) {
            // A client leaving the EU leaves the exemption a VAT number gave; their country's tax rules apply.
            if (in_array(strtoupper((string) $oldCountry), self::COUNTRIES, true) && self::settings($di)['exempt']) {
                $client->setTaxExempt(false);
            }

            return;
        }
        $number = self::number((string) $client->getCompanyVat(), $country);
        $changed = $number !== self::number((string) $oldVat, $country) || $country !== strtoupper((string) $oldCountry);
        if (!$changed && !$recheck) {
            return;
        }
        $settings = self::settings($di);
        if (!$settings['check']) {
            return;
        }
        if ($number === '') {
            if ($changed && trim((string) $oldVat) !== '' && $settings['exempt']) {
                $client->setTaxExempt(false);
            }

            return;
        }

        $client->setCompanyVat(self::prefix($country) . $number);
        $valid = self::vies($di, $country, $number);
        if ($valid === false) {
            if ($changed) {
                throw new InformationException('The VAT number :number is not valid for the chosen country. Check it, or leave the field empty.', [':number' => self::prefix($country) . $number]);
            }
            if ($settings['exempt']) {
                $client->setTaxExempt(false);
            }
        } elseif ($valid === null) {
            // VIES did not answer: VAT stays charged until the cron finds the number valid (recheck()).
            if ($changed && $settings['exempt']) {
                $client->setTaxExempt(false);
            }
        } elseif ($settings['exempt']) {
            $client->setTaxExempt($country !== $settings['home']);
        }
    }

    /** Daily from cron: numbers VIES did not answer for, saved in the last days, are checked again. */
    public static function recheck(\Pimple\Container $di): void
    {
        $settings = self::settings($di);
        $system = $di['mod_service']('system');
        if (!$settings['exempt'] || $system->getParamValue('eu_vat_rechecked_on') === date('Y-m-d')) {
            return;
        }
        $system->setParamValue('eu_vat_rechecked_on', date('Y-m-d'));

        $countries = array_values(array_diff(self::COUNTRIES, [$settings['home']]));
        $rows = $di['dbal']->fetchAllAssociative(
            "SELECT id, country, company_vat FROM client WHERE (tax_exempt = 0 OR tax_exempt IS NULL) AND company_vat <> ''
                AND country IN (?) AND updated_at >= ? LIMIT 100",
            [$countries, date('Y-m-d H:i:s', time() - self::RECHECK_DAYS * 86400)],
            [\Doctrine\DBAL\ArrayParameterType::STRING]
        );
        foreach ($rows as $row) {
            $country = strtoupper((string) $row['country']);
            if (self::vies($di, $country, self::number((string) $row['company_vat'], $country)) === true) {
                $di['dbal']->executeStatement('UPDATE client SET tax_exempt = 1 WHERE id = ?', [$row['id']]);
                $di['logger']->info('Client #%s VAT number is valid: the client is now tax exempt', $row['id']);
            }
        }
    }

    /** Whether an invoice row (with its computed tax) gets the reverse-charge note. */
    public static function reverseCharge(\Pimple\Container $di, array $row, float $tax): bool
    {
        $settings = self::settings($di);
        $country = strtoupper((string) ($row['buyer_country'] ?? ''));

        return $settings['check'] && $settings['home'] !== '' && $tax == 0 && trim((string) ($row['buyer_company_vat'] ?? '')) !== ''
            && $country !== $settings['home'] && in_array($country, self::COUNTRIES, true);
    }

    /** True or false from VIES, null when it did not answer (down, busy, or the member state's service is). */
    private static function vies(\Pimple\Container $di, string $country, string $number): ?bool
    {
        try {
            $result = $di['http_client']->request('GET', sprintf(self::VIES, self::prefix($country), rawurlencode($number)), ['timeout' => 10])->toArray();
        } catch (\Throwable $e) {
            $di['logger']->warning('VIES did not answer for a VAT number in %s: %s', $country, $e->getMessage());

            return null;
        }

        return match ($result['userError'] ?? null) {
            'VALID' => true,
            'INVALID', 'INVALID_INPUT' => false,
            default => null,
        };
    }

    /** The number without spaces, punctuation or its country prefix. */
    private static function number(string $vat, string $country): string
    {
        $vat = (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($vat));

        // Greek numbers may be typed with the ISO code GR as well as VIES's EL.
        return str_starts_with($vat, self::prefix($country)) || str_starts_with($vat, $country) ? substr($vat, 2) : $vat;
    }

    private static function prefix(string $country): string
    {
        return $country === 'GR' ? 'EL' : $country;
    }
}
