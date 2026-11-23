<?php

declare(strict_types=1);

namespace Osmium\Services\AioAudit\Controllers;

use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Services\AioAudit\Models\AuditRunner;
use Osmium\Services\AioAudit\Models\AuditSettings;
use Osmium\Services\AioAudit\Models\AuditStore;
use Osmium\Services\AioAudit\Models\CheckSummary;
use Osmium\Services\AioAudit\Models\Crawler;
use Osmium\Services\AioAudit\Models\ReportExport;
use Osmium\Services\AioAudit\Models\RunDiff;
use Osmium\Services\AioAudit\Models\AioAuditReport;

/**
 * How findable and citable the site is for AI answer engines: run the audit, read the prioritised fixes, compare runs, change the settings.
 *
 * Routes:
 *   - index()    → /admin/aio-audit/            the report (latest run, or ?run=ID)
 *   - runs()     → /admin/aio-audit/runs/       the history
 *   - settings() → /admin/aio-audit/settings/
 *   - download() → /admin/aio-audit/download/?run=ID   the run as a Markdown file
 *   - action()   → /admin/aio-audit/action/     start, step, get_items, save_settings
 */
class AioAuditController extends AdminController
{
    public function index(): void
    {
        $store = new AuditStore($this->osmium->dataSource);
        $store->failStaleRuns();
        $running = $store->runningRun();

        $requested = $this->requestedRun();
        $run = $requested > 0 ? $store->run($requested) : null;
        $run ??= $this->latestFinished($store);
        $findings = $run === null ? [] : $store->findings((int) $run['id']);

        $this->data['admin']['isAdmin'] = $this->isAdmin();
        $this->data['admin']['lists'] = require __DIR__ . '/../lists/aio-audit.php';
        $this->data['admin']['run'] = $run;
        $this->data['admin']['runningId'] = $running === null ? 0 : (int) $running['id'];
        $this->data['admin']['counts'] = AioAuditReport::severityCounts($findings);
        $this->data['admin']['rating'] = $run === null ? null : AioAuditReport::ratingBadge(
            $run['score'] === null ? null : (int) $run['score'], $this->data['admin']['counts']['critical'], $this->data['admin']['counts']['high'],
        );
        $this->data['admin']['checks'] = $run === null ? [] : CheckSummary::rows($findings, CheckSummary::decode($run['coverage'] ?? null));
        $this->data['admin']['fixes'] = $run === null ? [] : AioAuditReport::fixRows((int) $run['id'], $findings);
        $this->data['admin']['startedLabel'] = $run === null ? '' : \date('d M Y H:i', (int) \strtotime((string) $run['started_at']));
        $this->data['admin']['crawlBase'] = $this->settingsRunner()->crawlBase();

        $this->setView('aio-audit/index.phtml');
    }

    public function runs(): void
    {
        $store = new AuditStore($this->osmium->dataSource);
        $runs = $store->recentRuns();

        $this->data['admin']['isAdmin'] = $this->isAdmin();
        $this->data['admin']['lists'] = require __DIR__ . '/../lists/aio-audit.php';
        $this->data['admin']['runs'] = $this->runRows($store, $runs);

        $this->setView('aio-audit/runs.phtml');
    }

    public function settings(): void
    {
        if (!$this->isAdmin()) {
            $this->redirect('aio-audit/');
            return;
        }

        $this->data['admin']['isAdmin'] = true;
        $this->data['admin']['settings'] = AuditSettings::get();
        $this->data['admin']['siteUrl'] = (string) $this->osmium->config->site->FQDN;

        $this->setView('aio-audit/settings.phtml');
    }

    public function download(): void
    {
        $store = new AuditStore($this->osmium->dataSource);
        $run = $store->run($this->requestedRun());
        $isFinished = $run !== null && $run['status'] === 'done';
        if (!$isFinished) $this->admin->jsonError('No finished audit with that number');

        $siteUrl = (string) $this->osmium->config->site->FQDN;
        $markdown = ReportExport::markdown($run, $store->findings((int) $run['id']), $siteUrl);
        $filename = 'aio-audit-' . \date('Y-m-d', (int) \strtotime((string) $run['started_at'])) . '-run' . (int) $run['id'] . '.md';

        \header('Content-Type: text/markdown; charset=UTF-8');
        \header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $markdown;
        exit;
    }

