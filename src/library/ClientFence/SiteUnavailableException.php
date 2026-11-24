<?php

declare(strict_types=1);

namespace ClientFence;

/**
 * ClientFence: thrown by a module's site(string $host) for a host it serves but cannot serve now; the request gets a
 * 503 page instead of the primary site (Site::unavailable()).
 */
final class SiteUnavailableException extends \RuntimeException
{
}
