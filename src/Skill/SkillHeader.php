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

    /** Longest `name` the Agent Skills specification allows (https://agentskills.io/specification). */
    public const MAX_NAME_LENGTH = 64;

    /** A description that needs the block form, else YAML would read it as a mapping, a comment or an indicator. */
    private const NEEDS_BLOCK = '/: | #|:\z|\A[\[\]{}&*!|>\x27"%@`#?,:-]/';

    /** A plain scalar that YAML 1.1 reads as a boolean, null or number; only the block form keeps it a string. */
    private const READ_AS_NON_STRING = '/\A(?:true|false|yes|no|on|off|null|~|[-+]?(?:0x[0-9a-f_]+|0b[01_]+|0o[0-7_]+|(?:[0-9][0-9_]*(?:\.[0-9_]*)?|\.[0-9][0-9_]*)(?:e[-+]?[0-9]+)?|[0-9][0-9_]*(?::[0-5]?[0-9])+(?:\.[0-9_]*)?|\.(?:inf|nan)))\z/i';

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

        $text = implode(' ', $lines);
        if (1 === preg_match(self::NEEDS_BLOCK, $text) || 1 === preg_match(self::READ_AS_NON_STRING, $text)) {
            return "description: >-\n  ".implode("\n  ", $lines);
        }

        return 'description: '.implode("\n  ", $lines);
    }
}
