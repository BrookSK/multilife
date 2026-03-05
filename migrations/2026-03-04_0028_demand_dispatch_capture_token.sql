ALTER TABLE demand_dispatch_logs
  ADD COLUMN capture_token VARCHAR(60) NULL AFTER message;

CREATE INDEX idx_demand_dispatch_logs_capture_token ON demand_dispatch_logs (capture_token);
