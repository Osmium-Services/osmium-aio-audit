SELECT r.*, (SELECT COUNT(*) FROM {PREFIX}aio_audit_findings f WHERE f.run_id = r.id) AS finding_count
FROM {PREFIX}aio_audit_runs r ORDER BY r.id DESC LIMIT :limit
