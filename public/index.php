<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Factory\AppFactory;
use Slim\Exception\HttpUnauthorizedException;
use App\DteEmitter;

require __DIR__ . '/../vendor/autoload.php';

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
        
        $response->getBody()->write(json_encode($result));
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
