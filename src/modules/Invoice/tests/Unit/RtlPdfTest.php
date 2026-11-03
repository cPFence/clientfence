<?php

declare(strict_types=1);

use ClientFence\Mod\Invoice\RtlPdf;

test('RTL PDF glyphs keep numbers, invoice IDs and signed amounts in order', function (string $address, string $label, string $visualLabel, bool $joinsLetters): void {
    $text = RtlPdf::text(true);
    $visual = [];
    foreach ([$address, $label . ': 752-267-353', $label . ': CF-00002 / -$80.00'] as $line) {
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->setRTL(true);
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->AddPage();
        $pdf->Cell(0, 10, html_entity_decode($text($line), ENT_QUOTES, 'UTF-8'));

        $commands = (new ReflectionMethod(TCPDF::class, 'getPageBuffer'))->invoke($pdf, 1);
        preg_match('/\[\((.*?)\)\] TJ/s', $commands, $match);
        $glyphs = mb_convert_encoding(strtr($match[1], ['\\(' => '(', '\\)' => ')', '\\\\' => '\\', '\\r' => "\r"]), 'UTF-8', 'UTF-16BE');
        if ($joinsLetters) {
            expect($glyphs)->toMatch('/[\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFC}]/u');
        }
        $visual[] = trim(str_replace(["\u{200E}", "\u{200F}"], '', Normalizer::normalize($glyphs, Normalizer::FORM_KC)));
    }

    expect($visual[0])->toEndWith('7440')
        ->and($visual[1])->toStartWith('752-267-353 ')
        ->and($visual[1])->toEndWith($visualLabel)
        ->and($visual[2])->toContain('CF-00002')
        ->and($visual[2])->toContain('-$80.00');
})->with([
    'Arabic' => ['7440 – حي الكوثر – مكة المكرمة', 'الرقم الضريبي', 'مقرلا', true],
    'Hebrew' => ['7440 – רחוב הרצל – תל אביב', 'מספר מס', 'רפסמ', false],
    'Persian' => ['7440 – خیابان آزادی – تهران', 'شماره مالیاتی', 'هرامش', true],
]);
