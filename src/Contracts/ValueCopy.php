<?php

declare(strict_types=1);

namespace SeoLab\Contracts;

/**
 * Copia per valore dei dati composti al confine dei DTO. Rimuove i riferimenti PHP in ogni livello,
 * rifiuta oggetti, risorse e strutture cicliche o troppo profonde.
 */
final class ValueCopy
{
    private const MAX_DEPTH = 32;

    /**
     * @param mixed $value
     * @return mixed
     */
    public static function detach($value, string $field, int $depth = 0)
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException($field . ': struttura ciclica o troppo profonda');
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = self::detach($item, $field, $depth + 1);
            }
            return $out;
        }
        if (is_object($value)) {
            throw new \InvalidArgumentException($field . ': oggetto non ammesso nei dati');
        }
        if (is_resource($value)) {
            throw new \InvalidArgumentException($field . ': risorsa non ammessa nei dati');
        }
        return $value;
    }

    /**
     * Lista sequenziale (chiavi 0..n-1 in ordine), senza usare array_is_list di PHP 8.1.
     *
     * @param mixed $value
     */
    public static function isList($value): bool
    {
        return is_array($value) && array_values($value) === $value;
    }

    /**
     * Forma canonica per confronti indipendenti dall'ordine delle chiavi delle mappe.
     *
     * @param mixed $value
     */
    public static function canonical($value): string
    {
        return serialize(self::sortKeys($value));
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function sortKeys($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = self::sortKeys($item);
        }
        if (!self::isList($out)) {
            ksort($out);
        }
        return $out;
    }

    private function __construct()
    {
    }
}
