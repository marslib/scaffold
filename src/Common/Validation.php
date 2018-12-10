<?php
namespace MarsLib\Scaffold\Common;

class Validation
{

    const INVALID_RULE = '%s规则错误:%d';

    public $errors = [];

    public function __construct()
    {
    }

    public function check($rules, $obj = null, $data = null)
    {
        $validation_data = $this->getValidData($rules, $obj, $data);
        if(empty($this->errors)) {
            return $validation_data;
        }

        return false;
    }

    public function getValidData($rules, $obj = null, $data = null)
    {
        $this->errors = [];
        $validation_data = [];
        if(is_null($data)) {
            $data = $_POST;
            if(empty($data) && isset($_SERVER['CONTENT_TYPE']) && preg_match('@application/json@i', $_SERVER['CONTENT_TYPE'])) {
                $data = json_decode(file_get_contents('php://input'), true);
                if(is_null($data)) {
                    $data = [];
                }
            }
        }
        foreach($rules as $key => $rule) {
            if(is_array($rule)) {
                $name = $rule['name'];
                $label = $rule['label'];
                $rule = $rule['rule'];
            } else {
                @list($name, $label) = explode('|', $key);
            }
            if(empty($label)) {
                $label = $name;
            }
            $is_array = false;
            $is_object = false;
            if(preg_match('@\[\]$@', $name)) {
                $is_array = true;
                $name = preg_replace('@\[\]$@', '', $name);
            } elseif(preg_match('@\|?array\|?@', $rule)) {
                $is_array = true;
                $rule = trim(preg_replace('@\|?array\|?@', '|', $rule), '|');
            }
            if(preg_match('@\|?object\|?@', $rule)) {
                $is_object = true;
                $rule = trim(preg_replace('@\|?object\|?@', '|', $rule), '|');
            }
            $is_required = preg_match('@\brequired\b@', $rule);
            $is_any = preg_match('@\bany\b@', $rule);
            $value = null;
            $names = [];
            $count = substr_count($name, '[');
            if($count && preg_match('@^(\w+)' . str_repeat('(?:\[(\w+)\])', $count) . '$@', $name, $ma)) {
                if(isset($data[$ma[1]])) {
                    $ref_value = $data[$ma[1]];
                    for($i = 2, $n = count($ma); $i < $n; $i++) {
                        if(!is_array($ref_value)) {
                            break;
                        }
                        if(!isset($ref_value[$ma[$i]])) {
                            break;
                        }
                        if($i == $n - 1) {
                            $value = $ref_value[$ma[$i]];
                        } else {
                            $ref_value = $ref_value[$ma[$i]];
                        }
                    }
                }
                $names = array_splice($ma, 1);
            } elseif(isset($data[$name])) {
                $names[] = $name;
                $value = $data[$name];
            }
            if(is_null($value)) {
                if($is_required) {
                    $this->errors[$name] = sprintf('%s为必填项', $label);
                }
                continue;
            }
            if($is_array && $value && !is_array($value)) {
                $value = [$value];
            }
            if(!$is_any && !$is_array && !$is_object && is_array($value)) {
                $this->errors[$name] = sprintf(self::INVALID_RULE, $label, 1);
                continue;
            }
            if(!is_index_array($value) || $is_object) {
                $values = [&$value];
            } else {
                $values = &$value;
            }
            foreach($values as &$ivalue) {
                if($this->_check($name, $label, $rule, $ivalue, $obj, $data) === false) {
                    break;
                }
                unset($ivalue);
            }
            $ref = &$validation_data;
            for($i = 0, $n = count($names); $i < $n; $i++) {
                if($i == $n - 1) {
                    $ref[$names[$i]] = $value;
                } else {
                    if(!isset($ref[$names[$i]])) {
                        $ref[$names[$i]] = [];
                    }
                    $ref = &$ref[$names[$i]];
                }
            }
            unset($ref);
            unset($values);
            unset($value);
        }

        return $validation_data;
    }

