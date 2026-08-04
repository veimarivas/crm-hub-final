<?php

namespace App\Services\Wacrm;

use RuntimeException;

/**
 * Fallo de la API del wacrm que SÍ llegó al servidor y volvió con un código.
 *
 * Existe para separar "no se pudo llegar" (red caída, DNS, timeout — eso sigue
 * siendo una excepción de Guzzle) de "llegó y dijo que no": 401/403 es la API
 * key sin el scope o revocada, 404 es un wacrm sin ese endpoint. Los tres se
 * arreglan de manera distinta, así que colapsarlos en un mismo "sin conexión"
 * manda a buscar el problema al lugar equivocado.
 *
 * Extiende RuntimeException a propósito: quien ya la capturaba así sigue
 * funcionando igual.
 */
class WacrmApiException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
