<?php
define('CODE_SUCC', 0);
define('CODE_ERR_OTHER', 1);
define('CODE_ERR_AUTH', 100);
define('CODE_ERR_AUTH_USERNAME', 101);
define('CODE_ERR_AUTH_PASSWORD', 102);
define('CODE_ERR_AUTH_DISABLE', 103);
define('CODE_ERR_AUTH_VERIFICATION_CODE', 104);
define('CODE_ERR_AUTH_SECOND_VERIFICATION', 105);
define('CODE_ERR_DUPLICATE', 201);
define('CODE_ERR_REDIRECT', 302);
define('CODE_ERR_UNAUTHORIZED', 401);
define('CODE_ERR_DENY', 403);
define('CODE_ERR_NOT_FOUND', 404);
define('CODE_ERR_SYSTEM', 500);
define('CODE_ERR_SERVER', 502);
define('CODE_ERR_PARAM', 600);
define('CODE_ERR_PARAM_MISSING', 601);
define('CODE_ERR_PARAM_INVALID', 602);
define('CODE_ERR_PARAM_EXPIRE', 603);
define('CODE_ERR_PARAM_SIGNATURE', 604);
define('CODE_ERR_SERVER_WX', 503);
$GLOBALS['CODE_MESSAGES'] = [
    CODE_SUCC => '',
    CODE_ERR_AUTH => '登录信息错误',
    CODE_ERR_AUTH_USERNAME => '用户不存在',
    CODE_ERR_AUTH_PASSWORD => '密码不正确',
    CODE_ERR_AUTH_DISABLE => '账号状态为无效',
    CODE_ERR_AUTH_VERIFICATION_CODE => '需要验证码',
    CODE_ERR_AUTH_SECOND_VERIFICATION => '需要二次确认',
    CODE_ERR_DUPLICATE => '重复操作',
    CODE_ERR_REDIRECT => '页面跳转',
    CODE_ERR_UNAUTHORIZED => '未登录',
    CODE_ERR_DENY => '未授权',
    CODE_ERR_NOT_FOUND => '访问的资源不存在',
    CODE_ERR_SYSTEM => '系统错误',
    CODE_ERR_SERVER => '服务程序错误',
    CODE_ERR_PARAM => '参数错误',
    CODE_ERR_PARAM_MISSING => '缺少必要的参数',
    CODE_ERR_PARAM_INVALID => '参数包含无效的值',
    CODE_ERR_PARAM_EXPIRE => '参数过期',
    CODE_ERR_PARAM_SIGNATURE => '参数签名错误',
    CODE_ERR_OTHER => '未定义错误',
];
function filter_trim($val)
{
    return is_string($val) ? trim($val) : $val;
}

function filter_bool($val)
{
    if(empty($val) || in_array(strtolower($val), ['false', 'null', 'nil', 'none',])) {

        return false;
    }

    return true;
}

function write_file($file_path, $str, $mode = 'w')
{
    $handel = fopen($file_path, $mode);
    if(!$handel) {
        return false;
    }
    fwrite($handel, $str);
    fclose($handel);
    return true;
}

function read_file($path)
{
    $handle = fopen($path, "r");
    $data = [];
    if(!$handle) {
        return false;
    }
    while(($line = fgets($handle)) !== false) {
        $line = trim($line);
        $data[] = $line;
    }
    fclose($handle);
    return $data;
}