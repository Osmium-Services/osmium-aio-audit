<?php

declare(strict_types=1);

namespace Osmium\Services\AioAudit\Models;

/**
 * The AI visibility checks. Takes every page of a finished crawl and returns findings, each with a check key, a
 * severity, the page and a short detail. Pure: no network, no DB, so each rule is testable on plain arrays.
 */
class Checks
{
    /** Every check: label, severity, group and a plain-English fix. The report is drawn from this. */
    public const CATALOGUE = [
        'page_error' => ['label' => 'Page returns an error', 'severity' => 'critical', 'group' => 'Crawl', 'fix' => 'A page in your sitemap does not load, so AI answer engines cannot read it either. Fix the page, or remove or redirect it (Pages, Redirects).'],
        'ai_search_bot_blocked' => ['label' => 'AI search crawler blocked by robots.txt', 'severity' => 'high', 'group' => 'AI visibility', 'fix' => 'robots.txt tells this AI search crawler to stay away, so the site cannot appear in that engine\'s answers or citations. Remove the Disallow rule for it (or for * when it has no group of its own) unless keeping the site out of that engine is deliberate.'],
        'ai_user_bot_blocked' => ['label' => 'AI assistant fetcher blocked by robots.txt', 'severity' => 'medium', 'group' => 'AI visibility', 'fix' => 'robots.txt tells this crawler, which fetches a page when a person asks an assistant about it, to stay away. Remove the rule if you want those requests answered. Note that OpenAI says robots.txt rules may not apply to ChatGPT-User, so the rule may be ignored anyway.'],
        'ai_training_bot_blocked' => ['label' => 'AI training crawler blocked by robots.txt', 'severity' => 'low', 'group' => 'AI visibility', 'fix' => 'robots.txt keeps this crawler from collecting the site to train AI models. Blocking training crawlers is a legitimate policy choice and does not reduce how often the site is cited by AI search engines, so leave it if that is what you want.'],
        'org_schema_missing' => ['label' => 'No organisation or website structured data on the home page', 'severity' => 'medium', 'group' => 'AI visibility', 'fix' => 'Add JSON-LD naming the business (Organization or LocalBusiness) or the site (WebSite) to the home page, so answer engines know who is behind the site (theme head).'],
        'jsonld_missing' => ['label' => 'No structured data', 'severity' => 'low', 'group' => 'AI visibility', 'fix' => 'The page has no JSON-LD. Structured data tells answer engines what the page is (article, service, FAQ) without guessing (theme head or the service that owns the page).'],
        'jsonld_invalid' => ['label' => 'Broken structured data', 'severity' => 'medium', 'group' => 'AI visibility', 'fix' => 'The JSON-LD block does not parse, so answer engines ignore it. Fix the markup.'],
        'js_only_content' => ['label' => 'Text may appear only after JavaScript runs', 'severity' => 'medium', 'group' => 'AI visibility', 'fix' => 'The page\'s HTML has almost no text and an empty app container that scripts fill in. Crawlers such as GPTBot and ClaudeBot do not run JavaScript, so they may see an empty page. Render the content on the server, or in the HTML itself.'],
        'snippet_blocked' => ['label' => 'Snippets blocked (nosnippet or max-snippet:0)', 'severity' => 'medium', 'group' => 'AI visibility', 'fix' => 'The page\'s robots tag stops search engines showing any of its text. Google says a page must be eligible for a snippet to appear in AI Overviews and AI Mode. Remove nosnippet or max-snippet:0 from the robots meta tag or X-Robots-Tag header unless that is deliberate.'],
    ];

    public const SEVERITIES = ['critical', 'high', 'medium', 'low'];

    /** The AI crawlers whose access is checked, by their robots.txt name, with what each is for */
    public const AI_BOTS = [
        'OAI-SearchBot' => 'search', 'Claude-SearchBot' => 'search', 'PerplexityBot' => 'search',
        'ChatGPT-User' => 'user', 'Claude-User' => 'user', 'Perplexity-User' => 'user',
        'GPTBot' => 'training', 'ClaudeBot' => 'training', 'Google-Extended' => 'training', 'CCBot' => 'training',
    ];

    /** Per purpose: the check it raises and how its bots are described in a finding */
    private const BOT_PURPOSES = [
        'search' => ['check' => 'ai_search_bot_blocked', 'text' => 'search crawler'],
        'user' => ['check' => 'ai_user_bot_blocked', 'text' => 'assistant fetcher'],
        'training' => ['check' => 'ai_training_bot_blocked', 'text' => 'training crawler'],
    ];

    /** A page with fewer visible words than this in its raw HTML, and an empty app container, is probably filled in by JavaScript */
    public const JS_ONLY_WORDS = 150;

