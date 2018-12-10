<?php
namespace MarsLib\Db\Model;

use MarsLib\Db\Sql;
use MarsLib\Db\GlobalDb;
use MarsLib\Db\FarmDb;

class Base
{

    protected static $_forceReadOnMater = false;
    protected        $_table            = null;
    protected        $_dbClusterId      = null;
    protected        $_readOnMaster     = false;
    protected        $_primaryKey       = 'id';
    protected        $_fakeDeleteKey    = '';
    //Used with farm db
    protected $_objectId      = null;
    protected $_eventHandlers = [];
    /** @var \MarsLib\Db\DB */
    private $_dbInstance = null;
    /** @var \MarsLib\Db\Sql|null */
    protected $_sqlHelper         = null;
    private   $_lastSql;
    private   $_cache             = [];
    protected $_transactionEvents = [];

    /**
     * 构造器
     *
     * @param string $table     default NULL, 表名，为NULL则不能使用基类提供的数据库操作方法
     * @param string $clusterId default NULL, 数据库cluster id
     * @param string $objectId  default NULL, 对象id，用于分库选取用，单库不需要设置此参数
     */
    public function __construct($table = null, $clusterId = null, $objectId = null)
    {
        $this->_table = $table;
        $this->_dbClusterId = $clusterId;
        $this->_objectId = $objectId;
        $this->_sqlHelper = Sql::getInstance();
    }

    public static function instance()
    {
        return static::getInstance();
    }

    public static function getInstance()
    {
        static $instances = [];
        $class = get_called_class();
        if(!isset($instances[$class])) {
            $instances[$class] = new $class();
        }

        return $instances[$class];
    }

    //设置所有的Model都强制读写主库
    public static function setForceReadOnMater($bool = true)
    {
        self::$_forceReadOnMater = $bool;
    }

    public function rules()
    {
        return [];
    }

    public static function get($ids, $fields = '*')
    {
        $items = static::getInstance()->find($ids, '', true);
        if(empty($items) || $fields == '*') {
            return $items;
        }
        $newItems = [];
        $needArray = is_array($ids);
        if(!$needArray) {
            $items = [$ids => $items];
        }
        $fields = preg_split('@\s*,\s*@', $fields);
        foreach($items as $key => $item) {
            $newItem = [];
            foreach($fields as $field) {
                $newItem[$field] = isset($item[$field]) ? $item[$field] : null;
            }
            $newItems[$key] = $newItem;
        }

        return $needArray ? $newItems : array_values($newItems)[0];
    }

    public function getLastId()
    {
        $db = $this->_getDbInstance();
        if(!$db) {
            return false;
        }

        return $db->getLastId();
    }

    public function log($message, $level)
    {
        if(function_exists('log_message')) {
            log_message($message, $level, 2);
        }
    }

    public function insert($insArr, $returnLastId = false, $ignore_error = false)
    {
        if($this->_table === null) {
            return false;
        }
        $db = $this->_getDbInstance();
        if(!$db) {
            return false;
        }
        if($this->_inTransaction()) {
            $this->_transactionEvents[] = ['beforeInsert', $insArr];
        } else {
            $this->beforeInsert($insArr);
        }
        $sql = 'INSERT ' . ($ignore_error ? 'IGNORE ' : '') . $this->_table() . $this->_sqlHelper->insert($insArr);
        $ret = $db->mod($sql);
        $this->_lastSql = $sql;
        if($ret === false) {
            $this->log("[$sql] " . $db->getWriteErrorInfo(), LOG_ERR);

            return false;
        }
        $lastId = isset($insArr[$this->_primaryKey]) ? $insArr[$this->_primaryKey] : 0;
        if($returnLastId) {
            $lastId = $db->getLastId();
            if($lastId == 0 && isset($insArr[$this->_primaryKey])) {
                $lastId = $insArr[$this->_primaryKey];
            }
        }
        if($this->_inTransaction()) {
            $this->_transactionEvents[] = ['afterInsert', $insArr, $lastId];
        } else {
            $this->afterInsert($insArr, $lastId);
        }

        return $returnLastId ? $lastId : $ret;
    }

