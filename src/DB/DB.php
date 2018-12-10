<?php
namespace MarsLib\Scaffold\Db;

use MarsLib\Scaffold\Common\ETS;
use \PDO;

class DB
{

    private $_dbRead;
    private $_dbWrite;
    private $_dbName;
    private $_dbUser;
    private $_dbPwd;
    private $_readServers;
    private $_writeServers;
    private $_res;
    protected $_transactionCounter = 0;
    protected $_readOnMaster = false;
    private $retry_times = 0;
    private $retry_limit = 3;
    public $errorHandler = null;

    const ERR                       = -1;

    const FETCH_ALL                 = 0;

    const FETCH_ONE                 = 1;

    const SERVICE_STATUS_CACHE_TIME = 10;

    protected function __construct($dbName, $dbUser, $dbPwd, $readServers, $writeServers)
    {
        $this->_dbName = $dbName;
        $this->_dbUser = $dbUser;
        $this->_dbPwd = $dbPwd;
        $this->_readServers = $readServers;
        $this->_writeServers = $writeServers;
        if(empty($this->_readServers)) {
            $this->_readOnMaster = true;
        }
    }

    public function setReadOnMaster($operate = true)
    {
        $this->_readOnMaster = $operate;
    }

    protected function _log($message, $level)
    {
        if(function_exists('log_message')) {
            log_message($message, $level, 2);
        }
    }

    protected function _error($message, $excpetion = null)
    {
        if(function_exists('log_message')) {
            log_message("error_report:$message", LOG_ERR, 2);
        }
        if(function_exists('error_report')) {
            error_report($message);
        }
        if($this->errorHandler && is_callable($this->errorHandler)) {
            call_user_func($this->errorHandler, $message, $excpetion);
        }
    }

    protected function _time($type, $useTime, $message)
    {
        ETS::time("db.{$type}", $useTime, $message);
    }

    protected function _cacheSet($key, $val, $expireTime)
    {
        if(function_exists('xcache_set')) {

            return xcache_set($key, $val, $expireTime);
        }

        return false;
    }

    protected function _cacheGet($key)
    {
        if(function_exists('xcache_get')) {
            return xcache_get($key);
        }

        return false;
    }

    public function inTransaction()
    {
        return $this->_transactionCounter > 0;
    }

    public function beginTransaction()
    {
        $this->setReadOnMaster(true);
        $db = $this->getDbWrite();
        if(!$db) {
            return false;
        }
        if(!$this->_transactionCounter++) {
            return $db->beginTransaction();
        }
        $db->exec('SAVEPOINT trans' . $this->_transactionCounter);

        return $this->_transactionCounter >= 0;
    }

    public function commit()
    {
        $db = $this->getDbWrite();
        if(!$db) {
            return false;
        }
        if(!--$this->_transactionCounter) {
            return $db->commit();
        }

        return $this->_transactionCounter >= 0;
    }

    public function rollback()
    {
        $db = $this->getDbWrite();
        if(!$db) {
            return false;
        }
        if(--$this->_transactionCounter) {
            $db->exec('ROLLBACK TO trans' . ($this->_transactionCounter + 1));

            return true;
        }

        return $db->rollback();
    }

    public function query($sql)
    {
        try{
            $db = $this->getDb();
            $this->_log("Execute Sql: $sql", LOG_DEBUG);
            $startTime = microtime(true);
            $this->_res = $db->query($sql);
            $useTime = microtime(true) - $startTime;
            $this->_time('query', $useTime, $sql);
            if($this->_res === false) {
                //Log error here
                $this->_log('DB error:' . $sql, LOG_EMERG);

                return false;
            }
        } catch(\Exception $e){
            $this->_error('DB error:' . $e, $e);

            return false;
        }
    }

    public function call($procedure, $in_params = null, $out_params = null, &$res = [])
    {
        try{
            $db = $this->getDbWrite();
            $sql = "CALL {$procedure}(";
            $bind_params = [];
            if($in_params) {
                foreach($in_params as $in_param) {
                    if(is_string($in_param)) {
                        $bind_params[] = PDO::PARAM_STR;
                    } elseif(is_numeric($in_param)) {
                        $bind_params[] = PDO::PARAM_INT;
                    } else {
                        $this->_log("call procedure with unknow type in param:{$in_param}", LOG_ERROR);

                        return false;
                    }
                    $sql .= '?,';
                }
            }
            if($out_params) {
                foreach($out_params as $out_param) {
                    $sql .= "@{$out_param},";
                }
            }
            $sql = rtrim($sql, ',');
            $sql .= ")";
            $this->_log("Execute Sql: $sql", LOG_DEBUG);
            $startTime = microtime(true);
            $stmt = $db->prepare($sql);
            if($bind_params) {
                foreach($bind_params as $i => $bind_param_type) {
                    $stmt->bindParam($i + 1, $in_params[$i], $bind_param_type);
                }
            }
            $ok = $stmt->execute();
            $stmt->closeCursor();
            if($ok === false) {
                $this->_log('DB error:' . $sql, LOG_EMERG);

                return false;
            }
            if($out_params) {
                $sql2 = "SELECT ";
                foreach($out_params as $out_param) {
                    $sql2 .= "@{$out_param} AS {$out_param},";
                }
                $sql2 = rtrim($sql2, ',');
                $this->_log("Execute Sql: $sql2", LOG_DEBUG);
                $result = $db->query($sql2);
                if($result === false) {
                    $this->_log('DB error:' . $sql2, LOG_EMERG);

                    return false;
                }
                $res = $result->fetch(PDO::FETCH_ASSOC);
                $result->closeCursor();
            }

            return true;

        } catch(\Exception $e){
            $this->_error('DB error:' . $e, $e);

            return false;
        }
    }

