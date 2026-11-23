<?php

declare(strict_types=1);

namespace Tests\Services\AioAudit;

use Osmium\Services\AioAudit\Models\CheckSummary;
use Osmium\Services\AioAudit\Models\Checks;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/PageParser.php';
require_once __DIR__ . '/../models/Checks.php';
require_once __DIR__ . '/../models/CheckSummary.php';

/** The "What was checked" rows: pass, fail or skipped for every check, from findings plus recorded coverage */
class CheckSummaryTest extends TestCase
{
    private const LIVE = ['live' => true, 'robots' => true, 'sitemapPages' => 50, 'htmlPages' => 48, 'home' => true];

    private function finding(string $key, ?string $url, ?string $detail = null): array
    {
        return ['check_key' => $key, 'severity' => Checks::CATALOGUE[$key]['severity'], 'page_url' => $url, 'detail' => $detail];
    }

    /** @return array<string, array<string, mixed>> rows keyed by check */
    private function byKey(array $findings, ?array $coverage): array
    {
        $rows = [];
        foreach (CheckSummary::rows($findings, $coverage) as $row) {
            $rows[$row['check_key']] = $row;
        }

        return $rows;
    }

    public function test_there_is_one_row_per_catalogue_check_in_catalogue_order(): void
    {
        $rows = CheckSummary::rows([], self::LIVE);

        $this->assertSame(\array_keys(Checks::CATALOGUE), \array_column($rows, 'check_key'));
        $this->assertSame(\range(1, \count(Checks::CATALOGUE)), \array_column($rows, 'id'));
    }

    public function test_a_clean_live_run_passes_everything_and_says_what_it_covered(): void
    {
        $rows = $this->byKey([], self::LIVE);

        $this->assertSame(['pass'], \array_values(\array_unique(\array_column($rows, 'status'))));
        $this->assertSame('50 sitemap pages tested', $rows['page_error']['detail']);
        $this->assertSame('robots.txt read, 3 bots tested', $rows['ai_search_bot_blocked']['detail']);
        $this->assertSame('robots.txt read, 3 bots tested', $rows['ai_user_bot_blocked']['detail']);
        $this->assertSame('robots.txt read, 4 bots tested', $rows['ai_training_bot_blocked']['detail']);
        $this->assertSame('Home page checked', $rows['org_schema_missing']['detail']);
        foreach (['jsonld_missing', 'jsonld_invalid', 'js_only_content', 'snippet_blocked'] as $key) {
            $this->assertSame('48 pages tested', $rows[$key]['detail'], $key);
        }
    }

    public function test_the_bot_checks_cover_all_ten_bots_between_them(): void
    {
        $total = 0;
        foreach (['ai_search_bot_blocked', 'ai_user_bot_blocked', 'ai_training_bot_blocked'] as $key) {
            $total += \count(Checks::botsFor($key));
        }

        $this->assertSame(10, $total);
        $this->assertSame([], Checks::botsFor('page_error'));
    }

    public function test_the_bot_checks_are_skipped_when_the_site_is_not_live(): void
    {
        $rows = $this->byKey([], ['live' => false, 'robots' => false] + self::LIVE);

        foreach (['ai_search_bot_blocked', 'ai_user_bot_blocked', 'ai_training_bot_blocked'] as $key) {
            $this->assertSame('skipped', $rows[$key]['status']);
            $this->assertSame('Skipped: runs on live only', $rows[$key]['detail']);
        }
        $this->assertSame('pass', $rows['page_error']['status']);
    }

    public function test_the_bot_checks_are_skipped_when_robots_txt_could_not_be_read(): void
    {
        $rows = $this->byKey([], ['robots' => false] + self::LIVE);

        $this->assertSame('skipped', $rows['ai_search_bot_blocked']['status']);
        $this->assertSame('Skipped: robots.txt could not be read', $rows['ai_search_bot_blocked']['detail']);
    }

    public function test_a_failing_page_check_shows_how_many_pages_are_affected(): void
    {
        $findings = [$this->finding('jsonld_missing', '/a'), $this->finding('jsonld_missing', '/b'), $this->finding('snippet_blocked', '/a')];

        $rows = $this->byKey($findings, self::LIVE);

        $this->assertSame('fail', $rows['jsonld_missing']['status']);
        $this->assertSame('2 pages affected', $rows['jsonld_missing']['detail']);
        $this->assertSame('1 page affected', $rows['snippet_blocked']['detail']);
        $this->assertSame('pass', $rows['jsonld_invalid']['status']);
    }

    public function test_a_failing_bot_check_names_the_bots(): void
    {
        $findings = [
            $this->finding('ai_training_bot_blocked', null, 'GPTBot (training crawler) is blocked by its own group in robots.txt'),
            $this->finding('ai_training_bot_blocked', null, 'CCBot (training crawler) is blocked by the * group in robots.txt'),
        ];

        $rows = $this->byKey($findings, self::LIVE);

        $this->assertSame('fail', $rows['ai_training_bot_blocked']['status']);
        $this->assertSame('Blocked: GPTBot, CCBot', $rows['ai_training_bot_blocked']['detail']);
        $this->assertSame('pass', $rows['ai_search_bot_blocked']['status']);
    }

