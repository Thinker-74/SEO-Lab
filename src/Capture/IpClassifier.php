<?php

declare(strict_types=1);

namespace SeoLab\Capture;

/**
 * Classificazione degli indirizzi risolti per l'origine. Usa confronti binari CIDR, non i flag di filter_var,
 * per dare lo stesso esito su PHP 7.4 e 8.3. Un indirizzo e' pubblico solo se non cade in un blocco riservato.
 * IPv6 e' pubblico solo in 2000::/3, escludendo 2001::/23 (speciali IETF), 2001:db8::/32 (documentazione)
 * e 2002::/16 (6to4, tenuto fuori per prudenza).
 */
final class IpClassifier
{
    private const RESERVED_V4 = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    private const GLOBAL_V6 = '2000::/3';

    private const RESERVED_V6 = [
        '2001::/23',
        '2001:db8::/32',
        '2002::/16',
        '3fff::/20',
    ];

    public static function isPublic(string $ip): bool
    {
        $bin = inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        if (strlen($bin) === 4) {
            return !self::matchesAny($bin, self::RESERVED_V4);
        }
        if (strlen($bin) !== 16) {
            return false;
        }
        if (substr($bin, 0, 10) === str_repeat("\x00", 10) && substr($bin, 10, 2) === "\xff\xff") {
            return !self::matchesAny(substr($bin, 12), self::RESERVED_V4);
        }
        if (!self::matches($bin, self::GLOBAL_V6)) {
            return false;
        }
        return !self::matchesAny($bin, self::RESERVED_V6);
    }

    /**
     * @param string[] $cidrs
     */
    private static function matchesAny(string $bin, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if (self::matches($bin, $cidr)) {
                return true;
            }
        }
        return false;
    }

    private static function matches(string $bin, string $cidr): bool
    {
        $parts = explode('/', $cidr);
        $network = inet_pton($parts[0]);
        $bits = (int) $parts[1];
        if ($network === false || strlen($network) !== strlen($bin)) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        $rest = $bits % 8;
        if (substr($bin, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($bin[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }

    private function __construct()
    {
    }
}
