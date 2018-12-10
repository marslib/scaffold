<?php
namespace MarsLib\Db\Model;
use MarsLib\Redis\Redis;
//Warning: mulReplace方法调用，不支持自动清缓存
class CacheAble extends Base
{
    protected $_redisClusterId = 'default';

    //是否严格的更新cselect的接口数据
    protected $_cacheStrictMode = TRUE;

    private $_changedPrimaryValues = NULL;

    public function __construct($table = NULL, $clusterId = NULL, $objectId = NULL)
    {
        parent::__construct($table, $clusterId, $objectId);
    }

    protected function cacheKey4Keys()
    {
        return get_called_class() . '_cachekeys';
    }

    protected function addCacheKey($cache_key)
    {
        Redis::LPUSH($this->cacheKey4Keys(), $cache_key, $this->_redisClusterId);
    }

    protected function clearCache()
    {
        $cache_key = $this->cacheKey4Keys();
        $keys = Redis::LRANGE($cache_key, 0, -1, $this->_redisClusterId);
        if ($keys) {
            $keys[] = $cache_key;
            Redis::DEL($keys, $this->_redisClusterId);
        }
    }

    public function cselect($where = array(), $attrs = array(), $cache_time = 300)
    {
        ksort($where);
        ksort($attrs);

        $cache_key = get_called_class() . '_cselect_' . substr(md5(json_encode($where) . json_encode($attrs)), 0, 16);

        $data = Redis::GET($cache_key, $this->_redisClusterId);
        if (!empty($data)) {
            return json_decode($data, TRUE);
        }

        $result = $this->select($where, $attrs);
        if ($result !== FALSE) {
            Redis::SETEX($cache_key, json_encode($result), $cache_time, $this->_redisClusterId);
            $this->addCacheKey($cache_key);
        }

        return $result;
    }

    public function cacheCall($method, $params = NULL, $cache_time = 300)
    {
        if (!method_exists($this, $method)) {
            return FALSE;
        }

        $cache_key = get_called_class() . "_{$method}";

        if (!is_null($params)) {
            $cache_key .= '_' . substr(md5(json_encode($params)), 0, 16);
        }

        $data = Redis::GET($cache_key, $this->_redisClusterId);
        if ($data !== FALSE) {
            return json_decode($data, TRUE);
        }

        $result = call_user_func_array([$this, $method], is_null($params) ? [] : $params);

        Redis::SETEX($cache_key, json_encode($result), $cache_time, $this->_redisClusterId);

        return $result;
    }

    protected function setChangePrimaryValues($where)
    {
        if (isset($where[$this->_primaryKey])) {
            $cond = $where[$this->_primaryKey];

            if (!is_array($cond) || is_real_array($cond)) {
                $this->_changedPrimaryValues = $cond;
                return;
            }
        }

        $rows = $this->select($where, [ 'select' => $this->_primaryKey ]);
        if ($rows) {
            $this->_changedPrimaryValues = array_column($rows, $this->_primaryKey);
        }
    }

    private function updateCache()
    {
        if (!$this->_changedPrimaryValues) {
            return;
        }
        $this->delItemsCache($this->_changedPrimaryValues);
        $event = strtolower(ltrim(str_replace('\\', '_', static::class), '_') . '_update');
        //trigger_event($event, [ $this->_changedPrimaryValues ]);
        $this->_changedPrimaryValues = NULL;
    }

    protected function afterInsert ($data, $lastId)
    {
        if ($this->_cacheStrictMode) {
            $this->clearCache();
        }
    }

    protected function beforeUpdate(&$where, &$data)
    {
        $this->setChangePrimaryValues($where);
        foreach ($this->_eventHandlers as $handler) {
            $handler->beforeUpdate($this, $where, $data);
        }
    }

    protected function afterUpdate($where, $data)
    {
        $this->updateCache();
        if ($this->_cacheStrictMode) {
            $this->clearCache();
        }
        foreach ($this->_eventHandlers as $handler) {
            $handler->afterUpdate($this, $where, $data);
        }
    }

    protected function beforeReplace(&$data, &$replace)
    {
        foreach ($this->_eventHandlers as $handler) {
            $handler->beforeReplace($this, $data, $replace);
        }
    }

    protected function afterReplace($data, $replace)
    {
        $this->setChangePrimaryValues($data);
        $this->updateCache();
        if ($this->_cacheStrictMode) {
            $this->clearCache();
        }
        foreach ($this->_eventHandlers as $handler) {
            $handler->afterReplace($this, $data, $replace);
        }
    }

    protected function beforeDelete(&$where)
    {
        $this->setChangePrimaryValues($where);
        foreach ($this->_eventHandlers as $handler) {
            $handler->beforeDelete($this, $where);
        }
    }

    protected function afterDelete ($where)
    {
        $this->updateCache();
        if ($this->_cacheStrictMode) {
            $this->clearCache();
        }
        foreach ($this->_eventHandlers as $handler) {
            $handler->afterDelete($this, $where);
        }
    }

    protected function getCache($originKey)
    {
        $cache = parent::getCache($originKey);

        if (!is_null($cache)) {
            return $cache;
        }

        $key = $this->cacheKey($originKey);

        $cache = Redis::GET($key, $this->_redisClusterId);

        if ($cache !== FALSE) {
            return json_decode($cache, TRUE);
        }

        return NULL;
    }

    protected function mGetCache($originKeys)
    {
        $result = [];
        $caches = parent::mGetCache($originKeys);
        $remainOriginKeys = [];
        foreach ($originKeys as $originKey) {
            if (!isset($caches[$originKey])) {
                $remainOriginKeys[] = $originKey;
            }
        }

        if ($remainOriginKeys) {
            $caches2 = Redis::MGET($remainOriginKeys, $this->_redisClusterId);
            foreach ($remainOriginKeys as $i => $originKey) {
                if ($caches2 && $caches2[$i] !== FALSE) {
                    $caches[$originKey] = json_decode($caches2[$i], TRUE);
                }
            }
        }

        return $caches;
    }

    protected function setCache($originKey, $value, $expireTime = 600)
    {
        parent::setCache($originKey, $value);
        Redis::SETEX($this->cacheKey($originKey), json_encode($value), $expireTime, $this->_redisClusterId);
    }

    protected function delCache($originKey)
    {
        parent::delCache($originKey);
        Redis::DEL($this->cacheKey($originKey), $this->_redisClusterId);
    }

    protected function mDelCache($originKeys)
    {
        parent::mDelCache($originKeys);
        $keys = [];
        foreach ($originKeys as $originKey) {
            $keys[] = $this->cacheKey($originKey);
        }
        Redis::DEL($keys, $this->_redisClusterId);
    }

    public function getList($where, $page = 1, $size = 20, $attr = [])
    {
        $attr = array_merge($attr, [
            'limit' => $size,
            'offset' => ($page - 1) * $size
        ]);

        $count = $this->selectCount($where);

        $list = $this->select($where, $attr);

        $res = [
            'page' => [
                'current' => $page,
                'size' => $size,
                'total' => $count,
            ],
            'list' => $list ? $list : []
        ];

        return $res;
    }
}