    public function test_a_check_with_nothing_to_test_is_skipped_not_passed(): void
    {
        $rows = $this->byKey([], ['sitemapPages' => 0, 'htmlPages' => 0, 'home' => false] + self::LIVE);

        $this->assertSame('skipped', $rows['page_error']['status']);
        $this->assertSame('Skipped: no sitemap pages in this run', $rows['page_error']['detail']);
        $this->assertSame('skipped', $rows['org_schema_missing']['status']);
        $this->assertSame('Skipped: the home page did not load', $rows['org_schema_missing']['detail']);
        $this->assertSame('Skipped: no indexable HTML pages in this run', $rows['js_only_content']['detail']);
    }

    public function test_one_page_reads_in_the_singular(): void
    {
        $rows = $this->byKey([], ['sitemapPages' => 1, 'htmlPages' => 1] + self::LIVE);

        $this->assertSame('1 sitemap page tested', $rows['page_error']['detail']);
        $this->assertSame('1 page tested', $rows['jsonld_missing']['detail']);
    }

    public function test_a_run_with_no_recorded_coverage_shows_unknown_but_still_shows_failures(): void
    {
        $rows = $this->byKey([$this->finding('jsonld_invalid', '/a')], null);

        $this->assertSame('fail', $rows['jsonld_invalid']['status']);
        $this->assertSame('unknown', $rows['page_error']['status']);
        $this->assertSame('Coverage was not recorded for this run', $rows['page_error']['detail']);
        $this->assertSame('unknown', $rows['ai_search_bot_blocked']['status']);
    }

    public function test_each_status_has_its_word_icon_and_colour(): void
    {
        $rows = $this->byKey([$this->finding('jsonld_invalid', '/a')], ['live' => false, 'robots' => false] + self::LIVE);

        $this->assertSame(['Fail', 'bxs-x-circle', 'text-danger'], [$rows['jsonld_invalid']['statusLabel'], $rows['jsonld_invalid']['statusIcon'], $rows['jsonld_invalid']['statusClass']]);
        $this->assertSame(['Pass', 'bxs-check-circle', 'text-success'], [$rows['page_error']['statusLabel'], $rows['page_error']['statusIcon'], $rows['page_error']['statusClass']]);
        $this->assertSame(['Skipped', 'bxs-minus-circle', 'text-muted'], [$rows['ai_search_bot_blocked']['statusLabel'], $rows['ai_search_bot_blocked']['statusIcon'], $rows['ai_search_bot_blocked']['statusClass']]);
        $this->assertSame(['Unknown', 'bxs-help-circle'], [CheckSummary::rows([], null)[0]['statusLabel'], CheckSummary::rows([], null)[0]['statusIcon']]);
        $this->assertLessThan($rows['page_error']['statusRank'], $rows['jsonld_invalid']['statusRank']);
    }

    public function test_decode_reads_stored_coverage_and_refuses_anything_else(): void
    {
        $this->assertSame(self::LIVE, CheckSummary::decode(\json_encode(self::LIVE)));
        $this->assertNull(CheckSummary::decode(null));
        $this->assertNull(CheckSummary::decode(''));
        $this->assertNull(CheckSummary::decode('not json'));
        $this->assertNull(CheckSummary::decode('{"pages":3}'));
        $this->assertSame(['live' => false, 'robots' => false, 'sitemapPages' => 0, 'htmlPages' => 0, 'home' => false], CheckSummary::decode('{"live":false}'));
    }

    // ---- Checks::coverage, what a run records

    private function page(string $url, array $over = []): array
    {
        return $over + ['url' => $url, 'in_sitemap' => 1, 'status_code' => 200, 'robots' => 'index', 'jsonld_types' => []];
    }

    public function test_coverage_counts_sitemap_pages_indexable_html_and_the_home_page(): void
    {
        $pages = [
            $this->page('/'),
            $this->page('/a'),
            $this->page('/hidden', ['robots' => 'noindex']),
            $this->page('/feed', ['jsonld_types' => null]),
            $this->page('/gone', ['status_code' => 404, 'jsonld_types' => null]),
            $this->page('/linked', ['in_sitemap' => 0]),
        ];

        $coverage = Checks::coverage($pages, ['isLive' => true, 'robotsRead' => true]);

        $this->assertSame(['live' => true, 'robots' => true, 'sitemapPages' => 5, 'htmlPages' => 3, 'home' => true], $coverage);
    }

    public function test_coverage_records_no_robots_read_off_live_or_when_unreadable(): void
    {
        $this->assertFalse(Checks::coverage([], ['isLive' => false, 'robotsRead' => true])['robots']);
        $this->assertFalse(Checks::coverage([], ['isLive' => true, 'robotsRead' => false])['robots']);
        $this->assertFalse(Checks::coverage([$this->page('/', ['status_code' => 500])], [])['home']);
    }
}
