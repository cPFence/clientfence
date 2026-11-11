<?php

declare(strict_types=1);

/**
 * ClientFence: an optional registrar adapter capability, where an incoming transfer stands
 * (Servicedomain/DomainSync.php). Adapters without it keep the old behaviour: a transfer counts
 * as done once the registrar accepts it.
 */
interface Registrar_TransferStatus
{
    public const PENDING = 'pending';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';

    /**
     * @return array{status: string, message: string} status: one of the constants; message: the registrar's words
     *
     * @throws Registrar_Exception when the registrar cannot be asked (the check runs again later)
     */
    public function getTransferStatus(Registrar_Domain $domain): array;
}
