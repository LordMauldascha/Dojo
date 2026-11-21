<?php

declare(strict_types=1);

namespace App\Skill;

/**
 * The YAML front matter of a SKILL.md, derived from title and description. assets/js/services/skillHeader.ts
 * implements the same rules for the live preview; keep both in sync.
 */
final class SkillHeader
{
    private const UMLAUTS = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue', 'ß' => 'ss'];

    /** A description that needs the block form, else YAML would read it as a mapping, a comment or an indicator. */
    private const NEEDS_BLOCK = '/: | #|:\z|\A[\[\]{}&*!|>\x27"%@`#?,:-]/';

    /** Null when the title contains anything but letters, digits, spaces and hyphens (after umlaut replacement). */
    public static function name(string $title): ?string
    {
        $words = preg_split('/\s+/u', mb_strtolower(strtr($title, self::UMLAUTS)), -1, \PREG_SPLIT_NO_EMPTY);
        $name = implode('-', false === $words ? [] : $words);

        return 1 === preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $name) ? $name : null;
    }

    public static function render(?string $title, ?string $description): string
    {
        return "---\nname: ".(self::name($title ?? '') ?? '')."\n".self::descriptionEntry($description ?? '')."\n---";
    }

    private static function descriptionEntry(string $description): string
    {
        $lines = [];
        foreach (preg_split('/\R/u', $description) ?: [] as $line) {
            $line = preg_replace('/\A\s+|\s+\z/u', '', $line) ?? '';
            if ('' !== $line) {
                $lines[] = $line;
            }
        }

        if ([] === $lines) {
            return 'description: ""';
        }

        $block = 1 === preg_match(self::NEEDS_BLOCK, implode(' ', $lines));
        if ($block) {
            return "description: >-\n  ".implode("\n  ", $lines);
        }

        return 'description: '.implode("\n  ", $lines);
    }
}
