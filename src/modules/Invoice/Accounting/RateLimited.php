<?php

declare(strict_types=1);

/**
 * ClientFence: the accounting program answered HTTP 429, or cannot be used now ($outage: not reachable, a server
 * error, the access refused or the connection ended). The sync stops the run without counting a try against the
 * records and waits $retryAfter seconds (Sync.php).
 */

namespace ClientFence\Mod\Invoice\Accounting;

final class RateLimited extends \ClientFence\InformationException
{
    public function __construct(public readonly int $retryAfter, string $program, public readonly ?\Throwable $outage = null)
    {
        parent::__construct($outage?->getMessage() ?? ':program asked ClientFence to wait :seconds seconds before sending more.', $outage === null ? [':program' => $program, ':seconds' => $retryAfter] : null);
    }
}
