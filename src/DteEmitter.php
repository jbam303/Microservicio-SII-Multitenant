<?php

declare(strict_types=1);

namespace App;

use Exception;
use RuntimeException;
// Clases reales de la versión 24.1.x de libredte-lib-core
use sasco\LibreDTE\FirmaElectronica;
use sasco\LibreDTE\Sii\Folios;
use sasco\LibreDTE\Sii\Dte;

class DteEmitter
{
    public function __construct()
    {
    }

    /**
     * Emite un DTE (Factura, Boleta, etc.) en un modelo Stateless / Zero-Trust
     * usando libredte-lib-core v24.1
     *
     * @param array $payload Datos del DTE, certificado base64 y CAF xml base64 desde Django
     * @return array Resultado con folio y xml
     * @throws RuntimeException Si hay un error en el proceso
     */
    public function emitir(array $payload): array
    {
        $tenantSlug = $payload['tenant_slug'] ?? null;
        $tipoDte = (int) ($payload['tipo_dte'] ?? 0);
        $folioAsignado = (int) ($payload['folio_asignado'] ?? 0);
        $credenciales = $payload['credenciales'] ?? [];

        if (!$tenantSlug || !$tipoDte || !$folioAsignado) {
            throw new RuntimeException("Faltan campos obligatorios: tenant_slug, tipo_dte o folio_asignado", 400);
        }

        if (empty($credenciales['certificado_b64']) || empty($credenciales['caf_xml_b64'])) {
            throw new RuntimeException("Faltan credenciales (certificado o CAF en base64)", 400);
        }

        $password = $credenciales['password'] ?? '';

        $certPath = null;
        $cafPath = null;

        try {
            // 1. Decodificar y guardar archivos temporales de forma segura
            $certContent = base64_decode($credenciales['certificado_b64'], true);
            $cafContent = base64_decode($credenciales['caf_xml_b64'], true);

            if ($certContent === false || $cafContent === false) {
                throw new RuntimeException("No se pudieron decodificar el certificado o el CAF (asegúrate de que estén en base64 puro)", 400);
            }

            // Crear archivos temporales en memoria /tmp/
            $certPath = tempnam(sys_get_temp_dir(), 'dte_cert_');
            $cafPath = tempnam(sys_get_temp_dir(), 'dte_caf_');

            if (!$certPath || !$cafPath) {
                throw new RuntimeException("No se pudieron crear los archivos temporales en el sistema", 500);
            }

            file_put_contents($certPath, $certContent);
            file_put_contents($cafPath, $cafContent);

            // 2. Instanciar la Firma Electrónica
            // FirmaElectronica en v24.1 espera el contenido del certificado en arreglo o path si no especificamos pero usamos arreglo:
            $configFirma = [
                'data' => $certContent,
                'pass' => $password
            ];
            $firma = new FirmaElectronica($configFirma);
            
            // 3. Cargar folios (CAF)
            $folios = new Folios($cafContent);
            
            // Validar que los folios cargados correspondan al tipo de DTE
            // (La clave 'TipoDTE' la suele dar el CAF, usamos el tipoDte pasado como confirmación)
            
            // 4. Mapear Payload de Django al formato requerido por LibreDTE
            $documento = self::construirDocumento($payload);

            // Migas de pan: si el proceso muere sin llegar al shutdown handler
            // (p. ej. un segfault), la última miga escrita dice hasta dónde
            // llegó. Es lo único que sobrevive a una muerte abrupta.
            $miga = function (string $paso) use ($tenantSlug, $folioAsignado) {
                error_log(json_encode([
                    'severity' => 'INFO',
                    'message'  => "[DTE-PASO] $paso",
                    'tenant'   => $tenantSlug,
                    'folio'    => $folioAsignado,
                ]));
            };

            // 5. Instanciar y armar DTE
            $miga('antes de new Dte');
            $dte = new Dte($documento);

            // Ningún monto puede salir con decimales. El redondeo de MontoItem
            // ya lo evita en el origen, pero esto lo comprueba sobre lo que
            // LibreDTE realmente calculó, que es lo que va a viajar al SII.
            // Es barato y cierra la puerta a que vuelva a pasar en silencio.
            self::verificarMontosEnteros($dte->getDatos());

            // 6. Timbrar DTE con CAF (Timbre Electrónico SII) y Folio.
            // Acá se arma el TED, que incluye IT1 = nombre del primer ítem.
            // Es el paso sospechoso cuando ese nombre tiene caracteres no-ASCII.
            $miga('antes de timbrar');
            // timbrar() no lanza: devuelve false si el folio cae fuera del
            // rango del CAF o falta un dato del timbre. Ignorarlo firmaba un
            // documento sin TED válido.
            if (!$dte->timbrar($folios)) {
                throw new RuntimeException("Error al timbrar el DTE: revisa que el folio $folioAsignado esté dentro del rango del CAF.", 500);
            }

            // 7. Firmar documento
            $miga('antes de firmar');
            $firmadoOk = $dte->firmar($firma);
            $miga('firmado ok');
            
            if (!$firmadoOk) {
                 throw new RuntimeException("Error al firmar el DTE. Revisa tus credenciales y CAF.", 500);
            }

            // El TED viene en ISO-8859-1 (el DTE del SII usa esa codificación).
            // Devolverlo crudo rompe la respuesta: json_encode() exige UTF-8
            // válido y ante un acento devuelve false, con lo que Slim termina
            // llamando a fwrite($handle, false) y el request muere con un 500
            // de cuerpo vacío. Es lo que impedía emitir cualquier boleta cuyo
            // PRIMER ítem llevara tilde o ñ, porque el TED incluye IT1.
            // El campo 'xml' no sufre esto porque va en base64.
            $ted = $dte->getTED();
            if (is_string($ted) && $ted !== '') {
                $ted = mb_convert_encoding($ted, 'UTF-8', 'ISO-8859-1');
            }

            return [
                'folio' => $folioAsignado,
                'xml'   => base64_encode($dte->saveXML()),
                'ted'   => $ted,
                'pdf'   => null // PDF se delegaría a otro sistema o a otra función de libredte
            ];

        } catch (Exception $e) {
            $code = $e->getCode() ?: 500;
            if ($code < 400 || $code > 599) {
                $code = 500;
            }
            throw new RuntimeException("Error LibreDTE: " . $e->getMessage(), $code, $e);
        } finally {
            // Regla Crítica de Seguridad: Siempre limpiar los archivos temporales efímeros
            if ($certPath !== null && file_exists($certPath)) {
                unlink($certPath);
            }
            if ($cafPath !== null && file_exists($cafPath)) {
                unlink($cafPath);
            }
        }
    }

