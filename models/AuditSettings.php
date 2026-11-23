<?php

declare(strict_types=1);

namespace Osmium\Services\AioAudit\Models;

/**
 * The audit's settings, kept in app/config/services/osmium-aio-audit.json.php: how many pages one run may check, the
 * pause between requests and the address to crawl (blank = the site's own address).
 */
class AuditSettings
{
    public const PATH = 'app/config/services/osmium-aio-audit.json.php';

    /** @return array{pageCap: int, delayMs: int, baseUrl: string} */
    public static function defaults(): array
    {
        return ['pageCap' => 100, 'delayMs' => 1000, 'baseUrl' => ''];
    }

    /** @return array{pageCap: int, delayMs: int, baseUrl: string} */
    public static function get(string $path = self::PATH): array
    {
        $defaults = self::defaults();

        $fileExists = \file_exists($path);
        if (!$fileExists) return $defaults;

        $content = (string) \file_get_contents($path);
        $jsonStart = \strpos(haystack: $content, needle: '{');
        $noJsonFound = $jsonStart === false;
        if ($noJsonFound) return $defaults;

        $decoded = \json_decode(\substr(string: $content, offset: $jsonStart), associative: true);
        $saved = \is_array($decoded) ? ($decoded['aioAudit'] ?? []) : [];
        if (!\is_array($saved)) return $defaults;

        return self::clean($saved);
    }

    /**
     * @param array<string, mixed> $input
     * @throws \InvalidArgumentException
     */
    public static function save(array $input, string $path = self::PATH): array
    {
        $clean = self::clean($input);
        self::assertBaseUrl($clean['baseUrl']);

        $dir = \dirname($path);
        $dirMissing = !\is_dir($dir);
        if ($dirMissing) \mkdir(directory: $dir, permissions: 0755, recursive: true);

        $json = \json_encode(value: ['aioAudit' => $clean], flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        \file_put_contents($path, "<?php exit(); ?>\n" . $json . "\n");

        return $clean;
    }

    /** @param array<string, mixed> $input */
    private static function clean(array $input): array
    {
        $defaults = self::defaults();
        return [
            'pageCap' => \max(1, \min(2000, (int) ($input['pageCap'] ?? $defaults['pageCap']))),
            'delayMs' => \max(500, \min(5000, (int) ($input['delayMs'] ?? $defaults['delayMs']))),
            'baseUrl' => \rtrim(\trim((string) ($input['baseUrl'] ?? '')), '/'),
        ];
    }

    private static function assertBaseUrl(string $baseUrl): void
    {
        if ($baseUrl === '') return;

        $parts = \parse_url($baseUrl);
        $scheme = \is_array($parts) ? ($parts['scheme'] ?? '') : '';
        $valid = \in_array($scheme, ['http', 'https'], true) && !empty($parts['host']) && !isset($parts['path']);
        if (!$valid) throw new \InvalidArgumentException('Crawl address must look like https://example.com or http://localhost (no path)');
    }
}