    //批量替换，在性能要求较高的情况下使用，但不支持Model_Cacheable的自动清缓存
    public function mulReplace($fields, $rowsData, $resData)
    {
        if($this->_table === null) {
            return false;
        }
        $db = $this->_getDbInstance();
        if(!$db) {
            return false;
        }
        $sql = 'INSERT ' . $this->_table() . $this->_sqlHelper->mulReplace($fields, $rowsData, $resData);
        $ret = $db->mod($sql);
        $this->_lastSql = $sql;
        if($ret === false) {
            $this->log("[$sql] " . $db->getWriteErrorInfo(), LOG_ERR);

            return false;
        }

        return $ret;
    }

    public function replace($insArr, $replaceArr = null, $returnLastId = false)
    {
        if($this->_table === null) {
            return false;
        }
        $db = $this->_getDbInstance();
        if(!$db) {
            return false;
        }
        if($this->_inTransaction()) {
            $this->_transactionEvents[] = [
                'beforeReplace',
                $insArr,
                $replaceArr,
            ];
        } else {
            $this->beforeReplace($insArr, $replaceArr);
        }
        if($returnLastId) {
            if(!$replaceArr) {
                $replaceArr = [];
            }
            $replaceArr[$this->_primaryKey] = '&/LAST_INSERT_ID(' . $this->_primaryKey . ')';
        }
        $sql = 'INSERT ' . $this->_table() . $this->_sqlHelper->replace($insArr, $replaceArr);
        $ret = $db->mod($sql);
        $this->_lastSql = $sql;
        if($ret === false) {
            $this->log("[$sql] " . $db->getWriteErrorInfo(), LOG_ERR);

            return false;
        }
        $last_id = null;
        if($returnLastId) {
            $last_id = $db->getLastId();
        }
        if($this->_inTransaction()) {
            $this->_transactionEvents[] = [
                'afterReplace',
                $insArr,
                $replaceArr,
            ];
        } else {
            $this->afterReplace($insArr, $replaceArr);
        }

        return $returnLastId ? $last_id : $ret;
    }

    public function update($where, $uptArr)
    {
        if($this->_table === null) {
            return false;
        }
        $db = $this->_getDbInstance();
        if(!$db) {
            return false;
        }
        if($where && !is_array($where)) {
            $where = [$this->_primaryKey => $where];
        }
        if($this->_inTransaction()) {
            $this->_transactionEvents[] = ['beforeUpdate', $where, $uptArr];
        } else {
            $this->beforeUpdate($where, $uptArr);
        }
        $sql = 'UPDATE ' . $this->_table() . $this->_sqlHelper->update($uptArr) . $this->_sqlHelper->where($where);
        $ret = $db->mod($sql);
        $this->_lastSql = $sql;
        if($ret === false) {
            $this->log("[$sql] " . $db->getWriteErrorInfo(), LOG_ERR);

            return false;
        }
        if($this->_inTransaction()) {
            $this->_transactionEvents[] = ['afterUpdate', $where, $uptArr];
        } else {
            $this->afterUpdate($where, $uptArr);
        }

        return $ret;
    }

    public function delete($where)
    {
        if($this->_table === null) {
            return false;
        }
        $db = $this->_getDbInstance();
        if(!$db) {
            return false;
        }
        if($this->_inTransaction()) {
            $this->_transactionEvents[] = ['beforeDelete', $where];
        } else {
            $this->beforeDelete($where);
        }
        $sql = 'DELETE FROM ' . $this->_table() . $this->_sqlHelper->where($where);
        $ret = $db->mod($sql);
        $this->_lastSql = $sql;
        if($ret === false) {
            $this->log("[$sql] " . $db->getWriteErrorInfo(), LOG_ERR);

            return false;
        }
        if($this->_inTransaction()) {
            $this->_transactionEvents[] = ['afterDelete', $where];
        } else {
            $this->afterDelete($where);
        }

        return $ret;
    }

