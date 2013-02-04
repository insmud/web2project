<?php

/**
 * This class abstracts the concept of a user Role, which is, in effect, an ARO
 * group in phpGACL speak.  phpGACL has a few constraints, e.g. having only a
 * single parent group, from which all other groups must be determined.  The
 * parent for Roles is 'role'.  You can create parent trees, however a role
 * cannot be its own parent.  For the first pass of this, we limit to a single
 * depth role structure.
 *
 * Once a Role is created, users can be assigned to one or more roles, by adding
 * their user ARO id to the group. All users are given an ARO id which is separate
 * from their user id, but maps it between the w2P database and the phpGacl database.
 *
 * Roles, like individual users, can be assigned permissions, and it is expected
 * that most permissions will be assigned at role level, leaving user level for
 * just those exceptions warranting it.  Permissions are added as ACLs.
 *
 * If a role is deleted, then all of the ACLs associated with the role must also
 * be deleted, and then the user id mappings.  Note that the user ARO is _never_
 * deleted, unless the user is.
 *
 * @package     web2project\modules\core
 *
 * @todo TODO: This should probably extend our w2p_Core_BaseObject class as
 *   there is a lot of duplicated functionality that we could just eliminate
 *   from here.
 */

class CSystem_Role {
	public $role_id = null;
	public $role_name = null;
	public $role_description = null;
	public $perms = null;
    /**
     * @var object permissions/preference/translation object
     */
    protected $_AppUI;

	public function __construct($name = '', $description = '') {
		$this->role_name = $name;
		$this->role_description = $description;
		$this->perms = &$GLOBALS['AppUI']->acl();

        global $AppUI;
        $this->_AppUI = $AppUI;
        $this->perms = $this->_AppUI->acl();
	}

	public function bind($hash) {
		if (!is_array($hash)) {
			return get_class($this) . "::bind failed";
		} else {
			$q = new w2p_Database_Query;
			$q->bindHashToObject($hash, $this);
			$q->clear();
			return null;
		}
	}

	public function check() {
		// Not really much to check, just return OK for this iteration.
		return array(); // object is ok
	}

	public function store() {
        $stored = false;

        $this->_error = $this->check();
        if (count($this->_error)) {
            return false;
        }

		if ($this->role_id) {
			$ret = $this->perms->updateRole($this->role_id, $this->role_name, $this->role_description);
		} else {
			$ret = $this->perms->insertRole($this->role_name, $this->role_description);
		}

		if (!$ret) {
            $this->_error['store-check'] = get_class($this) . '::store failed';
		} else {
			$stored = true;
		}
        
        return $stored;
	}

	public function delete() {
		// Delete a role requires deleting all of the ACLs associated
		// with this role, and all of the group data for the role.
		if (canDelete('roles')) {
			// Delete all the children from this group
			return $this->perms->deleteRole($this->role_id);
		} else {
			return false; //get_class($this) . '::delete failed - You do not have permission to delete this role';
		}
	}

	public function __sleep() {
		return array('role_id', 'role_name', 'role_description');
	}

	public function __wakeup() {
        global $AppUI;
        $this->_AppUI = $AppUI;
        $this->perms = $this->_AppUI->acl();
	}

	/**
	 * Return a list of known roles.
	 */
	public function getRoles() {
		$role_parent = $this->perms->get_group_id('role');
		$roles = $this->perms->getChildren($role_parent);
		return $roles;
	}

	public function rename_array(&$roles, $from, $to) {
		if (count($from) != count($to)) {
			return false;
		}
		foreach ($roles as $key => $val) {
			// 4.2 and before return NULL on fail, later returns false.
			if (($k = array_search($k, $from)) !== false && $k !== null) {
				unset($roles[$key]);
				$roles[$to[$k]] = $val;
			}
		}
		return true;
	}
	
