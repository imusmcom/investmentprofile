<?php
declare(strict_types=1);

/**
 * Front controller. Every request is routed through index.php/<path> so the app runs
 * on the PHP built-in server, Apache and nginx without rewrite rules.
 */

require __DIR__ . '/../app/bootstrap.php';

use App\App;

App::run();
