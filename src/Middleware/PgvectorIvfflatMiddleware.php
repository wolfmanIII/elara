<?php

namespace App\Middleware;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

#[AsMiddleware] // per tutte le connessioni; oppure connections: ['default']
final class PgvectorIvfflatMiddleware implements Middleware
{
    public function __construct(
        private int $probes = 10,
    ) {}

    public function wrap(Driver $driver): Driver
    {
        return new PgvectorIvfflatDriver($driver, $this->probes);
    }
}

final class PgvectorIvfflatDriver extends AbstractDriverMiddleware
{
    public function __construct(
        Driver $driver,
        private int $probes,
    ) {
        parent::__construct($driver);
    }

    public function connect(array $params): Connection
    {
        $connection = parent::connect($params);

        // QUI settiamo la sessione pgvector
        $connection->exec('SET ivfflat.probes = '.$this->probes);

        return $connection;
    }
}
