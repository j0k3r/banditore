<?php

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;

/**
 * @see https://github.com/phpstan/phpstan-symfony#console-command-analysis
 */
require dirname(__DIR__) . '/tests/bootstrap.php';

$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);

return new Application($kernel);
