<?php

use Seld\JsonLint\JsonParser;

/**
 * Escapes text to make it safe to use with Javascript
 * It is usable as, e.g.:
 *  echo
 *  '<script>aiert(\'begin'.escape_js_quotes($mid_part).'end\');</script>';
 * OR
 *  echo '<tag onclick="aiert(\'begin'.escape_js_quotes($mid_part).'end\');">';
 * Notice that this function happily works in both cases; i.e. you don't need:
 *  echo '<tag
 *  onclick="aiert(\'begin'.txt2html_old(escape_js_quotes($mid_part)).'end\');">';
 *  That would also work but is not necessary.
 *
 * @param  string $str    The data to escape
 * @param  bool   $quotes should wrap in quotes (isn't this kind of silly?)
 *
 * @return string         Escaped data
 */
function escape_js_quotes($str, $quotes = false)
{
    if($str === null) {
        return;
    }
    $str = strtr($str, [
        '\\' => '\\\\',
        "\n" => '\\n',
        "\r" => '\\r',
        '"' => '\\x22',
        '\'' => '\\\'',
        '<' => '\\x3c',
        '>' => '\\x3e',
        '&' => '\\x26',
    ]);

    return $quotes ? '"' . $str . '"' : $str;
}

if(!function_exists('str_startwith')) {
    function str_startwith($haystack, $needle)
    {
        return strpos($haystack, $needle) === 0;
    }
}
function is_empty_string($str)
{
    return $str === null || $str === '';
}

#按字符宽度截取字符串,一个半角字符为一个宽度,全角字符为两个宽度
function str_truncate($str, $len)
{
    if(empty($str)) {
        return $str;
    }
    $gbkStr = @iconv('UTF-8', 'gbk', $str);
    if($gbkStr == '') {
        //Convert encoding to gbk failed
        $i = 0;
        $wi = 0;
        $n = strlen($str);
        $newStr = '';
        while($i < $n) {
            $ord = ord($str{$i});
            if($ord > 224) {
                $newStr .= substr($str, $i, 3);
                $i += 3;
                $wi += 2;
            } elseif($ord > 192) {
                $newStr .= substr($str, $i, 2);
                $i += 3;
                $wi += 2;
            } else {
                $newStr .= substr($str, $i, 1);
                $i += 1;
                $wi += 1;
            }
            if($wi >= $len) {
                break;
            }
        }
        if($wi < $len || ($wi == $len && $i == $n)) {
            return $str;
        }

        return preg_replace('@([\x{00}-\x{ff}]{3}|.{2})$@u', '...', $newStr);
    }
    if($len < 3 || strlen($gbkStr) <= $len) {
        return $str;
    }
    $cutStr = mb_strcut($gbkStr, 0, $len - 3, 'gbk');
    $cutStr = iconv('gbk', 'UTF-8', $cutStr);

    return $cutStr . '...';
}

/**
 * Format string camelize
 */
function camelize($str, $upperFirstChar = true)
{
    $segments = explode('_', $str);
    $ret = '';
    for($i = 0, $n = count($segments); $i < $n; $i++) {
        $segment = $segments[$i];
        if(strlen($segment) == 0) {
            continue;
        }
        if($i == 0 && !$upperFirstChar) {
            $ret .= $segment;
        } else {
            $ret .= strtoupper($segment{0});
            if(strlen($segment) > 1) {
                $ret .= substr($segment, 1);
            }
        }
    }

    return $ret;
}

// funnyThing => funny_thing
function underscore($str)
{
    return trim(preg_replace_callback('@[A-Z]@', create_function('$m', 'return "_".strtolower($m[0]);'), $str), '_');
}

