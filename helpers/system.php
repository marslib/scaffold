<?php

use MarsLib\Common\Config;

function is_prod()
{
    if(defined('ENV')) {
        return ENV === 'PRODUCTION' || ENV === 'PROD';
    }

    return false;
}

/**
 * @param string $msg   日志消息
 * @param int    $level 日志等级
 * @param int    $depth
 * @param string $type
 *
 * @return void
 */
function log_message($msg, $level = LOG_INFO, $depth = 1, $type = null)
{
    // TODO 提示调用栈
    //$backtrace = debug_backtrace();
    //array_shift($backtrace);
    static $log_types = [
        LOG_DEBUG => 'DEBUG',
        LOG_INFO => 'INFO',
        LOG_NOTICE => 'NOTICE',
        LOG_WARNING => 'WARNING',
        LOG_ERR => 'ERR',
        LOG_CRIT => 'CRIT',
        LOG_ALERT => 'ALERT',
        LOG_EMERG => 'EMERG',
    ];
    if(!(is_int($level) && $level <= LOG_DEBUG && $level >= LOG_EMERG)) {
        return;
    }
    $log_level = LOG_INFO;
    if($cl = Config::get('log.level')) {
        $cl = strtoupper($cl);
        if($cl == 'ERROR') {
            $cl = 'ERR';
        }
        if(($cl = array_search($cl, $log_types)) !== false) {
            $log_level = $cl;
        }
    }
    if($level <= $log_level) {
        $log_type = $log_types[$level];
        if(!is_prod()) {
            $backtrace = debug_backtrace();
            if(!empty($backtrace[$depth - 1]) && is_array($backtrace[$depth - 1])) {
                $file = $backtrace[$depth - 1]['file'];
                $file = basename(dirname($file)) . '/' . basename($file);
                $fileinfo = $file . ":" . $backtrace[$depth - 1]['line'];
                $msg = "[{$fileinfo}] {$msg}";
            }
        }
        $msg = date('Y-m-d H:i:s') . ":[{$log_type}] {$msg}\n";
        $fn = date('Ymd') . ".log";
        $logdir = Config::get('log.dir', '.') . ($type ? '/' . $type : '');
        if(!is_dir($logdir)) {
            $oldmask = umask(0);
            mkdir($logdir, 0777, true);
            umask($oldmask);
        }
        $filename = $logdir . '/' . $fn;
        if(!file_exists($filename)) {
            error_log($msg, 3, $filename);
            chmod($filename, 0777);
            $link_file = "$logdir/current.log";
            if(file_exists($link_file)) {
                unlink($link_file);
            }
            symlink($filename, $link_file);
        } else {
            error_log($msg, 3, $filename);
        }
        if(Config::get('log.stdout')) {
            echo $msg;
        }
    }
}

if(!function_exists('error_report')) {
    function error_report($msg)
    {
        log_message('[error_report]' . $msg, 1);
        //send mail or sms?
    }
}
function M($model_name, $db_cluster_id = null)
{
    return model($model_name, $db_cluster_id);
}

function model($model_name, $db_cluster_id = null)
{
    if(strpos($model_name, '\\') !== false) {

        return call_user_func([$model_name, 'getInstance']);
    }
    if(preg_match('@^\w+$@', $model_name) && !$db_cluster_id) {
        $model_name = camelize($model_name);
        $class = '';
        if(defined('DEFAULT_MODEL_NAMESPACE')) {
            $class = DEFAULT_MODEL_NAMESPACE . '\\' . $model_name;
        }
        if(!($class && @class_exists($class))) {
            $class = $model_name . 'Model';
        }
        if(@class_exists($class)) {

            return call_user_func([$class, 'getInstance']);
        }
    }
    if(is_null($db_cluster_id) && defined('DEFAULT_CLUSTER_ID')) {
        $db_cluster_id = DEFAULT_CLUSTER_ID;
    }

    return new \MarsLib\Scaffold\Db\Model\Base($model_name, $db_cluster_id);
}

function get_client_ip()
{
    if (getenv('HTTP_CLIENT_IP')) {
        $ip = getenv('HTTP_CLIENT_IP');
    }
    elseif (getenv('HTTP_X_FORWARDED_FOR')) {
        $ip = getenv('HTTP_X_FORWARDED_FOR');
    }
    elseif (getenv('HTTP_X_FORWARDED')) {
        $ip = getenv('HTTP_X_FORWARDED');
    }
    elseif (getenv('HTTP_FORWARDED_FOR')) {
        $ip = getenv('HTTP_FORWARDED_FOR');
    }
    elseif (getenv('HTTP_FORWARDED')) {
        $ip = getenv('HTTP_FORWARDED');
    }
    else {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    if ($pos=strpos($ip, ',')){
        $ip = substr($ip,0,$pos);
    }
    return $ip;
}

function response($data, $code = CODE_SUCC, $message = '')
{
    $ret = [
        'code' => $code,
        'data' => $data ? $data : (object)[],
    ];
    $error = $message ? ($GLOBALS['CODE_MESSAGES'][$code] ?? '') : $message;
    if ($error) {
        $ret['error'] = $error;
    }

    header("Cache-Control: no-cache");
    header("Pragma: no-cache");
    header('Content-Type: application/json; charset=UTF-8');

    echo json_encode($ret, JSON_UNESCAPED_UNICODE);

    return FALSE;
}

