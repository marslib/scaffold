<?php
namespace MarsLib\Scaffold\Common;

/**
 * Class ListPlugin
 * 用于对list追加额外列信息
 * @package MarsLib\Scaffold\Common
 */
class ListPlugin
{

    /**
     * 将如 select a.id, b.name from a left join b on a.id = b.a_id 样查询转换为分步构造数据
     * 同时避免 foreach 中 sql N+1 问题
     * @param array $row 现有列表
     * @param string $field_name 数据源列
     * @param string $source 关联表信息 databases.table.field
     */
    public function test($row, $field_name, $source)
    {
        $foreign_value = array_column($row, $field_name);

    }
}