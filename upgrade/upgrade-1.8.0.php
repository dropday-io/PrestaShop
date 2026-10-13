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
    require_once dirname(__FILE__) . '/../classes/DropdayOrder.php';

    $sql = 'CREATE TABLE IF NOT EXISTS `' . DropdayOrder::tableName() . '` (
        `id_order` INT UNSIGNED NOT NULL,
        `status` VARCHAR(16) NOT NULL DEFAULT \'' . DropdayOrder::STATUS_PENDING . '\',
        `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        `locked_until` DATETIME NULL,
        `last_http_code` SMALLINT NULL,
        `last_error` TEXT NULL,
        `dropday_reference` VARCHAR(64) NULL,
        `date_add` DATETIME NOT NULL,
        `date_upd` DATETIME NOT NULL,
        PRIMARY KEY (`id_order`),
        KEY `idx_status_date` (`status`, `date_add`)
    ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8';

    if (!Db::getInstance()->execute($sql)) {
        return false;
    }

    return $module->registerHook('displayAdminOrder') &&
        $module->registerHook('displayAdminOrderMain');
}