    public function addEventHandler($handlerObj)
    {
        $class = get_class($handlerObj);
        if(!isset($this->_eventHandlers[$class])) {
            $this->_eventHandlers[$class] = $handlerObj;
        }
    }

    protected function beforeInsert(&$data)
    {
        foreach($this->_eventHandlers as $handler) {
            $handler->beforeInsert($this, $data);
        }
    }

    protected function afterInsert($data, $lastId)
    {
        foreach($this->_eventHandlers as $handler) {
            $handler->afterInsert($this, $data, $lastId);
        }
    }

    protected function beforeUpdate(&$where, &$data)
    {
        foreach($this->_eventHandlers as $handler) {
            $handler->beforeUpdate($this, $where, $data);
        }
    }

    protected function afterUpdate($where, $data)
    {
        if(!empty($this->_cache)) {
            $this->_cache = [];
        }
        foreach($this->_eventHandlers as $handler) {
            $handler->afterUpdate($this, $where, $data);
        }
    }

    protected function beforeReplace(&$data, &$replace)
    {
        foreach($this->_eventHandlers as $handler) {
            $handler->beforeReplace($this, $data, $replace);
        }
    }

    protected function afterReplace($data, $replace)
    {
        if(!empty($this->_cache)) {
            $this->_cache = [];
        }
        foreach($this->_eventHandlers as $handler) {
            $handler->afterReplace($this, $data, $replace);
        }
    }

    protected function beforeDelete(&$where)
    {
        foreach($this->_eventHandlers as $handler) {
            $handler->beforeDelete($this, $where);
        }
    }

    protected function afterDelete($where)
    {
        if(!empty($this->_cache)) {
            $this->_cache = [];
        }
        foreach($this->_eventHandlers as $handler) {
            $handler->afterDelete($this, $where);
        }
    }

    public function batchSelect($where, $attrs, $options, $handler)
    {
        $pk = $options['order_key'] ?? $this->_primaryKey;
        if(is_null($attrs)) {
            $attrs = [];
        }
        if(is_null($options)) {
            $options = [];
        }
        $batch_count = $options['batch_count'] ?? 100;
        $attrs['limit'] = $batch_count;
        $attrs['order_by'] = $pk . ' ASC';
        $handler_type = $options['handler_type'] ?? 'rows';
        while(true) {
            $rows = $this->select($where, $attrs);
            if(empty($rows)) {
                break;
            }
            if(!isset($rows[count($rows) - 1][$pk])) {
                throw new \Exception("batchSelect找不到{$pk}字段");
            }
            $result = true;
            if($handler_type === 'row') {
                foreach($rows as $row) {
                    $result = call_user_func($handler, $row);
                    if($result === false) {
                        break;
                    }
                }
            } else {
                $result = call_user_func($handler, $rows);
            }
            if($result === false) {
                break;
            }
            $last_pk = $rows[count($rows) - 1][$pk];
            $where[$pk] = ['>' => $last_pk];
        }
    }

    public function select($where = [], $attrs = [])
    {
        if($this->_table === null) {
            return false;
        }
        $db = $this->_getDbInstance();
        if(!$db) {
            return false;
        }
        if($this->_fakeDeleteKey && !isset($where[$this->_fakeDeleteKey])) {
            if(!$where) {
                $where = [$this->_fakeDeleteKey => 0];
            } else {
                $where = [
                    $where,
                    [$this->_fakeDeleteKey => 0],
                    '__logic' => 'AND',
                ];
            }
        }
        if(is_callable([
                $this,
                'beforeSelect',
            ], true)
           && !$this->_inTransaction()) {
            $this->beforeSelect($where, $attrs);
        }
        $selectFields = isset($attrs['select']) ? $attrs['select'] : '*';
        $sql = "SELECT {$selectFields} FROM " . $this->_table() . (!empty($attrs['force_index']) ? " FORCE INDEX({$attrs['force_index']})" : '') . $this->_sqlHelper->where($where, $attrs);
        $res = null;
        $this->log($sql, LOG_INFO);
        $this->_lastSql = $sql;
        if($db->select($sql, $res) === false) {
            $this->log("[$sql] " . $db->getReadErrorInfo(), LOG_ERR);

            return false;
        }
        if(is_callable([
                $this,
                'afterSelect',
            ], true)
           && !$this->_inTransaction()) {
            $this->afterSelect($res);
        }

        return $res;
    }

