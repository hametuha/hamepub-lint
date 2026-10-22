<?php
/**
 * Build the Slim application.
 *
 * Returns a fresh instance on every require so that tests can boot the app repeatedly.
 */

use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;

$app = AppFactory::create();

$renderer = new PhpRenderer(dirname(__DIR__) . '/templates/');

// Do not show error details to visitors. Errors are logged to stderr (Cloud Logging)
// without stack traces, so that 404s from scanners do not flood the log.
$app->addRoutingMiddleware();
$app->addErrorMiddleware(false, true, false);

require __DIR__ . '/routes.php';

return $app;
