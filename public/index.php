<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Factory\AppFactory;
use Slim\Exception\HttpUnauthorizedException;
use App\DteEmitter;

require __DIR__ . '/../vendor/autoload.php';

// Zona horaria de Chile, no la del contenedor.
//
// Sin esto PHP asume UTC, y como Chile está en UTC-4 toda boleta emitida entre
// las 20:00 y medianoche salía con la FECHA DE MAÑANA en el XML: cuatro horas
// por día, todos los días. El comprobante impreso mostraba la fecha correcta
// —la pone el POS— así que la discrepancia no se veía en el papel.
//
// Afecta FchEmis del DTE, el TmstFirma y el período del consumo de folios.
date_default_timezone_set('America/Santiago');

// Ocultar warnings de librerías legacy (ej. LibreDTE) para no romper el output JSON
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '0');

// Los errores igual se registran, a stderr, que es de donde Cloud Logging lee.
ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr');

/**
 * Captura de errores FATALES.
 *
 * El handler de errores de Slim (más abajo) atrapa Throwable, pero un fatal
 * de PHP no es un Throwable: escapa por completo y Apache devuelve un 500 con
 * cuerpo vacío, sin dejar rastro. Es exactamente lo que ocurre al emitir una
 * boleta cuyo primer ítem lleva tilde o ñ (2026-07-27): 500, 0 bytes, cero
 * logs. Sin esto no hay forma de saber qué falló.
 */
register_shutdown_function(function () {
    $err = error_get_last();
    if (!$err) {
        return;
    }
    $fatales = [E_ERROR, E_PARSE, E_CORE_ERROR, E_CORE_WARNING, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($err['type'], $fatales, true)) {
        return;
    }
    error_log(json_encode([
        'severity' => 'CRITICAL',
        'message'  => 'FATAL PHP: ' . $err['message'],
        'tipo'     => $err['type'],
        'file'     => $err['file'] . ':' . $err['line'],
        'uri'      => $_SERVER['REQUEST_URI'] ?? '',
    ]));
});

// Cargar token estático desde variables de entorno
$staticToken = getenv('API_TOKEN') ?: 'token_secreto_por_defecto';

// Instanciar App
$app = AppFactory::create();
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

// Custom Error Handler para devolver JSON
$customErrorHandler = function (
    Request $request,
    Throwable $exception,
    bool $displayErrorDetails,
    bool $logErrors,
    bool $logErrorDetails
) use ($app) {
    $code = $exception->getCode();
    if ($code < 400 || $code > 599) {
        $code = 500;
    }
    
    // Convertir excepciones HTTP de Slim a sus respectivos códigos
    if ($exception instanceof \Slim\Exception\HttpException) {
        $code = $exception->getCode();
    }

    // Log estructurado. OJO: bajo mod_php/Apache la constante STDERR NO existe
    // (solo está definida en CLI). Usar fwrite(STDERR,...) acá hacía que el
    // propio handler de errores lanzara un fatal, y Apache devolvía un 500 con
    // cuerpo vacío. Por eso durante semanas ningún error del microservicio dejó
    // rastro. error_log() sí funciona: va al log de Apache, que es stderr.
    $logEntry = json_encode([
        'severity' => 'ERROR',
        'message' => $exception->getMessage(),
        'code' => $code,
        'exception_class' => get_class($exception),
        'file' => $exception->getFile() . ':' . $exception->getLine(),
        'trace' => $exception->getTraceAsString(),
        'uri' => (string) $request->getUri(),
        'method' => $request->getMethod(),
    ]);
    error_log($logEntry);

    $payload = [
        'error' => $exception->getMessage(),
        'code'  => $code,
    ];

    $response = $app->getResponseFactory()->createResponse();
    $response->getBody()->write(json_encode($payload));
    
    return $response
            ->withStatus($code)
            ->withHeader('Content-Type', 'application/json');
};

// Add Error Middleware
$errorMiddleware = $app->addErrorMiddleware(true, true, true);
$errorMiddleware->setDefaultErrorHandler($customErrorHandler);

// Middleware de Autenticación
$authMiddleware = function (Request $request, RequestHandler $handler) use ($staticToken) {
    $header = $request->getHeaderLine('Authorization');
    
    if (empty($header) || !preg_match('/Bearer\s+(.*)$/i', $header, $matches)) {
        throw new HttpUnauthorizedException($request, 'Falta el token Bearer en el header Authorization');
    }

    $token = $matches[1];
    
    // Comparación segura en tiempo constante para el token estático
    if (!hash_equals($staticToken, $token)) {
        throw new HttpUnauthorizedException($request, 'Token inválido');
    }

    return $handler->handle($request);
};

// Instanciar el emisor DTE (ahora stateless)
$dteEmitter = new DteEmitter();

// RUTAS

// Endpoint de monitoreo
$app->get('/health', function (Request $request, Response $response) {
    $response->getBody()->write(json_encode(['status' => 'ok', 'php_version' => PHP_VERSION, 'mode' => 'stateless']));
    return $response->withHeader('Content-Type', 'application/json');
});

