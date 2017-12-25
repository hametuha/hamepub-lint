<?php

use Slim\Http\Request;
use Slim\Http\Response;

use Hametuha\PubLint\Validator;

// Routes.
$app->get('/', function (Request $request, Response $response, array $args) {
    // Render index view
    return $this->renderer->render($response, 'index.phtml', [
        'versions' => Validator::getAvailables(),
    ]);
});

// Do post request.
$app->post('/validator', [Validator::class, 'handlePostRequest']);
$app->post('/validator/{version}', [ Validator::class, 'handlePostRequest']);