    public function action()
    {
        \header('Content-Type: application/json');

        $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
        if (!$isPost) $this->admin->jsonError('Method not allowed');

        $input = $this->admin->auth->getJsonInput();
        $action = (string) ($input['action'] ?? '');

        $readOnly = $action === 'get_items';
        $csrfValid = $readOnly || $this->admin->auth->validateCsrfJson($input);
        if (!$csrfValid) $this->admin->jsonError('Invalid request token. Please refresh the page and try again.');

        try {
            $result = match ($action) {
                'start' => $this->start(),
                'step' => $this->step((int) ($input['run'] ?? 0)),
                'get_items' => $this->getItems((int) ($input['id'] ?? 0)),
                'save_settings' => $this->saveSettings($input),
                default => throw new \InvalidArgumentException('Unknown action'),
            };
            $this->admin->jsonSuccess($result);
        } catch (\Exception $e) {
            $this->admin->jsonError($e->getMessage());
        }
    }

    /** The ?run= the router stashed when it redirected to the site's slash form (so $_GET no longer holds it) */
    private function requestedRun(): int
    {
        return (int) $this->osmium->getStashedParam(key: 'run', default: 0);
    }

    private function latestFinished(AuditStore $store): ?array
    {
        $runs = $store->recentRuns(10);
        foreach ($runs as $run) {
            if ($run['status'] === 'done') return $run;
        }

        return null;
    }

    /** @param array<int, array<string, mixed>> $runs newest first */
    private function runRows(AuditStore $store, array $runs): array
    {
        $rows = [];
        $count = \count($runs);
        for ($i = 0; $i < $count; $i++) {
            $previous = $this->previousDone($runs, $i);
            $diff = $previous === null || $runs[$i]['status'] !== 'done' ? null : RunDiff::compare(
                previous: $store->findingKeys((int) $previous['id']),
                current: $store->findingKeys((int) $runs[$i]['id']),
            );
            $rows[] = AioAuditReport::runRow($runs[$i], $diff, $previous === null || $previous['score'] === null ? null : (int) $previous['score']);
        }

        return $rows;
    }

    /** The finished run just before position $index in a newest-first list */
    private function previousDone(array $runs, int $index): ?array
    {
        $count = \count($runs);
        for ($j = $index + 1; $j < $count; $j++) {
            if ($runs[$j]['status'] === 'done') return $runs[$j];
        }

        return null;
    }

    private function start(): array
    {
        $runner = $this->settingsRunner();
        $runId = $runner->start($this->sitePages());
        $this->admin->model->changelog->log(description: 'Started an AIO audit', recordType: 'aio_audit', recordId: $runId);

        return ['success' => true, 'run' => $runId];
    }

    private function step(int $runId): array
    {
        if ($runId < 1) throw new \InvalidArgumentException('Missing run');

        return ['success' => true] + $this->settingsRunner()->step($runId);
    }

    private function getItems(int $id): array
    {
        $target = AioAuditReport::unpackId($id);
        if ($target === null) throw new \InvalidArgumentException('Missing issue');

        $findings = (new AuditStore($this->osmium->dataSource))->findings($target['runId']);
        $items = \array_values(\array_filter($findings, fn(array $f): bool => $f['check_key'] === $target['checkKey']));

        return ['success' => true, 'items' => $items];
    }

    private function saveSettings(array $input): array
    {
        $isAdmin = $this->isAdmin();
        if (!$isAdmin) throw new \InvalidArgumentException('Only an administrator can change these settings');

        $saved = AuditSettings::save($input);
        $this->admin->model->changelog->log(description: 'Updated AIO audit settings', recordType: 'aio_audit', recordId: 0);

        return ['success' => true, 'settings' => $saved];
    }

    private function settingsRunner(): AuditRunner
    {
        $isLive = ($this->osmium->config->site->environment ?? null) === 'live';

        return new AuditRunner(
            store: new AuditStore($this->osmium->dataSource),
            crawler: new Crawler(),
            settings: AuditSettings::get(),
            siteUrl: (string) $this->osmium->config->site->FQDN,
            isLive: $isLive,
        );
    }

    /**
     * Every page of the site worth auditing: the home page plus each live page that is not redirected, flagged by
     * whether the sitemap would list it.
     *
     * @return array<int, array{path: string, inSitemap: bool}>
     */
    private function sitePages(): array
    {
        $redirected = $this->osmium->model->redirect->activePaths();
        $everything = $this->osmium->model->page->getSitemap(mode: 'all', redirectedPaths: $redirected);
        $indexable = $this->osmium->model->page->getSitemap(mode: 'indexable', redirectedPaths: $redirected);
        $listed = \array_flip(\array_column($indexable, 'url'));

        $pages = [['path' => '/', 'inSitemap' => true]];
        foreach ($everything as $page) {
            $path = $this->osmium->url->to('/' . \trim($page['url'], '/') . '/'); // the site's own form: slash or no slash
            $pages[] = ['path' => $path, 'inSitemap' => isset($listed[$page['url']])];
        }

        return $pages;
    }
}
