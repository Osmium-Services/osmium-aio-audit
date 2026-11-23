<?php

declare(strict_types=1);

namespace Tests\Services\AioAudit;

use Osmium\Services\AioAudit\Models\PageParser;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/PageParser.php';

class PageParserTest extends TestCase
{
    private const PAGE = <<<'HTML'
<!doctype html><html><head><title>Solar</title>
<meta name="robots" content="index,follow">
<script type="application/ld+json">{"@context":"https://schema.org","@type":"Organization","name":"X"}</script>
<script type="application/ld+json">{broken</script>
<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"WebSite"},{"@type":["LocalBusiness","Plumber"]},{"@type":"WebPage"}]}</script>
<script type="application/ld+json">[{"@type":"https://schema.org/FAQPage"},{"@type":"Organization"}]</script>
</head>
<body><h1>Solar</h1>
<a href="/about/">About</a><a href="contact/">Contact</a><a href="https://example.com/shop/?x=1#top">Shop</a>
<a href="https://other.com/x/">Other</a><a href="mailto:a@b.c">Mail</a><a href="#top">Top</a><a href="/files/a.pdf">PDF</a>
</body></html>
HTML;

    private function parsed(string $html = self::PAGE): array
    {
        return PageParser::parse($html, '/solar/', 'example.com');
    }

    public function test_it_counts_blocks_and_broken_blocks(): void
    {
        $page = $this->parsed();

        $this->assertSame(4, $page['jsonld_blocks']);
        $this->assertSame(1, $page['jsonld_invalid']);
    }

    public function test_it_records_types_from_objects_graphs_lists_and_schema_urls(): void
    {
        $types = $this->parsed()['jsonld_types'];

        $this->assertEqualsCanonicalizing(['Organization', 'WebSite', 'LocalBusiness', 'Plumber', 'WebPage', 'FAQPage'], $types);
    }

    public function test_a_page_with_no_json_ld_has_zero_blocks_and_an_empty_type_list(): void
    {
        $page = $this->parsed('<html><body><p>Hi</p></body></html>');

        $this->assertSame(0, $page['jsonld_blocks']);
        $this->assertSame([], $page['jsonld_types']);
    }

    public function test_jsonld_types_handles_odd_shapes(): void
    {
        $this->assertSame([], PageParser::jsonLdTypes('text'));
        $this->assertSame([], PageParser::jsonLdTypes(null));
        $this->assertSame([], PageParser::jsonLdTypes(['name' => 'no type']));
        $this->assertSame(['A', 'B'], PageParser::jsonLdTypes(['@type' => ['A', 'B', '', 7]]));
        $this->assertSame(['Thing'], PageParser::jsonLdTypes(['@type' => 'http://www.schema.org/Thing']));
        $this->assertSame(['Outer', 'Inner'], PageParser::jsonLdTypes(['@type' => 'Outer', '@graph' => [['@type' => 'Inner'], 'junk', ['@graph' => 'nope']]]));
        $this->assertSame(['A'], PageParser::jsonLdTypes([['@type' => 'A'], ['@type' => 'A'], 'x']));
    }

    public function test_a_nested_type_is_not_counted(): void
    {
        $types = PageParser::jsonLdTypes(['@type' => 'Article', 'publisher' => ['@type' => 'Organization']]);

        $this->assertSame(['Article'], $types);
    }

    public function test_robots_come_from_the_meta_tag_and_the_header(): void
    {
        $page = PageParser::parse(self::PAGE, '/solar/', 'example.com', 'NoIndex');

        $this->assertSame('index,follow,noindex', $page['robots']);
        $this->assertTrue(PageParser::isNoindex($page['robots']));
        $this->assertTrue(PageParser::isNoindex('none'));
        $this->assertFalse(PageParser::isNoindex('index,follow'));
    }

    public function test_internal_links_are_resolved_and_external_ones_skipped(): void
    {
        $links = $this->parsed()['links'];

        $this->assertSame(['/about/', '/solar/contact/', '/shop/'], $links);
    }

    public function test_internal_path_rules(): void
    {
        $this->assertSame('/a/', PageParser::internalPath('//example.com/a/', '/', 'example.com'));
        $this->assertSame('/a/', PageParser::internalPath('https://www.example.com/a/', '/', 'example.com'));
        $this->assertSame('/x/b/', PageParser::internalPath('../b/', '/x/y/', 'example.com'));
        $this->assertSame('/p/', PageParser::internalPath('?page=2', '/p/', 'example.com'));
        $this->assertNull(PageParser::internalPath('/cdn-cgi/l/email', '/', 'example.com'));
        $this->assertNull(PageParser::internalPath('tel:123', '/', 'example.com'));
        $this->assertNull(PageParser::internalPath('/a/photo.JPG', '/', 'example.com'));
        $this->assertNull(PageParser::internalPath('http://[bad', '/', 'example.com'));
    }

    public function test_it_counts_the_words_a_visitor_reads_not_scripts_or_noscript(): void
    {
        $html = '<html><body><h1>One two</h1><script>var a = "ignored words here";</script><noscript>also ignored</noscript><p>three four five</p></body></html>';

        $this->assertSame(5, $this->parsed($html)['word_count']);
        $this->assertSame(0, $this->parsed('<html><body></body></html>')['word_count']);
    }

    /** @dataProvider shells */
    public function test_an_empty_app_container_on_a_page_with_scripts_is_a_shell(string $body, bool $expected): void
    {
        $page = $this->parsed('<html><body>' . $body . '</body></html>');

        $this->assertSame($expected, $page['app_shell']);
    }

    public static function shells(): array
    {
        $script = '<script src="/bundle.js"></script>';

        return [
            'empty root' => ['<div id="root"></div>' . $script, true],
            'empty next' => ['<div id="__next"> </div>' . $script, true],
            'empty app with only a noscript note' => ['<div id="app"><noscript>Enable JS</noscript></div>' . $script, true],
            'empty root, no scripts' => ['<div id="root"></div>', false],
            'inline script only' => ['<div id="root"></div><script>init()</script>', false],
            'root with server-rendered text' => ['<div id="root"><h1>Hello world</h1></div>' . $script, false],
            'no container at all' => ['<p>Hi</p>' . $script, false],
            'unrelated empty div' => ['<div id="spacer"></div>' . $script, false],
        ];
    }

    /** @dataProvider snippetDirectives */
    public function test_snippet_directives_in_combined_robots_values(string $robots, bool $expected): void
    {
        $this->assertSame($expected, PageParser::blocksSnippets($robots));
    }

    public static function snippetDirectives(): array
    {
        return [
            'nosnippet alone' => ['nosnippet', true],
            'in a list' => ['index,follow,nosnippet', true],
            'spaces after commas' => ['index, nosnippet, noarchive', true],
            'uppercase' => ['NoSnippet', true],
            'max-snippet 0' => ['max-snippet:0', true],
            'max-snippet 0 with space' => ['index, max-snippet: 0', true],
            'google-specific' => ['googlebot: nosnippet', true],
            'google-specific max-snippet' => ['googlebot: max-snippet:0', true],
            'meta and header joined' => ['index,follow,noarchive,nosnippet', true],
            'max-snippet 10' => ['max-snippet:10', false],
            'max-snippet unlimited' => ['max-snippet:-1', false],
            'max-snippet 0 prefix of larger number' => ['max-snippet:05', false],
            'another crawler only' => ['bingbot: nosnippet', false],
            'plain index' => ['index,follow', false],
            'noindex' => ['noindex,nofollow', false],
            'empty' => ['', false],
            'text that only contains the word' => ['nosnippets', false],
        ];
    }
}
