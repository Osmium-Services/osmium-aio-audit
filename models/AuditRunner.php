<?php

declare(strict_types=1);

namespace Osmium\Services\AioAudit\Models;

/**
 * Drives one audit run in small steps. The admin page calls step() repeatedly, each call fetching a few pages inside
 * a time budget, so no background process is needed. The last step runs the checks and closes the run.
 */
class AuditRunner
{
    private const BATCH = 2;

    /**
     * @param array{pageCap: int, delayMs: int, baseUrl: string} $settings
     * @param string $siteUrl the site's public address (config FQDN), whose host marks a link as internal
     * @param bool $isLive whether the site's environment is live, which switches on the robots.txt check
     */
    public function __construct(
        private AuditStore $store,
        private Crawler $crawler,
        private array $settings,
        private string $siteUrl,
        private bool $isLive,
    ) {}

    /** The address requests go to: the setting, or the site's own address */
    public function crawlBase(): string
    {
        $configured = $this->settings['baseUrl'];

        return $configured !== '' ? $configured : \rtrim($this->siteUrl, '/');
    }

    /**
     * @param array<int, array{path: string, inSitemap: bool}> $pages the site's own pages
     * @return int the new run's id
     * @throws \RuntimeException when a run is already in progress
     */
    public function start(array $pages): int
    {
        $this->store->failStaleRuns();
        $isRunning = $this->store->runningRun() !== null;
        if ($isRunning) throw new \RuntimeException('An audit is already running');

        $token = \bin2hex(\random_bytes(16));
        $runId = $this->store->createRun(baseUrl: $this->crawlBase(), pageCap: $this->settings['pageCap'], token: $token);

        $added = 0;
        foreach ($pages as $page) {
            $full = $added >= $this->settings['pageCap'];
            if ($full) break;
            if ($this->store->addPage(runId: $runId, url: $page['path'], source: 'site', inSitemap: $page['inSitemap'])) $added++;
        }

        if ($this->isLive) $this->queueSitemapPages($runId, $token);

        return $runId;
    }

    /** Largest number of child sitemaps followed from an index, so a bad index cannot keep a run busy */
    private const MAX_CHILD_SITEMAPS = 50;

    /**
     * The root sitemap's status and every page address it lists. A sitemap index is followed one level down (own host
     * only); the status stays the root's, and a child that cannot be fetched adds nothing.
     *
     * @return array{status: int, paths: string[]}
     */
    private function readSitemap(string $token): array
    {
        $root = $this->crawler->fetch($this->crawlBase() . '/sitemap.xml', $token);
        $host = $this->siteHost();
        $paths = SitemapReader::paths($root['body'], $host);

        foreach (\array_slice(SitemapReader::children($root['body'], $host), 0, self::MAX_CHILD_SITEMAPS) as $child) {
            $response = $this->crawler->fetch($this->crawlBase() . $child, $token);
            if ($response['status'] !== 200) continue;
            $paths = \array_merge($paths, SitemapReader::paths($response['body'], $host));
        }

        return ['status' => $root['status'], 'paths' => \array_values(\array_unique($paths))];
    }

    /** Every address the live sitemap lists is checked too, in the exact form it is listed */
    private function queueSitemapPages(int $runId, string $token): void
    {
        $sitemap = $this->readSitemap($token);
        $found = $sitemap['status'] === 200;
        if (!$found) return;

        $total = $this->store->progress($runId)['total'];
        foreach ($sitemap['paths'] as $path) {
            $full = $total >= $this->settings['pageCap'];
            $tooLong = \strlen($path) > 255;
            if ($full) return;
            if ($tooLong) continue;
            if ($this->store->addPage(runId: $runId, url: $path, source: 'sitemap', inSitemap: true)) $total++;
        }
    }

