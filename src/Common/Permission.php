<?php
namespace MarsLib\Scaffold\Common;

class Permission
{
    //当前用户的权限表
    protected $_permissions = [];

    //当前用户的角色
    protected $_roles = [];
    protected $_is_admin;
    protected $_role_model;

    public function __construct($role_model, $roles = '', $is_admin = FALSE)
    {
        $this->_is_admin = $is_admin;
        $this->_role_model = $role_model;

        if ($roles) {
            if (is_string($roles)) {
                $roles = $this->_role_model->select([ 'id' => explode(',', $roles) ]);
            }
            array_change_v2k($roles, 'id');
            $this->_roles = $roles;
        }

        $this->_default_permissions = $this->_role_model->getDefaultPermissions();

        $this->_permissions = $this->_getPermissions($roles);
        //var_dump($this->_permissions);
    }

    public function getRoles()
    {
        return $this->_roles;
    }

    public function isAdmin()
    {
        return $this->_is_admin;
    }

    public function isRole($role_id)
    {
        return isset($this->_roles[$role_id]);
    }

    public function contains($roles, &$err_msg = NULL)
    {
        if ($this->_is_admin) {

            return TRUE;
        }

        $p = $roles instanceof Permission ? $roles : new Permission($roles);

        foreach ($p->_roles as $role) {
            if (empty($role['parent_id']) || !$this->isSubRole($role['parent_id'])) {

                $err_msg = '父级角色错误';

                return FALSE;
            }
        }

        $check_list = $p->_permissions;

        while ($check_list) {
            $permissions = array_shift($check_list);

            foreach ($permissions as $name => $define) {
                if ($name == '__exclude' || !is_array($define) || !isset($define['__mode'])) {
                    continue;
                }

                if (!$this->check($name, $define['__mode'])) {
                    $err_msg = "{$name}[{$define['__mode']}]";

                    return FALSE;
                }

                $children = [];
                foreach ($define as $iname => $idefine) {
                    if ($iname !== '__mode') {
                        $children["{$name}.{$iname}"] = $idefine;
                    }
                }

                if ($children) {
                    array_push($check_list, $children);
                }
            }
        }

        $exclude_list = [];
        foreach ($this->_permissions as $permissions) {
            if (isset($permissions['__exclude'])) {
                $exclude_list[] = $permissions['__exclude'];
            }
        }

        while ($exclude_list) {
            $permissions = array_shift($exclude_list);

            foreach ($permissions as $name => $define) {
                if (!is_array($define) || !isset($define['__mode']) || $this->check($name, $define['__mode'])) {
                    continue;
                }

                if ($p->check($name, $define['__mode'])) {
                    $err_msg = "{$name}[{$define['__mode']}]";

                    return FALSE;
                }

                $children = [];
                foreach ($define as $iname => $idefine) {
                    if ($iname !== '__mode') {
                        $children["{$name}.{$iname}"] = $idefine;
                    }
                }

                if ($children) {
                    array_push($exclude_list, $children);
                }
            }
        }

        return TRUE;
    }

    public function isSubRole($role_id, $exclude_self = FALSE)
    {
        if ($this->_is_admin) {

            return TRUE;
        }

        if ($exclude_self && $this->isRole($role_id)) {

            return FALSE;
        }

        while (TRUE) {
            if (isset($this->_roles[$role_id])) {

                return TRUE;
            }

            $role = $this->_role_model->find($role_id);
            if (!$role || !$role['parent_id']) {

                return FALSE;
            }

            $role_id = $role['parent_id'];
        }
    }

    public function check($resource, $mode = 'r')
    {
        if ($this->_is_admin) {

            return TRUE;
        }

        $segments = explode('.', strtolower($resource));
        $n = count($segments);
        $permission_list = $this->_permissions;

        foreach ($permission_list as $permissions) {
            if (!empty($permissions['__exclude'])) {
                $exclude = $permissions['__exclude'];
                if ($this->_inPermissionMap($resource, $exclude, $mode, TRUE)) {
                    continue;
                }
            }

            if ($this->_inPermissionMap($resource, $permissions, $mode)) {

                return TRUE;
            }
        }

        return FALSE;
    }

