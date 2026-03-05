ALTER TABLE professional_documentations
  ADD COLUMN patient_id BIGINT UNSIGNED NULL AFTER appointment_id;

CREATE INDEX idx_prof_docs_patient_id ON professional_documentations (patient_id);

ALTER TABLE professional_documentations
  ADD CONSTRAINT fk_prof_docs_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE SET NULL;
