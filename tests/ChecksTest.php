<?php

declare(strict_types=1);

namespace Tests\Services\AioAudit;

use Osmium\Services\AioAudit\Models\Checks;
use Osmium\Services\AioAudit\Models\PageParser;
use Osmium\Services\AioAudit\Models\Scoring;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/PageParser.php';
require_once __DIR__ . '/../models/Checks.php';
require_once __DIR__ . '/../models/Scoring.php';

class ChecksTest extends TestCase
{
    /** A healthy page; each test overrides what it wants to break */
    private function page(string $url, array $over = []): array
    {
        return $over + [
            'url' => $url, 'in_sitemap' => 1, 'fetched' => 1, 'status_code' => 200, 'redirect_target' => null, 'fetch_error' => null,
            'robots' => 'index,follow', 'word_count' => 400, 'app_shell' => 0, 'jsonld_blocks' => 1, 'jsonld_invalid' => 0, 'jsonld_types' => ['Article'], 'links' => [],
        ];
    }

    private function home(array $over = []): array
    {
        return $this->page('/', $over + ['jsonld_types' => ['Organization', 'WebSite']]);
    }

    /** @return string[] sorted check keys raised for a url (null for site-wide findings) */
    private function keysFor(array $findings, ?string $url): array
    {
        $keys = [];
        foreach ($findings as $finding) {
            if ($finding['page_url'] === $url) $keys[] = $finding['check_key'];
        }
        \sort($keys);

        return $keys;
    }

    // ---- the catalogue

    public function test_every_check_has_label_severity_group_and_fix(): void
    {
        $this->assertSame(['page_error', 'ai_search_bot_blocked', 'ai_user_bot_blocked', 'ai_training_bot_blocked', 'org_schema_missing', 'jsonld_missing', 'jsonld_invalid', 'js_only_content', 'snippet_blocked'], \array_keys(Checks::CATALOGUE));
        foreach (Checks::CATALOGUE as $check) {
            $this->assertContains($check['severity'], Checks::SEVERITIES);
            $this->assertNotSame('', $check['label']);
            $this->assertNotSame('', $check['fix']);
        }
        $this->assertSame('AI visibility', Checks::CATALOGUE['ai_search_bot_blocked']['group']);
        $this->assertSame('high', Checks::CATALOGUE['ai_search_bot_blocked']['severity']);
        $this->assertSame('medium', Checks::CATALOGUE['ai_user_bot_blocked']['severity']);
        $this->assertSame('low', Checks::CATALOGUE['ai_training_bot_blocked']['severity']);
        $this->assertSame('medium', Checks::CATALOGUE['org_schema_missing']['severity']);
        $this->assertSame('low', Checks::CATALOGUE['jsonld_missing']['severity']);
        $this->assertSame('medium', Checks::CATALOGUE['jsonld_invalid']['severity']);
        $this->assertSame('medium', Checks::CATALOGUE['js_only_content']['severity']);
        $this->assertSame('medium', Checks::CATALOGUE['snippet_blocked']['severity']);
        $this->assertStringContainsString('may be ignored', Checks::CATALOGUE['ai_user_bot_blocked']['fix']);
        $this->assertStringContainsString('legitimate policy choice', Checks::CATALOGUE['ai_training_bot_blocked']['fix']);
        $this->assertStringContainsString('does not reduce', Checks::CATALOGUE['ai_training_bot_blocked']['fix']);
    }

    public function test_a_healthy_site_has_no_findings(): void
    {
        $this->assertSame([], Checks::run([$this->home(), $this->page('/a/')], ['isLive' => true, 'robotsTxt' => "User-agent: *\nDisallow: /admin/"]));
    }

    // ---- page_error

    public function test_a_sitemap_page_that_fails_is_a_critical_page_error(): void
    {
        $pages = [$this->home(), $this->page('/gone/', ['status_code' => 404, 'jsonld_types' => null]), $this->page('/down/', ['status_code' => 0, 'fetch_error' => 'timeout', 'jsonld_types' => null])];

        $findings = Checks::run($pages);

        $this->assertSame(['page_error'], $this->keysFor($findings, '/gone/'));
        $this->assertSame('critical', $findings[0]['severity']);
        $this->assertSame('Status 404', $findings[0]['detail']);
        $this->assertSame('timeout', $findings[1]['detail']);
    }

    public function test_a_failure_with_no_error_text_says_no_response(): void
    {
        $findings = Checks::run([$this->home(), $this->page('/x/', ['status_code' => 0, 'fetch_error' => null, 'jsonld_types' => null])]);

        $this->assertSame('No response', $findings[0]['detail']);
    }

