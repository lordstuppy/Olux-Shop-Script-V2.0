<?php

namespace App\Support;

use Symfony\Component\Finder\Finder;

/**
 * Collects the translation keys used in the source so lang/en.json lists
 * every user-facing string. A translator copies lang/en.json to
 * lang/<locale>.json and translates the values.
 *
 * Keys are the English text. Only single-quoted literals passed to __(),
 * trans(), trans_choice() and @lang() are recognised, plus every enum label
 * (App\Enums\Concerns\HasLabel). Keys that point at a lang/en/<group>.php
 * file ("validation.required") are left to those files.
 */
class TranslationCatalog
{
    private const CALL = '/(?<![\w>$:])(?:__|trans|trans_choice|@lang)\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/';

    /** @return array<string, string> key => English text, sorted by key */
    public static function extract(): array
    {
        $keys = [];
        $finder = (new Finder)->files()->in([app_path(), resource_path('views'), base_path('routes')])->name('*.php');
        foreach ($finder as $file) {
            preg_match_all(self::CALL, $file->getContents(), $matches);
            foreach ($matches[1] as $raw) {
                // Single-quoted PHP literal: only \\ and \' are escapes.
                $key = preg_replace('/\\\\([\\\\\'])/', '$1', $raw);
                if (! self::isGroupKey($key)) {
                    $keys[$key] = $key;
                }
            }
        }

        foreach ((new Finder)->files()->in(app_path('Enums'))->depth(0)->name('*.php') as $file) {
            $class = 'App\\Enums\\'.$file->getBasename('.php');
            if (! enum_exists($class) || ! method_exists($class, 'labelKey')) {
                continue;
            }
            foreach ($class::cases() as $case) {
                $keys[$case->labelKey()] = $case->labelKey();
            }
        }

        ksort($keys, SORT_STRING);

        return $keys;
    }

    public static function path(): string
    {
        return lang_path('en.json');
    }

    public static function encode(array $keys): string
    {
        return json_encode($keys, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    private static function isGroupKey(string $key): bool
    {
        if (! preg_match('/^([a-z_]+)\.[a-z0-9_.]+$/', $key, $m)) {
            return false;
        }

        return is_file(lang_path('en/'.$m[1].'.php'));
    }
}
