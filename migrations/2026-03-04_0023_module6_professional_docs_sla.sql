ALTER TABLE professional_documentations
  ADD COLUMN reminders_sent INT UNSIGNED NOT NULL DEFAULT 0 AFTER due_at,
  ADD COLUMN last_reminder_at DATETIME NULL AFTER reminders_sent;

CREATE INDEX idx_prof_docs_last_reminder_at ON professional_documentations (last_reminder_at);
