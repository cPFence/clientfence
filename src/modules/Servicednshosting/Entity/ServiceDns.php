<?php

declare(strict_types=1);

namespace ClientFence\Mod\Servicednshosting\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'service_dnshosting')]
#[ORM\UniqueConstraint(name: 'dns_order', columns: ['order_id'])]
class ServiceDns
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(name: 'order_id', type: Types::BIGINT)]
    public int $orderId;

    #[ORM\Column(name: 'domain_name', length: 253)]
    public string $domain;

    #[ORM\Column(type: Types::TEXT)]
    private string $config;

    #[ORM\Column(name: 'zone_id', length: 253, nullable: true)]
    public ?string $zoneId = null;

    #[ORM\Column(type: Types::JSON)]
    public array $nameservers = [];

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConfig(): array
    {
        return json_decode($this->config, true, flags: JSON_THROW_ON_ERROR);
    }
}
