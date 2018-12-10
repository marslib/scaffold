<?php
namespace MarsLib\Common;

/**
 * 全局配制接口文件
 **/
class Config
{

    private static $CONFIG = [];

    /**
     * 添加配制数组
     *
     * @param $config array
     *
     * @return void
     */
    public static function add($config)
    {
        self::$CONFIG = self::_merge($config, self::$CONFIG);
    }

    private static function _merge($source, $target)
    {
        foreach($source as $key => $val) {
            if(!is_array($val) || !isset($target[$key]) || !is_array($target[$key])) {
                $target[$key] = $val;
            } else {
                $target[$key] = self::_merge($val, $target[$key]);
            }
        }

        return $target;
    }

    public static function set($key, $val)
    {
        $config = &self::$CONFIG;
        $segments = explode('.', $key);
        $key = array_pop($segments);
        foreach($segments as $segment) {
            if(!isset($config[$segment])) {
                $config[$segment] = [];
            }
            $config = &$config[$segment];
        }
        $config[$key] = $val;
    }

    /**
     * 获取一个配制值
     *
     * @param string $key     配制名, 可包含多级，用 "." 分隔
     * @param string $default default NULL,默认值
     *
     * @return mixed
     */
    public static function get($key = null, $default = null)
    {
        $config = self::$CONFIG;
        if(!is_null($key)) {
            $path = explode('.', $key);
            foreach($path as $key) {
                $key = trim($key);
                if(empty($config) || !isset($config[$key])) {
                    return $default;
                }
                $config = $config[$key];
            }
        }

        return $config;
    }
}