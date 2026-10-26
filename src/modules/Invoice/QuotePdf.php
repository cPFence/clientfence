<?php

declare(strict_types=1);

namespace ClientFence\Mod\Invoice;

use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * ClientFence: a quote (Quotes.php) as a PDF in the reader's language (RtlPdf: TCPDF, right to left
 * for Arabic and Hebrew): the proposal, the lines with their discounts, the totals, the notes and,
 * while it is open, the link to accept it.
 */
final class QuotePdf
{
    public static function response(\Pimple\Container $di, array $quote, string $locale): Response
    {
        $response = new Response(self::render($di, $quote, $locale));
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, self::fileName($quote)));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public static function fileName(array $quote): string
    {
        return 'quote-' . $quote['id'] . '.pdf';
    }

    /**
     * The PDF in `$locale`: its labels too, so a quote staff send reads in the client's language; the
     * request's language is restored afterwards.
     */
    public static function render(\Pimple\Container $di, array $quote, string $locale): string
    {
        $previous = \Locale::getDefault();
        (new \ClientFence\Translate())->setLocale($locale)->setup();
        try {
            $title = __trans('Quote #:id', [':id' => $quote['id']]);
            $pdf = RtlPdf::document($di, $locale, $title);
            $company = $di['mod_service']('system')->getCompany();
            $money = static fn (float $amount): string => RtlPdf::money($di, $amount, $quote['currency']);
            $link = $quote['open'] ? Quotes::link($di, $quote) : '';
            $pdf->writeHTML(self::html($quote, $company, $money, RtlPdf::isRtl($locale), $title, $link), true, false, true, false, '');

            return $pdf->Output(self::fileName($quote), 'S');
        } finally {
            (new \ClientFence\Translate())->setLocale($previous ?: 'en_US')->setup();
        }
    }

    private static function html(array $quote, array $company, callable $money, bool $rtl, string $title, string $link): string
    {
        $text = RtlPdf::text($rtl);
        // In right-to-left documents TCPDF mirrors the columns; the amounts keep to the end side.
        $end = $rtl ? 'left' : 'right';
        $start = $rtl ? 'right' : 'left';
        $lines = static fn (string $value): string => implode('<br>', array_map($text, explode("\n", $value)));

        $seller = array_filter([
            $company['name'] ?? '',
            trim(($company['address_1'] ?? '') . ' ' . ($company['address_2'] ?? '') . ' ' . ($company['address_3'] ?? '')),
            $company['email'] ?? '',
            $company['tel'] ?? '',
        ], static fn ($line): bool => trim((string) $line) !== '');
        $to = $quote['recipient'];
        $buyer = array_filter([$to['company'], $to['name'], $to['email']], static fn ($line): bool => trim((string) $line) !== '');
        if ($quote['client_id'] !== null) {
            $buyer[] = __trans('Client number') . ': ' . $quote['client_id'];
        }

        $html = '<h1 style="font-size:16pt;">' . $text($title) . '</h1>'
            . '<p style="font-size:11pt;"><b>' . $text($quote['subject']) . '</b></p>'
            . '<table cellpadding="2"><tr>'
            . '<td width="50%" align="' . $start . '"><b>' . $text(__trans('From')) . '</b><br>' . implode('<br>', array_map($text, $seller)) . '</td>'
            . '<td width="50%" align="' . $start . '"><b>' . $text(__trans('To')) . '</b><br>' . implode('<br>', array_map($text, $buyer)) . '</td>'
            . '</tr></table><br>'
            . '<table cellpadding="2"><tr>'
            . '<td width="34%">' . $text(__trans('Date')) . ': ' . $text(substr($quote['created_at'], 0, 10)) . '</td>'
            . '<td width="33%">' . ($quote['valid_until'] !== '' ? $text(__trans('Valid until')) . ': ' . $text($quote['valid_until']) : '') . '</td>'
            . '<td width="33%">' . $text(__trans('Currency')) . ': ' . $text($quote['currency']) . '</td>'
            . '</tr></table><br>';

        if ($quote['proposal'] !== '') {
            $html .= '<p>' . $lines($quote['proposal']) . '</p>';
        }

        $hasDiscount = array_filter($quote['items'], static fn (array $item): bool => $item['discount'] > 0) !== [];
        $html .= '<table cellpadding="3" border="0.3" style="border-color:#bbbbbb;">'
            . '<tr style="background-color:#f2f4f7;font-weight:bold;">'
            . '<td width="' . ($hasDiscount ? 43 : 55) . '%">' . $text(__trans('Description')) . '</td>'
            . '<td width="9%" align="' . $end . '">' . $text(__trans('Qty')) . '</td>'
            . '<td width="18%" align="' . $end . '">' . $text(__trans('Unit price')) . '</td>'
            . ($hasDiscount ? '<td width="12%" align="' . $end . '">' . $text(__trans('Discount')) . '</td>' : '')
            . '<td width="18%" align="' . $end . '">' . $text(__trans('Amount')) . '</td></tr>';
        foreach ($quote['items'] as $item) {
            $html .= '<tr nobr="true"><td>' . $text($item['description']) . ($item['taxed'] && $quote['tax'] > 0 ? ' *' : '') . '</td>'
                . '<td align="' . $end . '">' . $text($item['quantity']) . '</td>'
                . '<td align="' . $end . '">' . $text($money($item['price'])) . '</td>'
                . ($hasDiscount ? '<td align="' . $end . '">' . ($item['discount'] > 0 ? $text(rtrim(rtrim(number_format($item['discount'], 2, '.', ''), '0'), '.') . '%') : '') . '</td>' : '')
                . '<td align="' . $end . '">' . $text($money($item['amount'])) . '</td></tr>';
        }
        $span = $hasDiscount ? 4 : 3;
        $total = static fn (string $label, float $amount, bool $bold = false): string => '<tr' . ($bold ? ' style="font-weight:bold;"' : '') . '>'
            . '<td colspan="' . $span . '" align="' . $end . '">' . $text($label) . '</td>'
            . '<td align="' . $end . '">' . $text($money($amount)) . '</td></tr>';
        if ($quote['tax'] > 0) {
            $html .= $total(__trans('Subtotal'), $quote['subtotal']);
            $html .= $total(($quote['taxname'] !== '' ? $quote['taxname'] : __trans('Tax')) . ' (' . rtrim(rtrim(number_format($quote['taxrate'], 2, '.', ''), '0'), '.') . '%)', $quote['tax']);
        }
        $html .= $total(__trans('Total'), $quote['total'], true) . '</table>';
        if ($quote['tax'] > 0) {
            $html .= '<p style="color:#666666;font-size:8pt;">' . $text(__trans('* Taxed line.')) . '</p>';
        }

        if ($quote['customer_notes'] !== '') {
            $html .= '<br><p><b>' . $text(__trans('Notes')) . '</b><br>' . $lines($quote['customer_notes']) . '</p>';
        }
        if ($link !== '') {
            $html .= '<br><p>' . $text(__trans('To accept this quote, open:')) . '<br>' . $text($link) . '</p>';
        }

        return $html;
    }
}
