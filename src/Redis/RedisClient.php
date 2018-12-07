<?php
namespace MarsLab\Redis;

use MarsLab\Common\Config;
use MarsLab\Common\ETS;

Class RedisClient
{

    public static $PERSISTENT_CONNECT = true;
    public static $TIMEOUT            = 5;
    protected $_client = null;
    protected static $_instances = [];

    private function __construct()
    {
        $this->_client = new \Redis();
    }

    public function getClient()
    {
        return $this->_client;
    }

    public function __call($name, $arguments)
    {
        if(!$this->_client) {
            log_message('Redis client invalid!', LOG_ERR);

            return false;
        }
        if(!method_exists($this->_client, $name)) {
            trigger_error("The method \"$name\" not exists for Redis object.", E_USER_ERROR);

            return false;
        }
        if(empty($arguments)) {
            $arguments = [];
        }
        try{
            $start_time = microtime(true);
            $ret = call_user_func_array([$this->_client, $name], $arguments);
            $use_time = microtime(true) - $start_time;
            ETS::time("redis.{$name}", $use_time, "");
        } catch(\Exception $e){
            $ret = false;
            log_message("Redis exception:" . $e, LOG_ERR);
        }
        if($ret === false
           && in_array($name, [
                'open',
                'connect',
                'popen',
                'pconnect',
            ])) {

            log_message("REDIS connect error:{$arguments[0]}:{$arguments[1]}", LOG_ERR);
        }

        return $ret;
    }

    public function __destruct()
    {
        if($this->_client) {
            @$this->_client->close();
        }
    }

    /**
     * @param $clusterId string
     *
     * @return \Redis
     */
    public static function getInstance($clusterId = 'default')
    {
        $config = Config::get("redis_single.{$clusterId}");
        if(empty($config)) {
            trigger_error("Config error:no redis cluster config $clusterId", E_USER_ERROR);

            return null;
        }
        list($map, $db) = explode('.', $config);
        if(isset(self::$_instances[$clusterId])) {
            $client = self::$_instances[$clusterId];
            $client->select($db);

            return $client;
        }
        $physicalConfig = Config::get("redis_physical.{$map}");
        if(empty($physicalConfig)) {
            trigger_error("Config error:no redis physical config $map", E_USER_ERROR);

            return null;
        }
        $host = $physicalConfig['host'];
        $port = $physicalConfig['port'];
        $pswd = $physicalConfig['pwd'] ?? '';
        /** @var \Redis $client */
        $client = new self();
        $connectRet = true;
        if(self::$PERSISTENT_CONNECT) {
            $connectRet = $client->pconnect($host, $port, self::$TIMEOUT);
        } else {
            $connectRet = $client->connect($host, $port, self::$TIMEOUT);
        }
        if($pswd) {
            $client->auth($pswd);
        }
        if(!$connectRet) {
            throw new \Exception('redis connect fail');
        }
        self::$_instances[$clusterId] = $client;
        $client->select($db);

        return self::$_instances[$clusterId];
    }
}
