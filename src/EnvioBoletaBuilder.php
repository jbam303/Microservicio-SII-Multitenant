<?php

declare(strict_types=1);

namespace App;

use RuntimeException;
use sasco\LibreDTE\FirmaElectronica;
use sasco\LibreDTE\Sii\Dte;
use sasco\LibreDTE\Sii\EnvioDte;

/**
 * Arma el sobre EnvioBOLETA a partir de boletas YA emitidas y firmadas.
 *
 * No re-emite ni re-firma los documentos: los toma tal cual están guardados en
 * `Venta.xml_dte_b64` y los envuelve. Cada DTE conserva su `FchEmis` original;
 * el sobre lleva su propio `TmstFirmaEnv`. Para el SII eso es un envío tardío
 * con documentos viejos, que es exactamente lo que es.
 *
 * NO ENVÍA NADA. Devuelve el XML del sobre firmado para que el llamador decida
 * qué hacer con él.
 *
 * Datos de la carátula:
 *  - RutEmisor / RutReceptor / RutEnvia los deduce LibreDTE del DTE y la firma.
 *  - `FchResol` y `NroResol` son la resolución que autorizó al contribuyente
 *    como emisor electrónico. Son PÚBLICOS: se consultan por RUT en
 *    https://palena.sii.cl/cvc_cgi/dte/ee_empresa_rut (ver PLAN_ENVIO_SII.md).
 */
class EnvioBoletaBuilder
{
    /** Tope de documentos por sobre que admite el schema para boletas. */
    public const MAX_POR_SOBRE = 1000;

    /**
     * @param string[] $xmlsFirmados XML de cada boleta ya firmada (texto, no base64)
     * @param array    $resolucion   ['fecha' => 'YYYY-MM-DD', 'numero' => int]
     * @param array    $credenciales ['certificado_b64' => ..., 'password' => ...]
     * @return array ['xml' => string, 'documentos' => int, 'caratula' => array]
     */
    public static function armar(array $xmlsFirmados, array $resolucion, array $credenciales): array
    {
        if (!$xmlsFirmados) {
            throw new RuntimeException('No hay boletas para envolver.', 400);
        }
        if (count($xmlsFirmados) > self::MAX_POR_SOBRE) {
            throw new RuntimeException(
                'Demasiadas boletas en un sobre: ' . count($xmlsFirmados)
                . '. El máximo es ' . self::MAX_POR_SOBRE . '.',
                400
            );
        }
        if (!isset($resolucion['fecha'], $resolucion['numero'])) {
            throw new RuntimeException(
                'Falta la resolución del emisor (fecha y número). Se consulta por '
                . 'RUT en el servicio público de contribuyentes autorizados.',
                400
            );
        }

        $certContent = base64_decode($credenciales['certificado_b64'] ?? '', true);
        if ($certContent === false) {
            throw new RuntimeException('El certificado no está en base64 válido.', 400);
        }
        $firma = new FirmaElectronica([
            'data' => $certContent,
            'pass' => $credenciales['password'] ?? '',
        ]);

        $envio = new EnvioDte();

        foreach ($xmlsFirmados as $i => $xml) {
            // El segundo parámetro en false evita que LibreDTE "normalice" un
            // documento que ya está firmado: cualquier cambio invalidaría la
            // firma que el SII debe poder verificar.
            $dte = new Dte($xml, false);

            if (!$dte->esBoleta()) {
                throw new RuntimeException(
                    "El documento en la posición $i no es una boleta (tipo "
                    . $dte->getTipo() . '). Un sobre no puede mezclar boletas '
                    . 'con otros DTE.',
                    400
                );
            }
            if (!$envio->agregar($dte)) {
                throw new RuntimeException("No se pudo agregar el documento en la posición $i al sobre.", 500);
            }
        }

        $envio->setFirma($firma);

        $ok = $envio->setCaratula([
            'RutReceptor' => '60803000-K',   // el SII
            'FchResol'    => $resolucion['fecha'],
            'NroResol'    => (int) $resolucion['numero'],
        ]);
        if (!$ok) {
            throw new RuntimeException('No se pudo armar la carátula del sobre.', 500);
        }

        $xmlSobre = $envio->generar();
        if (!$xmlSobre) {
            throw new RuntimeException('No se pudo generar el sobre EnvioBOLETA.', 500);
        }

        return [
            'xml'        => $xmlSobre,
            'documentos' => count($xmlsFirmados),
            'caratula'   => $envio->getCaratula(),
        ];
    }
}
