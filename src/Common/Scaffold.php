<?php
namespace MarsLib\Scaffold\Common;

class Scaffold
{

    protected $model_class;
    /** @var \MarsLib\Scaffold\Db\Model\Base */
    protected $model;
    protected $request;
    protected $permission;
    protected $resource;
    protected $validation;
    protected $scaffold_options = [
        'single_id' => false, //单个记录的编辑
        'modal_mode' => false, //窗口模式
        'create_able' => true,
        'editable' => true,
        'delete_able' => true,
        'list_param_filter' => [],
        'list_columns' => [],
        'enums' => [], //枚举值的映射表 [ 'filed' => [ 'value' => 'text' ], .. ]
        'max_page_size' => 500,
        'page_size' => 10,
        'max_export_count' => 0,
        'where' => [],
        'attrs' => [],
        'list_default' => true,
    ];

    public function __construct($model, $scaffold_options = [])
    {
        $this->model = $model;
        $this->request = Request::getInstance();
        //$this->permission = new Permission();
        $this->validation = new Validation();
        $this->scaffold_options = array_merge($this->scaffold_options, $scaffold_options);
    }

    public function list()
    {
        $pk = $this->model->getPrimaryKey();
        $filter_fields = explode(',', str_replace(' ', '', $this->scaffold_options['list_param_filter']));
        $filter = array_filter($this->request->get(array_merge($filter_fields, ['page', 'page_size']),
            ['page' => 1, 'page_size' => $this->scaffold_options['page_size']]), function($item) {
            return !is_null($item);
        });
        $filter['page_size'] = $filter['page_size'] <= $this->scaffold_options['max_page_size'] ? $filter['page_size'] : $this->scaffold_options['max_page_size'];

        $attrs = [
            'limit' => $filter['page_size'],
            'offset' => ($filter['page'] - 1) * $filter['page_size']
        ];
        $page = $filter['page'];
        $page_size = $filter['page_size'];
        unset($filter['page'], $filter['page_size']);
        $where = array_merge($this->scaffold_options['where'], $filter);
        $attrs = array_merge($this->scaffold_options['attrs'], $attrs);
        $count = $this->model->selectCount($where);
        $list = $this->model->select($where, $attrs) ?: [];
        $rules = $this->getRules($this->preprocessRules($this->model->rules()), $this->resource);
        $fields = $this->getFormFields($rules, false, null, $pk);
        foreach($rules as $key => $item) {
            if(!in_array($item['name'], $filter_fields)) {
                unset($rules[$key]);
                continue;
            }
        }
        return $this->success([
            'list' => $list,
            'filter' => $fields,
            'page' => [
                'total' => $count,
                'current_page' => $page,
                'page_size' => $page_size
            ],
        ]);
    }

    public function form()
    {
        $id = $this->request->get($this->model->getPrimaryKey());
        $record = [];
        $history = [];
        if($id) {
            $record = $this->model->get($id);
            $history = $this->model->getDataVersion($id);
        }
        $rules = $this->getRules($this->preprocessRules($this->model->rules()), $this->resource);
        $fields = $this->getFormFields($rules);

        return [
            'form' => $record,
            'fields' => $fields,
            'history' => $history,
        ];
    }

    public function save()
    {
        $pk = $this->model->getPrimaryKey();
        $id = $this->request->post($pk);
        $rules = $this->getRules($this->preprocessRules($this->model->rules()), $this->resource);
        foreach(array_keys($rules) as $key) {
            if($id) {
                if(($rules[$key]['readonly'] ?? false)) {
                    unset($rules[$key]);
                    continue;
                }
            }
            // permission filter
            $rules[$key] = $rules[$key]['rule'];
        }
        $fields = array_map(function($item)
        {
            return explode('|', $item)[0];
        }, array_keys($rules));
        $record = $this->validation->check($rules, $this, $this->request->post($fields));
        if($this->validation->errors) {
            return $this->error(CODE_ERR_PARAM, $this->validation->errors);
        }
        if($id) {
            $ok = $this->model->update([$pk => $id], $record);
        } else {
            $id = $this->model->insert($record, true);
            $ok = (bool)$id;
        }
        if($ok) {
            return $this->success([$pk => $id]);
        } else {
            return $this->error(CODE_ERR_SERVER);
        }
    }

