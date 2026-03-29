<?php

declare(strict_types=1);

namespace Moneo\LaravelRag\Security;

class InputSanitiser
{
    /**
     * Known prompt injection patterns to strip or neutralise.
     *
     * @var array<int, string>
     */
    protected static array $patterns = [
        // Role override attempts
        '/\brole\s*:\s*(system|assistant|user)\b/i',
        '/\bsystem\s*:\s*/i',
        '/\bassistant\s*:\s*/i',

        // Instruction override
        '/ignore\s+(all\s+)?previous\s+instructions/i',
        '/disregard\s+(all\s+)?(your\s+)?guidelines/i',
        '/forget\s+(all\s+)?(your\s+)?instructions/i',
        '/override\s+(your\s+)?instructions/i',
        '/bypass\s+(your\s+)?instructions/i',
        '/ignore\s+(your\s+)?instructions/i',
        '/do\s+not\s+follow\s+(your\s+)?instructions/i',

        // Jailbreak markers
        '/\[INST\].*?\[\/INST\]/is',
        '/\[SYSTEM\].*?\[\/SYSTEM\]/is',
        '/<\|im_start\|>.*?<\|im_end\|>/is',
        '/<\|system\|>.*?<\|end\|>/is',
        '/<<SYS>>.*?<<\/SYS>>/is',

        // DAN / persona switching
        '/\bDAN\s+mode\b/i',
        '/\byou\s+are\s+now\b/i',
        '/\bact\s+as\s+if\b/i',
        '/\bpretend\s+(you\s+are|to\s+be)\b/i',
        '/\bswitch\s+to\s+.*\s+mode\b/i',
        '/\benable\s+.*\s+mode\b/i',

        // Prompt leaking
        '/\brepeat\s+(your\s+)?(system\s+)?prompt\b/i',
        '/\bshow\s+(me\s+)?(your\s+)?(system\s+)?prompt\b/i',
        '/\bprint\s+(your\s+)?(system\s+)?prompt\b/i',
        '/\bwhat\s+(is|are)\s+(your\s+)?instructions\b/i',
        '/\bwhat\s+is\s+your\s+system\s+prompt\b/i',
        '/\boutput\s+(your\s+)?initial\s+instructions\b/i',

        // Encoded injection (base64 markers)
        '/\bbase64\s*decode\b/i',
        '/\beval\s*\(/i',

        // Markdown/HTML injection into prompts
        '/```\s*(system|role|instruction)/i',

        // Multi-turn manipulation
        '/\bignore\s+the\s+above\b/i',
        '/\bignore\s+everything\s+above\b/i',
        '/\bignore\s+all\s+of\s+the\s+above\b/i',
        '/\bnew\s+instructions\s*:/i',
        '/\bupdated\s+instructions\s*:/i',

        // Tool/function call injection
        '/\bcall\s+function\b/i',
        '/\bexecute\s+tool\b/i',
    ];

    /**
     * Sanitise user input before passing it to an LLM.
     *
     * Strips known prompt injection patterns and normalises whitespace.
     *
     * @param  string  $input  The raw user input
     * @return string  The sanitised input
     */
    public static function clean(string $input): string
    {
        $cleaned = $input;

        foreach (self::$patterns as $pattern) {
            $cleaned = (string) preg_replace($pattern, '', $cleaned);
        }

        // Normalise whitespace (collapse multiple spaces/newlines)
        $cleaned = (string) preg_replace('/\s{3,}/', "\n\n", $cleaned);

        return trim($cleaned);
    }

    /**
     * Check if input contains any injection patterns.
     *
     * @param  string  $input  The raw input to check
     * @return bool  True if injection patterns are detected
     */
    public static function containsInjection(string $input): bool
    {
        foreach (self::$patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the list of patterns (for testing).
     *
     * @return array<int, string>
     */
    public static function getPatterns(): array
    {
        return self::$patterns;
    }
}