    /**
     * Comprueba que ningún monto del documento lleve decimales.
     *
     * El peso chileno no tiene fracciones y el SII rechaza montos decimales.
     * Hasta el 2026-07-29, 926 de 3.159 boletas salieron con totales como
     * `<MntTotal>9986.1</MntTotal>`, arrastrados desde MontoItem en las ventas
     * por peso. Nadie se enteró porque nada lo miraba.
     *
     * La CANTIDAD queda fuera del chequeo a propósito: puede ser fraccionaria.
     *
     * @throws RuntimeException si algún monto tiene decimales
     */
    private static function verificarMontosEnteros(array $datos): void
    {
        $sucios = [];

        foreach (($datos['Encabezado']['Totales'] ?? []) as $campo => $valor) {
            if (is_numeric($valor) && (float) $valor != floor((float) $valor)) {
                $sucios[] = "Totales.$campo = $valor";
            }
        }

        foreach (($datos['Detalle'] ?? []) as $i => $linea) {
            foreach (['MontoItem', 'PrcItem'] as $campo) {
                $valor = $linea[$campo] ?? null;
                if (is_numeric($valor) && (float) $valor != floor((float) $valor)) {
                    $sucios[] = "Detalle[$i].$campo = $valor";
                }
            }
        }

        if ($sucios) {
            throw new RuntimeException(
                'El documento lleva montos con decimales y el SII no los acepta: '
                . implode(', ', $sucios) . '.',
                500
            );
        }
    }