    public function test_a_failing_page_that_is_not_in_the_sitemap_is_not_an_error(): void
    {
        $findings = Checks::run([$this->home(), $this->page('/x/', ['status_code' => 500, 'in_sitemap' => 0, 'jsonld_types' => null])]);

        $this->assertSame([], $findings);
    }

    public function test_a_redirect_raises_nothing_here(): void
    {
        $findings = Checks::run([$this->home(), $this->page('/r/', ['status_code' => 301, 'jsonld_types' => null])]);

        $this->assertSame([], $findings);
    }

    // ---- org_schema_missing

    public function test_a_home_page_without_organisation_or_website_data_is_flagged(): void
    {
        $findings = Checks::run([$this->home(['jsonld_types' => ['Article', 'BreadcrumbList']])]);

        $this->assertSame(['org_schema_missing'], $this->keysFor($findings, '/'));
        $this->assertSame('medium', $findings[0]['severity']);
    }

    public function test_a_home_page_with_no_json_ld_at_all_gets_both_findings(): void
    {
        $findings = Checks::run([$this->home(['jsonld_blocks' => 0, 'jsonld_types' => []])]);

        $this->assertSame(['jsonld_missing', 'org_schema_missing'], $this->keysFor($findings, '/'));
    }

    /** @dataProvider acceptedTypes */
    public function test_organisation_localbusiness_subtypes_and_website_each_satisfy_the_check(string $type): void
    {
        $findings = Checks::run([$this->home(['jsonld_types' => [$type]])]);

        $this->assertSame([], $findings);
    }

    public static function acceptedTypes(): array
    {
        return [['Organization'], ['LocalBusiness'], ['Plumber'], ['ProfessionalService'], ['WebSite'], ['website'], ['OnlineStore']];
    }

    public function test_the_organisation_check_looks_only_at_the_home_page(): void
    {
        $findings = Checks::run([$this->home(), $this->page('/a/', ['jsonld_types' => ['Article']])]);

        $this->assertSame([], $findings);
    }

    public function test_no_organisation_finding_when_the_home_page_did_not_load(): void
    {
        $findings = Checks::run([$this->home(['status_code' => 500, 'in_sitemap' => 0, 'jsonld_types' => null])]);

        $this->assertSame([], $findings);
    }

    public function test_no_organisation_finding_when_the_home_page_was_not_crawled(): void
    {
        $this->assertSame([], Checks::run([$this->page('/a/')]));
    }

    // ---- jsonld_missing

    public function test_an_indexable_content_page_with_no_json_ld_is_low(): void
    {
        $findings = Checks::run([$this->home(), $this->page('/a/', ['jsonld_blocks' => 0, 'jsonld_types' => []])]);

        $this->assertSame(['jsonld_missing'], $this->keysFor($findings, '/a/'));
        $this->assertSame('low', $findings[0]['severity']);
    }

    public function test_a_noindex_page_is_not_asked_for_json_ld(): void
    {
        $findings = Checks::run([$this->home(), $this->page('/a/', ['robots' => 'noindex,follow', 'jsonld_blocks' => 0, 'jsonld_types' => []])]);

        $this->assertSame([], $findings);
    }

    public function test_a_page_that_is_not_html_is_not_asked_for_json_ld(): void
    {
        $findings = Checks::run([$this->home(), $this->page('/feed/', ['jsonld_blocks' => 0, 'jsonld_types' => null])]);

        $this->assertSame([], $findings);
    }

    public function test_a_page_with_only_a_broken_block_is_invalid_not_missing(): void
    {
        $findings = Checks::run([$this->home(), $this->page('/a/', ['jsonld_blocks' => 1, 'jsonld_invalid' => 1, 'jsonld_types' => []])]);

        $this->assertSame(['jsonld_invalid'], $this->keysFor($findings, '/a/'));
    }

    // ---- jsonld_invalid

    public function test_broken_json_ld_is_medium_and_counts_the_blocks(): void
    {
        $findings = Checks::run([$this->home(), $this->page('/a/', ['jsonld_blocks' => 3, 'jsonld_invalid' => 2])]);

        $this->assertSame(['jsonld_invalid'], $this->keysFor($findings, '/a/'));
        $this->assertSame('medium', $findings[0]['severity']);
        $this->assertSame('2 block(s)', $findings[0]['detail']);
    }

