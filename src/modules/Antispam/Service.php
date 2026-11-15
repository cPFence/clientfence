<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Mod\Antispam;

use EmailChecker\Adapter;
use EmailChecker\Utilities;
use ClientFence\InjectionAwareInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\Cache\ItemInterface;

class Service implements InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;
    private Filesystem $filesystem;

    public function __construct()
    {
        $this->filesystem = new Filesystem();
    }

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
        if (isset($di['filesystem'])) {
            $this->filesystem = $di['filesystem'];
        }
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function getModulePermissions(): array
    {
        return [
            'view' => [
                'type' => 'bool',
                'display_name' => __trans('View antispam settings'),
                'description' => __trans('Allows the staff member to view antispam configuration and blocked IPs.'),
            ],
            'manage' => [
                'type' => 'bool',
                'display_name' => __trans('Manage antispam settings'),
                'description' => __trans('Allows the staff member to block and unblock IP addresses.'),
            ],
            'manage_settings' => [],
        ];
    }

    public static function onBeforeClientSignUp(\ClientFence\Event $event): void
    {
        // Client\Api\Guest::create() already verifies the CAPTCHA before firing
        // this event, so isSpam() below must not check it again: CAPTCHA
        // tokens are single-use, and a second siteverify call would fail and
        // reject every genuine signup.
        $di = $event->getDi();
        $antispamService = $di['mod_service']('Antispam');
        $antispamService->isBlockedIp($event);
        // ClientFence core patch: banned emails and phones (CF-117, Banned.php) and hosting networks (CF-119, Networks.php), before the outside check.
        $antispamService->isBanned($event);
        $antispamService->isHostingNetwork($event);
        $antispamService->isSpam($event);
        $antispamService->isTemp($event);
        $antispamService->checkHoneypot($event);
    }

    public static function onBeforeClientOpenTicket(\ClientFence\Event $event): void
    {
        if ($event->getParameters()['author_role'] !== 'guest') {
            return;
        }

        $di = $event->getDi();
        $antispamService = $di['mod_service']('Antispam');
        $antispamService->isBlockedIp($event);
        // ClientFence core patch: imported email cannot answer a browser CAPTCHA.
        if (!\ClientFence\Mod\Support\MailImport::isCreatingGuestTicket()) {
            $antispamService->checkCaptcha($event->getParameters());
        }
        $antispamService->isSpam($event);
        $antispamService->isTemp($event);
    }

    public static function onBeforeClientProfileUpdate(\ClientFence\Event $event): void
    {
        $di = $event->getDi();
        $antispamService = $di['mod_service']('Antispam');
        $antispamService->isBlockedIp($event);
        // ClientFence core patch: a client's new email or phone may not be on the banned lists (CF-117).
        $antispamService->isBanned($event);
    }

    // ClientFence core patch: checkout checks the blocked IPs, the client's email and phone (CF-117) and hosting networks (CF-119).
    public static function onBeforeClientCheckout(\ClientFence\Event $event): void
    {
        $di = $event->getDi();
        $antispamService = $di['mod_service']('Antispam');
        $antispamService->isBlockedIp($event);
        $antispamService->isBanned($event);
        $antispamService->isHostingNetwork($event);
    }

    public static function onBeforeAdminClientUpdate(\ClientFence\Event $event): void
    {
        $di = $event->getDi();
        $antispamService = $di['mod_service']('Antispam');
        $antispamService->isBlockedIp($event);
    }

    public static function onBeforeClientUpdate(\ClientFence\Event $event): void
    {
        $di = $event->getDi();
        $antispamService = $di['mod_service']('Antispam');
        $antispamService->isBlockedIp($event);
    }

    public static function onBeforeClientLogin(\ClientFence\Event $event): void
    {
        $di = $event->getDi();
        $antispamService = $di['mod_service']('Antispam');
        $antispamService->isBlockedIp($event);
    }

    public static function onBeforeAdminLogin(\ClientFence\Event $event): void
    {
        $di = $event->getDi();
        $antispamService = $di['mod_service']('Antispam');
        $antispamService->isBlockedIp($event);
    }

    public function isBlockedIp(\ClientFence\Event $event): void
    {
        $di = $event->getDi();
        $config = $di['mod_config']('Antispam');
        if (isset($config['block_ips']) && $config['block_ips'] && isset($config['blocked_ips'])) {
            $blocked_ips = explode(PHP_EOL, $config['blocked_ips']);
            // ClientFence core patch: ranges (203.0.113.0/24, 2001:db8::/32) and wildcards (203.0.113.*), as WHMCS.
            $blocked_ips = array_map(self::ipRange(...), array_filter(array_map(trim(...), $blocked_ips), static fn (string $ip): bool => $ip !== ''));
            if (IpUtils::checkIp((string) $di['request']->getClientIp(), $blocked_ips)) {
                throw new \ClientFence\InformationException('Your IP address (:ip) is blocked. Please contact our support to lift your block.', [':ip' => $di['request']->getClientIp()], 403);
            }
        }
    }

    /** ClientFence: a wildcard address (203.0.113.*, 198.51.*.*) as its range; anything else as it is. */
    private static function ipRange(string $entry): string
    {
        if (preg_match('/^((?:\d{1,3}\.){1,3})\*(?:\.\*)*$/', $entry, $m) !== 1) {
            return $entry;
        }
        $known = substr_count($m[1], '.');

        return $m[1] . implode('.', array_fill(0, 4 - $known, '0')) . '/' . ($known * 8);
    }

    /**
     * ClientFence: CF-117 (Banned.php): a sign-up's own email and phone, a client's changed ones, or at checkout
     * the account's own. A sign-up's parameters come from the visitor, so only the other two read a client id.
     */
    public function isBanned(\ClientFence\Event $event): void
    {
        $params = $event->getParameters();
        if ($event->getName() === 'onBeforeClientCheckout') {
            Banned::refuse($event->getDi(), null, (int) $params['client_id']);
        } else {
            Banned::refuse($event->getDi(), $params, $event->getName() === 'onBeforeClientProfileUpdate' ? (int) $params['id'] : null);
        }
    }

    /** ClientFence: CF-119 Block (Networks.php) at sign-up and checkout; a client staff allowed passes at checkout. */
    public function isHostingNetwork(\ClientFence\Event $event): void
    {
        $di = $event->getDi();
        $clientId = $event->getName() === 'onBeforeClientCheckout' ? (int) $event->getParameters()['client_id'] : null;
        Networks::refuse($di, (string) $di['request']->getClientIp(), $clientId);
    }

    public function isSpam(\ClientFence\Event $event): void
    {
        $di = $event->getDi();
        $params = $event->getParameters();

        $data = [
            'ip' => $params['ip'] ?? null,
            'email' => $params['email'] ?? null,
        ];

        $config = $di['mod_config']('Antispam');

        if (isset($config['sfs']) && $config['sfs']) {
            $antispamService = $di['mod_service']('Antispam');
            $antispamService->isInStopForumSpamDatabase($data);
        }
    }

    public function checkCaptcha(array $params): void
    {
        $di = $this->di;
        $config = $di['mod_config']('Antispam');

        if (isset($config['captcha_enabled']) && $config['captcha_enabled']) {
            $provider = $config['captcha_provider'] ?? 'recaptcha_v2';

            if ($provider === 'recaptcha_v2' || $provider === 'recaptcha_v3') {
                if (!isset($config['captcha_recaptcha_privatekey']) || $config['captcha_recaptcha_privatekey'] == '') {
                    throw new \ClientFence\InformationException("To use reCAPTCHA you must get an API key from <a href='https://www.google.com/recaptcha/admin/create'>here</a>");
                }

                if (!isset($params['g-recaptcha-response']) || $params['g-recaptcha-response'] == '') {
                    throw new \ClientFence\InformationException('You have to complete the CAPTCHA to continue');
                }

                $httpClient = $di['http_client'];
                $response = $httpClient->request('POST', 'https://google.com/recaptcha/api/siteverify', [
                    'body' => [
                        'secret' => $config['captcha_recaptcha_privatekey'],
                        'response' => $params['g-recaptcha-response'],
                        'remoteip' => $di['request']->getClientIp(),
                    ],
                ]);
                $content = $response->toArray();

                if (!isset($content['success']) || $content['success'] !== true) {
                    throw new \ClientFence\InformationException('reCAPTCHA verification failed.');
                }

                if ($provider === 'recaptcha_v3') {
                    $threshold = (float) ($config['captcha_recaptcha_v3_threshold'] ?? 0.5);
                    if (!is_finite($threshold)) {
                        $threshold = 0.5;
                    }
                    $threshold = min(1.0, max(0.0, $threshold));
                    $score = isset($content['score']) ? (float) $content['score'] : 0.0;

                    if ($score < $threshold) {
                        throw new \ClientFence\InformationException('reCAPTCHA verification failed.');
                    }

                    $expectedAction = 'clientfence_submit';
                    $action = $params['g-recaptcha-action'] ?? $expectedAction;

                    if ($action !== $expectedAction || ($content['action'] ?? null) !== $expectedAction) {
                        throw new \ClientFence\InformationException('reCAPTCHA verification failed.');
                    }
                }
            } elseif ($provider === 'turnstile') {
                if (empty($config['turnstile_secret_key'])) {
                    throw new \ClientFence\InformationException('Cloudflare Turnstile secret key is not configured.');
                }

                $turnstile_response = $params['cf-turnstile-response'] ?? null;
                if (empty($turnstile_response)) {
                    throw new \ClientFence\InformationException('Please complete the CAPTCHA verification.');
                }

                $httpClient = $di['http_client'];
                $response = $httpClient->request('POST', 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'body' => [
                        'secret' => $config['turnstile_secret_key'],
                        'response' => $turnstile_response,
                        'remoteip' => $di['request']->getClientIp(),
                    ],
                ]);
                $content = $response->toArray();

                if (!isset($content['success']) || $content['success'] !== true) {
                    throw new \ClientFence\InformationException('CAPTCHA verification failed. Please try again.');
                }
            } elseif ($provider === 'hcaptcha') {
                if (empty($config['hcaptcha_secret_key'])) {
                    throw new \ClientFence\InformationException('hCaptcha secret key is not configured.');
                }

                $hcaptcha_response = $params['h-captcha-response'] ?? null;
                if (empty($hcaptcha_response)) {
                    throw new \ClientFence\InformationException('Please complete the CAPTCHA verification.');
                }

                $httpClient = $di['http_client'];
                $response = $httpClient->request('POST', 'https://hcaptcha.com/siteverify', [
                    'body' => [
                        'secret' => $config['hcaptcha_secret_key'],
                        'response' => $hcaptcha_response,
                        'remoteip' => $di['request']->getClientIp(),
                    ],
                ]);
                $content = $response->toArray();

                if (!isset($content['success']) || $content['success'] !== true) {
                    throw new \ClientFence\InformationException('CAPTCHA verification failed. Please try again.');
                }
            }
        }
    }

    public function isTemp(\ClientFence\Event $event): void
    {
        $di = $event->getDi();
        $config = $di['mod_config']('Antispam');

        $check = $config['check_temp_emails'] ?? true;
        if ($check) {
            $antispamService = $di['mod_service']('Antispam');
            $params = $event->getParameters();
            $email = $params['email'] ?? '';

            $antispamService->isATempEmail($email, true);
        }
    }

    public function checkHoneypot(\ClientFence\Event $event): void
    {
        $di = $event->getDi();
        $config = $di['mod_config']('Antispam');

        $enabled = $config['honeypot_enabled'] ?? true;
        if ($enabled) {
            $params = $event->getParameters();
            $honeypotField = $config['honeypot_field'] ?? 'bio';

            if (!empty($params[$honeypotField])) {
                $this->di['logger']->info('Potential spam registration blocked. Reason: honeypot field was not empty.');

                throw new \ClientFence\InformationException('Registration failed.');
            }
        }
    }

    /**
     * Pass params:
     * ip
     * email
     * username
     */
    public function isInStopForumSpamDatabase(array $data): bool
    {
        $url = 'https://www.stopforumspam.com/api';
        $httpClient = $this->di['http_client'];
        $queryParams = array_merge($data, ['f' => 'json']);
        // ClientFence core patch: a few seconds at most; a slow or unreachable service lets the sign-up through.
        try {
            $response = $httpClient->request(
                'GET',
                $url,
                ['query' => $queryParams, 'timeout' => 3, 'max_duration' => 5]
            );
            $file_contents = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            $this->di['logger']->warning('Stop Forum Spam could not be reached: %s', $e->getMessage());

            return false;
        }

        $json = json_decode($file_contents);
        if (!is_object($json) || isset($json->success) && !$json->success) {
            return false;
        }

        if (isset($json->username->appears) && $json->username->appears) {
            throw new \ClientFence\InformationException('Your username is blacklisted in the Stop Forum Spam database');
        }
        if (isset($json->email->appears) && $json->email->appears) {
            throw new \ClientFence\InformationException('Your e-mail is blacklisted in the Stop Forum Spam database');
        }
        if (isset($json->ip->appears) && $json->ip->appears) {
            throw new \ClientFence\InformationException('Your IP address is blacklisted in the Stop Forum Spam database');
        }

        return true;
    }

    /**
     * Checks if a provided email address is using a disposable email service.
     *
     * @param string $email the email address to check
     * @param bool   $throw (optional) Configures if you want the function to throw an exception. Defaults to true.
     *
     * @return bool true if the email address is disposable, false if it isn't
     */
    public function isATempEmail(string $email, bool $throw = true): bool
    {
        /*
         * The EmailChecker package utilizes PHP's email verification which does not correctly validate international email addresses.
         * We are already using a proper validation package that does validate these as it should, so below is actually a workaround for the limitation.
         * @see https://github.com/MattKetmo/EmailChecker/issues/92
         *
         * Without this workaround, FOSSBilling would be unable to accept international email addresses when disposable email checking is enabled.
         */
        $adapter = new Adapter\ArrayAdapter($this->getTempMailDomainDB());

        try {
            [$local, $domain] = Utilities::parseEmailAddress($email);
        } catch (\Exception) {
            // Just to be on the safe side, assume the email is valid if there was an error.
            return false;
        }
        $invalid = $adapter->isThrowawayDomain($domain);
        if ($invalid && $throw) {
            throw new \ClientFence\InformationException('Disposable email addresses are not allowed');
        }

        return $invalid;
    }

    /**
     * Fetches the most recent list of disposable email addresses, parses them to remove blanks or invalid domains, and then returns it as an array.
     * The database is from here: https://github.com/7c/fakefilter
     * Results are cached for 1 week unless there's an error at which point the list will be retried in a half hour.
     */
    private function getTempMailDomainDB(): array
    {
        return $this->di['cache']->get('tempMailDB', function (ItemInterface $item) {
            $item->expiresAfter(86400); // The list is updated once every 24 hours, so we will cache it for that long

            $httpClient = $this->di['http_client'];
            $response = $httpClient->request('GET', 'https://raw.githubusercontent.com/7c/fakefilter/main/txt/data.txt');
            $dbPath = Path::join(PATH_CACHE, 'tempEmailDB.txt');

            if ($response->getStatusCode() === 200) {
                $this->filesystem->dumpFile($dbPath, $response->getContent());
            } else {
                $item->expiresAfter(3600);

                return [];
            }

            $database = file($dbPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $this->filesystem->remove($dbPath);
            if (!$database) {
                $item->expiresAfter(3600);

                return [];
            }

            return array_filter($database, fn ($domain): bool => !str_starts_with($domain, '#') && filter_var($domain, FILTER_VALIDATE_DOMAIN));
        });
    }
}
