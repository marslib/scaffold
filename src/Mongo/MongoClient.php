<?php
namespace MarsLib\Scaffold\Mongo;

use MarsLib\Scaffold\Common\Config;
use MongoDB\Client;

class MongoClient
{

    protected static $_client    = null;
    protected static $_instances = [];

    private function __construct()
    {
    }

    /**
     * @param int $clusterId
     * @return \MongoDB\Client
     */
    public static function getInstance($clusterId = 0)
    {
        if(isset(self::$_instances[$clusterId])) {
            $client = self::$_instances[$clusterId];
            self::$_client = $client;
            return $client;
        }
        $physical = Config::get('mongo_physical.' . $clusterId);
        if(empty($physical)) {
            trigger_error("Config error:no mongo_physical config", E_USER_ERROR);

            return null;
        }
        // mongodb://127.0.0.1:27017/admin:admin
        $uri = "mongodb://{$physical['host']}";
        $option = $physical;
        unset($option['host']);
        self::$_instances[$clusterId] = new Client($uri, $option);
        self::$_client = self::$_instances[$clusterId];
        return self::$_instances[$clusterId];
    }
}