<?php
/**
 * Dropday
 *
 * @author    Dropday
 * @copyright 2024 Dropday
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param Dropday $module
 * @return bool
 */
function upgrade_module_1_5_0($module)
{
    return $module->unregisterHook('actionValidateOrder');
}
