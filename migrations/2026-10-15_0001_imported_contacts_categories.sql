-- Separa contatos importados em profissional, operadora/cliente, paciente e outros.

ALTER TABLE imported_contacts
    MODIFY category ENUM('cliente','profissional','operadora','paciente','outros') NOT NULL DEFAULT 'outros';

UPDATE imported_contacts SET category = 'operadora' WHERE category = 'cliente';
