ALTER TABLE professional_documentations
  ADD COLUMN appointment_id BIGINT UNSIGNED NULL AFTER professional_user_id,
  ADD KEY idx_prof_docs_appointment_id (appointment_id);

ALTER TABLE professional_documentations
  ADD CONSTRAINT fk_prof_docs_appointment
  FOREIGN KEY (appointment_id) REFERENCES appointments(id)
  ON DELETE SET NULL;
