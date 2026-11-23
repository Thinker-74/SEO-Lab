<?php

declare(strict_types=1);

namespace SeoLab\Storage;

use SeoLab\Ai\ReviewConfirmation;

/**
 * Registro delle richieste AI del sito (P1.2): riserva atomica prima dell'invio, quote per sito e per utente sul
 * giorno UTC, cooldown dell'utente e replay bloccato per digest della conferma. Il tentativo ammesso resta conteggiato
 * anche su errore o timeout: nessun retry e nessun rimborso. Ogni esito negativo e' un codice chiuso; uno stato
 * corrotto, un orologio arretrato o un conflitto non risolto chiudono con budget_exhausted.
 */
final class WordPressAiLedger
{
    public const VERSION = 1;
    public const MAX_ROWS = 40;
    public const MAX_ATTEMPTS = 3;
    public const COOLDOWN_SECONDS = 10;
    public const MARGIN_UNITS = 1024;
    public const SITE_DAILY_REQUESTS = 20;
    public const SITE_DAILY_UNITS = 65536;
    public const USER_DAILY_REQUESTS = 5;
    public const USER_DAILY_UNITS = 16384;

    private const DAY_SECONDS = 86400;
    private const HASH_PATTERN = '/\A[0-9a-f]{64}\z/';

    /** @var AiLedgerStore */
    private $store;

    public function __construct(AiLedgerStore $store)
    {
        $this->store = $store;
    }

    /**
     * Stima operativa della riserva: byte UTF-8 di system e data piu' il margine fisso. Non e' la fattura.
     *
     * @param array{system: string, data: string} $prompt
     */
    public static function estimateUnits(array $prompt): int
    {
        return strlen($prompt['system']) + strlen($prompt['data']) + self::MARGIN_UNITS;
    }

    /**
     * @return string|null null se ammesso e riservato, altrimenti rate_limited o budget_exhausted
     */
    public function admit(int $siteId, int $userId, string $confirmationId, int $issuedAt, int $now, int $units): ?string
    {
        if ($siteId < 1 || $userId < 1 || $units < 1 || preg_match(ReviewConfirmation::ID_PATTERN, $confirmationId) !== 1) {
            return 'budget_exhausted';
        }
        $hash = hash('sha256', $confirmationId);
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $raw = $this->store->read();
            } catch (\Throwable $e) {
                return 'budget_exhausted';
            }
            $rows = $raw === null ? [] : self::decode($raw);
            $rows = $rows === null ? null : self::live($rows, $now);
            if ($rows === null) {
                return 'budget_exhausted';
            }
            $verdict = self::check($rows, $userId, $hash, $units, $now);
            if ($verdict !== null) {
                return $verdict;
            }

