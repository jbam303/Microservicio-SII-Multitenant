<?php

declare(strict_types=1);

namespace App;

use DOMDocument;
use DOMElement;
use RuntimeException;
use SimpleXMLElement;
use sasco\LibreDTE\FirmaElectronica;
use sasco\LibreDTE\Sii\ConsumoFolio;
use sasco\LibreDTE\Sii\Dte;

/**
 * Arma el Consumo de Folios (hoy "resumen de ventas diarias", RVD) de un día.
 *
 * Es el reporte diario obligatorio que acompaña a la boleta electrónica:
 * declara qué folios se usaron, cuáles se anularon y por cuánto. El SII lo
 * exige tanto en operación normal como dentro del set de pruebas.
 *
 * Va a un destino distinto que las boletas. Según el propio spec del SII:
 *
 *   "Los sitios rahue.sii.cl y api.sii.cl son plataformas dedicadas a la
 *    recepción de Boleta Electrónica en Producción. El sitio de palena.sii.cl
 *    es la plataforma dedicada para la recepción de DTE y RVD en Producción."
 *
 * O sea que el RVD viaja por `DTEUpload` (palena), no por la API REST de
 * boleta. `ConsumoFolio` no hereda el guard que tiene `EnvioDte::enviar()`
 * contra las boletas, así que ese camino está abierto.
 *
 * NO ENVÍA NADA: devuelve el XML firmado y decide el llamador, igual que
 * [[EnvioBoletaBuilder]].
 *
 * ── Sobre los folios anulados ───────────────────────────────────────────────
 *
 * `ConsumoFolio` de LibreDTE NO los soporta: en `getResumen()` las líneas de
 * `RangoAnulados` están comentadas y `FoliosAnulados` se inicializa en 0 y
 * nunca se incrementa. Como `getResumen()` es privado no se puede extender,
 * así que acá el XML se genera SIN firmar, se le inyectan los anulados y
 * recién entonces se firma. Firmar primero e inyectar después invalidaría la
 * firma.
 *
 * Qué son, según la documentación del propio schema:
 *
 *   "SE REFIERE A LOS FOLIOS ANULADOS EN EL SISTEMA (Opción anulación de
 *    folios) y no a Documentos anulados mediante Notas de Crédito"
 *
 * Es exactamente el caso del POS: la cajera se equivoca, el folio ya se
 * consumió y la boleta nunca se entregó al cliente. Sin declararlos, el SII ve
 * huecos sin explicación en la secuencia de folios.
 */
class ConsumoFoliosBuilder
{
    /** Namespace de los documentos tributarios del SII. */
    private const NS = 'http://www.sii.cl/SiiDte';

