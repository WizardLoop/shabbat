-- SPDX-License-Identifier: AGPL-3.0-only
-- A durable reservation records uncertainty before each network request. Never rewrite an attempted identity.
ALTER TABLE scheduled_jobs ADD COLUMN dispatch_started_at INTEGER;
ALTER TABLE scheduled_jobs ADD COLUMN last_dispatch_at INTEGER;
-- Earlier releases did not mark the request boundary. Conservatively retain every possibly attempted old delivery.
UPDATE scheduled_jobs SET dispatch_started_at=COALESCE((SELECT MIN(started_at) FROM delivery_log d WHERE d.job_id=scheduled_jobs.job_id),completed_at,created_at),last_dispatch_at=COALESCE((SELECT MAX(started_at) FROM delivery_log d WHERE d.job_id=scheduled_jobs.job_id),completed_at,created_at)
WHERE status='sent' OR attempts>deferrals OR EXISTS(SELECT 1 FROM delivery_log d WHERE d.job_id=scheduled_jobs.job_id AND d.outcome IN ('attempting','uncertain','retry','sent'));
CREATE INDEX jobs_delivery_group ON scheduled_jobs(group_id,module,status,completed_at,last_dispatch_at);
CREATE INDEX jobs_tefillin_coalesce ON scheduled_jobs(module,status,group_id,run_at,expires_at,dispatch_started_at);
CREATE INDEX jobs_retention ON scheduled_jobs(status,expires_at,completed_at);
CREATE INDEX delivery_retention ON delivery_log(job_id,outcome);
CREATE INDEX cache_retention ON calendar_cache(expires_at);
CREATE INDEX audit_retention ON audit_log(created_at,audit_id);
CREATE INDEX jobs_active_window ON scheduled_jobs(run_at,expires_at) WHERE status IN ('pending','leased');
CREATE INDEX jobs_uncertain_evidence ON scheduled_jobs(last_dispatch_at) WHERE status<>'sent' AND dispatch_started_at IS NOT NULL;
CREATE INDEX jobs_sent_recent ON scheduled_jobs(completed_at,run_at) WHERE status='sent';
CREATE INDEX jobs_expired_recent ON scheduled_jobs(expires_at) WHERE status='expired';
