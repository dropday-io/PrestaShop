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
 * Adds the order retry queue table and the admin order display hooks used by
 * the "Send to Dropday again" status block.
 *
 * Note: rename this file if the release actually ships under a different
 * version number than 1.8.0.
 *
 * @param Dropday $module
 * @return bool
 */
function upgrade_module_1_8_0($module)
{
    if (!Db::getInstance()->execute($module->queueTableSql())) {
        return false;
    }

    return $module->registerHook('displayAdminOrder') &&
        $module->registerHook('displayAdminOrderMain');
}