    /**
     * @param string[] $xmlsFirmados Boletas del día, ya firmadas. Puede ir vacío
     *                               si ese día solo hubo folios anulados.
     * @param array    $resolucion   ['fecha' => 'YYYY-MM-DD', 'numero' => int]
     * @param array    $credenciales ['certificado_b64' => ..., 'password' => ...]
     * @param int      $secuencia    SecEnvio: 1 el primer envío del día, 2+ en correcciones
     * @param array    $anulados     Folios anulados por tipo: [39 => [11, 12], ...]
     * @param array    $contexto     ['fecha' => 'YYYY-MM-DD', 'rut_emisor' => '...'],
     *                               obligatorio solo si $xmlsFirmados va vacío
     * @return array ['xml','documentos','anulados','caratula','tipos']
     */
    public static function armar(
        array $xmlsFirmados,
        array $resolucion,
        array $credenciales,
        int $secuencia = 1,
        array $anulados = [],
        array $contexto = []
    ): array {
        $anulados = self::normalizarAnulados($anulados);

        if (!$xmlsFirmados && !$anulados) {
            throw new RuntimeException('No hay documentos para reportar.', 400);
        }
        if (!isset($resolucion['fecha'], $resolucion['numero'])) {
            throw new RuntimeException('Falta la resolución del emisor (fecha y número).', 400);
        }
        if ($secuencia < 1) {
            throw new RuntimeException("SecEnvio debe ser 1 o mayor, se recibió $secuencia.", 400);
        }

        $certContent = base64_decode($credenciales['certificado_b64'] ?? '', true);
        if ($certContent === false) {
            throw new RuntimeException('El certificado no está en base64 válido.', 400);
        }
        $firma = new FirmaElectronica([
            'data' => $certContent,
            'pass' => $credenciales['password'] ?? '',
        ]);

        $consumo = new ConsumoFolio();
        // A propósito NO se llama setFirma(): con firma, generar() firma el XML
        // y ya no se le puede inyectar nada. Se firma al final, a mano.

        $tipos = [];
        $rutEmisor = $contexto['rut_emisor'] ?? null;
        $fecha = $contexto['fecha'] ?? null;
        $emitidos = [];

        foreach ($xmlsFirmados as $i => $xml) {
            // false = no normalizar: el documento ya está firmado y cualquier
            // cambio invalidaría la firma.
            $dte = new Dte($xml, false);
            $resumen = $dte->getResumen();

            // El reporte es de UN día. Mezclar fechas produce un XML que el
            // esquema acepta pero que declara un período que no corresponde, y
            // el error solo aparece cuando el SII cruza los folios.
            $fechaDoc = $resumen['FchDoc'] ?? null;
            if ($fecha === null) {
                $fecha = $fechaDoc;
            } elseif ($fechaDoc !== $fecha) {
                throw new RuntimeException(
                    "El consumo de folios es diario: el documento en la posición $i es del "
                    . "$fechaDoc y el reporte es del $fecha.",
                    400
                );
            }

            $tipo = (int) $resumen['TpoDoc'];
            $tipos[$tipo] = true;
            $emitidos[$tipo][] = (int) $resumen['NroDoc'];

            if ($rutEmisor === null && preg_match('#<RUTEmisor>(.*?)</RUTEmisor>#', $xml, $m)) {
                $rutEmisor = $m[1];
            }

            $consumo->agregar($resumen);
        }

        foreach ($anulados as $tipo => $folios) {
            $tipos[$tipo] = true;
            // Un folio emitido y anulado a la vez es una contradicción: el SII
            // recibiría un reporte que se desmiente solo. Casi siempre es un
            // bug del llamador consultando mal la base.
            $choque = array_intersect($folios, $emitidos[$tipo] ?? []);
            if ($choque) {
                throw new RuntimeException(
                    'Estos folios del tipo ' . $tipo . ' figuran como emitidos y anulados '
                    . 'al mismo tiempo: ' . implode(', ', $choque) . '.',
                    400
                );
            }
        }

        if (!$rutEmisor) {
            throw new RuntimeException(
                'No se pudo determinar el RUT del emisor. Si el lote no trae documentos '
                . 'emitidos hay que pasarlo en $contexto[\'rut_emisor\'].',
                400
            );
        }
        if (!$fecha) {
            throw new RuntimeException(
                'No se pudo determinar la fecha del reporte. Si el lote no trae documentos '
                . 'emitidos hay que pasarla en $contexto[\'fecha\'].',
                400
            );
        }

        $tipos = array_keys($tipos);
        sort($tipos);
        $consumo->setDocumentos($tipos);

        // ORDEN CRÍTICO: la carátula va DESPUÉS de agregar los documentos.
        // `setCaratula()` resuelve FchInicio y FchFinal llamando a
        // getFechaEmisionInicial()/Final(), que leen los detalles ya cargados.
        // Invertido, el reporte sale con el período vacío y parece correcto.
        //
        // RutEnvia se pasa explícito porque normalmente LibreDTE lo saca de la
        // firma, y acá la firma no está puesta. Como array_merge deja ganar a
        // lo que pasa el llamador, esto se impone.
        //
        // Si no hubo documentos emitidos, las fechas también van explícitas:
        // sin detalles, LibreDTE devuelve sus centinelas (9999-12-31).
        $caratula = [
            'RutEmisor' => $rutEmisor,
            'RutEnvia'  => $firma->getID(),
            'FchResol'  => $resolucion['fecha'],
            'NroResol'  => (int) $resolucion['numero'],
            'SecEnvio'  => $secuencia,
        ];
        if (!$xmlsFirmados) {
            $caratula['FchInicio'] = $fecha;
            $caratula['FchFinal'] = $fecha;
        }
        $consumo->setCaratula($caratula);

        $xmlConsumo = $consumo->generar();
        if (!$xmlConsumo) {
            throw new RuntimeException('No se pudo generar el consumo de folios.', 500);
        }

        if ($anulados) {
            $xmlConsumo = self::inyectarAnulados($xmlConsumo, $anulados);
        }

        // Se firma acá, ya con los anulados adentro.
        $id = self::leerId($xmlConsumo);
        $xmlFirmado = $firma->signXML($xmlConsumo, '#' . $id, 'DocumentoConsumoFolios', true);
        if (!$xmlFirmado) {
            throw new RuntimeException('No se pudo firmar el consumo de folios.', 500);
        }

        self::validarSchema($xmlFirmado);

        return [
            'xml'        => $xmlFirmado,
            'documentos' => count($xmlsFirmados),
            'anulados'   => array_sum(array_map('count', $anulados)),
            'caratula'   => self::leerCaratula($xmlFirmado),
            'tipos'      => $tipos,
        ];
    }