    public function test_a_noindex_page_with_broken_json_ld_is_not_flagged(): void
    {
        $findings = Checks::run([$this->home(), $this->page('/a/', ['robots' => 'none', 'jsonld_invalid' => 1])]);

        $this->assertSame([], $findings);
    }

    // ---- the AI crawler checks, split by purpose

    private const SEARCH = ['OAI-SearchBot', 'Claude-SearchBot', 'PerplexityBot'];
    private const USER = ['ChatGPT-User', 'Claude-User', 'Perplexity-User'];
    private const TRAINING = ['GPTBot', 'ClaudeBot', 'Google-Extended', 'CCBot'];

    public function test_a_blanket_disallow_raises_one_finding_per_bot_at_its_purpose_severity(): void
    {
        $findings = Checks::run([$this->home()], ['isLive' => true, 'robotsTxt' => "User-agent: *\nDisallow: /"]);

        $this->assertCount(10, $findings);
        $byKey = [];
        foreach ($findings as $finding) {
            $byKey[$finding['check_key']][] = $finding;
            $this->assertNull($finding['page_url']);
        }
        $this->assertCount(3, $byKey['ai_search_bot_blocked']);
        $this->assertCount(3, $byKey['ai_user_bot_blocked']);
        $this->assertCount(4, $byKey['ai_training_bot_blocked']);
        $this->assertSame('high', $byKey['ai_search_bot_blocked'][0]['severity']);
        $this->assertSame('medium', $byKey['ai_user_bot_blocked'][0]['severity']);
        $this->assertSame('low', $byKey['ai_training_bot_blocked'][0]['severity']);
        $this->assertSame('OAI-SearchBot (search crawler) is blocked by the * group in robots.txt', $byKey['ai_search_bot_blocked'][0]['detail']);
        $this->assertSame('ChatGPT-User (assistant fetcher) is blocked by the * group in robots.txt', $byKey['ai_user_bot_blocked'][0]['detail']);
        $this->assertSame('GPTBot (training crawler) is blocked by the * group in robots.txt', $byKey['ai_training_bot_blocked'][0]['detail']);
    }

    public function test_each_bot_is_classified_by_purpose(): void
    {
        foreach ([...self::SEARCH, ...self::USER, ...self::TRAINING] as $bot) {
            $findings = Checks::run([$this->home()], ['isLive' => true, 'robotsTxt' => "User-agent: {$bot}\nDisallow: /"]);
            $expected = match (true) {
                \in_array($bot, self::SEARCH, true) => 'ai_search_bot_blocked',
                \in_array($bot, self::USER, true) => 'ai_user_bot_blocked',
                default => 'ai_training_bot_blocked',
            };

            $this->assertCount(1, $findings, $bot);
            $this->assertSame($expected, $findings[0]['check_key'], $bot);
            $this->assertStringStartsWith($bot . ' (', $findings[0]['detail']);
            $this->assertStringContainsString('its own group', $findings[0]['detail']);
        }
    }

    public function test_blocking_only_training_bots_raises_only_the_low_check(): void
    {
        $robots = "User-agent: GPTBot\nUser-agent: ClaudeBot\nDisallow: /";

        $findings = Checks::run([$this->home()], ['isLive' => true, 'robotsTxt' => $robots]);

        $this->assertSame(['ai_training_bot_blocked'], \array_values(\array_unique(\array_column($findings, 'check_key'))));
        $this->assertSame(['low'], \array_values(\array_unique(\array_column($findings, 'severity'))));
    }

    public function test_a_blocked_group_does_not_touch_other_purposes(): void
    {
        $robots = "User-agent: GPTBot\nUser-agent: CCBot\nDisallow: /\n\nUser-agent: *\nDisallow: /private/";

        $findings = Checks::run([$this->home()], ['isLive' => true, 'robotsTxt' => $robots]);

        $this->assertSame(
            ['GPTBot (training crawler) is blocked by its own group in robots.txt', 'CCBot (training crawler) is blocked by its own group in robots.txt'],
            \array_column($findings, 'detail'),
        );
    }

    public function test_the_checks_do_not_run_off_live(): void
    {
        $findings = Checks::run([$this->home()], ['isLive' => false, 'robotsTxt' => "User-agent: *\nDisallow: /"]);

        $this->assertSame([], $findings);
    }

    public function test_no_robots_txt_means_nothing_is_blocked(): void
    {
        $this->assertSame([], Checks::run([$this->home()], ['isLive' => true, 'robotsTxt' => '']));
    }

    // ---- js_only_content

