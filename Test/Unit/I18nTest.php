<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\AstoundAffirm\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The shipped dictionaries translate exactly the phrases the module's PHP and templates pass to __().
 */
class I18nTest extends TestCase
{
    private const SOURCE = __DIR__ . '/../../src';
    private const LOCALES = ['en_US', 'fr_CA'];

    public function testDictionariesCoverEveryPhrase(): void
    {
        $phrases = $this->collectPhrases();
        $this->assertNotEmpty($phrases);

        foreach (self::LOCALES as $locale) {
            $dictionary = $this->dictionary($locale);
            $this->assertSame($phrases, array_keys($dictionary), $locale . ': phrases missing or unused');

            foreach ($dictionary as $phrase => $translation) {
                $this->assertNotSame('', trim($translation), $locale . ': ' . $phrase);
                $this->assertSame($this->placeholders($phrase), $this->placeholders($translation), $locale);
                if ($locale === 'en_US') {
                    $this->assertSame($phrase, $translation);
                }
            }
        }
    }

    /**
     * Literal first arguments of __() in every PHP and PHTML file, sorted.
     *
     * @return string[]
     */
    private function collectPhrases(): array
    {
        $phrases = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SOURCE));
        foreach ($files as $file) {
            if (!$file->isFile() || !in_array($file->getExtension(), ['php', 'phtml'], true)) {
                continue;
            }
            $tokens = array_values(array_filter(
                token_get_all((string) file_get_contents($file->getPathname())),
                static fn ($token): bool => !is_array($token) || $token[0] !== T_WHITESPACE
            ));
            foreach ($tokens as $index => $token) {
                $literal = $tokens[$index + 2] ?? null;
                if (is_array($token) && $token[0] === T_STRING && $token[1] === '__'
                    && ($tokens[$index + 1] ?? null) === '('
                    && is_array($literal) && $literal[0] === T_CONSTANT_ENCAPSED_STRING
                ) {
                    $phrases[] = $this->unquote($literal[1]);
                }
            }
        }
        $phrases = array_values(array_unique($phrases));
        sort($phrases);

        return $phrases;
    }

    /**
     * @return array<string, string>
     */
    private function dictionary(string $locale): array
    {
        $file = self::SOURCE . '/i18n/' . $locale . '.csv';
        $this->assertFileExists($file);
        $handle = fopen($file, 'rb');
        $this->assertNotFalse($handle);
        $dictionary = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $this->assertCount(2, $row, $locale);
            $this->assertArrayNotHasKey((string) $row[0], $dictionary, $locale . ': duplicate phrase');
            $dictionary[(string) $row[0]] = (string) $row[1];
        }
        fclose($handle);
        ksort($dictionary);

        return $dictionary;
    }

    private function unquote(string $literal): string
    {
        $inner = substr($literal, 1, -1);

        return $literal[0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $inner) : stripcslashes($inner);
    }

    /**
     * @return string[]
     */
    private function placeholders(string $text): array
    {
        preg_match_all('/%\d+/', $text, $matches);
        $placeholders = $matches[0];
        sort($placeholders);

        return $placeholders;
    }
}
