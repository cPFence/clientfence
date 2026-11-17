<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling.
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Twig\Extension;

use DebugBar\JavascriptRenderer;
use DebugBar\StandardDebugBar;
use ClientFence\Csp;
use ClientFence\Environment;
use Twig\Attribute\AsTwigFunction;

class DebugBarExtension
{
    private readonly JavascriptRenderer $debugbarRenderer;

    public function __construct(StandardDebugBar $debugBar)
    {
        $this->debugbarRenderer = $debugBar->getJavascriptRenderer();
    }

    #[AsTwigFunction('debug_bar_render_head', isSafe: ['html'])]
    public function renderHead(): string
    {
        if (!Environment::isDevelopment()) {
            return '';
        }

        $css = $this->debugbarRenderer->dumpCssAssets(echo: false);
        $js = $this->debugbarRenderer->dumpJsAssets(echo: false);
        $head = $this->debugbarRenderer->dumpHeadAssets(echo: false);

        // ClientFence core patch: the debug bar's own tags carry the script policy nonce, or the browser blocks them.
        $nonce = ' nonce="' . Csp::nonce() . '"';
        $html = '';
        if ($css) {
            $html .= '<style' . $nonce . '>' . $css . '</style>';
        }
        if ($js) {
            $html .= '<script type="text/javascript"' . $nonce . '>' . $js . '</script>';
        }

        return $html . preg_replace('/<(script|style)(?![^>]*nonce=)/i', '<$1' . $nonce, $head);
    }

    #[AsTwigFunction('debug_bar_render', isSafe: ['html'])]
    public function render(): string
    {
        if (!Environment::isDevelopment()) {
            return '';
        }

        return $this->debugbarRenderer->setCspNonce(Csp::nonce())->render();
    }
}