    public function getDb()
    {
        $db = $this->_readOnMaster ? $this->getDbWrite() : $db = $this->getDbRead();
        if(!$this->pdoPing($db) && $this->retry_times <= $this->retry_limit) {
            $this->retry_times++;
            $this->_dbRead = null;
            $this->_dbWrite = null;
            $this->_log('<<<<<<<<<<<<<< reconnection ' . $this->retry_times . ' >>>>>>>>>>>>>>>>>>>', LOG_ERR);
            $db = $this->getDb();
        }

        return $db;
    }

    /**
     * 获取SQL查询结果
     *
     * @param string  $sql
     * @param array   $res        Out parameter, array to be filled with
     *                            fetched results
     * @param integer $fetchStyle 获取命名列或是数字索引列，默认为命令列
     * @param integer $fetchMode  获取全部或是一行，默认获取全部
     *
     * @return boolean|integer false on failure, else return count of fetched
     *                         rows
     */
    public function select($sql, &$res, $fetchStyle = PDO::FETCH_NAMED, $fetchMode = self::FETCH_ALL)
    {
        try{

            $db = $this->getDb();
            $this->_log("Execute Sql: $sql", LOG_DEBUG);
            $startTime = microtime(true);
            $this->_res = $db->query($sql);
            $useTime = microtime(true) - $startTime;
            $this->_time('select', $useTime, $sql);
            if($this->_res === false) {
                //Log error here
                $this->_log('DB error:' . $sql, LOG_EMERG);

                return false;
            }
            if($fetchMode === self::FETCH_ALL) {
                $res = $this->_res->fetchAll($fetchStyle);

                return count($res);
            } elseif($fetchMode === self::FETCH_ONE) {
                $res = $this->_res->fetch($fetchStyle);
                $this->_res->closeCursor();

                return $res ? 1 : 0;
            } else {
                return false;
            }
        } catch(\Exception $e){


            $this->_error('DB error:' . $e, $e);

            return false;
        }
    }

