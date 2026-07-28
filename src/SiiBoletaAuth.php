<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Autenticación contra la API REST de boleta electrónica del SII.
 *
 * Existe porque `FirmaElectronica::signXML()` de libredte-lib-core NO sirve
 * acá: formatea el XML (`XML.php` fija `formatOutput = true`) y el SII rechaza
 * el documento. Su documentación es explícita:
 *
 *   "Notese que el archivo tiene 2 líneas, no tres"
 *   "si se elimina el elemento Signature el documento debe quedar tal como
 *    estaba antes de firmar, incluyendo espacios, saltos de línea"
 *
 * Por eso acá el XML se arma por concatenación de strings, nunca con DOM: un
 * DOMDocument reformatea y rompe el digest.
 *
 * Flujo: GET semilla → firmar → POST token.
 */
class SiiBoletaAuth
{
    /** Namespace de XML Digital Signature. */
    private const NS = 'http://www.w3.org/2000/09/xmldsig#';

    /** Vector de prueba publicado por el SII, para verificar el digest. */
    public const SEMILLA_EJEMPLO = '030530912644';
    public const DIGEST_EJEMPLO  = 'l2s9BqLppHaWo+w1Al1J5SsYScs=';

    private string $apiBase;
    private string $userAgent;

    public function __construct(bool $certificacion = true, string $userAgent = 'ManhattanPOS/1.0')
    {
        // Ojo: el ENVÍO usa otro host (pangal/rahue). Acá solo semilla y token.
        $this->apiBase = $certificacion
            ? 'https://apicert.sii.cl/recursos/v1'
            : 'https://api.sii.cl/recursos/v1';
        $this->userAgent = $userAgent;
    }

    /**
     * El cuerpo que se firma. UNA sola línea, sin declaración XML.
     * El digest se calcula sobre exactamente este string.
     */
    public static function cuerpoGetToken(string $semilla): string
    {
        return '<getToken><item><Semilla>' . $semilla . '</Semilla></item></getToken>';
    }

    /**
     * DigestValue: SHA1 del cuerpo, en Base64.
     *
     * No hace falta canonicalizar: la forma canónica de este elemento simple
     * es el string tal cual. Verificable contra el vector del SII.
     */
    public static function digest(string $semilla): string
    {
        return base64_encode(sha1(self::cuerpoGetToken($semilla), true));
    }

    /** Comprueba el firmador contra el vector publicado por el SII. */
    public static function autoTest(): bool
    {
        return self::digest(self::SEMILLA_EJEMPLO) === self::DIGEST_EJEMPLO;
    }

    /**
     * Arma el documento firmado de 2 líneas.
     *
     * @param string $semilla   Semilla obtenida del SII
     * @param string $certPem   Certificado en PEM
     * @param resource|\OpenSSLAsymmetricKey $pkey Clave privada ya cargada
     */
    public static function firmarGetToken(string $semilla, string $certPem, $pkey): string
    {
        $digest = self::digest($semilla);

        // XMLDSig distingue dos formas de SignedInfo:
        //  - la que se FIRMA: la canónica, con el namespace heredado explícito
        //  - la que se EMITE: sin la declaración, porque ya la trae <Signature>
        // Emitir la canónica deja un xmlns duplicado que el parser del SII no
        // digiere: responde ESTADO 11 como si no encontrara el certificado.
        $interior =
            '<CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>'
            . '<SignatureMethod Algorithm="' . self::NS . 'rsa-sha1"/>'
            . '<Reference URI="">'
            . '<Transforms>'
            . '<Transform Algorithm="' . self::NS . 'enveloped-signature"/>'
            . '</Transforms>'
            . '<DigestMethod Algorithm="' . self::NS . 'sha1"/>'
            . '<DigestValue>' . $digest . '</DigestValue>'
            . '</Reference>';

        // Se firma la forma canónica (con xmlns), se emite la otra.
        $signedInfoCanonico = '<SignedInfo xmlns="' . self::NS . '">' . $interior . '</SignedInfo>';
        $signedInfoEmitido  = '<SignedInfo>' . $interior . '</SignedInfo>';

        $firmaBin = '';
        if (!openssl_sign($signedInfoCanonico, $firmaBin, $pkey, OPENSSL_ALGO_SHA1)) {
            throw new RuntimeException('No se pudo firmar el SignedInfo.');
        }
        $signatureValue = base64_encode($firmaBin);

        [$modulus, $exponent] = self::modulusExponent($certPem);
        $x509 = self::soloBase64Cert($certPem);

        $signature =
            '<Signature xmlns="' . self::NS . '">'
            . $signedInfoEmitido
            . '<SignatureValue>' . $signatureValue . '</SignatureValue>'
            . '<KeyInfo>'
            . '<KeyValue>'
            . '<RSAKeyValue>'
            . '<Modulus>' . $modulus . '</Modulus>'
            . '<Exponent>' . $exponent . '</Exponent>'
            . '</RSAKeyValue>'
            . '</KeyValue>'
            . '<X509Data><X509Certificate>' . $x509 . '</X509Certificate></X509Data>'
            . '</KeyInfo>'
            . '</Signature>';

        // La Signature va dentro de getToken, después de item. Al quitarla, el
        // documento vuelve a ser exactamente el cuerpo sobre el que se calculó
        // el digest: eso es lo que exige la transformación enveloped-signature.
        $cuerpoConFirma = str_replace(
            '</getToken>',
            $signature . '</getToken>',
            self::cuerpoGetToken($semilla)
        );

        // Exactamente 2 líneas, sin salto final.
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $cuerpoConFirma;
    }