    public function checkMode($mode, $check)
    {
        if (strlen($check) == 1) {
            return strpos($mode, $check) !== FALSE;
        }

        $ok = TRUE;
        for ($i = 0, $n = strlen($check); $i < $n; $i++) {
            $ok = $ok && strpos($mode, $check{$i}) !== FALSE;

            if (!$ok) {

                return FALSE;
            }
        }

        return $ok;
    }

    protected function _getPermissions($roles)
    {
        $role_permissions = [ $this->_default_permissions['public'] ];
        if ($this->_roles) {

            $role_permissions[] = $this->_default_permissions['user'];

            $expand_roles = $this->_roles;
            $roles = $this->_roles;
            while ($roles) {
                $role = array_pop($roles);

                $sub_roles = $this->_role_model->select([ 'parent_id' => $role['id'] ]);
                if ($sub_roles) {
                    foreach ($sub_roles as $sub_role) {
                        if (!isset($expand_roles[$sub_role['id']])) {
                            $roles[$sub_role['id']] = $sub_role;
                            $expand_roles[$sub_role['id']] = $sub_role;
                        }
                    }
                }
                $role_permission = d(@$role['permission'], []);
                $role_permissions[] = $role_permission;
            }
        }

        $role_permission_map_list = [];
        foreach ($role_permissions as $role_permission) {
            $exclude = [];
            if (isset($role_permission['__exclude'])) {
                $exclude = $role_permission['__exclude'];
                unset($role_permission['__exclude']);
                $exclude = $this->_getPermissionMap($exclude);
            }
            $role_permission_map = $this->_getPermissionMap($role_permission);
            $role_permission_map['__exclude'] = $exclude;
            $role_permission_map_list[] = $role_permission_map;
        }

        return $role_permission_map_list;
    }

    protected function _inPermissionMap($resource, $perm_map, $mode = 'r', $strict = FALSE)
    {
        $segments = explode('.', strtolower($resource));
        $n = count($segments);

        if (isset($perm_map['__all'])) {
            if ($this->checkMode($perm_map['__all']['__mode'], $mode)) {

                return TRUE;
            }
        }

        for ($i = 0; $i < $n; $i++) {
            $segment = $segments[$i];
            if (empty($perm_map[$segment])) {

                return FALSE;
            }

            $imode = $perm_map[$segment]['__mode'];

            if ((($i == $n - 1) || $this->checkMode($imode, 'R')) && $this->checkMode($imode, $mode)) {

                //__exclude检测时，要排除父级节点的默认权限继承
                if (($i == $n - 1) && $strict) {
                    if (count($perm_map[$segment]) >= 2) {
                        return FALSE;
                    }
                }
                return TRUE;
            }

            $perm_map = $perm_map[$segment];
        }

        return FALSE;
    }

    private function _getPermissionMap($perms)
    {
        $perm_map = array();

        foreach ($perms as $rid => $rmode) {
            if (!is_string($rid)) {
                $rid = $rmode;
                $rmode = 'rw';
            }

            $segments = explode('.', $rid);
            $n = count($segments);
            $map = &$perm_map;
            for ($i = 0; $i < $n; $i++) {
                $segment = $segments[$i];

                if ($i == $n - 1 && !$this->checkMode($rmode, 'R')) {
                    $rmode .= 'R';
                }

                if (isset($map[$segment])) {
                    if ($this->checkMode($map[$segment]['__mode'], $rmode . 'R')) {
                        break;
                    }
                } else {
                    $map[$segment] = [ '__mode' => 'r' ];
                }

                if ($i == $n - 1) {
                    $map[$segment]['__mode'] = $rmode;
                }

                $map = &$map[$segment];
            }
            unset($map);
        }

        return $perm_map;
    }
}
