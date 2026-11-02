<?php

require dirname(__DIR__).'/vendor/autoload.php';

Tests\Support\IsolatedDatabase::configureMySql();

Illuminate\Database\Connection::resolverFor('mysql', fn ($pdo, $database, $prefix, $config) => new Tests\Support\IsolatedMySqlConnection($pdo, $database, $prefix, $config));
