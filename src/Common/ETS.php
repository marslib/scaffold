<?php
namespace MarsLib\Scaffold\Common;

/**
 * 执行时间统计
 * Execution time statistics
 */
class ETS
{
    private static $starts = array();
    public static $warnTimes = array(
        'db.connect' => [0.5, 1],  //[warning_time, error_report_time] unit second
        'db' => [0.5, 1],
        'redis' => [0.1, 1]
    );

    public static $defaultWarnTime = [0.5, 1];

    public static function start($name)
    {
        self::$starts[$name] = microtime(TRUE);
    }

    public static function end($name, $msg = '')
    {
        if (empty(self::$starts[$name])) {
            return FALSE;
        }
        $start = self::$starts[$name];
        $end = microtime(TRUE);
        $executeTime = $end - $start;
        self::time($name, $executeTime, $msg);
        return $executeTime;
    }

    public static function time($name, $use_time, $msg = '')
    {
        $name_list = explode('.', $name);
        $warn_times = self::$defaultWarnTime;
        while ($name_list) {
            $subname = implode('.', $name_list);
            if (isset(self::$warnTimes[$subname])) {
                $warn_times = self::$warnTimes[$subname];
                break;
            }
            array_pop($name_list);
        }

        $log = 'ETS:' . $name . ':' . $use_time . ':' . $msg;
        if ($use_time >= $warn_times[1]) {
            error_report($log);
        } elseif ($use_time >= $warn_times[0]) {
            log_message($log, LOG_ERR, 2);
        } elseif (ENV !== 'PRODUCTION') {
            log_message($log, LOG_DEBUG, 2);
        }
    }
}