    protected function _check($name, $label, $rule, &$value, $obj, $data)
    {
        $check_list = explode('|', $rule);
        foreach($check_list as $check_item) {
            $err_msg = '%s校验错误';
            if(preg_match('@^cb_(.+)$@', $check_item, $ma)) {
                $cb = $ma[1];
                if($obj && method_exists($obj, $cb)) {
                    if($obj->$cb($value, $err_msg, $data) === false) {
                        $this->errors[$name] = preg_replace('@%s\b@u', $label, $err_msg);
                    }
                } elseif(is_callable($cb)) {
                    if(call_user_func_array($cb, [$value, &$err_msg, $data])) {
                        $this->errors[$name] = preg_replace('@%s\b@u', $label, $err_msg);
                    }
                } else {
                    $this->errors[$name] = sprintf(self::INVALID_RULE, $label, 2);
                }

                return false;
            }
            if(!preg_match('@^(\w+)(?:\[(.+)\])?$@', $check_item, $ma)) {
                $this->errors[$name] = sprintf(self::INVALID_RULE, $label, 3);

                return false;
            }
            $func = $ma[1];
            $method = 'rule_' . $func;
            $args = isset($ma[2]) ? preg_split('@\s*,\s*@', $ma[2]) : [];
            if(!method_exists($this, $method)) {

                // As filter function
                if(is_callable($func)) {
                    $value = call_user_func($func, $value);
                    continue;
                }
                $filter_method = 'filter_' . $func;
                if(method_exists($this, $filter_method)) {
                    $value = call_user_func_array([
                        $this,
                        $filter_method,
                    ], [$value]);
                    continue;
                }
                $this->errors[$name] = sprintf(self::INVALID_RULE, $label, 4);

                return false;
            }
            array_unshift($args, $value);
            $args[] = &$err_msg;
            $args[] = $data;
            if(call_user_func_array([$this, $method], $args) === false) {
                $this->errors[$name] = sprintf($err_msg, $label);

                return false;
            }
        }

        return true;
    }

    public function errors($line_breaker = '<br/>')
    {
        return implode($line_breaker, $this->errors);
    }

    public function filter_trim($val)
    {
       return is_string($val) ? trim($val) : $val;
    }

    public function filter_bool($val)
    {
        if(empty($val)
           || in_array(strtolower($val), [
                'false',
                'null',
                'nil',
                'none',
            ])) {

            return false;
        }

        return true;
    }

    public function rule_any($val, &$err_msg)
    {
        return true;
    }

    public function rule_required($val, &$err_msg)
    {
        if($val === '') {
            $err_msg = '%s为必填项';

            return false;
        }

        return true;
    }

    public function rule_uri($val, &$err_msg)
    {
        if($val === '') {
            return true;
        }
        if(!preg_match('@^http@i', $val)) {
            $val = 'http://xxx.com/' . $val;
        }

        return $this->rule_url($val, $err_msg);
    }

    public function rule_url($val, &$err_msg)
    {
        if($val === '') {
            return true;
        }
        $pattern = "/^(http|https|ftp):\/\/([A-Z0-9][A-Z0-9_-]*(?:\.[A-Z0-9][A-Z0-9_-]*)+):?(\d+)?\/?/i";
        if(!preg_match($pattern, $val)) {
            $err_msg = '%s不是合法的URL';

            return false;
        }

        return true;
    }

    public function rule_email($val, &$err_msg)
    {
        if($val === '') {
            return true;
        }
        if(!preg_match("/^([a-z0-9\+_\-]+)(\.[a-z0-9\+_\-]+)*@([a-z0-9\-]+\.)+[a-z]{2,6}$/ix", $val)) {
            $err_msg = '%s不是合法的Email地址';

            return false;
        }

        return true;
    }

    //包含注释语法的JSON
    public function rule_extended_json($val, &$err_msg)
    {
        if($val === '') {
            return true;
        }
        $j = is_json_str($val, true);
        if($j) {
            $err_msg = '%s不是合法的JSON:' . $j;

            return false;
        }

        return true;
    }

