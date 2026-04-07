<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$router = new Bramus\Router\Router();

require dirname(__DIR__) . '/src/routes.php';

$router->run();
