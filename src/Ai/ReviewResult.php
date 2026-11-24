<?php

declare(strict_types=1);

namespace SeoLab\Ai;

/**
 * Esito di una revisione AI opzionale (P1.0): item validati contro la richiesta, oppure un codice di errore
 * stabile senza messaggi, eccezioni o corpi di risposta. Il testo restituito e' dato non affidabile: P1.2 lo
 * mostrera' solo con escaping testuale.
 */
final class ReviewResult
{
    public const ERROR_CODES = [
        'disabled',
        'unsupported_runtime',
        'core_disabled',
        'unconfigured',
        'model_unavailable',
        'invalid_input',
        'invalid_response',
        'timeout',
        'rate_limited',
        'budget_exhausted',
        'provider_error',
        'output_truncated',
    ];

    private const KINDS = ['explanation', 'hypothesis', 'suggested_test'];
    private const ROOT_KEYS = ['items', 'schema_version'];
    private const ITEM_KEYS = ['kind', 'refs', 'text'];
    private const MAX_JSON_BYTES = 12288;
    private const MAX_DEPTH = 8;
    private const MAX_ITEMS = 8;
    private const MAX_ITEM_TEXT_BYTES = 768;
    private const MAX_REFS = 4;

    /** @var string|null */
    private $code;

    /** @var array<int, array<string, mixed>> */
    private $items;

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function __construct(?string $code, array $items)
    {
        $this->code = $code;
        $this->items = $items;
    }

    /**
     * @throws \InvalidArgumentException codice fuori dal set chiuso
     */
    public static function failure(string $code): self
    {
        if (!in_array($code, self::ERROR_CODES, true)) {
            throw new \InvalidArgumentException('codice di errore non previsto');
        }
        return new self($code, []);
    }

    /**
     * Valida il testo di risposta contro la richiesta. Ogni violazione produce invalid_response senza item.
     */
    public static function fromJson(string $json, ReviewRequest $request): self
    {
        $items = self::validItems($json, $request);
        if ($items === null) {
            return self::failure('invalid_response');
        }
        return new self(null, $items);
    }

    public function code(): ?string
    {
        return $this->code;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private static function validItems(string $json, ReviewRequest $request): ?array
    {
        if (strlen($json) > self::MAX_JSON_BYTES || preg_match('//u', $json) !== 1) {
            return null;
        }

        $root = json_decode($json, false, self::MAX_DEPTH);
        if (json_last_error() !== JSON_ERROR_NONE || !is_object($root) || !self::hasKeys($root, self::ROOT_KEYS)) {
            return null;
        }
        if ($root->schema_version !== ReviewRequest::SCHEMA_VERSION || !is_array($root->items)) {
            return null;
        }
        $count = count($root->items);
        if ($count < 1 || $count > self::MAX_ITEMS) {
            return null;
        }

        $refIds = $request->referenceIds();
        $items = [];
        $kinds = [];
        foreach ($root->items as $raw) {
            $item = self::validItem($raw, $refIds);
            if ($item === null) {
                return null;
            }
            $kinds[] = $item['kind'];
            $items[] = $item;
        }
        if (!in_array('explanation', $kinds, true) || !in_array('suggested_test', $kinds, true)) {
            return null;
        }

        return $items;
    }

    /**
     * @param mixed    $raw
     * @param string[] $refIds
     * @return array<string, mixed>|null
     */
    private static function validItem($raw, array $refIds): ?array
    {
        if (!is_object($raw) || !self::hasKeys($raw, self::ITEM_KEYS)) {
            return null;
        }
        if (!is_string($raw->kind) || !in_array($raw->kind, self::KINDS, true)) {
            return null;
        }
        if (!is_string($raw->text) || !self::isValidText($raw->text)) {
            return null;
        }
        if (!is_array($raw->refs) || !self::isValidRefs($raw->refs, $refIds)) {
            return null;
        }

        return ['kind' => $raw->kind, 'text' => $raw->text, 'refs' => array_values($raw->refs)];
    }

    private static function isValidText(string $text): bool
    {
        if (strlen($text) > self::MAX_ITEM_TEXT_BYTES || trim($text) === '') {
            return false;
        }
        return preg_match('/[\x00-\x1F\x7F]/', $text) !== 1;
    }

    /**
     * @param array<int, mixed> $refs
     * @param string[]          $refIds
     */
    private static function isValidRefs(array $refs, array $refIds): bool
    {
        if (count($refs) < 1 || count($refs) > self::MAX_REFS) {
            return false;
        }
        $seen = [];
        foreach ($refs as $ref) {
            if (!is_string($ref) || !in_array($ref, $refIds, true) || isset($seen[$ref])) {
                return false;
            }
            $seen[$ref] = true;
        }
        return true;
    }

    /**
     * Chiavi esatte dell'oggetto, senza mancanti ne' extra.
     *
     * @param object   $object
     * @param string[] $keys
     */
    private static function hasKeys($object, array $keys): bool
    {
        $actual = array_map('strval', array_keys(get_object_vars($object)));
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        return $actual === $keys;
    }
}
