<?php
namespace MarsLib\Scaffold\Mongo;

use MarsLib\Scaffold\Common\Config;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\BSON\Timestamp;
use MongoDB\BSON\UTCDateTime;

class MongoModel
{

    protected $_primaryKey        = '_id';
    protected $_fake_delete       = false;
    protected $_break_fake_delete = false;
    protected $_delete_key        = 'is_delete';
    protected $_database;
    protected $_collection;
    /** @var \MongoDB\Client */
    protected $_client;
    protected $_filter                = [];
    protected $select_default_options = [
        'typeMap' => [
            'root' => 'array',
            'document' => 'array',
        ],
        'projection' => [],
    ];
    protected $data_version           = false;
    protected $auto_create_at         = true;
    protected $auto_update_at         = true;
    private   $compare_operator       = [
        '>' => '$gt',
        '>=' => '$gte',
        '<' => '$lt',
        '<=' => '$lte',
        '!=' => '$ne',
        '=' => '$eq',
    ];

    public function __construct($collection, $database)
    {
        $this->_database = $database;
        $this->_collection = $collection;
        $single_conf = Config::get('mongo_singles.' . $database);
        $this->_client = MongoClient::getInstance($single_conf['map'] ?? 0);
    }

    /**
     * @return \MarsLib\Scaffold\Mongo\MongoModel
     */
    public static function getInstance()
    {
        static $instances = [];
        $class = get_called_class();
        if(!isset($instances[$class])) {
            $instances[$class] = new $class();
        }

        return $instances[$class];
    }

    public function collection()
    {
        if(!$this->_client->getManager()) {
            return false;
        }

        return $this->_client->selectDatabase($this->_database)->selectCollection($this->_collection);
    }

    public function insert(Array $document)
    {
        if($this->_fake_delete && !isset($document['is_delete'])) {
            $document['is_delete'] = 0;
        }
        $this->beforeInsert($document);
        if(is_assoc_array($document)) {
            $result = $this->collection()->insertOne($document);
            $id = $result->getInsertedId();
        } else {
            $result = $this->collection()->insertMany($document);
            $id = $result->getInsertedIds();
        }
        $this->afterInsert($document, $id);

        return is_array($id) ? array_map(function($val) { return "$val"; }, $id) : "$id";
    }

    public function count(Array $filter = [])
    {
        $filter = $this->transFilter($filter);

        return $this->collection()->countDocuments($filter);
    }

    public function get($_id)
    {
        $filter['_id'] = $_id;
        if(is_string($_id)) {
            return $this->selectOne($filter);
        } else {
            return $this->select($filter);
        }
    }

    public function select($filter = [], $options = [])
    {
        $options = $this->transOptions($options);
        $filter = $this->transFilter($filter);
        $list = $this->collection()->find($filter, $options)->toArray();
        $list = array_map(function($item)
        {
            $item['_id'] = (string)$item['_id'];

            return $item;
        }, $list);

        return $list;
    }

    public function selectOne(array $filter, array $options = [])
    {
        $options += $this->select_default_options;
        $filter = $this->transFilter($filter);
        $doc = $this->collection()->findOne($filter, $options);
        if($doc) {
            $doc['_id'] = (string)$doc['_id'];
        }

        return $doc;
    }

    public function update(array $filter, array $update, $options = [])
    {
        $filter = $this->transFilter($filter);
        unset($update['_id']);
        $this->beforeUpdate($filter, $update);
        if(isset($filter['_id'])) {
            $result = $this->collection()->updateOne($filter, ['$set' => $update], $options);
        } else {
            $result = $this->collection()->updateMany($filter, ['$set' => $update], $options);
        }
        $this->afterUpdate($this->_filter, $update, $filter['_id'] ?? null);

        return $result->getModifiedCount();
    }