    /**
     * Fetch the next few pages, or finish the run when none are left.
     *
     * @return array{status: string, done: int, total: int, finished: bool, score: ?int}
     */
    public function step(int $runId, float $budgetSeconds = 4.0): array
    {
        $run = $this->store->run($runId);
        $notRunning = $run === null || $run['status'] !== 'running';
        if ($notRunning) return $this->state($runId, $run['status'] ?? 'missing', null);

        $started = \microtime(true);
        $token = (string) $run['token'];
        $pending = $this->store->pendingPages($runId, self::BATCH);
        while ($pending !== []) {
            foreach ($pending as $page) {
                $took = $this->fetchPage($runId, $page, $token, (int) $run['page_cap']);
                \usleep(\max($this->settings['delayMs'], $took * 2) * 1000); // back off when the site is slow
            }

            $outOfTime = (\microtime(true) - $started) > $budgetSeconds;
            if ($outOfTime) return $this->state($runId, 'running', null);

            $pending = $this->store->pendingPages($runId, self::BATCH);
        }

        $score = $this->finish($runId, $token);

        return $this->state($runId, 'done', $score);
    }

    /** @return array{status: string, done: int, total: int, finished: bool, score: ?int} */
    private function state(int $runId, string $status, ?int $score): array
    {
        $progress = $this->store->progress($runId);

        return ['status' => $status, 'done' => $progress['done'], 'total' => $progress['total'], 'finished' => $status !== 'running', 'score' => $score];
    }

    /** @param array{id: int, url: string, source: string} $page */
    private function fetchPage(int $runId, array $page, string $token, int $pageCap): int
    {
        $response = $this->crawler->fetch($this->crawlBase() . $page['url'], $token);
        $status = $response['status'];
        $contentType = \strtolower($response['headers']['content-type'] ?? '');
        $isHtml = $status === 200 && \str_contains($contentType, 'html');

        $facts = $isHtml ? PageParser::parse($response['body'], $page['url'], $this->siteHost(), $response['headers']['x-robots-tag'] ?? '') : [];
        if ($isHtml) $this->queueLinks($runId, $facts['links'], $pageCap);

        $this->store->savePage(
            id: (int) $page['id'], status: $status, redirect: $this->redirectTarget($response['headers']['location'] ?? null),
            error: $response['error'], facts: $facts, ms: $response['ms'], bytes: $response['bytes'],
        );

        return $response['ms'];
    }

    /** @param string[] $links */
    private function queueLinks(int $runId, array $links, int $pageCap): void
    {
        $total = $this->store->progress($runId)['total'];
        foreach ($links as $link) {
            $full = $total >= $pageCap;
            $tooLong = \strlen($link) > 255;
            if ($full) return;
            if ($tooLong) continue;
            if ($this->store->addPage(runId: $runId, url: $link, source: 'linked', inSitemap: false)) $total++;
        }
    }

    /** A redirect's Location as a path when it stays on the site, otherwise the full address */
    private function redirectTarget(?string $location): ?string
    {
        if ($location === null || $location === '') return null;

        $path = PageParser::internalPath($location, '/', $this->siteHost());
        $query = \parse_url($location, PHP_URL_QUERY);

        return $path === null ? \mb_substr($location, 0, 500) : $path . ($query ? '?' . $query : '');
    }

    private function finish(int $runId, string $token): int
    {
        $pages = $this->store->pages($runId);
        $robotsTxt = $this->isLive ? $this->robotsTxt($token) : null;
        $context = ['baseUrl' => $this->siteUrl, 'isLive' => $this->isLive, 'robotsTxt' => (string) $robotsTxt, 'robotsRead' => $robotsTxt !== null];

        $findings = Checks::run($pages, $context);
        $score = Scoring::score($findings, \count($pages));
        $this->store->addFindings($runId, $findings);
        $this->store->finishRun(id: $runId, status: 'done', pages: \count($pages), score: $score, coverage: Checks::coverage($pages, $context));

        return $score;
    }

    /** The live site's robots.txt, or null when it does not return 200 (nothing is blocked then, and the bot checks are skipped) */
    private function robotsTxt(string $token): ?string
    {
        $robots = $this->crawler->fetch($this->crawlBase() . '/robots.txt', $token);

        return $robots['status'] === 200 ? $robots['body'] : null;
    }

    private function siteHost(): string
    {
        return (string) \parse_url($this->siteUrl, PHP_URL_HOST);
    }
}
