<?php

declare(strict_types=1);

namespace ClientFence\Mod\Invoice;

use ClientFence\Twig\Markdown\ClientFenceMarkdown;

final class InvoicePdf
{
    public static function render(\Pimple\Container $di, array $data, string $locale): string
    {
        $previous = \Locale::getDefault();
        (new \ClientFence\Translate())->setLocale(RtlPdf::labels($locale))->setup();
        try {
            $pdf = RtlPdf::document($di, $locale, self::heading($data));
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

    /** "Invoice Number: CF00011", or the Document Title setting before the number ("Tax Invoice CF00011", GitHub #9). */
    private static function heading(array $data): string
    {
        $title = (string) ($data['footer']['invoice_title'] ?? '');

        return ($title !== '' ? $title . ' ' : __trans('Invoice Number') . ': ') . $data['invoice']['serie_nr'];
    }

    /**
     * The invoice in the CF-135 look, in TCPDF's HTML subset (tables, colours, borders; no classes): a
     * header row with the company and title, the facts with a coloured status cell, seller and buyer
     * side by side, a tinted lines table, the totals in a bordered panel on the end side, notes and
     * payment details in tinted boxes, a quiet footer. Every tint carries a border or dark text, so it
     * reads in black and white. TCPDF mirrors table columns in right-to-left documents itself.
     */
    private static function html(\Pimple\Container $di, array $data, callable $money, string $locale): string
    {
        $rtl = RtlPdf::isRtl($locale);
        $text = RtlPdf::text($rtl);
        $start = $rtl ? 'right' : 'left';
        $end = $rtl ? 'left' : 'right';
        $invoice = $data['invoice'];
        $date = new \IntlDateFormatter(RtlPdf::labels($locale), \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE);
        $taxLabel = (string) ($data['footer']['tax_label'] ?? '');
        // Palette: navy ink, teal accent, muted grey, hairline, soft tint.
        $ink = '#1b2433';
        $teal = '#0f8f86';
        $muted = '#5b6779';
        $hair = '#dde3ec';
        $soft = '#f4f6fb';
        $box = static fn (string $content): string => '<table cellpadding="6"><tr><td style="background-color:' . $soft . ';border:0.3px solid ' . $hair . ';border-' . $start . ':1.5px solid ' . $teal . ';">' . $content . '</td></tr></table>';
        [$statusBg, $statusInk] = match ($invoice['status']) {
            \Model_Invoice::STATUS_PAID => ['#dcf3e4', '#0f6b3a'],
            \Model_Invoice::STATUS_UNPAID => ['#fff0d4', '#7a4600'],
            default => ['#e6ebf3', '#3f4a5c'],
        };
        $fact = static fn (string $label, string $value, string $style = ''): string => '<tr><td width="40%" style="color:' . $muted . ';border-bottom:0.3px solid ' . $hair . ';">' . $label . '</td>'
            . '<td width="60%" style="font-weight:bold;border-bottom:0.3px solid ' . $hair . ';' . $style . '">' . $value . '</td></tr>';

        $html = '<table cellpadding="2"><tr>'
            . '<td width="50%" align="' . $start . '" style="font-size:13pt;font-weight:bold;color:' . $ink . ';border-bottom:1.5px solid ' . $teal . ';">' . $text($data['footer']['company_name'] ?? '') . '</td>'
            . '<td width="50%" align="' . $end . '" style="border-bottom:1.5px solid ' . $teal . ';"><h1 style="font-size:16pt;color:' . $ink . ';">' . $text(self::heading($data)) . '</h1></td>'
            . '</tr></table><br><br>'
            . '<table cellpadding="4" width="60%">'
            . $fact($text(__trans('Invoice Date')), $text($date->format(new \DateTimeImmutable((string) $invoice['created_at']))))
            . $fact($text(__trans('Due Date')), $text($date->format(new \DateTimeImmutable((string) $invoice['due_at']))))
            . $fact($text(__trans('Invoice Status')), $text(__trans(ucfirst($invoice['status']))), 'background-color:' . $statusBg . ';color:' . $statusInk . ';border:0.5px solid ' . $statusInk . ';')
            // ClientFence (GitHub #31): the purchase order number, when the invoice has one.
            . (($invoice['po_number'] ?? '') !== '' ? $fact($text(__trans('PO number')), $text((string) $invoice['po_number'])) : '')
            . '</table><br><br>'
            . '<table cellpadding="6"><tr>';

        foreach ([['Company', $data['seller'], 'Name'], ['Client', $data['buyer'], 'Company']] as $i => [$heading, $fields, $bold]) {
            $html .= ($i ? '<td width="4%"></td>' : '') . '<td width="48%" align="' . $start . '" style="background-color:' . $soft . ';border:0.3px solid ' . $hair . ';">'
                . '<span style="font-size:8pt;font-weight:bold;color:' . $teal . ';">' . $text(__trans($heading)) . '</span><br>';
            foreach ($fields as $label => $value) {
                $line = match ($label) {
                    'Phone', 'Company Number' => $text(__trans($label)) . ': ',
                    'VAT Number' => $text($taxLabel !== '' ? $taxLabel : __trans($label)) . ': ',
                    default => '',
                } . $text($value);
                $html .= ($label === $bold ? '<b>' . $line . '</b>' : $line) . '<br>';
            }
            $html .= '</td>';
        }
        $html .= '</tr></table><br><br>';

        $markdown = new ClientFenceMarkdown($di);
        // Notes may contain Markdown images; PDF rendering must not fetch their URLs.
        $note = static function (string $value) use ($markdown, $text): string {
            $html = strip_tags($markdown->convert($value), '<p><br><strong><em><s><del><ul><ol><li><blockquote><pre><code><a><h1><h2><h3><h4><h5><h6><hr><table><thead><tbody><tr><th><td>');

            return (string) preg_replace_callback('/>([^<]+)</u', static fn (array $m): string => trim($m[1]) === '' ? $m[0] : '>' . $text(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')) . '<', $html);
        };
        if ($invoice['text_1']) {
            $html .= $box($note($invoice['text_1'])) . '<br>';
        }

        $th = 'style="font-weight:bold;color:' . $ink . ';background-color:#e3f2f1;border-bottom:1px solid ' . $teal . ';"';
        $html .= '<table cellpadding="4">'
            . '<tr>'
            . '<td width="5%" align="center" ' . $th . '>#</td>'
            . '<td width="45%" align="' . $start . '" ' . $th . '>' . $text(__trans('Product')) . '</td>'
            . '<td width="30%" align="' . $end . '" ' . $th . '>' . $text(__trans('Quantity & Price')) . '</td>'
            . '<td width="20%" align="' . $end . '" ' . $th . '>' . $text(__trans('Total')) . '</td></tr>';
        foreach ($invoice['lines'] as $nr => $item) {
            $html .= '<tr nobr="true" style="background-color:' . ($nr % 2 ? $soft : '#ffffff') . ';">'
                . '<td align="center" style="border-bottom:0.3px solid ' . $hair . ';">' . $text($nr + 1) . '</td>'
                . '<td align="' . $start . '" style="border-bottom:0.3px solid ' . $hair . ';">' . $text($item['title']) . '</td>'
                . '<td align="' . $end . '" style="border-bottom:0.3px solid ' . $hair . ';">' . $text($item['quantity'] . 'x ' . $money($item['price'])) . '</td>'
                . '<td align="' . $end . '" style="border-bottom:0.3px solid ' . $hair . ';">' . $text($money($item['total'])) . '</td></tr>';
        }
        $html .= '</table><br><br>';

        // The totals panel on the end side: label and amount cells inside one border.
        $totals = [];
        if ($invoice['tax'] != 0) {
            $totals[] = [$invoice['taxname'] . ' ' . $invoice['taxrate'] . '% ' . __trans('Tax'), $invoice['tax'], false];
        }
        if (($invoice['discount'] ?? 0) > 0) {
            $totals[] = [__trans('Discount'), $invoice['discount'], false];
        }
        $totals[] = [__trans('Total'), $invoice['total'], true];
        if ($invoice['status'] === \Model_Invoice::STATUS_UNPAID && ($invoice['amount_paid'] ?? 0) > 0) {
            $totals[] = [__trans('Amount paid'), -$invoice['amount_paid'], false];
            $totals[] = [__trans('Balance due'), $invoice['balance_due'], true];
        }
        $html .= '<table cellpadding="4" nobr="true">';
        foreach ($totals as $i => [$label, $amount, $strong]) {
            $edge = ($i === 0 || $strong ? 'border-top:0.5px solid ' . $ink . ';' : '')
                . ($i === count($totals) - 1 ? 'border-bottom:0.5px solid ' . $ink . ';' : '')
                . ($strong ? 'font-weight:bold;background-color:#e3f2f1;' : '');
            $html .= '<tr><td width="50%"></td>'
                . '<td width="30%" align="' . $end . '" style="' . $edge . 'border-' . $start . ':0.5px solid ' . $ink . ';' . ($strong ? '' : 'color:' . $muted . ';') . '">' . $text($label) . ':</td>'
                . '<td width="20%" align="' . $end . '" style="' . $edge . 'border-' . $end . ':0.5px solid ' . $ink . ';">' . $text($money($amount)) . '</td></tr>';
        }
        $html .= '</table>';
        if ($invoice['reverse_charge'] ?? false) {
            $html .= '<br>' . $box($text(__trans('VAT reverse charge: the buyer accounts for the VAT (Article 196, Directive 2006/112/EC).')));
        }
        if ($invoice['text_2']) {
            $html .= '<br>' . $box($note($invoice['text_2']));
        }

        $footer = $data['footer'];
        if ($footer['display_bank_info'] == 1) {
            $bank = '<b>' . $text(__trans('Payment Details')) . ':</b><br>';
            foreach (['Account Owner' => 'company_name', 'Bank' => 'bank_name', 'BIC / SWIFT Code' => 'bic', 'Account Number' => 'account_number'] as $label => $key) {
                $bank .= '<b>' . $text(__trans($label)) . ':</b> ' . $text($footer[$key]) . '<br>';
            }
            $html .= '<br>' . $box($bank);
        }
        $html .= '<br><br><table cellpadding="4"><tr><td align="center" style="font-size:7pt;color:' . $muted . ';border-top:0.3px solid ' . $hair . ';">';
        $address = array_filter([$footer['address_1'], $footer['address_2'], $footer['address_3']], static fn ($line): bool => (string) $line !== '');
        $html .= '<b>' . $text($footer['company_name']) . '</b> ' . $text(implode(', ', $address)) . '<br>'
            . '<b>' . $text(__trans('Email')) . ':</b> ' . $text($footer['email']) . '<br>';
        foreach (['Phone' => 'phone', 'VAT ID' => 'company_vat', 'Company Registration #:' => 'company_number'] as $label => $key) {
            if ($footer[$key]) {
                $html .= '<b>' . $text($key === 'company_vat' && $taxLabel !== '' ? $taxLabel : __trans($label)) . '</b> ' . $text($footer[$key]) . '<br>';
            }
        }

        return $html . '<b>' . $text(__trans('Website')) . ':</b> ' . $text($footer['www'])
            . '<br><span style="color:#656665;">' . $text($footer['signature']) . '</span></td></tr></table>';
    }
}