    public function test_an_empty_app_shell_with_almost_no_text_is_flagged(): void
    {
        $findings = Checks::run([$this->home(), $this->page('/app/', ['word_count' => 12, 'app_shell' => 1])]);

        $this->assertSame(['js_only_content'], $this->keysFor($findings, '/app/'));
        $this->assertSame('medium', $findings[0]['severity']);
        $this->assertSame('12 words in the HTML', $findings[0]['detail']);
    }

    public function test_a_short_page_with_no_app_shell_is_not_flagged(): void
    {
        $findings = Checks::run([$this->home(), $this->page('/contact/', ['word_count' => 20, 'app_shell' => 0])]);

        $this->assertSame([], $findings);
    }

    public function test_a_page_with_an_app_shell_but_plenty_of_text_is_not_flagged(): void
    {
        $findings = Checks::run([$this->home(), $this->page('/ssr/', ['word_count' => Checks::JS_ONLY_WORDS, 'app_shell' => 1])]);

        $this->assertSame([], $findings);
    }

    public function test_the_word_limit_is_exclusive(): void
    {
        $findings = Checks::run([$this->home(), $this->page('/edge/', ['word_count' => Checks::JS_ONLY_WORDS - 1, 'app_shell' => 1])]);

        $this->assertSame(['js_only_content'], $this->keysFor($findings, '/edge/'));
    }

    public function test_a_noindex_or_non_html_page_is_not_checked_for_js_only_content(): void
    {
        $findings = Checks::run([
            $this->home(),
            $this->page('/hidden/', ['robots' => 'noindex', 'word_count' => 0, 'app_shell' => 1]),
            $this->page('/api/', ['jsonld_types' => null, 'word_count' => 0, 'app_shell' => 1]),
        ]);

        $this->assertSame([], $findings);
    }

    // ---- snippet_blocked

    public function test_nosnippet_and_max_snippet_zero_are_flagged_with_the_directives_as_detail(): void
    {
        $findings = Checks::run([
            $this->home(),
            $this->page('/a/', ['robots' => 'index,nosnippet']),
            $this->page('/b/', ['robots' => 'max-snippet:0']),
        ]);

        $this->assertSame(['snippet_blocked'], $this->keysFor($findings, '/a/'));
        $this->assertSame(['snippet_blocked'], $this->keysFor($findings, '/b/'));
        $this->assertSame('medium', $findings[0]['severity']);
        $this->assertSame('index,nosnippet', $findings[0]['detail']);
    }

    public function test_a_limited_but_nonzero_snippet_is_fine(): void
    {
        $findings = Checks::run([$this->home(), $this->page('/a/', ['robots' => 'max-snippet:50']), $this->page('/b/', ['robots' => 'max-snippet:-1,max-image-preview:large'])]);

        $this->assertSame([], $findings);
    }

    public function test_a_noindex_page_is_not_flagged_for_snippets(): void
    {
        $findings = Checks::run([$this->home(), $this->page('/a/', ['robots' => 'noindex,nosnippet'])]);

        $this->assertSame([], $findings);
    }

    // ---- the per-agent robots parser

    public function test_a_bot_with_its_own_group_ignores_the_star_group(): void
    {
        $robots = "User-agent: *\nDisallow: /\n\nUser-agent: GPTBot\nAllow: /";

        $blocked = \array_keys(Checks::blockedBots($robots));

        $this->assertSame(\array_values(\array_diff(\array_keys(Checks::AI_BOTS), ['GPTBot'])), $blocked);
    }

    public function test_an_own_group_with_no_rules_allows_everything(): void
    {
        $robots = "User-agent: *\nDisallow: /\n\nUser-agent: ClaudeBot\n";

        $this->assertArrayNotHasKey('ClaudeBot', Checks::blockedBots($robots));
        $this->assertArrayHasKey('GPTBot', Checks::blockedBots($robots));
    }

    public function test_an_empty_disallow_allows_everything(): void
    {
        $this->assertSame([], Checks::blockedBots("User-agent: *\nDisallow:"));
    }

    public function test_a_disallow_that_misses_the_home_page_does_not_block(): void
    {
        $this->assertSame([], Checks::blockedBots("User-agent: GPTBot\nDisallow: /private/\nDisallow: /index.html"));
    }

