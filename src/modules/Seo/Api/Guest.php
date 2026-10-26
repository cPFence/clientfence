<?php

declare(strict_types=1);

namespace ClientFence\Mod\Seo\Api;

class Guest extends \ClientFence\Api\AbstractApi
{
    /**
     * The Google Search Console and Bing Webmaster verification codes for the client area head.
     */
    public function verification(): array
    {
        return $this->getService()->getVerification();
    }
}
