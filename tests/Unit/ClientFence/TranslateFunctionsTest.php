<?php

declare(strict_types=1);

/**
 * ClientFence: Translate.php declares the global __trans() and __pluralTrans() as well as the
 * ClientFence\Translate class. The tests define their own __trans() (tests/Pest.php), so this reads
 * the file: the helpers must stay in the global namespace block, or every page fails with
 * "Call to undefined function ClientFence\...\__trans()".
 */
test('the translation helpers are declared in the global namespace', function (): void {
    $tokens = token_get_all((string) file_get_contents(dirname(PATH_TESTS) . '/src/library/ClientFence/Translate.php'));
    $namespace = null;
    $declared = [];
    foreach ($tokens as $i => $token) {
        if (!is_array($token)) {
            continue;
        }
        if ($token[0] === T_NAMESPACE) {
            $name = '';
            for ($j = $i + 1; isset($tokens[$j]) && $tokens[$j] !== '{' && $tokens[$j] !== ';'; ++$j) {
                $name .= is_array($tokens[$j]) ? trim($tokens[$j][1]) : '';
            }
            $namespace = $name;
        }
        if ($token[0] === T_FUNCTION) {
            for ($j = $i + 1; isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE; ++$j) {
            }
            if (is_array($tokens[$j] ?? null) && in_array($tokens[$j][1], ['__trans', '__pluralTrans'], true)) {
                $declared[$tokens[$j][1]] = $namespace;
            }
        }
    }

    expect($declared)->toBe(['__trans' => '', '__pluralTrans' => '']);
});