    /** Valida la forma de $anulados y descarta los tipos sin folios. */
    private static function normalizarAnulados(array $anulados): array
    {
        $salida = [];
        foreach ($anulados as $tipo => $folios) {
            if (!is_array($folios)) {
                throw new RuntimeException(
                    "Los folios anulados del tipo $tipo deben venir en un arreglo.",
                    400
                );
            }
            $limpios = [];
            foreach ($folios as $f) {
                $folio = (int) $f;
                if ($folio < 1) {
                    throw new RuntimeException("Folio anulado inválido para el tipo $tipo: " . var_export($f, true), 400);
                }
                $limpios[$folio] = true;
            }
            if ($limpios) {
                $folios = array_keys($limpios);
                sort($folios);
                $salida[(int) $tipo] = $folios;
            }
        }
        return $salida;
    }

    /**
     * Agrega FoliosAnulados y RangoAnulados al XML sin firmar.
     *
     * El orden de los elementos importa: el schema define una secuencia
     * (FoliosEmitidos, FoliosAnulados, FoliosUtilizados, RangoUtilizados,
     * RangoAnulados). Como RangoAnulados va último, alcanza con agregarlo al
     * final del Resumen. FoliosAnulados y FoliosUtilizados ya existen —
     * LibreDTE los escribe en 0— así que solo se les cambia el valor.
     */
    private static function inyectarAnulados(string $xml, array $anulados): string
    {
        $doc = new DOMDocument();
        if (!$doc->loadXML($xml)) {
            throw new RuntimeException('El consumo de folios generado no es XML válido.', 500);
        }

        $pendientes = $anulados;
        foreach ($doc->getElementsByTagNameNS(self::NS, 'Resumen') as $resumen) {
            /** @var DOMElement $resumen */
            $tipo = (int) self::texto($resumen, 'TipoDocumento');
            if (!isset($pendientes[$tipo])) {
                continue;
            }
            $folios = $pendientes[$tipo];
            unset($pendientes[$tipo]);

            $emitidos = (int) self::texto($resumen, 'FoliosEmitidos');
            self::fijar($resumen, 'FoliosAnulados', (string) count($folios));
            self::fijar($resumen, 'FoliosUtilizados', (string) ($emitidos + count($folios)));

            foreach (self::rangos($folios) as $rango) {
                $nodo = $doc->createElementNS(self::NS, 'RangoAnulados');
                $nodo->appendChild($doc->createElementNS(self::NS, 'Inicial', (string) $rango[0]));
                $nodo->appendChild($doc->createElementNS(self::NS, 'Final', (string) $rango[1]));
                $resumen->appendChild($nodo);
            }
        }

        if ($pendientes) {
            throw new RuntimeException(
                'Hay folios anulados de tipos que no aparecen en el reporte: '
                . implode(', ', array_keys($pendientes)) . '.',
                500
            );
        }

        return (string) $doc->saveXML();
    }

