<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use Hametuha\PubLint\Validator;

/** @var \Slim\App $app */
/** @var \Slim\Views\PhpRenderer $renderer */

// Home.
$app->get('/', function (Request $request, Response $response) use ($renderer) {
    return $renderer->render($response, 'index.phtml', [
        'versions' => Validator::getAvailables(),
    ]);
});

// Do post request.
$app->post('/validator', [Validator::class, 'handlePostRequest']);
$app->post('/validator/{version}', [Validator::class, 'handlePostRequest']);

// Monitor site
$app->get('/monitor', function (Request $request, Response $response) {
    $now = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
    return Validator::json($response, [
        'status' => 'success',
        'timestamp' => $now->format(DateTime::ATOM),
    ]);
});