    public function selectOne($where = [], $attrs = [])
    {
        $attrs['limit'] = 1;
        $attrs['offset'] = 0;
        $res = $this->select($where, $attrs);
        if($res === false) {
            return false;
        }
        if(empty($res)) {
            return null;
        }

        return $res[0];
    }

    public function selectCount($where = [], $attrs = [])
    {
        if(!isset($attrs['select'])) {
            $attrs['select'] = 'COUNT(0)';
        }
        $attrs['select'] .= ' AS `total`';
        $res = $this->selectOne($where, $attrs);
        if($res === false) {
            return false;
        }

        return intval($res['total']);
    }

    protected function cacheKey($originKey)
    {
        if(is_array($originKey)) {
            ksort($originKey);
            $originKey = substr(md5(json_encode($originKey)), 0, 16);
        }

        return get_called_class() . '_item_' . $originKey;
    }

    protected function getCache($originKey)
    {
        $key = $this->cacheKey($originKey);
        if(isset($this->_cache[$key])) {
            return $this->_cache[$key];
        }

        return null;
    }

    protected function mGetCache($originKeys)
    {
        $result = [];
        foreach($originKeys as $originKey) {
            $key = $this->cacheKey($originKey);
            if(isset($this->_cache[$key])) {
                $result[$originKey] = $this->_cache[$key];
            }
        }

        return $result;
    }

    protected function setCache($originKey, $value, $expireTime = 3600)
    {
        $this->_cache[$this->cacheKey($originKey)] = $value;
    }

    protected function delCache($originKey)
    {
        unset($this->_cache[$this->cacheKey($originKey)]);
    }

    protected function mDelCache($originKeys)
    {
        foreach($originKeys as $originKey) {
            unset($this->_cache[$this->cacheKey($originKey)]);
        }
    }

    protected function getItemsCache($uniqValues, $uniqKey = null)
    {
        if(is_null($uniqKey)) {
            $uniqKey = $this->_primaryKey;
        }
        $ret = [];
        foreach($uniqValues as $uniqValue) {
            if($uniqKey == $this->_primaryKey) {
                $item = $this->getCache("{$uniqKey}-{$uniqValue}");
                if(!is_null($item)) {
                    $ret[$uniqValue] = $item;
                }
            } else {
                $primaryValue = $this->getCache("primary_key-{$uniqKey}-{$uniqValue}");
                if(!is_null($primaryValue)) {
                    $item = $this->getCache("{$this->_primaryKey}-{$primaryValue}");
                    if(!is_null($item)) {
                        $ret[$uniqValue] = $item;
                    }
                }
            }
        }

        return $ret;
    }

    protected function getItemCache($uniqValue, $uniqKey = null)
    {
        $caches = $this->getItemsCache([$uniqValue], $uniqKey);
        if(isset($caches[$uniqValue])) {
            return $caches[$uniqValue];
        }

        return null;
    }

    protected function delItemsCache($primaryValues)
    {
        if(!is_array($primaryValues)) {
            $primaryValues = [$primaryValues];
        }
        $keys = [];
        foreach($primaryValues as $primaryValue) {
            $keys[] = "{$this->_primaryKey}-{$primaryValue}";
        }

        return $this->mDelCache($keys);
    }

    protected function setItemsCache($uniqKeyValue, $uniqKey = null)
    {
        if(is_null($uniqKey)) {
            $uniqKey = $this->_primaryKey;
        }
        foreach($uniqKeyValue as $uniqValue => $item) {
            if(!is_null($item)) {
                if($uniqKey != $this->_primaryKey) {
                    if(!isset($item[$this->_primaryKey])) {
                        continue;
                    }
                    $primaryValue = $item[$this->_primaryKey];
                    $this->setCache("{$this->_primaryKey}-{$primaryValue}", $item);
                    $this->setCache("primary_key-{$uniqKey}-{$uniqValue}", $primaryValue);
                    continue;
                }
                $this->setCache("{$uniqKey}-{$uniqValue}", $item);
            }
        }
    }

