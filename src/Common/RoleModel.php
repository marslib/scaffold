<?php
namespace MarsLib\Scaffold\Common;

class RoleModel {
    const ROLE_ADMIN = 1;

    protected $ROLE_TREE = [
        [
            'id' => self::ROLE_ADMIN,
            'name' => '管理员',
            'permission' => [
                '__all' => 'rw',
            ],
        ],
        /*
        [
            'parent_Id' => self::ROLE_ADMIN,
            'id' => self::ROLE_PUBLISHER,
            'name' => '发布商[完整]',
            'permission' => [
                //'user' => 'Rrw',
                'app'  => 'Rrw',
                'slot' => 'Rrw',
                'ad'   => 'Rrw',
                'report' => 'Rr',
                'adnetwork' => 'Rrw',
                'profile.publisher' => 'Rrw',
                '__exclude' => [
                    'app.fields.status',
                ]
            ],
            'children' => [
                [
                    'id' => self::ROLE_REPORT_VIEW,
                    'name' => '发布商[报表]',
                    'help_text' => '仅能查看报表',
                    'permission' => [
                        'report' => 'Rr',
                    ],
                ],
                [
                    'id' => self::ROLE_PUBLISHER_SLOT,
                    'name' => '发布商[简易]',
                    'help_text' => '可管理位置，查看报表；无投放高级权限',
                    'permission' => [
                        'app' => 'Rrw',
                        'slot' => 'Rrw',
                        'adnetwork' => 'Rrw',
                        'report' => 'Rr',
                        '__exclude' => [
                            'app.fields.status',
                        ]
                    ],
                ],
            ]
        ],
         */
    ];

    protected $default_permissions = [
        'user' => [
        ],
        'public' => [
            'index', 'session', 'export', 'login', 'logout', 'error', 'upload', 'callback'
        ]
    ];

    private $ROLES = [];

    public function __construct()
    {
        $this->buildRoleData();
    }

    protected function buildRoleData()
    {
        $nodes = $this->ROLE_TREE;

        while ($node = array_shift($nodes)){
            if (!isset($node['parent_id'])) {
                $node['parent_id'] = 0;
            }
            if (isset($node['children'])) {
                $children = $node['children'];
                unset($node['children']);
                if ($children) {
                    foreach ($children as $child) {
                        $child['parent_id'] = $node['id'];
                        array_push($nodes, $child);
                    }
                }
            }
            $this->ROLES[] = $node;
        }
    }

    public function setRoleTree($role_tree)
    {
        $this->ROLE_TREE = $role_tree;
        $this->buildRoleData();
    }

    public function setDefaultPermission($permissions)
    {
        $this->default_permissions = $permissions;
    }

    public function getDefaultPermissions()
    {
        return $this->default_permissions;
    }

    public function select($where = [])
    {
        $result = [];

        foreach ($this->ROLES as $item) {
            $ok = TRUE;
            if (isset($where['id'])) {
                if (is_array($where['id'])) {
                    $ok = $ok && in_array($item['id'], $where['id']);
                } else {
                    $ok = $ok && $item['id'] == $where['id'];
                }
            }
            if (isset($where['parent_id'])) {
                if (is_array($where['parent_id'])) {
                    $ok = $ok && in_array($item['parent_id'], $where['parent_id']);
                } else {
                    $ok = $ok && $item['parent_id'] == $where['parent_id'];
                }
            }
            if ($ok) {
                $result[] = $item;
            }
        }

        unset($where['id']);
        unset($where['parent_id']);

        if ($where){
            throw new \Exception("Unsupported condition:" . json_encode($where));
        }

        return $result;
    }

    public function find($id)
    {
        $item = $this->select(['id' => $id]);

        return $item ? $item[0] : [];
    }

    public static function getInstance()
    {
        static $instance = NULL;

        if ($instance === NULL) {
            $instance = new static();
        }

        return $instance;
    }
}
