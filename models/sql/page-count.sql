SELECT COUNT(*) AS total, COALESCE(SUM(fetched), 0) AS done FROM {PREFIX}aio_audit_pages WHERE run_id = :run