    public function rule_json($val, &$err_msg)
    {
        if($val === '') {
            return true;
        }
        $j = is_json_str($val);
        if($j) {
            $err_msg = '%s不是合法的JSON:' . $j;

            return false;
        }

        return true;
    }

    public function rule_date($val, &$err_msg)
    {
        if($val === '') {
            return true;
        }
        $ret = strtotime($val);
        if($ret <= 0 || $ret === false || !preg_match('@^\d{4}-\d{2}-\d{2}$@', $val)) {
            $err_msg = '%s不是有效日期';

            return false;
        }

        return true;
    }

    public function rule_datetime($val, &$err_msg)
    {
        if($val === '') {
            return true;
        }
        $ret = strtotime($val);
        if($ret <= 0 || $ret === false || !preg_match('@^\d{4}-\d{2}-\d{2}@', $val)) {
            $err_msg = '%s不是有效日期时间';

            return false;
        }

        return true;
    }

    public function rule_safe_password($val, &$err_msg)
    {
        if($val === '') {
            return true;
        }
        if(strlen($val) < 8) {
            $err_msg = '%s长度最少为8位';

            return false;
        }
        $level = 0;
        if(preg_match('@\d@', $val)) {
            $level++;
        }
        if(preg_match('@[a-z]@', $val)) {
            $level++;
        }
        if(preg_match('@[A-Z]@', $val)) {
            $level++;
        }
        if(preg_match('@[^0-9a-zA-Z]@', $val)) {
            $level++;
        }
        if($level < 3) {
            $err_msg = '您设置的%s太简单，密码必须包含数字、大小写字母、其它符号中的三种及以上';

            return false;
        }

        return true;
    }

    public function rule_in($val, $list, &$err_msg)
    {
        if($val === '') {
            return true;
        }
        $ok = in_array($val, explode("\001", $list));
        if(!$ok) {
            $err_msg = '%s不是有效值';
        }

        return $ok;
    }

    function rule_exists_row($val, $table, &$err_msg)
    {
        if($val === '' || $val === '0') {
            return true;
        }
        $tokens = explode('.', $table);
        $key = array_pop($tokens);
        $table = array_pop($tokens);
        $dbClusterId = null;
        if($tokens) {
            $dbClusterId = $tokens[0];
        }
        $vals = explode(',', $val);
        $valCount = count($vals);
        $m = M($table, $dbClusterId);
        $class = get_class($m);
        $where = [$key => $vals];
        if(defined("$class::STATUS_DELETE")) {
            $where['status'] = ['!=' => constant("$class::STATUS_DELETE")];
        }
        $count = $m->selectCount($where);
        if($count != $valCount) {
            $err_msg = '%s包含不存在的记录';

            return false;
        }

        return true;
    }

    function rule_unique_row($val, $table, &$err_msg)
    {
        if($val === '') {
            return true;
        }
        $tokens = explode('.', $table);
        $key = array_pop($tokens);
        $table = array_pop($tokens);
        $dbClusterId = null;
        if($tokens) {
            $dbClusterId = $tokens[0];
        }
        param_request([
            'id' => 'UINT',
        ]);
        $where = [
            $key => $val,
        ];
        if(!empty($GLOBALS['req_id'])) {
            $where['id'] = [
                '!=' => $GLOBALS['req_id'],
            ];
        }
        $count = M($table, $dbClusterId)->selectCount($where);
        if($count > 0) {
            $err_msg = '%s已经存在';

            return false;
        }

        return true;
    }

    function rule_max_width($val, $len, &$err_msg)
    {
        if($val === '') {
            return true;
        }
        $res = (mb_strlen($val) > $len) ? false : true;
        if(!$res) {
            $err_msg = "%s最大长度为{$len}";

            return false;
        }

        return $res;
    }

