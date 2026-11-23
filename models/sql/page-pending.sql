SELECT id, url, source FROM {PREFIX}aio_audit_pages WHERE run_id = :run AND fetched = 0
ORDER BY FIELD(source, 'site', 'sitemap', 'linked'), id LIMIT :limit
