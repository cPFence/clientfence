<?php

declare(strict_types=1);

namespace ClientFence\Mod\Invoice;

use ClientFence\Twig\Markdown\ClientFenceMarkdown;

final class InvoicePdf
{
    public static function render(\Pimple\Container $di, array $data, string $locale): string
    {
        $previous = \Locale::getDefault();
        (new \ClientFence\Translate())->setLocale($locale)->setup();
        try {
            $pdf = RtlPdf::document($di, $locale, __trans('Invoice Number') . ': ' . $data['invoice']['serie_nr']);
            $logo = $data['logo_source'];
            if ($logo !== '') {
                $svg = str_starts_with($logo, 'data:image/svg+xml;');
                if (str_starts_with($logo, 'data:')) {
                    $logo = '@' . base64_decode(explode(',', $logo, 2)[1]);
                }
                if ($svg) {
                    $pdf->ImageSVG($logo, null, null, 0, 15, '', 'N');
                } else {
                    $pdf->Image($logo, null, null, 0, 15, '', '', 'N');
                }
                $pdf->Ln(3);
            }
            $money = static fn (mixed $amount): string => RtlPdf::money($di, (float) $amount, $data['currency_code']);
            $pdf->writeHTML(self::html($di, $data, $money, $locale), true, false, true, false, '');

            return $pdf->Output('invoice.pdf', 'S');
        } finally {
            (new \ClientFence\Translate())->setLocale($previous ?: 'en_US')->setup();
        }
    }

    private static function html(\Pimple\Container $di, array $data, callable $money, string $locale): string
    {
        $rtl = RtlPdf::isRtl($locale);
        $text = RtlPdf::text($rtl);
        $start = $rtl ? 'right' : 'left';
        $end = $rtl ? 'left' : 'right';
        $invoice = $data['invoice'];
        $date = new \IntlDateFormatter($locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE);
        $html = '<h1 style="font-size:16pt;">' . $text(__trans('Invoice Number')) . ': ' . $text($invoice['serie_nr']) . '</h1>'
            . '<p>' . $text(__trans('Invoice Date')) . ': ' . $text($date->format(new \DateTimeImmutable((string) $invoice['created_at']))) . '<br>'
            . $text(__trans('Due Date')) . ': ' . $text($date->format(new \DateTimeImmutable((string) $invoice['due_at']))) . '<br>'
            . $text(__trans('Invoice Status')) . ': ' . $text(__trans(ucfirst($invoice['status']))) . '</p>'
            . '<table cellpadding="2"><tr>';

        foreach ([['Company', $data['seller'], 'Name'], ['Client', $data['buyer'], 'Company']] as [$heading, $fields, $bold]) {
            $html .= '<td width="50%" align="' . $start . '"><b>' . $text(__trans($heading)) . '</b><br>';
            foreach ($fields as $label => $value) {
                $line = (in_array($label, ['Phone', 'VAT Number'], true) ? $text(__trans($label)) . ': ' : '') . $text($value);
                $html .= ($label === $bold ? '<b>' . $line . '</b>' : $line) . '<br>';
            }
            $html .= '</td>';
        }
        $html .= '</tr></table><br>';

        $markdown = new ClientFenceMarkdown($di);
        // Notes may contain Markdown images; PDF rendering must not fetch their URLs.
        $note = static function (string $value) use ($markdown, $text): string {
            $html = strip_tags($markdown->convert($value), '<p><br><strong><em><s><del><ul><ol><li><blockquote><pre><code><a><h1><h2><h3><h4><h5><h6><hr><table><thead><tbody><tr><th><td>');

            return (string) preg_replace_callback('/>([^<]+)</u', static fn (array $m): string => trim($m[1]) === '' ? $m[0] : '>' . $text(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')) . '<', $html);
        };
        if ($invoice['text_1']) {
            $html .= $note($invoice['text_1']) . '<br>';
        }

        $html .= '<table cellpadding="3" border="0.3" style="border-color:#bbbbbb;">'
            . '<tr style="background-color:#f2f4f7;font-weight:bold;">'
            . '<td width="5%" align="center">#</td>'
            . '<td width="45%" align="' . $start . '">' . $text(__trans('Product')) . '</td>'
            . '<td width="30%" align="' . $end . '">' . $text(__trans('Quantity & Price')) . '</td>'
            . '<td width="20%" align="' . $end . '">' . $text(__trans('Total')) . '</td></tr>';
        foreach ($invoice['lines'] as $nr => $item) {
            $html .= '<tr nobr="true"><td align="center">' . $text($nr + 1) . '</td>'
                . '<td align="' . $start . '">' . $text($item['title']) . '</td>'
                . '<td align="' . $end . '">' . $text($item['quantity'] . 'x ' . $money($item['price'])) . '</td>'
                . '<td align="' . $end . '">' . $text($money($item['total'])) . '</td></tr>';
        }
        $total = static fn (string $label, mixed $amount): string => '<tr style="font-weight:bold;">'
            . '<td colspan="3" align="' . $end . '">' . $text($label) . ':</td>'
            . '<td align="' . $end . '">' . $text($money($amount)) . '</td></tr>';
        if ($invoice['tax'] != 0) {
            $html .= $total($invoice['taxname'] . ' ' . $invoice['taxrate'] . '% ' . __trans('Tax'), $invoice['tax']);
        }
        if (($invoice['discount'] ?? 0) > 0) {
            $html .= $total(__trans('Discount'), $invoice['discount']);
        }
        $html .= $total(__trans('Total'), $invoice['total']);
        if ($invoice['status'] === \Model_Invoice::STATUS_UNPAID && ($invoice['amount_paid'] ?? 0) > 0) {
            $html .= $total(__trans('Amount paid'), -$invoice['amount_paid']) . $total(__trans('Balance due'), $invoice['balance_due']);
        }
        $html .= '</table>';
        if ($invoice['text_2']) {
            $html .= '<br>' . $note($invoice['text_2']);
        }

        $footer = $data['footer'];
        $html .= '<br><div style="font-size:8pt;" align="center">';
        if ($footer['display_bank_info'] == 1) {
            $html .= '<b>' . $text(__trans('Payment Details')) . ':</b><br>';
            foreach (['Account Owner' => 'company_name', 'Bank' => 'bank_name', 'BIC / SWIFT Code' => 'bic', 'Account Number' => 'account_number'] as $label => $key) {
                $html .= '<b>' . $text(__trans($label)) . ':</b> ' . $text($footer[$key]) . '<br>';
            }
            $html .= '<br>';
        }
        $address = array_filter([$footer['address_1'], $footer['address_2'], $footer['address_3']], static fn ($line): bool => (string) $line !== '');
        $html .= '<b>' . $text($footer['company_name']) . '</b> ' . $text(implode(', ', $address)) . '<br>'
            . '<b>' . $text(__trans('Email')) . ':</b> ' . $text($footer['email']) . '<br>';
        foreach (['Phone' => 'phone', 'VAT ID' => 'company_vat', 'Company Registration #:' => 'company_number'] as $label => $key) {
            if ($footer[$key]) {
                $html .= '<b>' . $text(__trans($label)) . '</b> ' . $text($footer[$key]) . '<br>';
            }
        }

        return $html . '<b>' . $text(__trans('Website')) . ':</b> ' . $text($footer['www'])
            . '<br><span style="color:#656665;">' . $text($footer['signature']) . '</span></div>';
    }
}
