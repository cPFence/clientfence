<?php

declare(strict_types=1);

namespace ClientFence\Mod\Custompages\Api;

/**
 * ClientFence: the published custom pages the viewer may open that show in the client-area menu or
 * footer (the Palisade theme's partial_menu and partials/footer).
 */
class Guest extends \ClientFence\Api\AbstractApi
{
    public function links(): array
    {
        return $this->getService()->getLinks();
    }
}
