<?php

declare(strict_types=1);

namespace Osmium\Services\AioAudit\Models;

/**
 * "What was checked": one row per check in the catalogue saying whether it passed, failed or could not run, and why.
 * A fail comes from the run's findings. A pass or a skip comes from the coverage the run recorded, so a run saved
 * before coverage was kept shows its non-failing checks as unknown instead of guessing. Pure: takes arrays.
 */
class CheckSummary
{
    public const PASS = 'pass';
    public const FAIL = 'fail';
    public const SKIPPED = 'skipped';
    public const UNKNOWN = 'unknown';

    /** Status => word, Boxicon and colour class, and the order a status list sorts in */
    private const STATUS = [
        self::FAIL => ['label' => 'Fail', 'icon' => 'bxs-x-circle', 'class' => 'text-danger', 'rank' => 0],
        self::SKIPPED => ['label' => 'Skipped', 'icon' => 'bxs-minus-circle', 'class' => 'text-muted', 'rank' => 1],
        self::UNKNOWN => ['label' => 'Unknown', 'icon' => 'bxs-help-circle', 'class' => 'text-muted', 'rank' => 2],
        self::PASS => ['label' => 'Pass', 'icon' => 'bxs-check-circle', 'class' => 'text-success', 'rank' => 3],
    ];

    /** @return array{live: bool, robots: bool, sitemapPages: int, htmlPages: int, home: bool}|null null when the run recorded none */
    public static function decode(mixed $stored): ?array
    {
        $decoded = \is_string($stored) ? \json_decode($stored, associative: true) : null;
        $isValid = \is_array($decoded) && \array_key_exists('live', $decoded);
        if (!$isValid) return null;

        return [
            'live' => (bool) $decoded['live'], 'robots' => (bool) ($decoded['robots'] ?? false),
            'sitemapPages' => (int) ($decoded['sitemapPages'] ?? 0), 'htmlPages' => (int) ($decoded['htmlPages'] ?? 0),
            'home' => (bool) ($decoded['home'] ?? false),
        ];
    }

    /**
     * @param array<int, array{check_key: string, page_url: ?string, detail: ?string}> $findings
     * @param array{live: bool, robots: bool, sitemapPages: int, htmlPages: int, home: bool}|null $coverage
     * @return array<int, array<string, mixed>> one row per catalogue check, in catalogue order
     */
    public static function rows(array $findings, ?array $coverage): array
    {
        $byCheck = [];
        foreach ($findings as $finding) {
            $byCheck[$finding['check_key']][] = $finding;
        }

        $rows = [];
        $position = 0;
        foreach (Checks::CATALOGUE as $checkKey => $check) {
            [$status, $detail] = self::outcome($checkKey, $byCheck[$checkKey] ?? [], $coverage);
            $rows[] = [
                'id' => ++$position, 'check_key' => $checkKey, 'label' => $check['label'], 'group' => $check['group'],
                'status' => $status, 'detail' => $detail, 'statusLabel' => self::STATUS[$status]['label'],
                'statusIcon' => self::STATUS[$status]['icon'], 'statusClass' => self::STATUS[$status]['class'],
                'statusRank' => self::STATUS[$status]['rank'],
            ];
        }

        return $rows;
    }

    /** @return array{0: string, 1: string} status and detail */
    private static function outcome(string $checkKey, array $hits, ?array $coverage): array
    {
        if ($hits !== []) return [self::FAIL, self::failDetail($checkKey, $hits)];
        if ($coverage === null) return [self::UNKNOWN, 'Coverage was not recorded for this run'];

        return self::coveredOutcome($checkKey, $coverage);
    }

    /** @param array<int, array{page_url: ?string, detail: ?string}> $hits */
    private static function failDetail(string $checkKey, array $hits): string
    {
        $isBotCheck = Checks::botsFor($checkKey) !== [];
        if ($isBotCheck) return 'Blocked: ' . \implode(', ', \array_map(fn(array $hit): string => \strstr((string) $hit['detail'], ' (', true) ?: (string) $hit['detail'], $hits));

        $pages = \count(\array_unique(\array_column($hits, 'page_url')));

        return $pages === 1 ? '1 page affected' : "{$pages} pages affected";
    }

    /** @return array{0: string, 1: string} */
    private static function coveredOutcome(string $checkKey, array $coverage): array
    {
        $bots = Checks::botsFor($checkKey);
        if ($bots !== []) return self::botOutcome($bots, $coverage);

        return match ($checkKey) {
            'page_error' => self::counted($coverage['sitemapPages'], 'sitemap page', 'No sitemap pages in this run'),
            'org_schema_missing' => $coverage['home'] ? [self::PASS, 'Home page checked'] : [self::SKIPPED, 'Skipped: the home page did not load'],
            default => self::counted($coverage['htmlPages'], 'page', 'No indexable HTML pages in this run'),
        };
    }

    /** @param string[] $bots */
    private static function botOutcome(array $bots, array $coverage): array
    {
        if (!$coverage['live']) return [self::SKIPPED, 'Skipped: runs on live only'];
        if (!$coverage['robots']) return [self::SKIPPED, 'Skipped: robots.txt could not be read'];

        return [self::PASS, 'robots.txt read, ' . \count($bots) . ' bots tested'];
    }

    /** @return array{0: string, 1: string} */
    private static function counted(int $count, string $noun, string $none): array
    {
        if ($count === 0) return [self::SKIPPED, 'Skipped: ' . \lcfirst($none)];

        return [self::PASS, $count . ' ' . $noun . ($count === 1 ? '' : 's') . ' tested'];
    }
}
