<?php
/**
 * Copyright 2010 - 2017, Cake Development Corporation (https://www.cakedc.com)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright 2010 - 2017, Cake Development Corporation (https://www.cakedc.com)
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */

namespace CakeDC\Auth\Rbac;

use Cake\Core\InstanceConfigTrait;
use Cake\Error\Debugger;
use Cake\Log\LogTrait;
use Cake\Utility\Hash;
use Cake\Utility\Inflector;
use CakeDC\Auth\Rbac\Permissions\AbstractProvider;
use CakeDC\Auth\Rbac\Rules\Rule;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LogLevel;

/**
 * Class Rbac, determine if a request matches any of the given rbac rules
 *
 * @package Rbac
 */
class Rbac
{
    use InstanceConfigTrait;
    use LogTrait;

    /**
     * @var array default configuration
     */
    protected $_defaultConfig = [
        // autoload permissions based on a configuration
        'autoload_config' => 'permissions',
        // role field in the Users table  passed to Rbac object config
        'role_field' => 'role',
        /**
         * default role, used in new users registered and also as role matcher when no role is available
         * passed to Rbac object config
         */
        'default_role' => 'user',
        /**
         * Used to change the controller key in the request, for example to "service" if we are using a
         * middleware
         */
        'controller_key' => 'controller',
        /**
         * Used to change the controller key in the request, if we are using a
         * middleware
         */
        'action_key' => 'action',
        /**
         * Class used to provide the RBAC rules, by default from a config file, must extend AbstractProvider
         */
        'permissions_provider_class' => '\CakeDC\Auth\Rbac\Permissions\ConfigProvider',
        /**
         * Used to set permissions array from configuration, ignoring the permissionsProvider
         */
        'permissions' => null,
    ];

    /**
     * @var array rules array
     */
    protected $permissions;

    /**
     * Rbac constructor.
     *
     * @param array $config Class configuration
     */
    public function __construct($config = [])
    {
        $this->setConfig($config);
        $permissions = $this->getConfig('permissions');
        if ($permissions !== null) {
            $this->permissions = $permissions;
        } else {
            $permissionsProviderClass = $this->getConfig('permissions_provider_class');
            if (!is_subclass_of($permissionsProviderClass, AbstractProvider::class)) {
                throw new \RuntimeException(sprintf('Class "%s" must extend AbstractProvider', $permissionsProviderClass));
            }
            $permissionsProvider = new $permissionsProviderClass([
                'autoload_config' => $this->getConfig('autoload_config'),
            ]);
            $this->permissions = $permissionsProvider->getPermissions();
        }
    }

    /**
     * @return array
     */
    public function getPermissions()
    {
        return $this->permissions;
    }

    /**
     * @param array $permissions
     */
    public function setPermissions($permissions)
    {
        $this->permissions = $permissions;
    }

    /**
     * Match against permissions, return if matched
     * Permissions are processed based on the 'permissions' config values
     *
     * @param array $user current user array
     * @param \Psr\Http\Message\ServerRequestInterface $request request
     * @return bool true if there is a match in permissions
     */
    public function checkPermissions(array $user, ServerRequestInterface $request)
    {
        $roleField = $this->getConfig('role_field');
        $defaultRole = $this->getConfig('default_role');
        $role = Hash::get($user, $roleField, $defaultRole);

        foreach ($this->permissions as $permission) {
            $allowed = $this->_matchPermission($permission, $user, $role, $request);
            if ($allowed !== null) {
                return $allowed;
            }
        }

        return false;
    }

    /**
     * Match the rule for current permission
     *
     * @param array $permission The permission configuration
     * @param array $user Current user data
     * @param string $role Effective user's role
     * @param ServerRequestInterface $request Current request
     *
     * @return bool|null Null if permission is discarded, boolean if a final result is produced
     */
    protected function _matchPermission(array $permission, array $user, $role, ServerRequestInterface $request)
    {
        $issetController = isset($permission['controller']) || isset($permission['*controller']);
        $issetAction = isset($permission['action']) || isset($permission['*action']);
        $issetUser = isset($permission['user']) || isset($permission['*user']);

        if (!$issetController || !$issetAction) {
            $this->log(
                "Cannot evaluate permission when 'controller' and/or 'action' keys are absent",
                LogLevel::DEBUG
            );

            return false;
        }
        if ($issetUser) {
            $this->log(
                "Permission key 'user' is illegal, cannot evaluate the permission",
                LogLevel::DEBUG
            );

            return false;
        }

        $permission += ['allowed' => true];
        $userArr = ['user' => $user];
        $params = $request->getAttribute('params');
        $reserved = [
            'prefix' => Hash::get($params, 'prefix'),
            'plugin' => Hash::get($params, 'plugin'),
            'extension' => Hash::get($params, '_ext'),
            'controller' => Hash::get($params, 'controller'),
            'action' => Hash::get($params, 'action'),
            'role' => $role,
        ];

        foreach ($permission as $key => $value) {
            $inverse = $this->_startsWith($key, '*');
            if ($inverse) {
                $key = ltrim($key, '*');
            }

            if (is_callable($value)) {
                $return = (bool)call_user_func($value, $user, $role, $request);
            } elseif ($value instanceof Rule) {
                $return = (bool)$value->allowed($user, $role, $request);
            } elseif ($key === 'bypassAuth' && $value === true) {
                return true;
            } elseif ($key === 'allowed') {
                $return = !empty($user) && (bool)$value;
            } elseif (array_key_exists($key, $reserved)) {
                $return = $this->_matchOrAsterisk($value, $reserved[$key], true);
            } else {
                if (!$this->_startsWith($key, 'user.')) {
                    $key = 'user.' . $key;
                }

                $return = $this->_matchOrAsterisk($value, Hash::get($userArr, $key));
            }

            if ($inverse) {
                $return = !$return;
            }
            if ($key === 'allowed') {
                return $return;
            }
            if (!$return) {
                break;
            }
        }

        return null;
    }

    /**
     * Check if rule matched or '*' present in rule matching anything
     *
     * @param string|array $possibleValues Values that are accepted (from permission config)
     * @param string|mixed|null $value Value to check with. We'll check the 'dasherized' value too
     * @param bool $allowEmpty If true and $value is null, the rule will pass
     *
     * @return bool
     */
    protected function _matchOrAsterisk($possibleValues, $value, $allowEmpty = false)
    {
        $possibleArray = (array)$possibleValues;

        if ($allowEmpty && empty($possibleArray) && $value === null) {
            return true;
        }

        if ($possibleValues === '*' ||
            in_array($value, $possibleArray) ||
            in_array(Inflector::camelize($value, '-'), $possibleArray)
        ) {
            return true;
        }

        return false;
    }

    /**
     * Checks if $haystack begins with $needle
     *
     * @see http://stackoverflow.com/a/7168986/2588539
     *
     * @param string $haystack The whole string
     * @param string $needle The beginning to check
     *
     * @return bool
     */
    protected function _startsWith($haystack, $needle)
    {
        return substr($haystack, 0, strlen($needle)) === $needle;
    }
}