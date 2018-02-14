<?php

namespace esc\classes;


use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;

class Database
{
    private static $capsule;

    public static function initialize()
    {
        self::connect();
    }

    private static function connect()
    {
        Log::info("Connecting to database...");

        $capsule = new Capsule();

        $capsule->addConnection([
            'driver' => 'mysql',
            'host' => Config::get('db.host'),
            'database' => Config::get('db.db'),
            'username' => Config::get('db.user'),
            'password' => Config::get('db.password'),
            'charset' => 'utf8',
            'collation' => 'utf8_unicode_ci',
            'prefix' => '',
        ]);

        $capsule->setAsGlobal();

        $capsule->bootEloquent();

        if ($capsule->getConnection() == null) {
            Log::error("Database connection failed. Exiting.");
            exit(2);
        }

        self::$capsule = $capsule;

        Log::info("Database connected.");
    }

    public static function getConnection(): Connection
    {
        return self::$capsule->getConnection();
    }

    public static function create(string $table, $callback)
    {
        if (!self::hasTable($table)) {
            self::getConnection()->getSchemaBuilder()->create($table, $callback);
        }
    }

    public static function hasTable(string $table): bool
    {
        return self::getConnection()->getSchemaBuilder()->hasTable('maps');
    }
}