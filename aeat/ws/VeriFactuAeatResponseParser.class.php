<?php
class VeriFactuAeatResponseParser
{
    public static function parse($response): array
    {
        // ⚠️ Esto depende del WSDL real. Base genérica y segura.
        $result = [
            'status'  => 'ERROR',
            'csv'     => null,
            'message' => 'Respuesta AEAT no reconocida'
        ];

        if (is_object($response)) {

            if (!empty($response->Estado) && $response->Estado === 'Aceptado') {
                $result['status']  = 'ACCEPTED';
                $result['csv']     = $response->CSV ?? null;
                $result['message'] = 'Aceptado por AEAT';
            }

            if (!empty($response->Estado) && $response->Estado === 'Rechazado') {
                $result['status']  = 'REJECTED';
                $result['message'] = $response->MensajeError ?? 'Rechazado por AEAT';
            }
        }

        return $result;
    }
}