// Rutas protegidas con autenticación
$app->group('', function (\Slim\Routing\RouteCollectorProxy $group) use ($dteEmitter) {

    // POST /dte/emitir
    $group->post('/dte/emitir', function (Request $request, Response $response) use ($dteEmitter) {
        $payload = (array) $request->getParsedBody();
        $result = $dteEmitter->emitir($payload);

        // Red de seguridad: si algún campo trae bytes que no son UTF-8 válido,
        // json_encode devuelve false y Slim revienta al escribir el cuerpo con
        // un error irreconocible. Mejor fallar diciendo qué pasó.
        $json = json_encode($result);
        if ($json === false) {
            throw new RuntimeException(
                'No se pudo serializar la respuesta: ' . json_last_error_msg(),
                500
            );
        }

        $response->getBody()->write($json);
        return $response->withHeader('Content-Type', 'application/json');
    });

    // POST /dte/enviar-boletas
    //
    // Arma UN sobre EnvioBOLETA con las boletas que le pasen y lo transmite al
    // SII. Devuelve el track ID.
    //
    // El tope es 50 por llamada, que es lo que el SII recomienda para volumen.
    // Django hace los lotes y registra los track ID.
    $group->post('/dte/enviar-boletas', function (Request $request, Response $response) {
        $payload = (array) $request->getParsedBody();

        $xmls = [];
        foreach (($payload['xmls_b64'] ?? []) as $i => $b64) {
            $xml = base64_decode((string) $b64, true);
            if ($xml === false) {
                throw new RuntimeException("El documento en la posición $i no está en base64 válido.", 400);
            }
            $xmls[] = $xml;
        }
        if (!$xmls) {
            throw new RuntimeException('No hay boletas para enviar.', 400);
        }
        if (count($xmls) > \App\BoletaSender::MAX_POR_LOTE) {
            throw new RuntimeException(
                'Demasiadas boletas en un lote: ' . count($xmls) . '. El máximo recomendado por el SII es '
                . \App\BoletaSender::MAX_POR_LOTE . '.',
                400
            );
        }

        $credenciales = (array) ($payload['credenciales'] ?? []);
        $ambiente = (string) ($payload['ambiente'] ?? 'produccion');

        // El sobre se arma acá y no en Django porque la firma vive en el
        // certificado, y el certificado no sale de este servicio.
        $sobre = \App\EnvioBoletaBuilder::armar(
            $xmls,
            (array) ($payload['resolucion'] ?? []),
            $credenciales
        );

        $sender = new \App\BoletaSender($credenciales, $ambiente);
        $envio = $sender->enviarSobre($sobre['xml'], (string) ($payload['rut_emisor'] ?? ''));

        $json = json_encode([
            'trackid' => $envio['trackid'],
            'estado' => $envio['estado'],
            'documentos' => $sobre['documentos'],
            'rut_envia' => $sender->getRutEnvia(),
            'respuesta_sii' => $envio['respuesta'],
        ]);
        if ($json === false) {
            throw new RuntimeException('No se pudo serializar la respuesta: ' . json_last_error_msg(), 500);
        }

        $response->getBody()->write($json);
        return $response->withHeader('Content-Type', 'application/json');
    });

    // POST /dte/consumo-folios
    //
    // Arma el resumen de ventas diarias (RVD, ex reporte de consumo de folios)
    // de UN día. No lo envía: devuelve el XML firmado y decide Django.
    //
    // Recibe los XML ya firmados tal como se guardaron en `Venta.xml_dte_b64`,
    // así que no se re-emite ni se re-firma nada.
    $group->post('/dte/consumo-folios', function (Request $request, Response $response) {
        $payload = (array) $request->getParsedBody();

        $xmls = [];
        foreach (($payload['xmls_b64'] ?? []) as $i => $b64) {
            $xml = base64_decode((string) $b64, true);
            if ($xml === false) {
                throw new RuntimeException("El documento en la posición $i no está en base64 válido.", 400);
            }
            $xmls[] = $xml;
        }

        // Folios anulados por tipo: {"39": [11, 12]}. Son folios que se
        // consumieron y cuya boleta nunca se entregó (la cajera se equivocó).
        // Sin declararlos, el SII ve huecos sin explicación en la secuencia.
        $anulados = [];
        foreach ((array) ($payload['anulados'] ?? []) as $tipo => $folios) {
            $anulados[(int) $tipo] = array_map('intval', (array) $folios);
        }

        $result = \App\ConsumoFoliosBuilder::armar(
            $xmls,
            (array) ($payload['resolucion'] ?? []),
            (array) ($payload['credenciales'] ?? []),
            (int) ($payload['secuencia'] ?? 1),
            $anulados,
            // Solo se usa si el lote no trae boletas emitidas y hay que sacar
            // la fecha y el emisor de algún lado.
            (array) ($payload['contexto'] ?? [])
        );

        // El XML va en base64 por la misma razón que en /dte/emitir: el
        // documento del SII es ISO-8859-1 y json_encode() exige UTF-8 válido.
        $result['xml'] = base64_encode($result['xml']);

        $json = json_encode($result);
        if ($json === false) {
            throw new RuntimeException(
                'No se pudo serializar la respuesta: ' . json_last_error_msg(),
                500
            );
        }

        $response->getBody()->write($json);
        return $response->withHeader('Content-Type', 'application/json');
    });

    // POST /dte/anular
    $group->post('/dte/anular', function (Request $request, Response $response) use ($dteEmitter) {
        $payload = (array) $request->getParsedBody();
        $result = $dteEmitter->anular($payload);
        
        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // POST /dte/test-cert
    $group->post('/dte/test-cert', function (Request $request, Response $response) {
        $payload = (array) $request->getParsedBody();
        $certBase64 = $payload['credenciales']['certificado_b64'] ?? '';
        $password = $payload['credenciales']['password'] ?? '';
        
        $certContent = base64_decode($certBase64);
        $certs = [];
        $success = openssl_pkcs12_read($certContent, $certs, $password);
        
        $errors = [];
        while ($msg = openssl_error_string()) {
            $errors[] = $msg;
        }

        $result = [
            'success' => $success,
            'openssl_errors' => $errors,
            'certs_keys' => $success ? array_keys($certs) : []
        ];
        
        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    });

})->add($authMiddleware);

// Ejecutar la aplicación
$app->run();