    /** Modulus y Exponent en Base64, como los espera XMLDSig. */
    private static function modulusExponent(string $certPem): array
    {
        $pub = openssl_pkey_get_public($certPem);
        if ($pub === false) {
            throw new RuntimeException('No se pudo leer la clave pública del certificado.');
        }
        $detalles = openssl_pkey_get_details($pub);
        if (!isset($detalles['rsa']['n'], $detalles['rsa']['e'])) {
            throw new RuntimeException('El certificado no expone una clave RSA.');
        }
        return [
            base64_encode($detalles['rsa']['n']),
            base64_encode($detalles['rsa']['e']),
        ];
    }

    /**
     * Cuerpo Base64 del certificado, sin las líneas BEGIN/END y en UNA SOLA
     * LÍNEA CONTINUA, sin saltos ni indentación.
     *
     * Se probaron ambas formas contra el SII —una línea y partido en 64, con
     * el xmlns ya corregido— y las dos devuelven el mismo ESTADO 11
     * ("elemento «Certificate» no existe, función getCertificado"). Se deja en
     * una línea por ser la forma más simple, no porque resuelva nada.
     */
    private static function soloBase64Cert(string $certPem): string
    {
        $limpio = preg_replace('/-----(BEGIN|END) CERTIFICATE-----/', '', $certPem);
        return trim(preg_replace('/\s+/', '', (string) $limpio));
    }

    /** GET /boleta.electronica.semilla */
    public function obtenerSemilla(): string
    {
        $r = $this->http('GET', $this->apiBase . '/boleta.electronica.semilla', [
            'headers' => ['Accept: application/xml'],
        ]);
        if ($r['codigo'] !== 200) {
            throw new RuntimeException("El SII no entregó semilla (HTTP {$r['codigo']}): "
                . substr($r['cuerpo'], 0, 300));
        }
        if (!preg_match('#<SEMILLA>(.*?)</SEMILLA>#s', $r['cuerpo'], $m)) {
            throw new RuntimeException('Respuesta de semilla sin elemento SEMILLA: '
                . substr($r['cuerpo'], 0, 300));
        }
        return trim($m[1]);
    }

    /** POST /boleta.electronica.token */
    public function obtenerToken(string $xmlFirmado): string
    {
        // El charset explícito y el Accept salen de una implementación de
        // referencia que sí funciona. Sin el Accept, el SII devuelve
        // ESTADO 11 "elemento «Certificate» no existe, función getCertificado".
        $r = $this->http('POST', $this->apiBase . '/boleta.electronica.token', [
            'headers' => [
                'Content-Type: application/xml; charset=utf-8',
                'Accept: application/xml',
            ],
            'body'    => $xmlFirmado,
        ]);
        if ($r['codigo'] !== 200) {
            throw new RuntimeException("El SII rechazó la solicitud de token (HTTP {$r['codigo']}): "
                . substr($r['cuerpo'], 0, 500));
        }
        if (!preg_match('#<TOKEN>(.*?)</TOKEN>#s', $r['cuerpo'], $m)) {
            throw new RuntimeException('Respuesta de token sin elemento TOKEN: '
                . substr($r['cuerpo'], 0, 500));
        }
        return trim($m[1]);
    }

    /**
     * GET /boleta.electronica/{rut}-{dv}-{tipo}-{folio}/estado
     * Solo lectura: pregunta si el SII conoce un documento ya emitido.
     */
    public function estadoDocumento(string $token, string $rut, string $dv, int $tipo, int $folio): array
    {
        $url = $this->apiBase . "/boleta.electronica/{$rut}-{$dv}-{$tipo}-{$folio}/estado";
        return $this->http('GET', $url, ['headers' => ["Cookie: TOKEN={$token}"]]);
    }

    private function http(string $metodo, string $url, array $opts = []): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CUSTOMREQUEST  => $metodo,
            // El SII marca User-Agent como parámetro requerido en el envío.
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_HTTPHEADER     => $opts['headers'] ?? [],
        ]);
        if (isset($opts['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
        }
        $cuerpo = curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['codigo' => $codigo, 'cuerpo' => (string) $cuerpo];
    }
}
