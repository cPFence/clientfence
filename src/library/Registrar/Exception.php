<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */
class Registrar_Exception extends ClientFence\Exception
{
    private string $template;

    private array $variables;

    /**
     * Creates a new translated exception, using the ClientFence\Exception class.
     *
     * @param string         $message   error message
     * @param array|int|null $variables translation variables, or the exception code
     *                                  (third-party adapters have been observed
     *                                  passing the code here, so tolerate it
     *                                  rather than fataling and hiding the error)
     * @param int            $code      the exception code
     */
    public function __construct(string $message, array|int|null $variables = null, int $code = 0)
    {
        if (is_int($variables)) {
            $code = $variables;
            $variables = null;
        }

        parent::__construct($message, $variables, $code, true);
        $this->template = $message;
        $this->variables = $variables ?? [];
    }

    /**
     * ClientFence core patch: clients and guests read "the registrar" for its name, so a reseller does not show which
     * registrar holds a domain. Adapters name it with :registrar (or :type: / :domain_registrar).
     */
    public function withoutRegistrarName(): static
    {
        $names = array_intersect_key($this->variables, array_flip([':registrar', ':type:', ':domain_registrar']));
        if ($names !== []) {
            $text = __trans($this->template, array_replace($this->variables, array_fill_keys(array_keys($names), __trans('the registrar'))));
            $this->message = mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
        }

        return $this;
    }
}