    /** schema.org types that count as naming the business: Organization, LocalBusiness and their common subtypes */
    public const ORGANISATION_TYPES = [
        'Organization', 'Corporation', 'NGO', 'OnlineBusiness', 'OnlineStore', 'LocalBusiness', 'Store', 'ProfessionalService',
        'Restaurant', 'FoodEstablishment', 'Hotel', 'LodgingBusiness', 'AutomotiveBusiness', 'AutoDealer', 'AutoRepair',
        'HomeAndConstructionBusiness', 'Electrician', 'Plumber', 'RoofingContractor', 'GeneralContractor', 'HVACBusiness',
        'HealthAndBeautyBusiness', 'HairSalon', 'BeautySalon', 'DaySpa', 'MedicalBusiness', 'Dentist', 'LegalService',
        'FinancialService', 'AccountingService', 'RealEstateAgent', 'TravelAgency', 'EmploymentAgency', 'SportsActivityLocation',
        'EntertainmentBusiness', 'Library', 'ChildCare', 'Locksmith', 'MovingCompany', 'SelfStorage', 'ShoppingCenter',
        'TouristInformationCenter', 'GovernmentOrganization', 'EducationalOrganization', 'School', 'CollegeOrUniversity',
        'SportsOrganization', 'Airline', 'PerformingGroup', 'NewsMediaOrganization', 'MedicalOrganization',
    ];

    /**
     * @param array<int, array<string, mixed>> $pages every crawled page (rows as stored, with 'links' and 'jsonld_types' decoded to lists)
     * @param array{baseUrl?: string, isLive?: bool, robotsTxt?: string} $context
     * @return array<int, array{check_key: string, severity: string, page_url: ?string, detail: ?string}>
     */
    public static function run(array $pages, array $context = []): array
    {
        $findings = [];
        foreach ($pages as $page) {
            $findings = \array_merge($findings, self::pageFindings($page));
        }

        return \array_values(\array_merge(
            $findings,
            self::homeFindings($pages),
            self::robotsFindings((string) ($context['robotsTxt'] ?? ''), (bool) ($context['isLive'] ?? false)),
        ));
    }

    /**
     * The rules of a robots.txt grouped by the user-agent they apply to (names lower-cased). Consecutive User-agent
     * lines share the rules that follow; groups for the same name are merged; empty Disallow/Allow lines add no rule.
     *
     * @return array<string, array<int, array{allow: bool, path: string}>>
     */
    public static function parseRobotsGroups(string $robotsTxt): array
    {
        $groups = [];
        $current = [];
        $lastWasAgent = false;
        foreach (\preg_split('/\R/', $robotsTxt) ?: [] as $line) {
            $clean = \trim((string) \preg_replace('/#.*$/', '', $line));
            $isField = \preg_match('/^([A-Za-z-]+)\s*:\s*(.*)$/', $clean, $match) === 1;
            if (!$isField) continue;

            $field = \strtolower($match[1]);
            $value = \trim($match[2]);
            if ($field === 'user-agent') {
                if (!$lastWasAgent) $current = [];
                $agent = \strtolower($value);
                $current[] = $agent;
                $groups[$agent] ??= [];
                $lastWasAgent = true;
                continue;
            }

            $isRule = \in_array($field, ['allow', 'disallow', 'crawl-delay'], true);
            if ($isRule) $lastWasAgent = false;
            $addsRule = \in_array($field, ['allow', 'disallow'], true) && $value !== '';
            if ($addsRule) self::addRule($groups, $current, $field === 'allow', $value);
        }

        return $groups;
    }

    /**
     * Whether robots.txt keeps a crawler off a path. The crawler obeys its own group, or the `*` group when it has none
     * (the two are never combined). The longest matching rule wins and Allow wins a tie, as in Google's reading of the
     * standard; `*` and a trailing `$` work in patterns.
     *
     * @param array<string, array<int, array{allow: bool, path: string}>> $groups from parseRobotsGroups()
     */
    public static function robotsBlocks(array $groups, string $agent, string $path): bool
    {
        $rules = $groups[\strtolower($agent)] ?? $groups['*'] ?? [];
        $winner = null;
        foreach ($rules as $rule) {
            $longer = $winner === null || \strlen($rule['path']) > \strlen($winner['path']);
            $tied = $winner !== null && \strlen($rule['path']) === \strlen($winner['path']);
            $wins = self::patternMatches($rule['path'], $path) && ($longer || ($tied && $rule['allow']));
            if ($wins) $winner = $rule;
        }

        return $winner !== null && !$winner['allow'];
    }

    /**
     * The AI crawlers a robots.txt keeps off the home page, each with where the rule comes from, in AI_BOTS order.
     *
     * @return array<string, string> bot name => 'its own group' or 'the * group'
     */
    public static function blockedBots(string $robotsTxt): array
    {
        $groups = self::parseRobotsGroups($robotsTxt);
        $blocked = [];
        foreach (\array_keys(self::AI_BOTS) as $bot) {
            $isBlocked = self::robotsBlocks($groups, $bot, '/');
            $hasOwnGroup = isset($groups[\strtolower($bot)]);
            if ($isBlocked) $blocked[$bot] = $hasOwnGroup ? 'its own group' : 'the * group';
        }

        return $blocked;
    }

