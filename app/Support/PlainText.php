<?php

namespace App\Support;

/**
 * Cleans text that members type. Antlers templates print field values as they are (no escaping),
 * so anything from a member is stored without HTML.
 */
class PlainText
{
    /** One line: no HTML, no angle brackets, single spaces. */
    public static function line(?string $value): string
    {
        $value = str_replace(['<', '>'], '', strip_tags((string) $value));

        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    /** Text that keeps its line breaks (at most one empty line in a row). */
    public static function lines(?string $value): string
    {
        $value = str_replace(['<', '>'], '', strip_tags(str_replace("\r\n", "\n", (string) $value)));
        $value = preg_replace(['/[^\S\n]+/u', '/ *\n */', '/\n{3,}/'], [' ', "\n", "\n\n"], $value);

        return trim($value);
    }

    /** A link (map, video): nothing that could break out of an href. Check it is http(s) before. */
    public static function link(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : str_replace(['"', "'", '<', '>', ' ', '`'], ['%22', '%27', '%3C', '%3E', '%20', '%60'], $value);
    }
}
