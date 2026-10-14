<?php
/**
* 2007-2021 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author    PrestaShop SA <contact@prestashop.com>
*  @copyright 2007-2021 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * ObjectModel wrapping the `dropday_order` retry queue table.
 *
 * Its primary key, id_order, is not auto-incremented: there's exactly one
 * queue row per PrestaShop order, and id_order is always set to that
 * order's own id rather than a generated identity.
 */
class DropdayOrder extends ObjectModel
{
    const STATUS_PENDING = 'pending';
    const STATUS_SENDING = 'sending';
    const STATUS_SENT = 'sent';
    const STATUS_FAILED = 'failed';

    /** @var int */
    public $id_order;

    /** @var string */
    public $status = self::STATUS_PENDING;

    /** @var int */
    public $attempts = 0;

    /** @var string|null */
    public $locked_until;

    /** @var int|null */
    public $last_http_code;

    /** @var string|null */
    public $last_error;

    /** @var string|null */
    public $dropday_reference;

    /** @var string */
    public $date_add;

    /** @var string */
    public $date_upd;

    /**
     * @var array
     */
    public static $definition = [
        'table' => 'dropday_order',
        'primary' => 'id_order',
        'fields' => [
            'id_order' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'status' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'required' => true, 'size' => 16],
            'attempts' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'locked_until' => ['type' => self::TYPE_DATE, 'allow_null' => true],
            'last_http_code' => ['type' => self::TYPE_INT, 'validate' => 'isInt', 'allow_null' => true],
            'last_error' => ['type' => self::TYPE_STRING, 'allow_null' => true, 'size' => 2000],
            // Opaque identifier from Dropday: don't constrain its charset.
            'dropday_reference' => ['type' => self::TYPE_STRING, 'allow_null' => true, 'size' => 64],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
            'date_upd' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];

    /**
     * @return string
     */
    public static function tableName()
    {
        return _DB_PREFIX_ . self::$definition['table'];
    }

    /**
     * Returns the given order id first, followed by the oldest pending
     * orders (excluding the given order id), up to $limit extra ids.
     *
     * @param int $id_order
     * @param int $limit
     * @return int[]
     */
    public static function getPendingOrderIds($id_order, $limit)
    {
        $ids = [(int) $id_order];

        $rows = Db::getInstance()->executeS(
            'SELECT `id_order` FROM `' . self::tableName() . '`
             WHERE `status` = \'' . self::STATUS_PENDING . '\'
                AND `id_order` != ' . (int) $id_order . '
             ORDER BY `date_add` ASC
             LIMIT ' . (int) $limit
        );

        foreach ($rows as $row) {
            $ids[] = (int) $row['id_order'];
        }

        return $ids;
    }

    /**
     * Atomically claims the row for sending, so two parallel requests can't
     * send the same order at once, and reclaims rows abandoned mid-send.
     *
     * This needs a single conditional UPDATE plus its affected-row count,
     * which plain ObjectModel::update() can't express - so, like several
     * list/locking helpers in PrestaShop core ObjectModels, it stays as a
     * raw query here instead of pretending to be simple CRUD.
     *
     * @param int $id_order
     * @param int $lockMinutes
     * @return bool True if this call claimed the row.
     */
    public static function claim($id_order, $lockMinutes)
    {
        Db::getInstance()->execute(
            'UPDATE `' . self::tableName() . '`
             SET `status` = \'' . self::STATUS_SENDING . '\',
                 `locked_until` = DATE_ADD(NOW(), INTERVAL ' . (int) $lockMinutes . ' MINUTE),
                 `attempts` = `attempts` + 1,
                 `date_upd` = NOW()
             WHERE `id_order` = ' . (int) $id_order . '
                AND (`status` = \'' . self::STATUS_PENDING . '\'
                    OR (`status` = \'' . self::STATUS_SENDING . '\' AND `locked_until` < NOW()))'
        );

        return (int) Db::getInstance()->Affected_Rows() === 1;
    }

    /**
     * id_order is a manually assigned primary key (not AUTO_INCREMENT).
     * ObjectModel::add() otherwise overwrites $this->id with Insert_ID(),
     * which would be 0 here since there's no auto-increment column.
     *
     * @param bool $autodate
     * @param bool $null_values
     * @return bool
     */
    public function add($autodate = true, $null_values = false)
    {
        $id_order = (int) $this->id_order;
        $result = parent::add($autodate, $null_values);
        $this->id = $id_order;
        $this->id_order = $id_order;

        return $result;
    }
}