    public function delete(array $filter, array $options = [])
    {
        $filter = $this->transFilter($filter);
        $this->beforeDelete($filter);
        if(!$this->_fake_delete) {
            if(isset($filter['_id'])) {
                $result = $this->collection()->deleteOne($filter);
            } else {
                $result = $this->collection()->deleteMany($filter, $options);
            }
            $re = $result->getDeletedCount();
        } else {
            $re = $this->update($filter, [$this->_delete_key => 1]);
        }
        $this->afterDelete($this->_filter, $filter['_id'] ?? null);

        return $re;
    }

    public function baseFilter($format, $val)
    {
        switch($format) {
            case 'timestamp':
                return new Timestamp(0, $val);
                break;
            case 'date':
                return new UTCDateTime(strtotime($val) * 1000);
                break;
            case 'regex':
                return new Regex($val);
                break;
            default:
                return $val;
        }
    }

    public function transFilter(Array $filter)
    {
        $this->_filter = $filter;
        if(isset($filter['_id'])) {
            $filter['_id'] = new ObjectId($filter['_id']);
        }
        $map = $this->compare_operator;
        $_filter = [];
        foreach($filter as $field => &$item) {
            // field  create_at|date
            $exp = explode('|', $field);
            $format = $exp[1] ?? false;
            if(!is_array($item) && $format) {
                $item = $this->baseFilter($format, $item);
                continue;
            }
            if(is_index_array($item)) {
                $item = ['$in' => $item];
                continue;
            }
            if(is_assoc_array($item)) {
                $tmp_item = [];
                foreach($item as $operator => $val) {
                    $tmp_item[($map[$operator] ?? false) ? $map[$operator] : $operator] = $format ? $this->baseFilter($format, $val) : $val;
                }
                $item = $tmp_item;
                if($format) {
                    $real_field = $exp[0];
                    $_filter[$real_field] = $tmp_item;
                    unset($filter[$field]);
                }
            }
            unset($field, $item);
        }
        $filter = array_merge($filter, $_filter);
        if($this->_fake_delete) {
            $filter['is_delete'] = 0;
        }
        if($this->_break_fake_delete) {
            unset($filter['is_delete']);
            $this->_break_fake_delete = false;
        }

        return $filter;
    }

    public function transOptions(Array $options)
    {
        $select = array_filter(array_map('trim', explode(',', $options['select'] ?? '')));
        unset($options['select']);
        $options += $this->select_default_options;
        foreach($select as $field) {
            $options['projection'][$field] = 1;
        }
        $order_by = array_values(array_filter(preg_split('@[ ,]@', $options['order_by'] ?? '')));
        unset($options['order_by']);
        $sort_case = [
            'desc' => -1,
            'asc' => 1,
        ];
        foreach($order_by as $index => $item) {
            if($index % 2 == 0) {
                $options['sort'][$item] = $sort_case[strtolower($order_by[$index + 1])] ?? 1;
            }
        }
        if(isset($options['limit'])) {
            $options['limit'] = (int)$options['limit'];
        }
        if(isset($options['offset'])) {
            $options['skip'] = (int)$options['offset'];
        }

        return $options;
    }

    public function beforeInsert(&$dco)
    {
        if($this->auto_create_at) {
            $dco['create_at'] = time();
        }
    }

    public function afterInsert(&$doc, $id)
    {
        $this->log($id, MongoDataVersion::METHOD_CREATE, $doc);
    }

    public function beforeUpdate($filter, &$doc) { }

    public function afterUpdate($filter, &$doc, $id)
    {
        if($this->auto_update_at) {
            $doc['update_at'] = time();
        }
        if($id) {
            $this->log($id, MongoDataVersion::METHOD_UPDATE, $doc);
        }
    }

    public function beforeDelete($filter) { }

    public function afterDelete($filter, $id)
    {
        if($id) {
            $this->log($id, MongoDataVersion::METHOD_DELETE);
        }
    }

    public function log($obj_id, $method, $new_doc = [])
    {
        if(!$this->data_version) {
            return false;
        }
        /** @var MongoDataVersion $data_version */
        $data_version = MongoDataVersion::getInstance();
        $data_version->createNewVersion($this->_database, $this->_collection, $obj_id, $method, $new_doc);
    }
}