    /**
     *  获取查询结果下一行
     *
     * @param array   $res        Out parameter, array to be filled with
     *                            fetched results
     * @param integer $fetchStyle same as select method
     *
     * @return boolean false on failure, true on success
     */
    public function fetchNext(&$res, $fetchStyle = PDO::FETCH_NAMED)
    {
        if(!empty($this->_res)) {
            try{
                $res = $this->_res->fetch($fetchStyle);
            } catch(\Exception $e){
                $this->_error('DB query error:' . $e, $e);

                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * update/delete/insert/replace sql use this method
     *
     * @param string $sql  sql语句
     * @param string $mode if is 'a', return affected rows count, else return
     *                     boolean
     *
     * @return boolean|integer
     */
    public function mod($sql, $mode = '')
    {
        try{
            $db = $this->getDbWrite();
            $this->_log("Execute Sql: $sql", LOG_DEBUG);
            $startTime = microtime(true);
            $res = $db->exec($sql);
            $useTime = microtime(true) - $startTime;
            $this->_time('mod', $useTime, $sql);
            if($res === false) {
                $this->_log('DB mod error:' . $sql, LOG_EMERG);

                return false;
            }

        } catch(\Exception $e){
            $this->_error('DB mod error:' . $e, $e);

            return false;
        }
        if($mode == 'a') {
            return $res;
        }

        return true;
    }

    public function getDbWrite()
    {
        $badServerHosts = [];
        if(!$this->_dbWrite) {
            $this->_dbWrite = $this->_selectDB($this->_writeServers, $badServerHosts);
        }
        if($this->_dbWrite) {
            return $this->_dbWrite;
        }
        //Log error here
        $this->_error('DB Write:Connect to host(s) failed:' . implode(',', $badServerHosts));

        return false;
    }

    public function getDbRead()
    {
        $badServerHosts = [];
        if(!$this->_dbRead) {
            $this->_dbRead = $this->_selectDB($this->_readServers, $badServerHosts);
        }
        if($this->_dbRead) {
            return $this->_dbRead;
        }
        //Log error here
        $this->_error('DB Read:Connect to host(s) failed:' . implode(',', $badServerHosts));
        //使用写库
        $this->_dbRead = $this->getDbWrite();

        return $this->_dbRead;
    }

    private function _selectDB($servers, &$badServerHosts = [])
    {
        //Check if it's indexed array
        if(!isset($servers[0])) {
            $servers = [$servers];
        }
        $activeServers = [];
        $badServerHosts = [];
        foreach($servers as &$server) {
            if(!isset($server['weight'])) {
                $server['weight'] = 1;
            }
            if($this->_isServerOk($server)) {
                $activeServers[] = $server;
            } else {
                $this->_log('DB Cluster:Bad status:' . $server['host'], LOG_ERR);
            }
        }
        unset($server);
        if(empty($activeServers)) {
            //所有服务器的状态都为不可用时，则尝试连接所有
            $activeServers = $servers;
        }
        $weights = 0;
        foreach($activeServers as $server) {
            $weights += $server['weight'];
        }
        $dbName = $this->_dbName;
        while($activeServers) {
            $ratio = rand(1, $weights);
            $weightLine = 0;
            $selectIndex = -1;
            foreach($activeServers as $index => $server) {
                $weightLine += $server['weight'];
                if($ratio <= $weightLine) {
                    $selectIndex = $index;
                    break;
                }
            }
            if($selectIndex == -1) {
                //主机都不可用，使用weight = 0的备机
                $selectIndex = array_rand($activeServers);
            }
            $server = $activeServers[$selectIndex];
            unset($activeServers[$selectIndex]);
            $this->_log("DB CLUSTER: Choose server {$server['host']}:{$server['port']}.", LOG_DEBUG);
            $sqltype = isset($server['type']) ? $server['type'] : 'mysql';
            $dsn = "{$sqltype}:host={$server['host']};port={$server['port']};dbname={$dbName}";
            $pdo = null;
            try{
                $startTime = microtime(true);
                $options = [
                    PDO::ATTR_EMULATE_PREPARES => true,
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES \'UTF8MB4\'',
                    PDO::ATTR_TIMEOUT => 10,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ];
                if($sqltype == 'mysql') {
                    $options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;
                }
                $pdo = new PDO($dsn, $this->_dbUser, $this->_dbPwd, $options);
                $useTime = microtime(true) - $startTime;
                $this->_time('connect', $useTime, $server['host'] . '.' . $dbName);
            } catch(\Exception $e){
                $this->_error('DB error:' . $e, $e);
                $pdo = null;
            }
            $this->_setServerStatus($server, !!$pdo);
            if($pdo) {
                return $pdo;
            } else {
                $badServerHosts[] = $server['host'];
                $weights -= $server['weight'];
            }
        }

        return null;
    }

    private function pdoPing(PDO $dbConnect)
    {
        try{
            $dbConnect->getAttribute(PDO::ATTR_SERVER_INFO);
        } catch(\PDOException $e){
            log_message($e->getMessage(), LOG_ERR);
            if(strpos($e->getMessage(), 'MySQL server has gone away') !== false) {
                return false;
            }
        }

        return true;
    }

    protected function _isServerOk($server)
    {
        $key = "server_status_{$server['host']}_{$server['port']}";
        $status = $this->_cacheGet($key);
        if(is_numeric($status)) {
            return !empty($status);
        }

        return true;
    }

    protected function _setServerStatus($server, $status)
    {
        $key = "server_status_{$server['host']}_{$server['port']}";
        $status = $status ? '1' : '0';
        $cacheTime = 60; //1 min
        if($this->_cacheGet($key) === $status) {

            return;
        }
        $this->_cacheSet($key, $status, self::SERVICE_STATUS_CACHE_TIME);
    }

    public function getDbName()
    {
        return $this->_dbName;
    }

    /**
     * 获取上次insert操作时得到的自增id
     *
     * @return integer
     */
    public function getLastId()
    {
        if($this->_dbWrite) {
            return $this->_dbWrite->lastInsertId();
        }

        return 0;
    }

    /**
     * 获取sql读取错误信息
     *
     * @return string
     */
    public function getReadErrorInfo()
    {
        if(!$this->_readOnMaster) {
            $db = $this->_dbRead;
        } else {
            $db = $this->_dbWrite;
        }
        if(!empty($db)) {
            $err = $db->errorInfo();

            return $err[2];
        }

        return "Db Reader Not initiated\n";
    }

    /**
     * 获取sql写入错误信息
     *
     * @return string
     */
    public function getWriteErrorInfo()
    {
        if(!empty($this->_dbWrite)) {
            $err = $this->_dbWrite->errorInfo();

            return $err[2];
        }

        return "DB Writer not initiated\n";
    }

    /**
     * 判断上次错误是否由于重复key引起
     *
     * @return boolean
     */
    public function isDuplicate()
    {
        if(!empty($this->_dbWrite)) {
            $err = $this->_dbWrite->errorInfo();

            return $err[1] == 1062;
        }

        return false;
    }

    public function close()
    {
        if($this->_dbWrite) {
            $this->_dbWrite = null;
        }
        if($this->_dbRead) {
            $this->_dbRead = null;
        }
    }
}
