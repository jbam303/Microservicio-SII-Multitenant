<?php

declare(strict_types=1);

namespace App;

use RuntimeException;
use sasco\LibreDTE\FirmaElectronica;
use sasco\LibreDTE\Log;
use sasco\LibreDTE\Sii;
use sasco\LibreDTE\Sii\Autenticacion;

/**
 * Transmite boletas electrónicas al SII.
 *
 * ── El camino, que costó cuatro días encontrar ──────────────────────────────
 *
 * En producción las boletas NO van por `DTEUpload` de palena: ese host recibe
 * DTE de factura y RVD, y rechaza un sobre EnvioBOLETA con
 * `SCH-00001: Invalid Schema Name` incluso con el contribuyente ya certificado.
 * Van por el endpoint REST de boleta en `rahue.sii.cl`.
 *
 * Y el token NO es el de `boleta.electronica.token`. Ese devuelve `ESTADO 11 —
 * elemento «Certificate» no existe` siempre, en los dos ambientes, con
 * contribuyentes ya certificados, y sigue sin explicación. No importa: el token
 * SOAP de `GetTokenFromSeed.jws` sirve. Solo da 401 contra `api.sii.cl`, que es
 * otro host.
 *
 * Mapa confirmado empíricamente:
 *   palena/cgi_dte/UPL/DTEUpload            DTE y RVD. Rechaza boletas.
 *   maullin/cgi_dte/UPL/DTEUpload           sí acepta boletas (set de certificación)
 *   rahue/recursos/v1/boleta.electronica.envio   boletas en producción ✓
 *   api.sii.cl                              401 con token SOAP
 *
 * ── Sobre el token ─────────────────────────────────────────────────────────
 *
 * Dura una hora y se renueva con cada uso, así que conviene pedirlo una vez y
 * reutilizarlo en todos los lotes: el propio spec del SII lo recomienda.
 */
class BoletaSender
{
    /** El SII recomienda 50 boletas por sobre para envíos de volumen. */
    public const MAX_POR_LOTE = 50;

    private const HOSTS = [
        // [host de envío, ambiente de LibreDTE para pedir el token]
        'produccion' => ['rahue.sii.cl', Sii::PRODUCCION],
        'certificacion' => ['pangal.sii.cl', Sii::CERTIFICACION],
    ];

    private string $host;
    private string $token;
    private string $rutEnvia;

    /**
     * @param array  $credenciales ['certificado_b64' => ..., 'password' => ...]
     * @param string $ambiente     'produccion' | 'certificacion'
     */
    public function __construct(array $credenciales, string $ambiente = 'produccion')
    {
        if (!isset(self::HOSTS[$ambiente])) {
            throw new RuntimeException("Ambiente desconocido: $ambiente.", 400);
        }
        [$this->host, $ambienteSii] = self::HOSTS[$ambiente];

        $certContent = base64_decode($credenciales['certificado_b64'] ?? '', true);
        if ($certContent === false) {
            throw new RuntimeException('El certificado no está en base64 válido.', 400);
        }
        $firma = new FirmaElectronica([
            'data' => $certContent,
            'pass' => $credenciales['password'] ?? '',
        ]);
        $this->rutEnvia = (string) $firma->getID();
        if (!$this->rutEnvia) {
            throw new RuntimeException('No se pudo leer el RUT del certificado.', 400);
        }

        // El token va por SOAP, no por la API REST de boleta. Ver la cabecera.
        Sii::setAmbiente($ambienteSii);
        $token = Autenticacion::getToken(['data' => $certContent, 'pass' => $credenciales['password'] ?? '']);
        if (!$token) {
            $errores = array_map('strval', Log::readAll());
            throw new RuntimeException(
                'No se pudo obtener el token del SII: '
                . ($errores ? implode(' | ', $errores) : 'sin detalle'),
                502
            );
        }
        $this->token = (string) $token;
    }

    public function getRutEnvia(): string
    {
        return $this->rutEnvia;
    }

    /**
     * Envía UN sobre ya armado y firmado.
     *
     * @param string $xmlSobre  XML del EnvioBOLETA
     * @param string $rutEmisor RUT del contribuyente, con guión
     * @return array ['trackid' => ..., 'estado' => ..., 'respuesta' => array]
     */
    public function enviarSobre(string $xmlSobre, string $rutEmisor): array
    {
        [$rs, $ds] = self::partirRut($this->rutEnvia);
        [$rc, $dc] = self::partirRut($rutEmisor);

        $archivo = tempnam(sys_get_temp_dir(), 'envio_');
        if ($archivo === false) {
            throw new RuntimeException('No se pudo crear el archivo temporal del envío.', 500);
        }
        file_put_contents($archivo, $xmlSobre);

        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => "https://{$this->host}/recursos/v1/boleta.electronica.envio",
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 120,
                // El spec del SII marca User-Agent como parámetro REQUERIDO.
                CURLOPT_USERAGENT => 'ManhattanPOS/1.0',
                CURLOPT_HTTPHEADER => [
                    "Cookie: TOKEN={$this->token}",
                    'Accept: application/json',
                ],
                CURLOPT_POSTFIELDS => [
                    'rutSender' => $rs,
                    'dvSender' => $ds,
                    'rutCompany' => $rc,
                    'dvCompany' => $dc,
                    'archivo' => curl_file_create($archivo, 'application/xml', basename($archivo)),
                ],
            ]);
            $cuerpo = (string) curl_exec($ch);
            $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errCurl = curl_error($ch);
            curl_close($ch);
        } finally {
            @unlink($archivo);
        }

        if ($codigo === 0) {
            throw new RuntimeException("No se pudo contactar al SII: $errCurl", 502);
        }
        if ($codigo !== 200) {
            throw new RuntimeException(
                "El SII rechazó el envío (HTTP $codigo): " . substr($cuerpo, 0, 400),
                502
            );
        }

        $datos = json_decode($cuerpo, true);
        if (!is_array($datos) || !isset($datos['trackid'])) {
            throw new RuntimeException(
                'La respuesta del SII no trae trackid: ' . substr($cuerpo, 0, 400),
                502
            );
        }

        return [
            'trackid' => (string) $datos['trackid'],
            'estado' => (string) ($datos['estado'] ?? ''),
            'respuesta' => $datos,
        ];
    }

    private static function partirRut(string $rut): array
    {
        $limpio = str_replace('.', '', trim($rut));
        if (!str_contains($limpio, '-')) {
            throw new RuntimeException("El RUT '$rut' debe venir con guión y dígito verificador.", 400);
        }
        [$num, $dv] = explode('-', $limpio, 2);
        return [$num, strtoupper($dv)];
    }
}
