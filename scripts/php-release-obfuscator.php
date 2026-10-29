<?php

// Conservative source obfuscation, not encryption. No eval or remote decoder.
function obfuscateReleasePhp(string $source): string
{
    $output = '';
    foreach (token_get_all($source, TOKEN_PARSE) as $token) {
        if (!is_array($token)) { $output .= $token; continue; }
        [$type, $text] = $token;
        if (in_array($type, [T_COMMENT, T_DOC_COMMENT], true)) { $output .= ' '; continue; }
        if ($type === T_WHITESPACE) { $output .= ' '; continue; }
        if ($type === T_CONSTANT_ENCAPSED_STRING && str_starts_with($text, "'")) {
            $value = substr($text, 1, -1);
            // Only two escape sequences have special meaning in single-quoted PHP literals.
            $decoded = '';
            for ($i = 0; $i < strlen($value); $i++) {
                if ($value[$i] === '\\' && isset($value[$i + 1]) && in_array($value[$i + 1], ["'", '\\'], true)) $i++;
                $decoded .= $value[$i];
            }
            $text = '"'.implode('', array_map(fn ($byte) => sprintf('\\x%02x', ord($byte)), str_split($decoded))).'"';
        }
        $output .= $text;
    }
    // Verify output is still syntactically valid before packing.
    token_get_all($output, TOKEN_PARSE);
    return $output;
}