	/**
	 * CRole::copyPermissions()
	 * Method to copy the Permissions of a Role into another Role object.
	 * 
	 * @param integer $copy_role_id of the Role we are copying permissions from
	 * @param integer $role_id of the Role we are copying permissions to
	 * @return true if sucessful
	 */
	public function copyPermissions($copy_role_id = null, $role_id = null) {
		if (!$copy_role_id || !$role_id) {
			return false;
		}
		
		//catch to be copied Role ACLs IDs
		$role_acls = $this->perms->getRoleACLs($copy_role_id);
		
		foreach ($role_acls as $acl) {
			//initialize acl data, so we don't fall on the situation of bleeding permissions from one ACL rule to the other.
			$group_data = null;
			$mod_data = null;
			$type_map = null;
			$aro_map = null;
			$mod_mod = null;
			$mod_group = null;
			$permission_access = null;
			$permission_type = null;
			
			//catch the permissions of that acl.
			//ex: Array ( [note] => [return_value] => [enabled] => 1 [allow] => 1 [acl_id] => 14 [aco] => Array ( [application] => Array ( [0] => access ) ) [aro] => Array ( ) [axo] => Array ( ) [aro_groups] => Array ( [0] => 12 ) [axo_groups] => Array ( [0] => 13 ) ) 
			$permission = $this->perms->get_acl($acl);
		
			if (is_array($permission)) {
				if (is_array($permission['axo_groups'])) {
					foreach ($permission['axo_groups'] as $group_id) {
						//catche Group of Permissions (All, All Non-Admin, and Admin) or Module Permissions
						//ex: Array ( [0] => 13 [id] => 13 [1] => 10 [parent_id] => 10 [2] => non_admin [value] => non_admin [3] => Non-Admin Modules [name] => Non-Admin Modules [4] => 6 [lft] => 6 [5] => 7 [rgt] => 7 ) 
						$group_data = $this->perms->get_group_data($group_id, 'axo');
					}
				}
				if (is_array($permission['axo'])) {
					foreach ($permission['axo'] as $key => $section) {
						foreach ($section as $id) {
							//catch Module and Module Item permissions
							//ex.: Array ( [id] => 36 [section_value] => companies [name] => 6 [value] => 6 [order_value] => 0 [hidden] => 0 ) 
							$mod_data = $this->perms->get_object_full($id, $key, 1, 'axo');
						}
					}
				}
				if (is_array($permission['aco'])) {
					foreach ($permission['aco'] as $key => $section) {
						foreach ($section as $value) {
							//catch Actions of the Permission.
							//ex: Array ( [id] => 11 [section_value] => application [name] => Access [value] => access [order_value] => 1 [hidden] => 0 ) 
							$perm = $this->perms->get_object_full($value, $key, 1, 'aco');
							$permission_type[] = $perm['id'];
						}
					}
				}
				
				$permission_table = '' . trim($mod_data['section_value']);
				$permission_item = (int)$mod_data['value'];
				$permission_access = (int)$permission['allow'];

				if (is_array($group_data)) {
					$mod_id = $group_data['id'];
					$mod_type = 'grp';
				} else {
					$mod_id = $mod_data['id'];
					$mod_type = 'mod';
				}

				$mod_group = null;
				$mod_mod = null;
				if ($mod_type == 'grp') {
					$mod_group = array($mod_id);
				} else {
					if (isset($permission_item) && $permission_item) {
						$mod_mod = array();
						$mod_mod[$permission_table][] = $permission_item;
						// check if the item already exists, if not create it.
						// First need to check if the section exists.
						if (!$this->perms->get_object_section_section_id(null, $permission_table, 'axo')) {
							$this->perms->addModuleSection($permission_table);
						}
						if (!$this->perms->get_object_id($permission_table, $permission_item, 'axo')) {
							$this->perms->addModuleItem($permission_table, $permission_item, $permission_item);
						}
					} else {
						// Get the module information
						$mod_info = $this->perms->get_object_data($mod_id, 'axo');
						$mod_mod = array();
						$mod_mod[$mod_info[0][0]][] = $mod_info[0][1];
					}
				}
				$aro_map = array($role_id);
				// Build the permissions info
				$type_map = array();
				foreach ($permission_type as $tid) {
					$type = $this->perms->get_object_data($tid, 'aco');
					foreach ($type as $t) {
						$type_map[$t[0]][] = $t[1];
					}
				}
				$this->perms->add_acl($type_map, null, $aro_map, $mod_mod, $mod_group, $permission_access, 1, null, null, 'user');
			}
		}
		return true;
	}
}