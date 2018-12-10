<?php
namespace MarsLib\Db\Model;

class SortAble extends Base
{

    protected $sortable_config = [
        'order_page_field' => 'order_page',
        'order_index_field' => 'order_index',
        'sort_type' => 'ASC', //ASC | DESC
        'insert_mode' => 'tail', //header | tail
    ];

    const MAX_ORDER_VALUE = PHP_INT_MAX; //2**63

    const ORDER_PAGE_STEP = PHP_INT_MAX / 2147483648; //MAX RECORDS 2**31

    public function insert($insArr, $returnLastId = false, $ignoreError = false)
    {
        $this->beginTransaction();
        $this->execute("LOCK TABLES {$this->_table} WRITE");
        $order_data = $this->getNewOrder();
        $insArr[$this->sortable_config['order_page_field']] = $order_data[0];
        $insArr[$this->sortable_config['order_index_field']] = $order_data[1];
        $ret = parent::insert($insArr, $returnLastId, $ignoreError);
        $this->commit();
        $this->execute("UNLOCK TABLES");

        return $ret;
    }

    public function moveToHeader($id)
    {
        $order_page = $this->sortable_config['order_page_field'];
        $order_index = $this->sortable_config['order_index_field'];
        $header_item = $this->getHeaderItem();
        if(!$header_item) {
            return false;
        }
        $update = [
            $order_page => $header_item[$order_page] - self::ORDER_PAGE_STEP,
            $order_index => round(self::MAX_ORDER_VALUE / 2),
        ];

        return $this->update($id, $update);
    }

    public function moveToHeaderByOne($id, $prev_item = null)
    {
        $order_page = $this->sortable_config['order_page_field'];
        $order_index = $this->sortable_config['order_index_field'];
        $item = $this->find($id, '', true);
        if(empty($item)) {
            return false;
        }
        if(is_null($prev_item)) {
            $prev_item = $this->getPrevItem($item);
            if(empty($prev_item)) {
                return $this->moveToHeader($id);
            }
        }
        $ok = $this->update($prev_item[$this->_primaryKey], [
            $order_page => $item[$order_page],
            $order_index => $item[$order_index],
        ]);
        if(!$ok) {
            return false;
        }

        return $this->update($id, [
            $order_page => $prev_item[$order_page],
            $order_index => $prev_item[$order_index],
        ]);
    }

    public function moveToTail($id)
    {
        $order_page = $this->sortable_config['order_page_field'];
        $order_index = $this->sortable_config['order_index_field'];
        $tail_item = $this->getTailItem();
        if(!$tail_item) {
            return false;
        }
        $update = [
            $order_page => $tail_item[$order_page] + self::ORDER_PAGE_STEP,
            $order_index => round(self::MAX_ORDER_VALUE / 2),
        ];

        return $this->update($id, $update);
    }

    public function moveToTailByOne($id, $next_item = null)
    {
        $order_page = $this->sortable_config['order_page_field'];
        $order_index = $this->sortable_config['order_index_field'];
        $item = $this->find($id, '', true);
        if(empty($item)) {
            return false;
        }
        if(is_null($next_item)) {
            $next_item = $this->getNextItem($item);
            if(empty($next_item)) {
                return $this->moveToTail($id);
            }
        }
        $ok = $this->update($next_item[$this->_primaryKey], [
            $order_page => $item[$order_page],
            $order_index => $item[$order_index],
        ]);
        if(!$ok) {
            return false;
        }

        return $this->update($id, [
            $order_page => $next_item[$order_page],
            $order_index => $next_item[$order_index],
        ]);
    }

    protected function getHeaderItem()
    {
        $order_page = $this->sortable_config['order_page_field'];
        $order_index = $this->sortable_config['order_index_field'];

        return $this->selectOne([], [
            'select' => "{$order_page},{$order_index}",
            'order_by' => "{$order_page} ASC,{$order_index} ASC",
        ]);
    }

    protected function getTailItem()
    {
        $order_page = $this->sortable_config['order_page_field'];
        $order_index = $this->sortable_config['order_index_field'];

        return $this->selectOne([], [
            'select' => "{$order_page},{$order_index}",
            'order_by' => "{$order_page} DESC,{$order_index} DESC",
        ]);
    }