    /**
     * Mapea el payload de Django al arreglo Encabezado/Detalle de LibreDTE.
     *
     * Está separado de emitir() para poder probarlo sin CAF: el timbrado exige
     * folios reales, pero el riesgo está acá, en el mapeo. Ver
     * `test_dte_emitter.php`.
     *
     * Los montos NO se calculan acá. LibreDTE normaliza el documento y deriva
     * MntNeto, IVA y MntExe del detalle; duplicar esa lógica sería la forma más
     * rápida de que los totales dejen de cuadrar con la boleta impresa.
     *
     * @param array $payload Datos del DTE tal como llegan desde Django
     * @return array Documento en el formato de LibreDTE
     */
    public static function construirDocumento(array $payload): array
    {
        // Fecha de emisión. Si no viene, LibreDTE pone la de hoy, que es lo
        // correcto al emitir una venta nueva. Pero al REGENERAR un documento
        // viejo hay que conservar la fecha original: la regeneración masiva del
        // 2026-07-29 le puso la fecha de ese día a 3.207 boletas, incluidas
        // ventas de mayo. Una boleta con la fecha equivocada declara el período
        // tributario equivocado.
        $fchEmis = $payload['fecha_emision'] ?? null;
        if ($fchEmis !== null && $fchEmis !== '') {
            $d = \DateTime::createFromFormat('!Y-m-d', (string) $fchEmis);
            if ($d === false || $d->format('Y-m-d') !== (string) $fchEmis) {
                throw new RuntimeException(
                    "La fecha de emisión '$fchEmis' no tiene el formato YYYY-MM-DD "
                    . 'o no es una fecha válida.',
                    400
                );
            }
        } else {
            $fchEmis = null;
        }

        $documento = [
            'Encabezado' => [
                'IdDoc' => array_filter([
                    'TipoDTE' => (int) ($payload['tipo_dte'] ?? 0),
                    'Folio' => (int) ($payload['folio_asignado'] ?? 0),
                    'FchEmis' => $fchEmis,
                ], static fn ($v) => $v !== null),
                'Emisor' => [
                    'RUTEmisor' => $payload['emisor']['rut'] ?? '',
                    'RznSoc' => $payload['emisor']['razon_social'] ?? '',
                    'GiroEmis' => $payload['emisor']['giro'] ?? '',
                    'Acteco' => 1000, // Hardcoded u obtener del payload si existe
                    'DirOrigen' => $payload['emisor']['direccion'] ?? '',
                    'CmnaOrigen' => $payload['emisor']['comuna'] ?? '',
                ],
                'Receptor' => [
                    'RUTRecep' => $payload['receptor']['rut'] ?? '',
                    'RznSocRecep' => $payload['receptor']['razon_social'] ?? '',
                    'GiroRecep' => $payload['receptor']['giro'] ?? 'Particular',
                    'DirRecep' => $payload['receptor']['direccion'] ?? 'Sin calle',
                    'CmnaRecep' => $payload['receptor']['comuna'] ?? 'Santiago',
                ],
                // Totales VACÍO a propósito. `normalizar_detalle()` de LibreDTE
                // ACUMULA cada MontoItem sobre el MntTotal que uno le pase
                // (Dte.php:1505), así que mandar el total ya calculado lo
                // DUPLICA. Es el bug que estuvo en producción hasta el
                // 2026-07-29: la venta 5192 era 1 Muffin de $1.500 y la boleta
                // declaraba $3.000. Ninguna de esas boletas llegó al SII, que
                // es lo único que evitó que los locales declararan el doble.
                'Totales' => []
            ],
            'Detalle' => []
        ];

        foreach (($payload['detalle'] ?? []) as $idx => $item) {
            $cantidad = $item['cantidad'] ?? 1;
            $precio = $item['precio'] ?? 0;

            $linea = [
                'NroLinDet' => $idx + 1,
                'NmbItem' => $item['nombre'] ?? '',
                // La CANTIDAD sí puede ser fraccionaria: 0,35 kg de pan son
                // 0,35 kg, y redondearla falsearía la venta.
                'QtyItem' => $cantidad,
                'PrcItem' => $precio,
                // El MONTO no. El peso chileno no tiene fracciones y el SII
                // rechaza montos decimales. Sin este redondeo, cantidad x
                // precio arrastra decimales hasta MntTotal: 926 de las 3.159
                // boletas emitidas hasta el 2026-07-29 quedaron con totales
                // del estilo <MntTotal>9986.1</MntTotal>.
                'MontoItem' => (int) round($cantidad * $precio),
            ];

            // Unidad de medida. El set de pruebas del SII trae un caso que la
            // exige explícitamente ("Arroz ... informar Unidad de medida en
            // Kg"). Va sólo si el producto la tiene: mandarla vacía ensucia el
            // XML sin aportar nada.
            if (!empty($item['unidad'])) {
                $linea['UnmdItem'] = $item['unidad'];
            }

            // Ítem exento DENTRO de una boleta afecta. No confundir con la
            // boleta exenta (tipo 41), que es otro documento y que estos
            // contribuyentes no emiten. LibreDTE usa IndExe para dejar esta
            // línea fuera del cálculo del IVA y sumarla a MntExe.
            if (!empty($item['exento'])) {
                $linea['IndExe'] = 1;
            }

            $documento['Detalle'][] = $linea;
        }

        // Descuento global sobre el total de la venta.
        //
        // El POS permite descontar del total, pero hasta el 2026-07-29 el DTE
        // no se enteraba: el detalle sumaba MÁS de lo que pagó el cliente. El
        // SII lo representa con DscRcgGlobal — TpoMov 'D' de descuento,
        // TpoValor '$' porque es un monto fijo y no un porcentaje.
        $descuento = (int) round((float) ($payload['descuento_global'] ?? 0));
        $sumaLineas = 0;
        foreach ($documento['Detalle'] as $linea) {
            $sumaLineas += $linea['MontoItem'];
        }

        if ($descuento > 0) {
            if ($descuento > $sumaLineas) {
                throw new RuntimeException(
                    "El descuento ($descuento) es mayor que el monto de las líneas "
                    . "($sumaLineas): quedaría una boleta de monto negativo.",
                    400
                );
            }
            $documento['DscRcgGlobal'] = [[
                'NroLinDR' => 1,
                'TpoMov' => 'D',
                'GlosaDR' => 'Descuento',
                'TpoValor' => '$',
                'ValorDR' => $descuento,
            ]];
        }

        // Red de seguridad sobre el monto. El total lo calcula LibreDTE desde
        // el detalle, pero si el llamador dice cuánto esperaba y no coincide,
        // hay que enterarse ACÁ y no cuando el SII acepte un documento con el
        // monto equivocado. El bug del total duplicado vivió meses en
        // producción justamente porque nada comparaba estas dos cifras.
        $esperado = $payload['totales']['monto_total'] ?? null;
        if ($esperado !== null) {
            // Con descuento, el total que pagó el cliente es MENOR que la suma
            // de las líneas, y esa diferencia es legítima. Descontarla antes de
            // comparar; si no, este guard rechaza documentos correctos.
            $suma = $sumaLineas - $descuento;
            // Tolerancia de 1 peso POR LÍNEA. Lo que este guard tiene que
            // atrapar es la duplicación —un error del 100%—, no vigilar el
            // último peso: multiplicar cantidades fraccionarias por precios no
            // da bit a bit lo mismo en PHP que en el llamador, y una diferencia
            // de $1 tiraba abajo regeneraciones perfectamente correctas.
            //
            // Con este margen la duplicación sigue siendo imposible de colar:
            // el doble de cualquier monto real se pasa del umbral por órdenes
            // de magnitud.
            $margen = max(2, count($documento['Detalle']));
            if (abs((int) round((float) $esperado) - (int) round($suma)) > $margen) {
                throw new RuntimeException(
                    'El monto total no cuadra con el detalle: el llamador esperaba '
                    . $esperado . ' y las líneas suman ' . $suma . '.',
                    400
                );
            }
        }

        // Referencia. Hoy sólo la usa el set de pruebas de certificación, que
        // exige identificar a qué caso corresponde cada boleta:
        //     <CodRef>SET</CodRef>  <RazonRef>CASO-1</RazonRef>
        $referencia = $payload['referencia'] ?? null;
        if (!empty($referencia['codigo']) || !empty($referencia['razon'])) {
            $documento['Referencia'] = [[
                'NroLinRef' => 1,
                'CodRef' => $referencia['codigo'] ?? '',
                'RazonRef' => $referencia['razon'] ?? '',
            ]];
        }

        return $documento;
    }

    /**
     * Anula un DTE emitiendo una Nota de Crédito (Tipo 61)
     *
     * @param array $payload Datos de la Nota de Crédito
     * @return array Resultado
     */
    public function anular(array $payload): array
    {
        $payload['tipo_dte'] = 61;
        return $this->emitir($payload);
    }
}