    public function delete()
    {
        $pk = $this->model->getPrimaryKey();
        $id = $this->request->post($pk);
        $res = $this->model->delete([$pk => $id]);
        $data = ['delete' => $res, $pk => $id];
        return $res ? $this->success($data) : $this->error(CODE_ERR_SYSTEM, '记录删除失败', $data);
    }

    public function __call($name, $arguments)
    {
        // 权限校验
    }

    protected function preprocessRules($rules)
    {
        $is_dft = false;
        $json_fields = [];
        $custom_fields = [];
        $cannot_edit_fields = [];
        foreach(array_keys($rules) as $key) {
            if(!is_array($rules[$key])) {
                $rules[$key] = [
                    'rule' => $rules[$key],
                ];
            }
            if(!isset($rules[$key]['rule'])) {
                $rules[$key]['rule'] = 'any';
            }
            $tokens = preg_split('@\|@u', $key, 2);
            $name = $tokens[0];
            $rules[$key]['name'] = $name;
            $rules[$key]['label'] = isset($tokens[1]) ? $tokens[1] : $name;
            if(preg_match('@^(\w+)\[@', $tokens[0], $ma)) {
                $name = $ma[1];
                if(!in_array($name, $json_fields)) {
                    $json_fields[] = $name;
                }
            }
            if(!empty($rules[$key]['custom_field'])) {
                $custom_fields[] = $name;
            }
            if(preg_match('@\badmin\[([\w.]+)\]@', $rules[$key]['rule'], $ma)) {
                if($this->permission && !$this->permission->check("admin.{$ma[1]}")) {
                    if(!in_array($name, $cannot_edit_fields)) {
                        $cannot_edit_fields[] = $name;
                    }
                    unset($rules[$key]);
                    continue;
                }
            }
        }

        return $rules;
    }

    public function getRules($rules, $resource = null)
    {
        $checked_rules = [];
        foreach($rules as $name => $def) {
            if($this->permission && !$this->permission->check("{$resource}.fields.{$def['name']}")) {
                continue;
            }
            if(is_array($def) && $_POST) {
                if(isset($def['depend'])) {
                    if(isset($_POST[$def['depend']['field']])) {
                        $cur_value = $_POST[$def['depend']['field']];
                        $depend_value = $def['depend']['value'];
                        if(!is_array($depend_value)) {
                            $depend_value = [$depend_value];
                        }
                        if(!in_array($cur_value, $depend_value)) {
                            continue;
                        }
                    }
                }
                if(isset($def['hide'])) {
                    if(isset($_POST[$def['hide']['field']])) {
                        $cur_value = $_POST[$def['hide']['field']];
                        $hide_value = $def['hide']['value'];
                        if(!is_array($hide_value)) {
                            $hide_value = [$hide_value];
                        }
                        if(in_array($cur_value, $hide_value)) {
                            continue;
                        }
                    }
                }
            }
            $checked_rules[$name] = $def;
        }

        return $checked_rules;
    }

