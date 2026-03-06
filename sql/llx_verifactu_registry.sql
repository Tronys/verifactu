-- Tabla principal de registros VeriFactu
-- Archivo: llx_verifactu_registry.sql

CREATE TABLE llx_verifactu_registry (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    entity INTEGER NOT NULL DEFAULT 1,

    fk_facture INTEGER NOT NULL,          -- Factura Dolibarr
    record_type VARCHAR(16) NOT NULL,     -- ALTA / BAJA
    date_creation DATETIME NOT NULL,

    total_ttc DOUBLE(24,8) DEFAULT 0,

    hash_actual VARCHAR(255) NOT NULL,
    hash_anterior VARCHAR(255) NULL,

    xml_path VARCHAR(255) NULL,            -- XML original
    xml_vf_path VARCHAR(255) NULL,         -- XML VeriFactu
    xml_signed_path VARCHAR(255) NULL,     -- XML firmado XAdES
    xml_vf_hash CHAR(64) NULL,              -- Hash SHA256 XML VF

    signature_status VARCHAR(20) NULL,     -- signed / error / pending

    aeat_status VARCHAR(32) NULL,           -- ACCEPTED / REJECTED / ERROR
    aeat_csv VARCHAR(64) NULL,              -- CSV devuelto por AEAT
    aeat_message TEXT NULL,                 -- Mensaje AEAT
    aeat_sent_at DATETIME NULL,             -- Fecha/hora envío
    aeat_response LONGTEXT NULL,             -- Respuesta completa AEAT

    tms TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;


CREATE INDEX idx_verifactu_entity      ON llx_verifactu_registry (entity);
CREATE INDEX idx_verifactu_fk_facture  ON llx_verifactu_registry (fk_facture);
CREATE INDEX idx_verifactu_date        ON llx_verifactu_registry (date_creation);

