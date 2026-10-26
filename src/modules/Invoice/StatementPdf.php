<?php

declare(strict_types=1);

namespace ClientFence\Mod\Invoice;

use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * ClientFence: the account statement (Statement.php) as a PDF, in the language of the request
 * (RtlPdf: TCPDF, right to left for Arabic and Hebrew).
 */
final class StatementPdf
{
    /** Languages written right to left. */
    public const RTL_LANGUAGES = RtlPdf::RTL_LANGUAGES;

    public static function response(\Pimple\Container $di, array $statement, string $locale): Response
    {
        $name = 'statement-' . $statement['client']['id'] . ($statement['to'] !== '' ? '-' . $statement['to'] : '') . '.pdf';
        $response = new Response(self::render($di, $statement, $locale));
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public static function render(\Pimple\Container $di, array $statement, string $locale): string
    {
        $pdf = RtlPdf::document($di, $locale, __trans('Account statement'));
        $company = $di['mod_service']('system')->getCompany();
        $money = static fn (float $amount): string => RtlPdf::money($di, $amount, $statement['currency']);
        $pdf->writeHTML(self::html($statement, $company, $money, RtlPdf::isRtl($locale)), true, false, true, false, '');

        return $pdf->Output('statement.pdf', 'S');
    }

    public static function isRtl(string $locale): bool
    {
        return RtlPdf::isRtl($locale);
    }

    private static function html(array $statement, array $company, callable $money, bool $rtl): string
    {
        // In right-to-left documents TCPDF mirrors the columns; the amounts keep to the end side.
        $end = $rtl ? 'left' : 'right';
        $start = $rtl ? 'right' : 'left';
        $text = RtlPdf::text($rtl);

        $seller = array_filter([
            $company['name'] ?? '',
            trim(($company['address_1'] ?? '') . ' ' . ($company['address_2'] ?? '') . ' ' . ($company['address_3'] ?? '')),
            $company['email'] ?? '',
            $company['tel'] ?? '',
        ], static fn ($line): bool => trim((string) $line) !== '');
        $client = $statement['client'];
        $buyer = array_filter([$client['company'], $client['name'], $client['email']], static fn ($line): bool => trim((string) $line) !== '');
        $period = $statement['from'] === '' && $statement['to'] === ''
            ? __trans('All dates')
            : ($statement['from'] ?: __trans('Account opening')) . ' – ' . ($statement['to'] ?: date('Y-m-d'));

        $html = '<h1 style="font-size:16pt;">' . $text(__trans('Account statement')) . '</h1>'
            . '<table cellpadding="2"><tr>'
            . '<td width="50%" align="' . $start . '"><b>' . $text(__trans('From')) . '</b><br>' . implode('<br>', array_map($text, $seller)) . '</td>'
            . '<td width="50%" align="' . $start . '"><b>' . $text(__trans('To')) . '</b><br>' . implode('<br>', array_map($text, $buyer)) . '<br>' . $text(__trans('Client number')) . ': ' . $text($client['id']) . '</td>'
            . '</tr></table><br>'
            . '<table cellpadding="2"><tr>'
            . '<td width="50%">' . $text(__trans('Statement period')) . ': ' . $text($period) . '</td>'
            . '<td width="20%">' . $text(__trans('Currency')) . ': ' . $text($statement['currency']) . '</td>'
            . '<td width="30%">' . $text(__trans('Date')) . ': ' . $text(date('Y-m-d')) . '</td>'
            . '</tr></table><br>';

        $html .= '<table cellpadding="4" border="0.3" style="border-color:#bbbbbb;">'
            . '<tr style="background-color:#f2f4f7;">'
            . '<td width="25%">' . $text(__trans('Opening balance')) . '<br><b>' . $text($money($statement['opening'])) . '</b></td>'
            . '<td width="25%">' . $text(__trans('Billed')) . '<br><b>' . $text($money($statement['debit'])) . '</b></td>'
            . '<td width="25%">' . $text(__trans('Paid')) . '<br><b>' . $text($money($statement['credit'])) . '</b></td>'
            . '<td width="25%">' . $text(__trans('Closing balance')) . '<br><b>' . $text($money($statement['closing'])) . '</b></td>'
            . '</tr></table><br>';

        $html .= '<table cellpadding="3" border="0.3" style="border-color:#bbbbbb;">'
            . '<tr style="background-color:#f2f4f7;font-weight:bold;">'
            . '<td width="14%">' . $text(__trans('Date')) . '</td>'
            . '<td width="41%">' . $text(__trans('Description')) . '</td>'
            . '<td width="15%" align="' . $end . '">' . $text(__trans('Debit')) . '</td>'
            . '<td width="15%" align="' . $end . '">' . $text(__trans('Credit')) . '</td>'
            . '<td width="15%" align="' . $end . '">' . $text(__trans('Balance')) . '</td></tr>';
        if ($statement['from'] !== '') {
            $html .= '<tr><td>' . $text($statement['from']) . '</td><td>' . $text(__trans('Opening balance')) . '</td><td></td><td></td>'
                . '<td align="' . $end . '">' . $text($money($statement['opening'])) . '</td></tr>';
        }
        foreach ($statement['lines'] as $line) {
            $detail = $line['detail'] !== null ? '<br><span style="color:#555555;font-size:8pt;">' . $text($line['detail']) . '</span>' : '';
            $html .= '<tr nobr="true"><td>' . $text($line['date']) . '</td><td>' . $text($line['title']) . $detail . '</td>'
                . '<td align="' . $end . '">' . ($line['debit'] > 0 ? $text($money($line['debit'])) : '') . '</td>'
                . '<td align="' . $end . '">' . ($line['credit'] > 0 ? $text($money($line['credit'])) : '') . '</td>'
                . '<td align="' . $end . '">' . $text($money($line['balance'])) . '</td></tr>';
        }
        if ($statement['lines'] === []) {
            $html .= '<tr><td colspan="5">' . $text(__trans('No invoices, payments or credit in this period.')) . '</td></tr>';
        }
        $html .= '<tr style="font-weight:bold;"><td></td><td>' . $text(__trans('Closing balance')) . '</td>'
            . '<td align="' . $end . '">' . $text($money($statement['debit'])) . '</td>'
            . '<td align="' . $end . '">' . $text($money($statement['credit'])) . '</td>'
            . '<td align="' . $end . '">' . $text($money($statement['closing'])) . '</td></tr></table>';

        return $html . '<p style="color:#666666;font-size:8pt;">' . $text(__trans('A balance below zero is the amount due; above zero is account credit.')) . '</p>';
    }
}
