<?php

declare(strict_types=1);

namespace Tests\Services\AioAudit;

use Osmium\Services\AioAudit\Models\AioAuditMcp;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/Checks.php';
require_once __DIR__ . '/../models/Scoring.php';
require_once __DIR__ . '/../models/RunDiff.php';
require_once __DIR__ . '/../models/AioAuditReport.php';
require_once __DIR__ . '/../models/AioAuditMcp.php';

/** The audit tool the /mcp endpoint offers: a score, counts and capped, masked fixes. Read only, no addresses. */
final class AioAuditMcpTest extends TestCase
{
    private function run_(): array
    {
        return ['id' => 7, 'started_at' => '2026-11-20 09:00:00', 'pages_checked' => 40, 'score' => 82, 'status' => 'done'];
    }

    private function finding(string $check, string $severity, ?string $url, ?string $detail = null): array
    {
        return ['check_key' => $check, 'severity' => $severity, 'page_url' => $url, 'detail' => $detail];
    }

    public function test_the_tool_is_described_and_read_only(): void
    {
        $tool = AioAuditMcp::tools([])[0];

        $this->assertSame('aio_audit_latest', $tool['name']);
        $this->assertSame(AioAuditMcp::class . '::latest', $tool['handler']);
        $this->assertStringContainsString('Read only', $tool['description']);
    }

    public function test_score_counts_and_fixes_come_through_most_serious_first(): void
    {
        $result = AioAuditMcp::shape($this->run_(), [
            $this->finding('page_error', 'critical', '/broken/'),
            $this->finding('jsonld_invalid', 'medium', '/a/'),
            $this->finding('jsonld_invalid', 'medium', '/b/'),
        ], ['new' => 1, 'fixed' => 2, 'unchanged' => 3]);

        $this->assertSame(7, $result['audit']['run_id']);
        $this->assertSame(82, $result['audit']['score']);
        $this->assertSame(['critical' => 1, 'high' => 0, 'medium' => 2, 'low' => 0], $result['audit']['findings_by_priority']);
        $this->assertSame(['new' => 1, 'fixed' => 2, 'unchanged' => 3], $result['audit']['since_previous_audit']);
        $this->assertSame('critical', $result['fixes'][0]['priority']);
        $this->assertSame(2, $result['fixes'][1]['affected']);
        $this->assertNotSame('', $result['fixes'][0]['how_to_fix']);
    }

    public function test_pages_are_capped_per_fix_and_the_rest_counted(): void
    {
        $findings = [];
        for ($i = 0; $i < 30; $i++) $findings[] = $this->finding('jsonld_invalid', 'medium', "/p{$i}/");

        $fix = AioAuditMcp::shape($this->run_(), $findings, null)['fixes'][0];

        $this->assertCount(20, $fix['pages']);
        $this->assertSame(30, $fix['affected']);
        $this->assertSame(10, $fix['more_not_shown']);
    }

    public function test_email_shaped_text_in_a_detail_is_masked_and_long_details_capped(): void
    {
        $detail = 'Title is "Contact jo.smith@client-domain.test now" ' . \str_repeat('x', 400);

        $fix = AioAuditMcp::shape($this->run_(), [$this->finding('jsonld_invalid', 'medium', '/c/', $detail)], null)['fixes'][0];

        $json = \json_encode($fix);
        $this->assertStringNotContainsString('jo.smith', $json);
        $this->assertStringNotContainsString('client-domain', $json);
        $this->assertStringContainsString('[email]', $fix['pages'][0]['detail']);
        $this->assertLessThanOrEqual(200, \mb_strlen($fix['pages'][0]['detail']));
    }

    public function test_an_unknown_check_key_is_skipped_rather_than_breaking_the_report(): void
    {
        $result = AioAuditMcp::shape($this->run_(), [$this->finding('no_such_check', 'low', '/x/')], null);

        $this->assertSame([], $result['fixes']);
    }

    public function test_no_internal_tokens_or_run_internals_are_returned(): void
    {
        $run = $this->run_() + ['token' => 'abcdef0123456789abcdef0123456789', 'base_url' => 'http://internal.test', 'page_cap' => 500];

        $json = \json_encode(AioAuditMcp::shape($run, [], null));

        $this->assertStringNotContainsString('abcdef0123456789', $json);
        $this->assertStringNotContainsString('internal.test', $json);
    }
}
