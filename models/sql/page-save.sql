UPDATE {PREFIX}aio_audit_pages SET fetched = 1, status_code = :status, redirect_target = :redirect, fetch_error = :error,
    robots = :robots, jsonld_blocks = :blocks, jsonld_invalid = :invalid, jsonld_types = :types, word_count = :words, app_shell = :shell,
    response_ms = :ms, bytes = :bytes, links = :links
WHERE id = :id
