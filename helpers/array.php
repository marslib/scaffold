<?php
function is_index_array($var)
{
    return is_array($var) && !is_assoc_array($var);
}

function is_assoc_array($var)
{
    return is_array($var) && (count(array_filter(array_keys($var), 'is_string')) > 0);
}

/**
 * 将二维数组某俩列值提取出来，一个作为key一个作为value
 * 构成新数组
 *
 * @param Array  $arr     二维数组
 * @param String $asKey   要作为key使用的第二维的key
 * @param String $asValue 要作为value使用的第二维的key
 * @param bool   $flag    新生成的数组是否是二维的
 *
 * @return Array
 */
function array_rack2nd_keyvalue($arr, $asKey, $asValue, $flag = false)
{
    if(empty($arr)) {
        return [];
    }
    $res = [];
    foreach($arr as $row) {
        if($flag) {
            $res[$row[$asKey]][] = $row[$asValue];
        } else {
            $res[$row[$asKey]] = $row[$asValue];
        }
    }

    return $res;
}

/**
 * 复制数组的部分至另一个数组中
 *
 * @param array $source 需要被复制的数组
 * @param array $dest   复制到的目标数组 out parameter
 * @param array $keys   需要被复制的键名数组
 */
function array_partial_copy($source, &$dest, $keys)
{
    if(!is_array($dest)) {
        $dest = [];
    }
    foreach($source as $key => $val) {
        if(in_array($key, $keys)) {
            $dest[$key] = $val;
        }
    }
}

/**
 * 将一个数组的key设置为value的值
 *
 * @param array $arr
 *
 * @return array
 */
function array_mirror($arr)
{
    $newArr = [];
    foreach($arr as $val) {
        $newArr[$val] = $val;
    }

    return $newArr;
}

function array_wrap_values($arr, $prefix = null, $suffix = null)
{
    foreach($arr as &$item) {
        if($prefix) {
            $item = $prefix . $item;
        }
        if($suffix) {
            $item .= $suffix;
        }
    }

    return $arr;
}

function array_sort_by_keys($arr, $keys)
{
    $newArr = [];
    foreach($keys as $key) {
        if(isset($arr[$key])) {
            $newArr[$key] = $arr[$key];
        }
    }

    return $newArr;
}

function array_sort_by_column($arr, $col, $dir = SORT_ASC)
{
    $sort_col = [];
    foreach($arr as $key => $row) {
        $sort_col[$key] = $row[$col];
    }
    array_multisort($sort_col, $dir, $arr);

    return $arr;
}

// 将多维数组重建成一维索引数组
function array_flat($arr, $keepKey = true)
{
    $newArr = [];
    if($keepKey) {
        foreach($arr as $key => $item) {
            if(is_array($item)) {
                $newArr = $newArr + array_flat($item, true);
            } else {
                $newArr[$key] = $item;
            }
        }
    } else {
        foreach($arr as $item) {
            if(is_array($item)) {
                $newArr = array_merge($newArr, array_flat($item));
            } else {
                $newArr[] = $item;
            }
        }
    }

    return $newArr;
}

/**
 * 将二维数组二维某列的key值作为一维的key
 */
function array_change_v2k(&$arr, $column)
{
    if(empty($arr)) {
        return;
    }
    $newArr = [];
    foreach($arr as $val) {
        $newArr[$val[$column]] = $val;
    }
    $arr = $newArr;
}

function array_last($arr)
{
    return $arr[count($arr) - 1];
}

/**
 * 将标准二维数组换成树，利用递归方式实现
 *
 * @param  array  $list  待转换的数据集
 * @param  string $pk    唯一标识字段
 * @param  string $pid   父级标识字段
 * @param  string $child 子集标识字段
 *                       return  array
 */
function getTree($list, $pk = 'id', $pid = 'pid', $child = 'child', $root = -1)
{
    $tree = [];
    foreach($list as $key => $val) {

        if($val[$pid] == $root) {
            //获取当前$pid所有子类
            unset($list[$key]);
            if(!empty($list)) {
                $sun = getTree($list, $pk, $pid, $child, $val[$pk]);
                if(!empty($sun)) {
                    $val[$child] = $sun;
                }
            }
            $tree[] = $val;
        }
    }

    return $tree;
}