            $rows[] = [$userId, $hash, $issuedAt, $now, $units];
            $rows = self::bounded($rows);
            $encoded = self::encode($rows);
            try {
                $written = $raw === null
                    ? $this->store->insertIgnore($encoded)
                    : $this->store->compareAndSwap($raw, $encoded);
            } catch (\Throwable $e) {
                return 'budget_exhausted';
            }
            if ($written === 1) {
                return null;
            }
            if ($written !== 0) {
                return 'budget_exhausted';
            }
        }
        return 'budget_exhausted';
    }

    /**
     * Righe ancora rilevanti: ammesse oggi (UTC) oppure con conferma ancora valida (replay, cooldown). Una riga con
     * istante futuro significa orologio arretrato: null, cioe' rifiuto.
     *
     * @param array<int, array{0: int, 1: string, 2: int, 3: int, 4: int}> $rows
     * @return array<int, array{0: int, 1: string, 2: int, 3: int, 4: int}>|null
     */
    private static function live(array $rows, int $now): ?array
    {
        $dayStart = $now - ($now % self::DAY_SECONDS);
        $kept = [];
        foreach ($rows as $row) {
            if ($row[2] > $now || $row[3] > $now) {
                return null;
            }
            // Oltre la scadenza della conferma resta la finestra di cooldown: una riga di ieri ancora in cooldown non va persa.
            if ($row[3] >= $dayStart || $row[2] + ReviewConfirmation::TTL_SECONDS >= $now || $row[3] + self::COOLDOWN_SECONDS > $now) {
                $kept[] = $row;
            }
        }
        return $kept;
    }

    /**
     * Controlli su righe ancora rilevanti: replay, cooldown, quote del giorno. Null se il tentativo passa.
     *
     * @param array<int, array{0: int, 1: string, 2: int, 3: int, 4: int}> $rows
     */
    private static function check(array $rows, int $userId, string $hash, int $units, int $now): ?string
    {
        $dayStart = $now - ($now % self::DAY_SECONDS);
        $userCount = 0;
        $userUnits = 0;
        $userLast = null;
        $siteCount = 0;
        $siteUnits = 0;
        foreach ($rows as $row) {
            if ($row[1] === $hash) {
                return 'rate_limited';
            }
            if ($row[0] === $userId && ($userLast === null || $row[3] > $userLast)) {
                $userLast = $row[3];
            }
            if ($row[3] < $dayStart) {
                continue;
            }
            $siteCount++;
            $siteUnits += $row[4];
            if ($row[0] === $userId) {
                $userCount++;
                $userUnits += $row[4];
            }
        }
        if ($userLast !== null && $now - $userLast < self::COOLDOWN_SECONDS) {
            return 'rate_limited';
        }
        if ($userCount + 1 > self::USER_DAILY_REQUESTS || $userUnits + $units > self::USER_DAILY_UNITS) {
            return 'rate_limited';
        }
        if ($siteCount + 1 > self::SITE_DAILY_REQUESTS || $siteUnits + $units > self::SITE_DAILY_UNITS) {
            return 'budget_exhausted';
        }
        return null;
    }

    /**
     * Tiene al massimo MAX_ROWS righe, le piu' recenti per istante di ammissione.
     *
     * @param array<int, array{0: int, 1: string, 2: int, 3: int, 4: int}> $rows
     * @return array<int, array{0: int, 1: string, 2: int, 3: int, 4: int}>
     */
    private static function bounded(array $rows): array
    {
        usort($rows, static function (array $a, array $b): int {
            return $a[3] <=> $b[3];
        });
        if (count($rows) > self::MAX_ROWS) {
            $rows = array_slice($rows, count($rows) - self::MAX_ROWS);
        }
        return array_values($rows);
    }

    /**
     * Forma rigida: versione, righe a cinque campi. Qualunque scostamento e' stato corrotto.
     *
     * @return array<int, array{0: int, 1: string, 2: int, 3: int, 4: int}>|null
     */
    private static function decode(string $raw): ?array
    {
        $data = json_decode($raw, true);
        if (!is_array($data) || ($data['v'] ?? null) !== self::VERSION || !isset($data['rows']) || !is_array($data['rows'])) {
            return null;
        }
        $rows = [];
        foreach ($data['rows'] as $row) {
            if (!is_array($row) || count($row) !== 5 || array_values($row) !== $row) {
                return null;
            }
            [$user, $hash, $issued, $admitted, $units] = $row;
            if (!is_int($user) || !is_string($hash) || preg_match(self::HASH_PATTERN, $hash) !== 1
                || !is_int($issued) || !is_int($admitted) || !is_int($units) || $user < 1 || $issued < 0 || $admitted < 0 || $units < 1) {
                return null;
            }
            $rows[] = [$user, $hash, $issued, $admitted, $units];
        }
        return $rows;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int, 3: int, 4: int}> $rows
     */
    private static function encode(array $rows): string
    {
        return (string) json_encode(['v' => self::VERSION, 'rows' => array_values($rows)], JSON_UNESCAPED_SLASHES);
    }
}
