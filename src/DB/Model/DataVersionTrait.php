<?php
namespace MarsLib\Scaffold\DB\Model;

use MarsLib\Scaffold\Db\DataVersion;

trait DataVersionTrait
{

    protected function addDataVersionEvent($ignoreFields = [])
    {
        $this->addEventHandler(new DataVersion(new DataVersion(), $this->getPrimaryKey(), $ignoreFields));
    }

    protected function afterUpdate($where, $data)
    {
        $this->trigger(null, $where);
        parent::afterUpdate($where, $data);
    }

    protected function afterDelete($where)
    {
        $this->trigger(null, $where);
        parent::afterDelete($where);
    }

    protected function afterInsert($data, $id)
    {
        $this->trigger($id);
        parent::afterInsert($data, $id);
    }

    protected function trigger($ids = null, $where = [])
    {
        if(!$ids) {
            if(isset($where[$this->getPrimaryKey()])) {
                $ids = $where[$this->getPrimaryKey()];
            } else {
                $rows = $this->select($where, ['select' => $this->getPrimaryKey()]);
                $ids = array_column($rows, $this->getPrimaryKey());
            }
        }
        //trigger_event($this->_table . '_modify', [$ids]);
    }
}
