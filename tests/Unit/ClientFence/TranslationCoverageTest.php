<?php

declare(strict_types=1);

use PhpMyAdmin\MoTranslator\Translator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

test('shipped catalogues translate every template text', function (string $language): void {
    $template = (new Filesystem())->readFile(PATH_THEMES . '/palisade/locale/clientfence.pot');
    preg_match_all('/^msgid (".*"(?:\n".*")*)/m', $template, $matches);
    $msgids = [];
    foreach ($matches[1] as $quoted) {
        $msgid = stripcslashes(substr(str_replace("\"\n\"", '', $quoted), 1, -1));
        if ($msgid !== '') {
            $msgids[] = $msgid;
        }
    }

    $translations = [];
    $reader = new Translator(PATH_LANGS . '/' . $language . '/LC_MESSAGES/messages.mo');
    foreach ($reader->getTranslations() as $msgid => $translation) {
        $translations[explode("\0", (string) $msgid, 2)[0]] = explode("\0", $translation, 2)[0];
    }
    $missing = array_filter($msgids, static fn (string $msgid): bool => ($translations[$msgid] ?? '') === '');

    $this->assertCount(0, $missing, $language . ': missing translations: ' . implode(' | ', array_slice($missing, 0, 5)));
})->with(function (): Generator {
    $catalogues = Finder::create()->files()->in(PATH_THEMES . '/palisade/locale')->name('clientfence.po')->depth(1)->sortByName();
    foreach ($catalogues as $catalogue) {
        $language = $catalogue->getRelativePath();

        yield $language => [$language];
    }
});
