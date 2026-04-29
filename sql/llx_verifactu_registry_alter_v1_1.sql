-- Migración VeriFactu v1.1 — añade tipo_factura y cuota_total
--
-- Ejecutar una sola vez sobre la base de datos Dolibarr.
-- Seguro si las columnas ya existen (IF NOT EXISTS).

ALTER TABLE llx_verifactu_registry
    ADD COLUMN IF NOT EXISTS tipo_factura VARCHAR(4) NOT NULL DEFAULT 'F1'
        COMMENT 'F1 = factura completa, F2 = simplificada',
    ADD COLUMN IF NOT EXISTS cuota_total DOUBLE(24,8) NOT NULL DEFAULT 0
        COMMENT 'Suma de cuotas IVA (necesaria para recalcular Huella)';
