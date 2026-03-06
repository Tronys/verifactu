<?php

class InvoiceXmlBuilder
{
    /**
     * Construye el XML VeriFactu (NO se envía todavía)
     */
    public static function build($facture, $verifactuRecord)
    {
        // Placeholder técnico
        // Aquí irá el XML conforme a XSD AEAT

        return [
            'xml' => null,
            'hash' => $verifactuRecord->hash_actual
        ];
    }
}
