<?php

declare(strict_types=1);

namespace ClientFence\Mod\Embed;

class Service implements \ClientFence\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function getModulePermissions(): array
    {
        return [
            'manage_settings' => [],
        ];
    }

    /**
     * The websites that may show the /embed/ pages in a frame.
     *
     * @return list<string>
     */
    public function getAllowedOrigins(): array
    {
        $saved = $this->di['mod_config']('embed')['allowed_origins'] ?? [];

        // extension/config_save can store any value and these go into a header: only origins count.
        return is_array($saved) ? array_values(array_filter($saved, fn ($origin): bool => is_string($origin) && $this->isOrigin($origin))) : [];
    }

    /**
     * @param string $text one origin per line, such as https://example.com
     */
    public function saveAllowedOrigins(string $text): void
    {
        $origins = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $origin = strtolower(str_ends_with($line, '/') ? substr($line, 0, -1) : $line);
            if (!$this->isOrigin($origin)) {
                throw new \ClientFence\InformationException(':site is not a website address such as https://example.com.', [':site' => $line]);
            }
            $origins[$origin] = true;
        }
        if (count($origins) > 20) {
            throw new \ClientFence\InformationException('Enter up to 20 websites.');
        }

        $this->di['mod_service']('extension')->setConfig(['ext' => 'mod_embed', 'allowed_origins' => array_keys($origins)]);
    }

    private function isOrigin(string $origin): bool
    {
        return preg_match('#\Ahttps?://[a-z0-9-]+(\.[a-z0-9-]+)*(:\d{1,5})?\z#', $origin) === 1;
    }
}
