<?php
namespace GG\Db\Model;

class Virtual
{

    protected $_primaryKey = 'id';

    function __construct()
    {
    }

    public static function instance()
    {
        return static::getInstance();
    }

    public function getPrimaryKey()
    {
        return $this->_primaryKey;
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

    public static function get($ids, $fields = '*')
    {
        return static::getInstance()->find($ids);
    }

    public function getLastId()
    {
        return false;
    }

    public function log($message, $level)
    {
        if(function_exists('log_message')) {
            log_message($message, $level);
        }
    }

    public function insert($insArr, $returnLastId = false, $ignoreError = false)
    {
        return false;
    }

    public function update($where, $uptArr)
    {
        return false;
    }

    public function delete($where)
    {
        return false;
    }

    public function select($where = [], $attrs = [])
    {
        return false;
    }

    protected function filter($where, $attrs, $items)
    {

    }

    public function selectOne($where = [], $attrs = [])
    {
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
        $items = $this->select($where, $attrs);
        if($items === false) {
            return false;
        }

        return count($items);
    }

    /**
     * find 主键查询
     *
     * @param mixed  $uniqValues 单个值或是数组值
     * @param string $uniqKey    惟一键值，默认是主键
     *
     * @access public
     * @return mixed 主键传入单个值时，返回单行记录，多行值返回以主键值为Key的关联数组
     */
    public function find($uniqValues, $uniqKey = '', $useCache = false)
    {
        if(!$uniqKey) {
            $uniqKey = $this->_primaryKey;
        }
        $items = $this->select([
            $this->_primaryKey => $uniqValues,
        ]);
        if($items === false) {
            return false;
        }
        if(is_array($uniqValues)) {
            array_change_v2k($items, $this->_primaryKey);

            return $items;
        }

        return $items ? $items[0] : null;
    }

    /**
     * Magic函数
     * 用于实现 get_by_xxx/getByXxx方法
     */
    public function __call($name, $args)
    {
        if(strpos($name, 'get_by_') === 0) {
            $key = substr($name, 7);
            $value = $args[0];

            return $this->selectOne([
                $key => $value,
            ]);
        } elseif(strpos($name, 'getBy') === 0) {
            $key = strtolower(substr($name, 5));
            if($key) {
                $where = [
                    $key => $args[0],
                ];

                return $this->selectOne($where);
            }
        } elseif(strpos($name, 'before') === 0 || strpos($name, 'after') === 0) {
            return true;
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

    public function beginTransaction()
    {
        return false;
    }

    public function commit()
    {
        return false;
    }

    public function rollback()
    {
        return false;
    }
}