    protected function getFormFields($rules = null, $checkReadonly = false, $resource = null, $pk = 'id')
    {
        $fields = [];
        $perm = $this->permission;
        $last_group_field = null;
        $last_section_field = null;
        foreach($rules as $field_index => $def) {
            $field = ['name' => $def['name'], 'label' => $def['label']];
            $rule = $def;
            if(is_array($def)) {
                $rule = $def['rule'];
            } else {
                $def = [];
            }
            if(preg_match('@\badmin\[([\w.]+)\]@', $rule, $ma)) {
                if($perm && !$perm->check("admin.{$ma[1]}")) {
                    continue;
                }
            }
            if(isset($def['form']) && !$def['form']) {
                continue;
            }
            $copy_keys = [
                'default',
                'help_text',
                'help_link',
                'depend',
                'hide',
                'params',
                'section',
            ];
            foreach($copy_keys as $key) {
                if(isset($def[$key])) {
                    $field[$key] = $def[$key];
                }
            }
            if(isset($def['type'])) {
                $field['type'] = $def['type'];
            } else {
                if($field['name'] == $pk) {
                    $field['type'] = 'hidden';
                } elseif(preg_match('@password@', $field['name'] . $rule)) {
                    $field['type'] = 'password';
                } elseif(preg_match('@property\[(.+?)\]@u', $rule, $ma)) {
                    list($object, $property) = explode('.', $ma[1]);
                    $class = ucfirst($object);
                    if(strpos($class, '\\') === false && defined('DEFAULT_MODEL_NAMESPACE')) {
                        $class = DEFAULT_MODEL_NAMESPACE . '\\' . $class;
                    }
                    $property = strtoupper($property);
                    $field['type'] = $class::$$property;
                    if($resource) {
                        foreach(array_keys($field['type']) as $property) {
                            if(!$perm->check("{$resource}.fields.{$field['name']}.{$property}")) {
                                unset($field['type'][$property]);
                            }
                        }
                    }
                } elseif(preg_match('@(?:^|\|)(int|numeric|alpha|alpha_numeric|alpha_dash|mobile)\b@u', $rule, $ma)) {
                    $field['type'] = 'text';
                    if(!isset($field['params'])) {
                        $field['params'] = ['mask' => $ma[1]];
                    }
                    if(preg_match_all('@(gt|lt|ge|le)\[(.+?)\]@', $rule, $ma)) {
                        for($i = 0, $n = count($ma[0]); $i < $n; $i++) {
                            $op = $ma[1][$i];
                            $num = $ma[2][$i];
                            if($op == 'gt' || $op == 'ge') {
                                $field['params']['min'] = $num * 1;
                            } else {
                                $field['params']['max'] = $num * 1;
                            }
                        }
                    }
                } elseif(preg_match('@\b(date|time|email|url|datetime)\b@', $rule, $ma)) {
                    $field['type'] = $ma[1];
                } elseif(preg_match('@\bjson\b@', $rule, $ma)) {
                    $field['type'] = 'json';
                } elseif(preg_match('@\bextended_json\b@', $rule, $ma)) {
                    $field['type'] = 'extended_json';
                } elseif(preg_match('@\bimage@', $rule)) {
                    $field['type'] = 'image';
                } else {
                    $field['type'] = 'text';
                }
            }
            if(is_array($field['type'])) {
                $field['options'] = $field['type'];
                $field['type'] = 'option';
            }
            if(isset($def['options'])) {
                $options = $def['options'];
                if(!is_array($options)) {
                    if(preg_match('@^cb_(\w+)$@', $options, $ma)) {
                        $cb = $ma[1];
                        $options = self::$cb();
                    }
                }
                $field['options'] = $options;
            }
            $field['required'] = !!@$def['required'];
            if(preg_match('@required@', $rule)) {
                $field['required'] = true;
            }
            if(preg_match('@max_width\[(\d+)\]@', $rule, $ma)) {
                $field['maxlength'] = $ma[1];
            }
            if($checkReadonly) {
                if(@$def['readonly']) {
                    $field['readonly'] = true;
                }
            }
            if(@$def['after_create'] && empty($GLOBALS['req_' . $pk])) {
                continue;
            }
            if(preg_match('@\barray\b@', $rule) && strpos($field['name'], '[') === false) {
                $field['array'] = true;
            }
            if(!empty($def['section'])) {
                if($last_section_field) {
                    $fields[count($fields) - 1]['section_end'] = true;
                }
                $field['section_start'] = true;
                $last_section_field = true;
            }
            if(!empty($def['group'])) {
                $field['group'] = $def['group'];
                if($last_group_field) {
                    if($last_group_field['group'] !== $def['group']) {
                        $last_group_field['group_end'] = true;
                        $field['group_start'] = true;
                    }
                } else {
                    $field['group_start'] = true;
                }
                $fields[] = $field;
                $last_group_field = &$fields[count($fields) - 1];
            } else {
                if(!empty($last_group_field)) {
                    $last_group_field['group_end'] = true;
                    unset($last_group_field);
                }
                $fields[] = $field;
            }
        }
        if(!empty($last_group_field)) {
            $last_group_field['group_end'] = true;
            unset($last_group_field);
        }
        if($last_section_field) {
            $fields[count($fields) - 1]['section_end'] = true;
        }

        return $fields;
    }

    public function error($code, $message = '', $data = [])
    {
        $response = [
            'code' => $code,
            'msg' => $message ?: ($GLOBALS[$code] ?? ''),
        ];
        if($data) {
            $response['data'] = $data;
        }

        return $response;
    }

    public function success($data = [])
    {
        return [
            'code' => 0,
            'data' => $data ?: (object)[],
        ];
    }
}