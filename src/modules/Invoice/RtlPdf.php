<?php

declare(strict_types=1);

namespace ClientFence\Mod\Invoice;

/**
 * ClientFence: the PDF documents built with TCPDF (the account statement, quotes) in the language
 * of the reader, right to left for Arabic and Hebrew.
 *
 * TCPDF, not Dompdf (which invoice PDFs use): TCPDF joins Arabic letters and lays out right-to-left
 * text with the Unicode bidirectional algorithm. The font is DejaVu Sans (shipped with TCPDF),
 * which has Latin, Arabic and Hebrew letters.
 */
final class RtlPdf
{
    /** Languages written right to left. */
    public const RTL_LANGUAGES = ['ar', 'he', 'fa', 'ur', 'yi'];

    public static function isRtl(string $locale): bool
    {
        return in_array(strtolower(substr($locale, 0, 2)), self::RTL_LANGUAGES, true);
    }

    /**
     * A new document with one page: the paper size of invoice PDFs (invoice_document_format), no
     * header or footer, DejaVu Sans 9 pt, 15 mm margins, right to left for those languages.
     */
    public static function document(\Pimple\Container $di, string $locale, string $title): \TCPDF
    {
        $rtl = self::isRtl($locale);
        $system = $di['mod_service']('system');
        $company = $system->getCompany();
        $format = strtoupper((string) $system->getParamValue('invoice_document_format', 'A4'));

        $pdf = new \TCPDF('P', 'mm', in_array($format, ['A4', 'LETTER', 'LEGAL'], true) ? $format : 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('ClientFence');
        $pdf->SetAuthor((string) ($company['name'] ?? ''));
        $pdf->SetTitle($title);
        $pdf->setRTL($rtl);
        $pdf->setLanguageArray(['a_meta_charset' => 'UTF-8', 'a_meta_dir' => $rtl ? 'rtl' : 'ltr', 'a_meta_language' => substr($locale, 0, 2), 'w_page' => '']);
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();

        return $pdf;
    }

    /** A word of Latin letters or digits, with its punctuation ("UI", "LNK-308", "-$80.00"). */
    private const LATIN_WORD = '[^\s\p{Arabic}\p{Hebrew}]*[\p{Latin}0-9][^\s\p{Arabic}\p{Hebrew}]*';
    private const DIGIT_WORD = '/[^\s\p{Arabic}\p{Hebrew}]*[0-9][^\s\p{Arabic}\p{Hebrew}]*/u';
    /** The longest run of Latin words embedded whole in mixed text. */
    private const RUN_MAX = 30;

    /**
     * Text for the document's HTML, escaped. In a right-to-left document TCPDF's bidi splits
     * "CF-00002", reverses "+20 100 000 0000" and swaps the words of "UI design", so a value with
     * no right-to-left letters (an email, a phone number, an amount) is embedded left to right as a
     * whole. In mixed text a short run of Latin words (up to RUN_MAX characters, which fits a table
     * cell) is embedded whole, and in a longer run each word with a digit ("LNK-308", "-$80.00"):
     * TCPDF cannot wrap a long embedding inside right-to-left text, so put long Latin text (a
     * payment method, a note) on a line of its own. The mark after each embedding start keeps a
     * leading sign in place.
     *
     * @return \Closure(mixed): string
     */
    public static function text(bool $rtl): \Closure
    {
        $escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $embed = static fn (string $run): string => "\u{202A}\u{200E}" . $run . "\u{202C}";

        return static function (mixed $value) use ($escape, $embed, $rtl): string {
            $value = (string) $value;
            if (!$rtl || $value === '') {
                return $escape($value);
            }
            if (preg_match('/[\p{Arabic}\p{Hebrew}]/u', $value) !== 1) {
                return $escape($embed($value));
            }

            // TCPDF infers paragraph direction from the first strong character, including an embedded LRM.
            return $escape("\u{200F}" . (string) preg_replace_callback('/' . self::LATIN_WORD . '(?:[ \t]+' . self::LATIN_WORD . ')*/u', static function (array $m) use ($embed): string {
                $run = $m[0];
                $words = preg_match('/\s/', $run) === 1;
                if ($words && mb_strlen($run) > self::RUN_MAX) {
                    return (string) preg_replace_callback(self::DIGIT_WORD, static fn (array $d): string => $embed($d[0]), $run);
                }

                // One word without digits needs no embedding.
                return $words || preg_match('/[0-9]/', $run) === 1 ? $embed($run) : $run;
            }, $value));
        };
    }

    /**
     * An amount as the client area shows it (Latin digits, Palisade's money macro), so pages, PDFs
     * and the numbers clients type agree in every language.
     */
    public static function money(\Pimple\Container $di, float $amount, string $currency): string
    {
        return (string) $di['mod_service']('currency')->formatCurrency($amount, $currency, [], 'en_US');
    }
}