    /**
     * find 主键查询
     *
     * @param mixed  $uniqValues 单个值或是数组值
     * @param string $uniqKey    惟一键值，默认是主键
     * @param bool   $useCache   时候使用缓存
     *
     * @access public
     * @return mixed 主键传入单个值时，返回单行记录，多行值返回以主键值为Key的关联数组
     */
    public function find($uniqValues, $uniqKey = '', $useCache = false)
    {
        if(!$uniqKey) {
            $uniqKey = $this->_primaryKey;
        }
        if(empty($uniqValues)) {
            return [];
        }
        $needArray = is_array($uniqValues);
        if($needArray) {
            $uniqValues = array_unique($uniqValues);
        } else {
            $uniqValues = [$uniqValues];
        }
        $items = [];
        if($useCache && !$this->_inTransaction()) {
            $caches = $this->getItemsCache($uniqValues, $uniqKey);
            $newUniqValues = [];
            foreach($uniqValues as $uniqValue) {
                if(isset($caches[$uniqValue])) {
                    $items[$uniqValue] = $caches[$uniqValue];
                    $this->log('[' . $this->_table . '] Get object from cache:' . $uniqValue, LOG_DEBUG);
                } else {
                    $newUniqValues[] = $uniqValue;
                }
            }
            $uniqValues = $newUniqValues;
        }
        if($uniqValues) {
            $rows = $this->select([
                $uniqKey => $uniqValues,
            ]);
            if($rows) {
                $setCaches = [];
                foreach($rows as $row) {
                    $setCaches[$row[$uniqKey]] = $row;
                    $items[$row[$uniqKey]] = $row;
                }
                if(!$this->_inTransaction()) {
                    $this->setItemsCache($setCaches, $uniqKey);
                }
            }
        }
        if($needArray) {
            return $items;
        } else {
            return $items ? array_values($items)[0] : null;
        }
    }

    public function call($procedure, $in_params, $out_params, &$res = [])
    {
        $db = $this->_getDbInstance();
        if(!$db) {
            return false;
        }

        return $db->call($procedure, $in_params, $out_params, $res);
    }

    /**
     * Execute sql statement:
     * For select statement, return the rows;
     * For non-select statement, return rows affected;
     * When error, return false
     *
     * @param string $sql
     *
     * @return mixed
     */
    public function execute($sql)
    {

        $method = @strtoupper(explode(' ', trim($sql))[0]);
        $db = $this->_getDbInstance();
        if(!$db) {
            return false;
        }
        if(in_array($method, [
            'SELECT',
            'SHOW',
            'DESC',
        ])) {
            $res = null;
            if($db->select($sql, $res) === false) {
                $this->log("[$sql] " . $db->getReadErrorInfo(), LOG_ERR);

                return false;
            }

            return $res;
        } else {
            $ret = $db->mod($sql, 'a');
            $this->_lastSql = $sql;
            if($ret === false) {
                $this->log("[$sql] " . $db->getWriteErrorInfo(), LOG_ERR);

                return false;
            }

            return $ret;
        }
    }

    /**
     * Magic函数
     * 用于实现 get_by_xxx/getByXxx方法
     *
     * @param string $name 方法名称
     * @param mixed  $args 参数
     *
     * @return mixed
     */
    public function __call($name, $args)
    {
        if(strpos($name, 'get_by_') === 0) {
            $key = substr($name, 7);
            $value = $args[0];

            return $this->selectOne([
                $key => $value,
            ]);
        } else {
            if(strpos($name, 'getBy') === 0) {
                $key = strtolower(substr($name, 5));
                if($key) {
                    $where = [
                        $key => $args[0],
                    ];

                    return $this->selectOne($where);
                }
            } else {
                if(strpos($name, 'before') === 0 || strpos($name, 'after') === 0) {
                    return true;
                }
            }
        }
        trigger_error('Undefined method ' . $name . ' called!');

        return false;
    }