    private static function finding(string $key, ?string $url, ?string $detail = null): array
    {
        return ['check_key' => $key, 'severity' => self::CATALOGUE[$key]['severity'], 'page_url' => $url, 'detail' => $detail];
    }

    /** @param array<string, array<int, array{allow: bool, path: string}>> $groups */
    private static function addRule(array &$groups, array $agents, bool $allow, string $path): void
    {
        foreach ($agents as $agent) {
            $groups[$agent][] = ['allow' => $allow, 'path' => $path];
        }
    }

    private static function patternMatches(string $pattern, string $path): bool
    {
        $anchored = \str_ends_with($pattern, '$');
        $body = $anchored ? \substr($pattern, 0, -1) : $pattern;
        $regex = '~^' . \str_replace('\*', '.*', \preg_quote($body, '~')) . ($anchored ? '$' : '') . '~';

        return \preg_match($regex, $path) === 1;
    }

    /** The checks that look at one page: a fetch error, or on an indexable HTML page structured data, rendering and snippets */
    private static function pageFindings(array $page): array
    {
        $url = $page['url'];
        $status = (int) ($page['status_code'] ?? 0);
        $failed = $status === 0 || $status >= 400;
        $listed = !empty($page['in_sitemap']);
        $isError = $failed && $listed;
        if ($isError) return [self::finding('page_error', $url, $status === 0 ? ($page['fetch_error'] ?: 'No response') : "Status {$status}")];

        $isHtml = $status === 200 && ($page['jsonld_types'] ?? null) !== null; // types stay null when the body was not parsed as HTML
        $isNoindex = PageParser::isNoindex((string) ($page['robots'] ?? ''));
        $skip = !$isHtml || $isNoindex;
        if ($skip) return [];

        return \array_merge(self::structuredDataFindings($page), self::renderingFindings($page), self::snippetFindings($page));
    }

    /** JSON-LD on an indexable HTML page: none at all, or a block that does not parse */
    private static function structuredDataFindings(array $page): array
    {
        $findings = [];
        $blocks = (int) ($page['jsonld_blocks'] ?? 0);
        $invalid = (int) ($page['jsonld_invalid'] ?? 0);
        if ($blocks === 0) $findings[] = self::finding('jsonld_missing', $page['url']);
        if ($invalid > 0) $findings[] = self::finding('jsonld_invalid', $page['url'], "{$invalid} block(s)");

        return $findings;
    }

    /** Almost no text in the raw HTML beside an empty app container: the text probably arrives with JavaScript */
    private static function renderingFindings(array $page): array
    {
        $words = (int) ($page['word_count'] ?? 0);
        $hasShell = !empty($page['app_shell']);
        $isJsOnly = $hasShell && $words < self::JS_ONLY_WORDS;

        return $isJsOnly ? [self::finding('js_only_content', $page['url'], "{$words} words in the HTML")] : [];
    }

    private static function snippetFindings(array $page): array
    {
        $blocked = PageParser::blocksSnippets((string) ($page['robots'] ?? ''));

        return $blocked ? [self::finding('snippet_blocked', $page['url'], (string) $page['robots'])] : [];
    }

    /** The home page should name the business or the site in structured data */
    private static function homeFindings(array $pages): array
    {
        foreach ($pages as $page) {
            $isHome = $page['url'] === '/' && (int) ($page['status_code'] ?? 0) === 200;
            if (!$isHome) continue;

            $named = self::hasOrganisationSchema((array) ($page['jsonld_types'] ?? []));

            return $named ? [] : [self::finding('org_schema_missing', '/')];
        }

        return [];
    }

    /** @param string[] $types */
    private static function hasOrganisationSchema(array $types): bool
    {
        $accepted = \array_map('strtolower', [...self::ORGANISATION_TYPES, 'WebSite']);
        foreach ($types as $type) {
            if (\in_array(\strtolower((string) $type), $accepted, true)) return true;
        }

        return false;
    }

    /** One site-wide finding per AI crawler robots.txt keeps off the home page. Live only: dev and staging block everything on purpose. */
    private static function robotsFindings(string $robotsTxt, bool $isLive): array
    {
        if (!$isLive) return [];

        $findings = [];
        foreach (self::blockedBots($robotsTxt) as $bot => $source) {
            $purpose = self::BOT_PURPOSES[self::AI_BOTS[$bot]];
            $findings[] = self::finding($purpose['check'], null, "{$bot} ({$purpose['text']}) is blocked by {$source} in robots.txt");
        }

        return $findings;
    }
}
