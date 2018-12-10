<?php
namespace MarsLib\Scaffold\Mongo;

class MongoDataVersion extends MongoModel
{

    const METHOD_CREATE = 'CREATE';

    const METHOD_UPDATE = 'UPDATE';

    const METHOD_DELETE = 'DELETE';

    public function __construct()
    {
        parent::__construct('log', 'data_version');
    }

    public function createNewVersion($dbName, $table, $objectId, $method, $newData)
    {
        $last = $this->getLastVersion($dbName, $table, $objectId) ?? [];
        $changeFields = arrayRecursiveDiff($newData, $last['data'] ?? []);
        $ins = [
            'version_id' => md5(time() . $dbName . $table),
            'user_id' => d(@$GLOBALS['uid'], 0),
            'db_name' => $dbName,
            'table_name' => $table,
            'pk' => (string)$objectId,
            'method' => $method,
            'data' => $newData,
            'modify_fields' => $changeFields,
            'create_at' => date('Y-m-d H:i:s'),
        ];
        self::insert($ins);
    }

    public function getLastVersion($dbName, $table, $objectId)
    {
        $last = self::selectOne([
            'db_name' => $dbName,
            'table_name' => $table,
            'pk' => (string)$objectId,
        ]);
        if($last) {
            unset($last['_id']);

            return $last;
        }

        return null;
    }

    public function afterInsert(&$doc, $id)
    {
    }
}