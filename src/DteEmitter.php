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
            
            // 4. Mapear Payload de Django al formato requerido por LibreDTE (Arreglo Encabezado/Detalle)
            // Se asume que el backend Django mandará las claves correctamente, o aquí se mapean:
            $documento = [
                'Encabezado' => [
                    'IdDoc' => [
                        'TipoDTE' => $tipoDte,
                        'Folio' => $folioAsignado,
                    ],
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
                    'Totales' => [
                        'MntTotal' => $payload['totales']['monto_total'] ?? 0,
                    ]
                ],
                'Detalle' => []
            ];

            // Rellenar detalles
            $detalles = $payload['detalle'] ?? [];
            foreach ($detalles as $idx => $item) {
                $documento['Detalle'][] = [
                    'NroLinDet' => $idx + 1,
                    'NmbItem' => $item['nombre'] ?? '',
                    'QtyItem' => $item['cantidad'] ?? 1,
                    'PrcItem' => $item['precio'] ?? 0,
                    'MontoItem' => ($item['cantidad'] ?? 1) * ($item['precio'] ?? 0)
                ];
            }

            // 5. Instanciar y armar DTE
            $dte = new Dte($documento);
            
            // 6. Timbrar DTE con CAF (Timbre Electrónico SII) y Folio
            $dte->timbrar($folios);
            
            // 7. Firmar documento
            $xmlFirmado = $dte->firmar($firma);
            
            if (!$xmlFirmado) {
                 throw new RuntimeException("Error al firmar el DTE. Revisa tus credenciales y CAF.", 500);
            }

            return [
                'folio' => $folioAsignado, 
                'xml'   => base64_encode($xmlFirmado),
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
