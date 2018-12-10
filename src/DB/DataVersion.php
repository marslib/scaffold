<?php
namespace MarsLib\Scaffold\Db;

class DataVersion extends EventHandler
{

    protected $_primaryKey   = 'id';
    protected $_logModel     = null;
    protected $_cache        = [];
    protected $_ignoreFields = [];

    const TYPE_INSERT = 'insert';

    const TYPE_UPDATE = 'update';

    const TYPE_DELETE = 'delete';

    public function __construct($logModel, $primaryKey = 'id', $ignoreFields = ['last_update_time'])
    {
        $this->_logModel = $logModel;
        $this->_primaryKey = $primaryKey;
        $this->_ignoreFields = $ignoreFields;
    }

    protected function _addLog($dbName, $table, $type, $objectId, $originData, $newData = [])
    {
        $changeData = [];
        if($type == self::TYPE_UPDATE) {
            foreach($originData as $field => $value) {
                if(in_array($field, $this->_ignoreFields)) {
                    continue;
                }
                if(isset($newData[$field]) && $newData[$field] != $value) {
                    $changeData[$field] = $newData[$field];
                }
            }
            if(empty($changeData)) {
                return false;
            }
        }
        $changeFields = array_keys($changeData);
        $ins = [
            'db_name' => $dbName,
            'table_name' => $table,
            'pk' => $objectId,
            'user_id' => d(@$GLOBALS['uid'], 0),
            'modify_fields' => implode(',', $changeFields),
            'version_id' => md5(time() . $dbName . $table),
            'data' => json_encode($newData),
        ];

        return $this->_logModel->insert($ins);
    }

    public function afterInsert($model, $data, $lastId)
    {
        $model->setReadOnMaster(true);
        if(!$lastId) {
            if(isset($data[$this->_primaryKey])) {
                $lastId = $data[$this->_primaryKey];
            } else {
                $lastId = $model->getLastId();
            }
        }
        if($lastId) {
            $data = $model->selectOne([$this->_primaryKey => $lastId]);
        }
        $this->_addLog($model->getDatabaseName(), $model->table(), self::TYPE_INSERT, $lastId, [], $data);
    }

    public function beforeUpdate($model, &$where, $data)
    {
        $model->setReadOnMaster(true);
        $originRows = $model->select($where);
        if(empty($originRows)) {
            return;
        }
        $this->_cache[md5(serialize($where))] = $originRows;
    }

    public function afterUpdate($model, $where, $data)
    {
        $cacheKey = md5(serialize($where));
        if(!isset($this->_cache[$cacheKey])) {
            return;
        }
        $originRows = $this->_cache[$cacheKey];
        unset($this->_cache[$cacheKey]);
        array_change_key($originRows, $this->_primaryKey);
        $ids = array_keys($originRows);
        $rows = $model->select([$this->_primaryKey => $ids]);
        $table = $model->table();
        $dbName = $model->getDatabaseName();
        foreach($rows as $row) {
            $id = $row[$this->_primaryKey];
            if(isset($originRows[$id])) {
                $this->_addLog($dbName, $table, self::TYPE_UPDATE, $id, $originRows[$id], $row);
            }
        }
    }

    public function beforeInsertReplace($model, &$data, &$replace)
    {
    }

    public function afterInsertReplace($model, $data, $replace)
    {
    }

    public function beforeDelete($model, &$where)
    {
        $model->setReadOnMaster(true);
        $originRows = $model->select($where);
        if(empty($originRows)) {
            return;
        }
        $this->_cache[md5(serialize($where))] = $originRows;
    }

    public function afterDelete($model, $where)
    {
        $cacheKey = md5(serialize($where));
        if(!isset($this->_cache[$cacheKey])) {
            return;
        }
        $originRows = $this->_cache[$cacheKey];
        unset($this->_cache[$cacheKey]);
        $table = $model->table();
        $dbName = $model->getDatabaseName();
        foreach($originRows as $originRow) {
            $this->_addLog($dbName, $table, self::TYPE_DELETE, $originRow[$this->_primaryKey], $originRow);
        }
    }
}
