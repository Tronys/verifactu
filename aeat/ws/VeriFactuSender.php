<?php

class VeriFactuSender
{
    /**
     * Preparado para envío futuro (NO activo)
     */
    public static function send($xml)
    {
        if (empty($GLOBALS['conf']->global->VERIFACTU_SEND_TO_AEAT)) {
            return [
                'status' => 'DISABLED',
                'message' => 'Envío a AEAT desactivado'
            ];
        }

        // Aquí irá SOAP + certificado en el futuro

        return [
            'status' => 'NOT_IMPLEMENTED',
            'message' => 'Módulo preparado, envío aún no activo'
        ];
    }
}