    public static function __callStatic($name, $arguments)
    {
        if(strpos($name, 'i') == 0) {
            $method = substr($name, 1);
            $ins = static::getInstance();
            if(method_exists($ins, $method)) {
                return call_user_func_array([$ins, $method], $arguments);
            }
        }
        trigger_error('Undefined method ' . $name . ' called!');

        return false;
    }

    public function setReadOnMaster($bool = true)
    {
        $this->_readOnMaster = $bool;
        if($this->_dbInstance) {
            $this->_dbInstance->setReadOnMaster($bool);
        }
    }

    public function beginTransaction()
    {
        return $this->_getDbInstance()->beginTransaction();
    }

    public function commit()
    {
        $ok = $this->_getDbInstance()->commit();
        if($ok) {
            $this->commitEvents();
        }

        return $ok;
    }

    public function rollback()
    {
        $this->rollbackEvents();
        $this->_getDbInstance()->rollback();
    }

    public function rollbackEvents()
    {
        $this->_transactionEvents = [];
    }

    public function commitEvents()
    {
        if($this->_transactionEvents) {
            foreach($this->_transactionEvents as $event) {
                $method = array_shift($event);
                $params = [];
                if(strpos($method, 'before') !== false) {
                    if(count($event) == 1) {
                        $p1 = $event[0];
                        $params = [&$p1];
                    } else {
                        $p1 = $event[0];
                        $p2 = $event[1];
                        $params = [&$p1, &$p2];
                    }
                } else {
                    $params = $event;
                }
                $this->log("commit event " . static::class . ":$method " . json_encode($params), LOG_DEBUG);
                call_user_func_array([$this, $method], $params);
            }
            $this->_transactionEvents = [];
        }
    }

    public function getTable()
    {
        return $this->_table;
    }

    public function table($table = null)
    {
        if(empty($table)) {
            return $this->_table;
        }
        $this->_table = $table;
    }

    public function getDatabaseName()
    {
        $db = $this->_getDbInstance();
        if($db) {
            return $db->getDbName();
        }

        return null;
    }

    private function _table()
    {
        $tables = $this->_table;
        if(is_string($this->_table)) {
            $tables = [$this->_table];
        }
        $arr = [];
        foreach($tables as $table) {
            if(preg_match('@^\w+$@', $table)) {
                $arr[] = "`{$table}`";
            } else {
                $arr[] = $table;
            }
        }

        return implode(',', $arr);
    }

    public function getPrimaryKey()
    {
        return $this->_primaryKey;
    }

    public function getLastSql()
    {
        return $this->_lastSql;
    }

    public function checkDbStatus()
    {
        $obj = $this->_getDbInstance();
        if($obj && $obj->getDbWrite()) {

            return true;
        }

        return false;
    }

    protected function _getDbInstance()
    {
        if($this->_dbInstance) {
            return $this->_dbInstance;
        }
        if($this->_dbClusterId !== null) {
            if($this->_objectId !== null) {
                $this->_dbInstance = FarmDb::getInstanceByObjectId($this->_objectId, $this->_dbClusterId);
            } else {
                $this->_dbInstance = GlobalDb::getInstance($this->_dbClusterId);
            }
            $this->_dbInstance->setReadOnMaster(self::$_forceReadOnMater || $this->_readOnMaster);

            return $this->_dbInstance;
        }

        return null;
    }

    protected function _inTransaction()
    {
        $db = $this->_getDbInstance();

        return $db && $db->inTransaction();
    }

    protected function _getPDO()
    {
        $db = $this->_getDbInstance();
        if(!$db) {
            return false;
        }

        return $db->getDbWrite();
    }

    public function __destruct()
    {
        if($this->_dbInstance) {
            $this->_dbInstance->close();
            $this->_dbInstance = null;
        }
        $this->_sqlHelper = null;
    }

    public function beforeSelect($where, $attr)
    {

    }

    public function afterSelect($res)
    {

    }

}
