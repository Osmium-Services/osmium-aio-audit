<?php

declare(strict_types=1);

namespace Osmium\Services\AioAudit\Models;

/**
 * Reads one fetched HTML page into the facts the checks need: robots directives, the JSON-LD blocks (how many, how many
 * are broken, which @type values they declare), how much text the raw HTML holds and whether it has an empty app
 * container for scripts to fill, and the internal links the crawl follows. Pure: no network, no DB.
 */
class PageParser
{
    /** ids of the empty containers single-page app frameworks render into */
    private const APP_ROOT_IDS = ['root', 'app', '__next', '__nuxt', 'app-root', 'svelte', '___gatsby'];

    private const NOT_PAGE_LINKS = ['mailto:', 'tel:', 'javascript:', 'sms:', 'data:', 'whatsapp:'];

    /**
     * @param string $html the page body
     * @param string $pagePath the page's own path, for resolving relative links
     * @param string $host the site's host, so links to it count as internal
     * @param string $robotsHeader the X-Robots-Tag response header, if any
     * @return array<string, mixed>
     */
    public static function parse(string $html, string $pagePath, string $host, string $robotsHeader = ''): array
    {
        $dom = self::load($html);
        $xpath = new \DOMXPath($dom);

        $jsonLd = self::jsonLd($xpath);
        $metaRobots = self::metaContent($xpath, 'name', 'robots');
        $robots = \strtolower(\trim($metaRobots . ',' . $robotsHeader, ', '));

        return [
            'robots' => $robots,
            'jsonld_blocks' => $jsonLd['blocks'],
            'jsonld_invalid' => $jsonLd['invalid'],
            'jsonld_types' => $jsonLd['types'],
            'word_count' => self::wordCount(self::visibleText($xpath, '//body')),
            'app_shell' => self::hasEmptyAppShell($xpath),
            'links' => self::internalLinks($xpath, $pagePath, $host),
        ];
    }

    /** Whether a robots value (meta and header joined) tells search engines not to index */
    public static function isNoindex(string $robots): bool
    {
        return \str_contains(\strtolower($robots), 'noindex') || \str_contains(\strtolower($robots), 'none');
    }

    /**
     * Whether a robots value (meta and header joined) forbids snippets: nosnippet or max-snippet:0, bare or for Google
     * (`googlebot: nosnippet`). A directive for another named crawler (`bingbot: nosnippet`) does not count.
     */
    public static function blocksSnippets(string $robots): bool
    {
        foreach (\explode(',', \strtolower($robots)) as $piece) {
            $isBlock = \preg_match('/^\s*(?:(?:googlebot|google)\s*:\s*)?(?:nosnippet|max-snippet\s*:\s*0)\s*$/', $piece) === 1;
            if ($isBlock) return true;
        }

        return false;
    }

    /**
     * The @type values a decoded JSON-LD block declares at its top level: the object itself, each object of a
     * top-level list, and each entity of a @graph. A schema.org address prefix is dropped (https://schema.org/Thing
     * gives Thing). Types of nested values, such as a publisher inside an Article, are not counted.
     *
     * @return string[]
     */
    public static function jsonLdTypes(mixed $decoded): array
    {
        $isNode = \is_array($decoded);
        if (!$isNode) return [];

        $entities = \array_is_list($decoded) ? $decoded : [$decoded];
        $types = [];
        foreach ($entities as $entity) {
            $types = [...$types, ...self::entityTypes($entity)];
        }

        return \array_values(\array_unique($types));
    }

    /** The path a link points to when it stays on this site, or null for anything else */
    public static function internalPath(string $href, string $pagePath, string $host): ?string
    {
        $path = self::resolveLocal($href, $pagePath, $host);
        if ($path === null) return null;

        $isFile = (bool) \preg_match('/\.(jpe?g|png|gif|webp|svg|ico|pdf|zip|css|js|xml|txt|mp4|mp3|docx?|xlsx?)$/i', $path);

        return $isFile ? null : $path;
    }

    private static function load(string $html): \DOMDocument
    {
        $dom = new \DOMDocument();
        $previous = \libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT);
        \libxml_clear_errors();
        \libxml_use_internal_errors($previous);