    protected function getPrevItem($id)
    {
        $order_page = $this->sortable_config['order_page_field'];
        $order_index = $this->sortable_config['order_index_field'];
        if(is_array($id)) {
            $item = $id;
        } else {
            $item = $this->find($id, '', true);
        }
        if(empty($item)) {
            return false;
        }
        $prev_item = $this->selectOne([
            [$order_page => ['<' => $item[$order_page]]],
            [
                $order_page => $item[$order_page],
                $order_index => ['<' => $item[$order_index]],
            ],
            '__logic' => 'OR',
        ], [
            'select' => "{$this->_primaryKey},{$order_page},{$order_index}",
            'order_by' => "{$order_page} DESC,{$order_index} DESC",
        ]);

        return $prev_item;
    }

    protected function getNextItem($id)
    {
        $order_page = $this->sortable_config['order_page_field'];
        $order_index = $this->sortable_config['order_index_field'];
        if(is_array($id)) {
            $item = $id;
        } else {
            $item = $this->find($id, '', true);
        }
        if(empty($item)) {
            return false;
        }
        $next_item = $this->selectOne([
            [$order_page => ['>' => $item[$order_page]]],
            [
                $order_page => $item[$order_page],
                $order_index => ['>' => $item[$order_index]],
            ],
            '__logic' => 'OR',
        ], [
            'select' => "{$this->_primaryKey},{$order_page},{$order_index}",
            'order_by' => "{$order_page} ASC,{$order_index} ASC",
        ]);

        return $next_item;
    }

    public function moveBefore($id, $before_id)
    {
        $prev_item = $this->getPrevItem($id);
        if($prev_item[$this->_primaryKey] == $before_id) {
            return $this->moveToHeaderByOne($id, $prev_item);
        }
        $before_item = $this->find($before_id, '', true);
        $before_prev_item = $this->getPrevItem($before_id);
        if(!$before_prev_item) {
            return $this->moveToHeader($id);
        }
        $order_page = $this->sortable_config['order_page_field'];
        $order_index = $this->sortable_config['order_index_field'];
        if($before_item[$order_page] - $before_prev_item[$order_page] > 2) {
            return $this->update($id, [
                $order_page => $before_item[$order_page] - round(($before_item[$order_page] - $before_prev_item[$order_page]) / 2),
                $order_index => round(self::MAX_ORDER_VALUE / 2),
            ]);
        }
        $ok = $this->update([
            $order_page => $before_item[$order_page],
            $order_index => ['<' => $before_item[$order_index]],
        ], [
            $order_index => "&/!{$order_index}-1",
        ]);
        if(!$ok) {
            return false;
        }

        return $this->update($id, [
            $order_page => $before_item[$order_page],
            $order_index => $before_item[$order_index] - 1,
        ]);
    }

    public function moveAfter($id, $after_id)
    {
        $next_item = $this->getNextItem($id);
        if($next_item[$this->_primaryKey] == $after_id) {
            return $this->moveToTailByOne($id, $next_item);
        }
        $after_item = $this->find($after_id, '', true);
        $after_next_item = $this->getNextItem($after_id);
        if(!$after_next_item) {
            return $this->moveToTail($id);
        }
        $order_page = $this->sortable_config['order_page_field'];
        $order_index = $this->sortable_config['order_index_field'];
        if($after_next_item[$order_page] - $after_item[$order_page] > 2) {
            return $this->update($id, [
                $order_page => $after_next_item[$order_page] - round(($after_next_item[$order_page] - $after_item[$order_page]) / 2),
                $order_index => round(self::MAX_ORDER_VALUE / 2),
            ]);
        }
        $ok = $this->update([
            $order_page => $after_item[$order_page],
            $order_index => ['>' => $after_item[$order_index]],
        ], [
            $order_index => "&/!{$order_index}+1",
        ]);
        if(!$ok) {
            return false;
        }

        return $this->update($id, [
            $order_page => $after_item[$order_page],
            $order_index => $after_item[$order_index] + 1,
        ]);
    }

    public function getNewOrder()
    {
        $sort_type = $this->sortable_config['insert_mode'] === 'header' ? 'ASC' : 'DESC';
        $order_page = $this->sortable_config['order_page_field'];
        $order_index = $this->sortable_config['order_index_field'];
        $where = [];
        $attrs = [
            'select' => "{$order_page},{$order_index}",
            'order_by' => "{$order_page} {$sort_type},{$order_index} {$sort_type}",
        ];
        $last_item = $this->selectOne($where, $attrs);
        if($last_item) {
            $order_page_value = $last_item[$order_page] + ($sort_type === 'ASC' ? -1 : 1) * self::ORDER_PAGE_STEP;
            $order_index_value = round(self::MAX_ORDER_VALUE / 2);
        } else {
            $order_page_value = round(self::MAX_ORDER_VALUE / 2);
            $order_index_value = round(self::MAX_ORDER_VALUE / 2);
        }

        return [$order_page_value, $order_index_value];
    }
} 