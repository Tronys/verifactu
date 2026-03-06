ALTER TABLE llx_verifactu_registry
ADD CONSTRAINT fk_verifactu_facture
FOREIGN KEY (fk_facture)
REFERENCES llx_facture(rowid);

ALTER TABLE llx_verifactu_registry
    ADD COLUMN xml_signed_path VARCHAR(255) NULL AFTER xml_vf_path,
    ADD COLUMN signature_status VARCHAR(20) NULL AFTER xml_signed_path;

