<?php

namespace App\Modules\Orders\Support;

/** Customer-facing order reference: "EM-" + 10 random Crockford base32 chars (no I, L, O, U). Not guessable. */
final class OrderReference
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function generate(): string
    {
        $out = '';
        foreach (str_split(random_bytes(10)) as $byte) {
            $out .= self::ALPHABET[ord($byte) & 31];
        }

        return 'EM-'.$out;
    }
}