/**
 * 数组深度比对 array_diff
 *
 * @param array $aArray1 原数组
 * @param array $aArray2 新数组
 */
function arrayRecursiveDiff($aArray1, $aArray2)
{
    $aReturn = [];
    foreach($aArray1 as $mKey => $mValue) {
        if(array_key_exists($mKey, $aArray2)) {
            if(is_array($mValue) && is_assoc_array($mValue)) {
                $aRecursiveDiff = arrayRecursiveDiff($mValue, $aArray2[$mKey]);
                if(count($aRecursiveDiff)) {
                    $aReturn[$mKey] = $aRecursiveDiff;
                }
            } else {
                if($mValue != $aArray2[$mKey]) {
                    $aReturn[$mKey] = $mValue;
                }
            }
        } else {
            $aReturn[$mKey] = $mValue;
        }
    }

    return $aReturn;
}

/**
 * 获取数组深度
 *
 * @param $array
 */
function array_depth(array $array)
{
    $max_depth = 1;
    foreach($array as $value) {
        if(is_array($value)) {
            $depth = array_depth($value) + 1;
            if($depth > $max_depth) {
                $max_depth = $depth;
            }
        }
    }

    return $max_depth;
}

/**
 * 多维数组转化为一维数组
 *
 * @param array $array 多维数组
 *
 * @return array $result_array 一维数组
 */
function array_multi2single($array)
{
    //首先定义一个静态数组常量用来保存结果
    static $result_array = [];
    //对多维数组进行循环
    foreach($array as $value) {
        //判断是否是数组，如果是递归调用方法
        if(is_array($value)) {
            array_multi2single($value);
        } else  //如果不是，将结果放入静态数组常量
        {
            $result_array [] = $value;
        }
    }

    //返回结果（静态数组常量）
    return $result_array;
}

// 将无限极分类数据转换为子孙结构
function generate_tree(Array $array, $pid_key = 'pid', $id_key = 'id', $children_key = 'children')
{
    //第一步 构造数据
    $items = [];
    foreach($array as $value) {
        $items[$value[$id_key]] = $value;
    }
    //第二部 遍历数据 生成树状结构
    $tree = [];
    foreach($items as $key => $value) {
        //如果pid这个节点存在
        if(isset($items[$value[$pid_key]])) {
            //把当前的$value放到pid节点的son中 注意 这里传递的是引用 为什么呢？
            $items[$value[$pid_key]][$children_key][] = &$items[$key];
        } else {
            $tree[] = &$items[$key];
        }
    }

    return $tree;
}

// 对数组的每个节点数据做trim
function array_trim($arr)
{
    array_walk_recursive($arr, function(&$val)
    {
        $val = is_string($val) ? trim($val) : $val;
        unset($val);
    });

    return $arr;
}

// 从已有数组总获取节点数据
function array_get_node(String $key, $Arr = [], $default = null)
{
    $path = explode('.', $key);
    foreach($path as $key) {
        $key = trim($key);
        if(empty($Arr) || !isset($Arr[$key])) {
            return $default;
        }
        $Arr = $Arr[$key];
    }

    return $Arr;
}

// 从数组中取出若干个key值
function array_get($key, $arr, $default)
{
    if(!is_array($key)) {
        return array_get_node($key, $arr, $default);
    }
    $tmp = [];
    foreach($key as $each) {
        $tmp[$each] = array_get_node($each, $arr, $default[$each] ?? null);
    }

    return $tmp;
}

// 将二维数据根据某列分组
function array_group_by($arr, $key)
{
    $grouped = [];
    foreach($arr as $value) {
        $grouped[$value[$key]][] = $value;
    }

    return $grouped;
}

// 从已有数组中随机取若干个
function array_random($arr, $number)
{
    $keys = array_rand($arr, $number);
    $random = [];
    foreach($arr as $key => $item) {
        if(in_array($key, $keys)) {
            $random[] = $item;
        }
    }

    return $random;
}

// 从以后数组中移除某些值
function array_remove($arr, $val)
{
    $val = is_array($val) ? $val : [$val];

    return array_filter($arr, function($item) use ($val)
    {
        return !in_array($item, $val);
    });
}