// Generate a random character string
function rand_str($length = 32, $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz1234567890')
{
    // Length of character list
    $chars_length = (strlen($chars) - 1);
    // Start our string
    $string = $chars{rand(0, $chars_length)};
    // Generate random string
    for($i = 1; $i < $length; $i = strlen($string)) {
        // Grab a random character from our list
        $r = $chars{rand(0, $chars_length)};
        // Make sure the same two characters don't appear next to each other
        if($r != $string{$i - 1}) {
            $string .= $r;
        }
    }

    // Return the string
    return $string;
}

/**
 * 将unicode转换成字符
 *
 * @param int $unicode
 *
 * @return string UTF-8字符
 **/
function unicode2Char($unicode)
{
    if($unicode < 128) {
        return chr($unicode);
    }
    if($unicode < 2048) {
        return chr(($unicode >> 6) + 192) . chr(($unicode & 63) + 128);
    }
    if($unicode < 65536) {
        return chr(($unicode >> 12) + 224) . chr((($unicode >> 6) & 63) + 128) . chr(($unicode & 63) + 128);
    }
    if($unicode < 2097152) {
        return chr(($unicode >> 18) + 240) . chr((($unicode >> 12) & 63) + 128) . chr((($unicode >> 6) & 63) + 128) . chr(($unicode & 63) + 128);
    }

    return false;
}

/**
 * 将字符转换成unicode
 *
 * @param string $char 必须是UTF-8字符
 *
 * @return int
 **/
function char2Unicode($char)
{
    switch(strlen($char)) {
        case 1 :
            return ord($char);
        case 2 :
            return (ord($char{1}) & 63) | ((ord($char{0}) & 31) << 6);
        case 3 :
            return (ord($char{2}) & 63) | ((ord($char{1}) & 63) << 6) | ((ord($char{0}) & 15) << 12);
        case 4 :
            return (ord($char{3}) & 63) | ((ord($char{2}) & 63) << 6) | ((ord($char{1}) & 63) << 12) | ((ord($char{0}) & 7) << 18);
        default :
            trigger_error('Character is not UTF-8!', E_USER_WARNING);

            return false;
    }
}

/**
 * 全角字符unicode编码从65281~65374 （十六进制 0xFF01 ~ 0xFF5E）
 * 半角字符unicode编码从33~126 （十六进制 0x21~ 0x7E）
 * 空格比较特殊,全角为 12288（0x3000）,半角为 32 （0x20）
 * 而且除空格外,全角/半角按unicode编码排序在顺序上是对应的
 * 所以可以直接通过用+-法来处理非空格数据,对空格单独处理
 */
/**
 * 全角转半角
 *
 * @param string $str
 *
 * @return string
 **/
function sbc2dbc($str)
{
    // 全角字符 '/[\x{3000}\x{ff01}-\x{ff5f}]/u',
    // 编码转换 0x3000是空格，特殊处理，其他全角字符编码-0xfee0即可以转为半角
    preg_match_all('/[\x{3000}\x{ff01}-\x{ff5f}]/u', $str, $m);
    foreach($m[0] as $code) {
        $str = str_replace($code, ($unicode = char2Unicode($code)) == 0x3000 ? " " : (($code = $unicode - 0xfee0) > 256 ? unicode2Char($code) : chr($code)), $str);
    }

    return $str;
}

/**
 * 半角转全角
 *
 * @param string $str
 *
 * @return string
 **/
function dbc2sbc($str)
{
    // 半角字符 '/[\x{0020}\x{0020}-\x{7e}]/u'
    // 编码转换 0x0020是空格，特殊处理，其他半角字符编码+0xfee0即可以转为全角
    preg_match_all('/[\x{0020}\x{0020}-\x{7e}]/u', $str, $m);
    foreach($m[0] as $code) {
        $str = str_replace($code, ($unicode = char2Unicode($code)) == 0x0020 ? unicode2Char(0x3000) : (($code = $unicode + 0xfee0) > 256 ? unicode2Char($code) : chr($code)), $str);
    }

    return $str;
}

function my_json_decode($json, $default = [])
{
    if(!$json) {
        return $default;
    }
    $json = preg_replace('@//[^"]+?$@mui', '', $json);
    $json = preg_replace('@^\s*//.*?$@mui', '', $json);
    $json = $json ? @json_decode($json, true) : $default;
    if(is_null($json)) {
        $json = $default;
    }

    return $json;
}

function is_json_str($str, $comment_mode = false)
{
    if($comment_mode) {
        $str = preg_replace('@//[^"]+?$@mui', '', $str);
        $str = preg_replace('@^\s*//.*?$@mui', '', $str);
    }
    $lint = (new JsonParser())->lint($str);

    return $lint ? $lint->getMessage() : $lint;
}