    public function test_a_wildcard_rule_that_matches_the_home_page_blocks(): void
    {
        $this->assertSame(['GPTBot'], \array_keys(Checks::blockedBots("User-agent: GPTBot\nDisallow: /*")));
        $this->assertSame(['GPTBot'], \array_keys(Checks::blockedBots("User-agent: GPTBot\nDisallow: /$")));
        $this->assertSame(['GPTBot'], \array_keys(Checks::blockedBots("User-agent: GPTBot\nDisallow: /*$")));
    }

    public function test_a_dollar_anchor_only_matches_the_exact_path(): void
    {
        $this->assertSame([], Checks::blockedBots("User-agent: GPTBot\nDisallow: /index$"));
        $this->assertSame([], Checks::blockedBots("User-agent: GPTBot\nDisallow: /*.pdf$"));
    }

    public function test_the_longest_matching_rule_wins_and_allow_wins_a_tie(): void
    {
        $this->assertSame([], Checks::blockedBots("User-agent: GPTBot\nDisallow: /\nAllow: /$"));
        $this->assertSame([], Checks::blockedBots("User-agent: GPTBot\nDisallow: /\nAllow: /"));
        $this->assertSame([], Checks::blockedBots("User-agent: GPTBot\nAllow: /\nDisallow: /"));
        $this->assertSame(['GPTBot'], \array_keys(Checks::blockedBots("User-agent: GPTBot\nAllow: /\nDisallow: /*")));
    }

    public function test_names_and_field_names_are_case_insensitive(): void
    {
        $this->assertSame(['GPTBot'], \array_keys(Checks::blockedBots("USER-AGENT: gptbot\nDISALLOW: /")));
    }

    public function test_comments_blank_lines_and_crlf_are_ignored(): void
    {
        $robots = "# keep AI out\r\nUser-agent: CCBot # common crawl\r\n\r\n# note\r\nDisallow: / # all of it\r\n";

        $this->assertSame(['CCBot'], \array_keys(Checks::blockedBots($robots)));
    }

    public function test_groups_for_the_same_bot_are_merged(): void
    {
        $robots = "User-agent: GPTBot\nAllow: /\n\nUser-agent: Googlebot\nDisallow: /x/\n\nUser-agent: GPTBot\nDisallow: /*";

        $this->assertSame(['GPTBot'], \array_keys(Checks::blockedBots($robots)));
    }

    public function test_a_sitemap_or_crawl_delay_line_does_not_split_a_group(): void
    {
        $robots = "User-agent: GPTBot\nSitemap: https://example.com/sitemap.xml\nUser-agent: CCBot\nCrawl-delay: 10\nDisallow: /";

        $this->assertSame(['GPTBot', 'CCBot'], \array_keys(Checks::blockedBots($robots)));
    }

    public function test_a_rule_after_a_rule_starts_a_new_group_for_the_next_agent(): void
    {
        $robots = "User-agent: GPTBot\nDisallow: /\nUser-agent: CCBot\nDisallow: /private/";

        $this->assertSame(['GPTBot'], \array_keys(Checks::blockedBots($robots)));
    }

    public function test_rules_before_any_user_agent_line_are_ignored(): void
    {
        $this->assertSame([], Checks::blockedBots("Disallow: /\nUser-agent: GPTBot\nDisallow: /private/"));
    }

    public function test_other_bots_in_robots_do_not_count(): void
    {
        $this->assertSame([], Checks::blockedBots("User-agent: Googlebot\nDisallow: /\nUser-agent: Bingbot\nDisallow: /"));
    }

    public function test_google_extended_is_matched_by_its_hyphenated_name(): void
    {
        $this->assertSame(['Google-Extended'], \array_keys(Checks::blockedBots("User-agent: Google-Extended\nDisallow: /")));
    }

    public function test_robots_blocks_uses_the_star_group_for_an_unlisted_agent_and_allows_with_no_groups(): void
    {
        $groups = Checks::parseRobotsGroups("User-agent: *\nDisallow: /private/");

        $this->assertTrue(Checks::robotsBlocks($groups, 'AnyBot', '/private/x'));
        $this->assertFalse(Checks::robotsBlocks($groups, 'AnyBot', '/public/'));
        $this->assertFalse(Checks::robotsBlocks([], 'AnyBot', '/private/x'));
    }

    // ---- scoring and diffing of site-wide findings

    public function test_several_blocked_bots_of_one_purpose_count_as_one_check_in_the_score(): void
    {
        $findings = Checks::run([$this->home()], ['isLive' => true, 'robotsTxt' => "User-agent: GPTBot\nUser-agent: CCBot\nDisallow: /"]);

        $this->assertSame(99, Scoring::score($findings, 1));
    }
}
