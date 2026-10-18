<?php

// No .env parsing: configuration comes from real environment variables (docker compose) and phpunit.dist.xml.
require dirname(__DIR__).'/vendor/autoload.php';

if ($_SERVER['APP_DEBUG'] ?? true) {
    umask(0000);
}
