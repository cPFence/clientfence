<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace ClientFence\Twig;

use Twig\Environment;

final class SandboxedStringRenderer
{
    public static function render(
        Environment $twig,
        string $template,
        array $vars,
        string $errorPrefix,
        ?callable $onSecurityError = null,
    ): string {
        try {
            return $twig->createTemplate($template)->render($vars);
        } catch (\Twig\Sandbox\SecurityError $e) {
            if ($onSecurityError !== null) {
                $onSecurityError($e);
            }

            throw new \ClientFence\InformationException($errorPrefix . ' contains disallowed Twig syntax: ' . self::message($e));
        } catch (\Twig\Error\SyntaxError $e) {
            throw new \ClientFence\InformationException($errorPrefix . ' syntax error: ' . self::message($e));
        } catch (\Twig\Error\Error $e) {
            throw new \ClientFence\InformationException($errorPrefix . ' rendering error: ' . self::message($e));
        }
    }

    /**
     * ClientFence core patch: the error for staff without Twig's internal template name ("__string_template__…");
     * without the line too when it would count lines the staff member did not write.
     */
    public static function message(\Twig\Error\Error $e, bool $withLine = true): string
    {
        $e->setSourceContext(null);
        if (!$withLine) {
            $e->setTemplateLine(-1);
        }

        return $e->getMessage();
    }
}
