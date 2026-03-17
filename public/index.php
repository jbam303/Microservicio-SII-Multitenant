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

})->add($authMiddleware);

// Ejecutar la aplicación
$app->run();