    /** Agrupa folios consecutivos: [1,2,3,7] => [[1,3],[7,7]] */
    private static function rangos(array $folios): array
    {
        sort($folios);
        $rangos = [];
        $inicio = $anterior = null;
        foreach ($folios as $folio) {
            if ($inicio === null) {
                $inicio = $anterior = $folio;
                continue;
            }
            if ($folio === $anterior + 1) {
                $anterior = $folio;
                continue;
            }
            $rangos[] = [$inicio, $anterior];
            $inicio = $anterior = $folio;
        }
        if ($inicio !== null) {
            $rangos[] = [$inicio, $anterior];
        }
        return $rangos;
    }

    private static function texto(DOMElement $padre, string $tag): string
    {
        $nodos = $padre->getElementsByTagNameNS(self::NS, $tag);
        return $nodos->length ? trim($nodos->item(0)->nodeValue ?? '') : '';
    }

    private static function fijar(DOMElement $padre, string $tag, string $valor): void
    {
        $nodos = $padre->getElementsByTagNameNS(self::NS, $tag);
        if (!$nodos->length) {
            throw new RuntimeException("El resumen generado no tiene el elemento $tag.", 500);
        }
        $nodos->item(0)->nodeValue = $valor;
    }

    private static function leerId(string $xml): string
    {
        if (!preg_match('#<DocumentoConsumoFolios[^>]*\bID="([^"]+)"#', $xml, $m)) {
            throw new RuntimeException('El consumo de folios generado no tiene ID.', 500);
        }
        return $m[1];
    }

    /**
     * Valida contra ConsumoFolio_v10.xsd antes de devolverlo. Es barato y evita
     * descubrir en el SII que el reporte estaba mal armado: allá el rechazo
     * llega por correo horas después, sin decir qué campo falló.
     */
    private static function validarSchema(string $xml): void
    {
        $xsd = __DIR__ . '/../vendor/libredte/libredte-lib-core/schemas/ConsumoFolio_v10.xsd';
        if (!file_exists($xsd)) {
            throw new RuntimeException('No se encontró el schema ConsumoFolio_v10.xsd.', 500);
        }
        $doc = new DOMDocument();
        $doc->loadXML($xml);
        $previo = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $ok = $doc->schemaValidate($xsd);
        $errores = array_map(
            static fn ($e) => trim($e->message),
            libxml_get_errors()
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previo);
        if (!$ok) {
            throw new RuntimeException(
                'El consumo de folios no valida contra el schema del SII: '
                . ($errores ? implode(' | ', $errores) : 'sin detalle'),
                500
            );
        }
    }

    /**
     * Lee la carátula del XML ya generado, no del objeto.
     *
     * Se hace así a propósito: lo que importa es lo que va a recibir el SII. Si
     * el armado dejó el período vacío, acá se ve; preguntándole al objeto, no.
     */
    private static function leerCaratula(string $xml): array
    {
        $sxml = @simplexml_load_string($xml);
        if ($sxml === false) {
            throw new RuntimeException('El consumo de folios generado no es XML válido.', 500);
        }
        $caratula = $sxml->xpath('//*[local-name()="Caratula"]');
        if (!$caratula) {
            throw new RuntimeException('El consumo de folios generado no tiene carátula.', 500);
        }
        $salida = [];
        foreach ($caratula[0] as $campo => $valor) {
            $salida[$campo] = (string) $valor;
        }
        return $salida;
    }
}