        return $dom;
    }

    private static function metaContent(\DOMXPath $xpath, string $attribute, string $value): string
    {
        $query = '//meta[translate(@' . $attribute . ', "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="' . $value . '"]';
        $node = $xpath->query($query)->item(0);
        $isElement = $node instanceof \DOMElement;

        return $isElement ? \trim($node->getAttribute('content')) : '';
    }

    /** The text a visitor reads inside the nodes the query finds, without scripts, styles and templates, space-separated */
    private static function visibleText(\DOMXPath $xpath, string $root): string
    {
        $query = $root . '//text()[not(ancestor::script or ancestor::style or ancestor::noscript or ancestor::template)]';
        $parts = [];
        foreach ($xpath->query($query) as $node) {
            $parts[] = $node->nodeValue;
        }

        return \trim((string) \preg_replace('/\s+/u', ' ', \implode(' ', $parts)));
    }

    private static function wordCount(string $text): int
    {
        if ($text === '') return 0;

        return \count(\preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /** An app framework's container (#root, #app, #__next ...) with no text in it, on a page that loads scripts */
    private static function hasEmptyAppShell(\DOMXPath $xpath): bool
    {
        $hasScripts = $xpath->query('//script[@src]')->length > 0;
        if (!$hasScripts) return false;

        foreach (self::APP_ROOT_IDS as $id) {
            $root = '//*[@id="' . $id . '"]';
            $exists = $xpath->query($root)->length > 0;
            $isEmpty = $exists && self::visibleText($xpath, $root) === '';
            if ($isEmpty) return true;
        }

        return false;
    }

    /** @return array{blocks: int, invalid: int, types: string[]} */
    private static function jsonLd(\DOMXPath $xpath): array
    {
        $found = ['blocks' => 0, 'invalid' => 0, 'types' => []];
        foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
            $found['blocks']++;
            $decoded = \json_decode($script->textContent, associative: true);
            $isBroken = \json_last_error() !== JSON_ERROR_NONE;
            if ($isBroken) $found['invalid']++;
            if (!$isBroken) $found['types'] = [...$found['types'], ...self::jsonLdTypes($decoded)];
        }
        $found['types'] = \array_values(\array_unique($found['types']));

        return $found;
    }

    /** @return string[] */
    private static function entityTypes(mixed $entity): array
    {
        $isObject = \is_array($entity) && !\array_is_list($entity);
        if (!$isObject) return [];

        $types = [];
        foreach ((array) ($entity['@type'] ?? []) as $type) {
            $isName = \is_string($type) && \trim($type) !== '';
            if ($isName) $types[] = (string) \preg_replace('~^https?://(www\.)?schema\.org/~i', '', \trim($type));
        }

        $graph = $entity['@graph'] ?? [];
        $hasGraph = \is_array($graph) && \array_is_list($graph);
        foreach ($hasGraph ? $graph : [] as $member) {
            $types = [...$types, ...self::entityTypes($member)];
        }

        return $types;
    }

    /** @return string[] distinct internal link paths (no query or fragment), resolved against the page */
    private static function internalLinks(\DOMXPath $xpath, string $pagePath, string $host): array
    {
        $paths = [];
        foreach ($xpath->query('//a[@href]') as $anchor) {
            $path = self::internalPath(\trim($anchor->getAttribute('href')), $pagePath, $host);
            if ($path !== null) $paths[$path] = true;
        }

        return \array_keys($paths);
    }

    /**
     * The path of an address on this site, resolved against the page it appears on, or null for another host, a
     * non-page scheme (mailto, data) or Cloudflare's proxy paths.
     */
    private static function resolveLocal(string $href, string $pagePath, string $host): ?string
    {
        $isEmpty = $href === '' || $href[0] === '#';
        if ($isEmpty) return null;

        $lower = \strtolower($href);
        foreach (self::NOT_PAGE_LINKS as $prefix) {
            if (\str_starts_with($lower, $prefix)) return null;
        }

        $parts = \parse_url(\str_starts_with($href, '//') ? 'https:' . $href : $href);
        if ($parts === false) return null;

        $linkHost = isset($parts['host']) ? \strtolower($parts['host']) : null;
        $isExternal = $linkHost !== null && $linkHost !== \strtolower($host) && $linkHost !== 'www.' . \strtolower($host) && 'www.' . $linkHost !== \strtolower($host);
        if ($isExternal) return null;

        $path = $parts['path'] ?? '';
        $isRelative = $path === '' || $path[0] !== '/';
        if ($isRelative) $path = self::resolveRelative($path, $pagePath);

        $isProxyPath = \str_starts_with($path, '/cdn-cgi/'); // Cloudflare's own paths (email hiding) exist only through its proxy
        if ($isProxyPath) return null;

        return $path === '' ? '/' : $path;
    }

    private static function resolveRelative(string $path, string $pagePath): string
    {
        $isSamePage = $path === '';
        if ($isSamePage) return $pagePath;

        $directory = \str_ends_with($pagePath, '/') ? $pagePath : \dirname($pagePath) . '/';
        $segments = [];
        foreach (\explode('/', $directory . $path) as $segment) {
            if ($segment === '..') \array_pop($segments);
            if ($segment !== '' && $segment !== '.' && $segment !== '..') $segments[] = $segment;
        }

        $trailing = \str_ends_with($path, '/') ? '/' : '';

        return '/' . \implode('/', $segments) . $trailing;
    }
}
