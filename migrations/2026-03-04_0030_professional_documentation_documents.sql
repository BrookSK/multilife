CREATE TABLE IF NOT EXISTS professional_documentation_documents (
  documentation_id BIGINT UNSIGNED NOT NULL,
  document_id BIGINT UNSIGNED NOT NULL,
  doc_kind ENUM('billing','productivity') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (documentation_id, document_id, doc_kind),
  KEY idx_pdd_document_id (document_id),
  KEY idx_pdd_doc_kind (doc_kind),
  CONSTRAINT fk_pdd_doc FOREIGN KEY (documentation_id) REFERENCES professional_documentations(id) ON DELETE CASCADE,
  CONSTRAINT fk_pdd_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
