<?php

namespace App\Legacy;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

/**
 * Opens the connections to the temporary legacy databases (compose profile "legacy") from the env URLs.
 * They are deliberately not Doctrine connections of the application.
 */
final class LegacyConnections
{
    public static function open(string $url): Connection
    {
        $params = (new DsnParser(['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql']))->parse($url);

        return DriverManager::getConnection($params);
    }
}
