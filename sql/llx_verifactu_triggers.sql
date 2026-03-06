DELIMITER $$

CREATE TRIGGER trg_block_delete_facture_verifactu
BEFORE DELETE ON llx_facture
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM llx_verifactu_registry
        WHERE fk_facture = OLD.rowid
    ) THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
            'No se puede eliminar esta factura porque está registrada en VeriFactu. Utilice una factura rectificativa.';
    END IF;
END$$

DELIMITER ;