    public function rule_natural($val, &$err_msg)
    {
        if($val === '') {
            return true;
        }
        if(!preg_match('/^[0-9]+$/', $val)) {
            $err_msg = '%s不是合法的自然数';

            return false;
        }

        return true;
    }

    public function rule_int($val, &$err_msg)
    {
        if($val === '') {
            return true;
        }
        if(!preg_match('/^[\-+]?[0-9]+$/', $val)) {
            $err_msg = '%s不是合法的整数';

            return false;
        }

        return true;
    }

    public function rule_alpha($val, &$err_msg)
    {
        if($val === '') {
            return true;
        }
        if(!preg_match("/^([a-z])+$/i", $val)) {
            $err_msg = '%s仅能包含字母';

            return false;
        }

        return true;
    }

    public function rule_alpha_numeric($val, &$err_msg)
    {
        if($val === '') {
            return true;
        }
        if(!preg_match("/^([a-z0-9])+$/i", $val)) {
            $err_msg = '%s仅能包含字母和数字';

            return false;
        }

        return true;
    }

    public function rule_alpha_dash($val, &$err_msg)
    {
        if($val === '') {
            return true;
        }
        if(!preg_match("/^([-a-z0-9_-])+$/i", $val)) {
            $err_msg = '%s仅能包含字母、数字、_-';

            return false;
        }

        return true;
    }

    public function rule_numeric($val, &$err_msg)
    {
        if($val === '') {
            return true;
        }
        if(!preg_match('/^[\-+]?[0-9]*\.?[0-9]+$/', $val)) {
            $err_msg = '%s不是合法数字';

            return false;
        }

        return true;
    }

    public function rule_match($val, $contrast_field, &$err_msg, $data)
    {
        if(!isset($data[$contrast_field]) || $val !== $data[$contrast_field]) {
            $err_msg = '%s不一致';

            return false;
        }

        return true;
    }

    public function rule_mobile($val, &$err_msg)
    {
        if($val === '') {
            return true;
        }
        if(strlen($val) != 11) {
            $err_msg = '%s长度为11位';

            return false;
        }
        if(!preg_match('/^1\d{10}$/', $val)) {
            $err_msg = '%s格式不正确';

            return false;
        }

        return true;
    }

    public function rule_gt($val, $n, &$err_msg)
    {
        if($val === '') {
            return true;
        }
        if(!$this->rule_numeric($val, $err_msg)) {

            return false;
        }
        if($val <= $n) {

            $err_msg = '%s必须大于' . $n;

            return false;
        }

        return true;
    }

    public function rule_ge($val, $n, &$err_msg)
    {
        if($val === '') {
            return true;
        }
        if(!$this->rule_numeric($val, $err_msg)) {

            return false;
        }
        if($val < $n) {
            $err_msg = '%s必须大于等于' . $n;

            return false;
        }

        return true;
    }

    public function rule_lt($val, $n, &$err_msg)
    {
        if($val === '') {
            return true;
        }
        if(!$this->rule_numeric($val, $err_msg)) {

            return false;
        }
        if($val >= $n) {
            $err_msg = '%s必须小于' . $n;

            return false;
        }

        return true;
    }

    public function rule_le($val, $n, &$err_msg)
    {
        if($val === '') {
            return true;
        }
        if(!$this->rule_numeric($val, $err_msg)) {

            return false;
        }
        if($val > $n) {
            $err_msg = '%s必须小于等于' . $n;

            return false;
        }

        return true;
    }

    public function rule_property($val, $name, &$err_msg)
    {
        if($val === '') {
            return true;
        }
        list($object, $property) = explode('.', $name);
        $class = ucfirst($object);
        if(strpos($class, '\\') === false && defined('DEFAULT_MODEL_NAMESPACE')) {
            $class = DEFAULT_MODEL_NAMESPACE . '\\' . $class;
        }
        $property = strtoupper($property);
        if(isset($class::$$property[$val])) {

            return true;
        }
        $err_msg = '%s包含了不符合要求的值';

        return false;
    }
}
