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

require_once dirname(__FILE__) . '/classes/Http.php';

class Dropday extends Module
{
    const STATUS_PENDING = 'pending';
    const STATUS_SENDING = 'sending';
    const STATUS_SENT = 'sent';
    const STATUS_FAILED = 'failed';
    const OUTCOME_RETRY = 'retry';

    const QUEUE_BATCH_SIZE = 5;
    const LOCK_MINUTES = 2;

    /**
     * @var string 
     */
    protected $api_uri = 'https://dropday.io/api/v1/';

    /**
     * @var string API key
     */
    protected $apiKey = null;

    /**
     * @var string Account ID
     */
    protected $accountId = null;

    /**
     * Dropday constructor.
     */
    public function __construct()
    {
        $this->name = 'dropday';
        $this->tab = 'shipping_logistics';
        $this->version = '1.8.0';
        $this->author = 'Dropday support@dropday.nl';
        $this->need_instance = 0;
        $this->module_key = '103f6d164a3687e35039203258788760';

        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Dropday');
        $this->description = $this->l('Order synchronisation with Dropday drop-shipping automation');

        $this->ps_versions_compliancy = ['min' => '1.6', 'max' => _PS_VERSION_];
    }

    /**
     * @return bool
     */
    public function install()
    {
        Configuration::updateValue('DROPDAY_LIVE_MODE', false);

        if (!$this->installDb()) {
            return false;
        }

        return parent::install() &&
            $this->registerHook('actionOrderStatusUpdate') &&
            $this->registerHook('displayAdminOrder') &&
            $this->registerHook('displayAdminOrderMain');
    }

    /**
     * @return mixed
     */
    public function uninstall()
    {
        $this->uninstallDb();

        return parent::uninstall();
    }

    /**
     * @return bool
     */
    protected function installDb()
    {
        return (bool) include dirname(__FILE__) . '/sql/install.php';
    }

    /**
     * @return bool
     */
    protected function uninstallDb()
    {
        return (bool) include dirname(__FILE__) . '/sql/uninstall.php';
    }

    /**
     * @return string
     */
    public function queueTable()
    {
        return _DB_PREFIX_ . 'dropday_order';
    }

    /**
     * @return string
     */
    public function queueTableSql()
    {
        return 'CREATE TABLE IF NOT EXISTS `' . $this->queueTable() . '` (
            `id_order` INT UNSIGNED NOT NULL,
            `status` VARCHAR(16) NOT NULL DEFAULT \'' . self::STATUS_PENDING . '\',
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
    }

    /**
     * @return string
     */
    public function getContent()
    {
        $output = '';
        if (((bool)Tools::isSubmit('submitDropdayModule')) == true) {
            $output .= $this->postProcess();
        }
        $output .= $this->renderForm();
        return $output;
    }

    /**
     * @param string $type
     * @return string
     */
    public function getApiUrl($type = '')
    {
        return $type ? trim($this->api_uri, '/') . '/' . $type : trim($this->api_uri, '/');
    }

    /**
     * Get API Key (in-memory override or configuration)
     * 
     * @return string
     */
    protected function getApiKey()
    {
        return $this->apiKey !== null 
            ? $this->apiKey 
            : Configuration::get('DROPDAY_ACCOUNT_APIKEY');
    }

    /**
     * Get Account ID (in-memory override or configuration)
     * 
     * @return string
     */
    protected function getAccountId()
    {
        return $this->accountId !== null 
            ? $this->accountId 
            : Configuration::get('DROPDAY_ACCOUNT_ID');
    }

    /**
     * Set API Key (in-memory, does not persist to database)
     * 
     * @param string $apiKey
     */
    protected function setApiKey($apiKey)
    {
        $this->apiKey = $apiKey;
    }

    /**
     * Set Account ID (in-memory, does not persist to database)
     * 
     * @param string $accountId
     */
    protected function setAccountId($accountId)
    {
        $this->accountId = $accountId;
    }

    /**
     * @return string
     */
    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitDropdayModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFormValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        $this->context->smarty->assign([
            'module_dir' => $this->_path
        ]);

        $output = $this->context->smarty->fetch($this->local_path.'views/templates/admin/configure.tpl');
        
        return $helper->generateForm($this->getConfigForm()) . $output;
    }

    /**
     * @return array[]
     */
    protected function getConfigForm()
    {
        // Get all order states for the select options
        $orderStates = OrderState::getOrderStates($this->context->language->id);
        $orderStateOptions = [
            [
                'id' => 'default',
                'name' => $this->l('Default behaviour (hookPaymentConfirmation)')
            ]
        ];
        
        foreach ($orderStates as $state) {
            $orderStateOptions[] = [
                'id' => $state['id_order_state'],
                'name' => $state['name']
            ];
        }

        return [
            [
                'form' => [
                    'legend' => [
                        'title' => $this->l('Settings'),
                        'icon' => 'icon-cogs',
                    ],
                    'input' => [
                        [
                            'type' => 'switch',
                            'label' => $this->l('Live mode'),
                            'name' => 'DROPDAY_LIVE_MODE',
                            'is_bool' => true,
                            'desc' => $this->l('Use this module in live mode'),
                            'values' => [
                                [
                                    'id' => 'active_on',
                                    'value' => true,
                                    'label' => $this->l('Enabled')
                                ],
                                [
                                    'id' => 'active_off',
                                    'value' => false,
                                    'label' => $this->l('Disabled')
                                ]
                            ],
                        ],
                        [
                            'col' => 6,
                            'type' => 'text',
                            'prefix' => '<i class="icon icon-key"></i>',
                            'name' => 'DROPDAY_ACCOUNT_APIKEY',
                            'label' => $this->l('API Key'),
                        ],
                        [
                            'col' => 6,
                            'type' => 'text',
                            'name' => 'DROPDAY_ACCOUNT_ID',
                            'label' => $this->l('Account ID'),
                        ],
                        [
                            'type' => 'select',
                            'label' => $this->l('Order status trigger'),
                            'desc' => $this->l('Select which order statuses should trigger sending orders to Dropday. Select "Default behaviour" to use hookPaymentConfirmation only.'),
                            'name' => 'DROPDAY_ORDER_STATUSES[]',
                            'multiple' => true,
                            'size' => is_array($orderStateOptions) ? count($orderStateOptions) : 8,
                            'col' => 6,
                            'options' => [
                                'query' => $orderStateOptions,
                                'id' => 'id',
                                'name' => 'name'
                            ]
                        ],
                    ],
                    'submit' => [
                        'title' => $this->l('Save'),
                    ],
                ],
            ]
        ];
    }

    /**
     * @return array
     */
    protected function getConfigFormValues()
    {
        $orderStatuses = Configuration::get('DROPDAY_ORDER_STATUSES');
        if (!$orderStatuses) {
            $orderStatuses = ['default']; // Default behavior
        } elseif (is_string($orderStatuses)) {
            $orderStatuses = json_decode($orderStatuses, true) ?: ['default'];
        }
        
        return [
            'DROPDAY_LIVE_MODE' => Configuration::get('DROPDAY_LIVE_MODE'),
            'DROPDAY_ACCOUNT_ID' => Configuration::get('DROPDAY_ACCOUNT_ID'),
            'DROPDAY_ACCOUNT_APIKEY' => Configuration::get('DROPDAY_ACCOUNT_APIKEY', null),
            'DROPDAY_ORDER_STATUSES[]' => $orderStatuses,
        ];
    }

    /**
     * @return mixed
     */
    protected function postProcess()
    {
        $form_values = $this->getConfigFormValues();
        $defaultBehaviourSelected = false;
        
        foreach (array_keys($form_values) as $key) {
            // Handle order statuses specially
            if ($key === 'DROPDAY_ORDER_STATUSES[]') {
                $orderStatuses = Tools::getValue('DROPDAY_ORDER_STATUSES');
                
                // If default is selected or nothing is selected, remove configuration
                if (!$orderStatuses || in_array('default', $orderStatuses)) {
                    Configuration::deleteByName('DROPDAY_ORDER_STATUSES');
                    $defaultBehaviourSelected = true;
                } else {
                    // Store as JSON string
                    Configuration::updateValue('DROPDAY_ORDER_STATUSES', json_encode($orderStatuses));
                }
            } else {
                Configuration::updateValue($key, Tools::getValue($key));
            }
        }
        
        // Show appropriate confirmation message
        if ($defaultBehaviourSelected) {
            return $this->displayConfirmation($this->l('Default behaviour is reset: order is send when marked as paid'));
        } else {
            return $this->displayConfirmation($this->l('Settings updated successfully!'));
        }
    }

    /**
     * Adds (or refreshes) a queue row for the order. Never overwrites an order
     * that was already confirmed as sent.
     *
     * @param int $id_order
     */
    protected function addToQueue($id_order)
    {
        $id_order = (int) $id_order;

        Db::getInstance()->execute(
            'INSERT INTO `' . $this->queueTable() . '` (`id_order`, `status`, `attempts`, `date_add`, `date_upd`)
             VALUES (' . $id_order . ', \'' . self::STATUS_PENDING . '\', 0, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                `status` = IF(`status` IN (\'' . self::STATUS_SENT . '\', \'' . self::STATUS_FAILED . '\'), `status`, \'' . self::STATUS_PENDING . '\'),
                `date_upd` = NOW()'
        );
    }

    /**
     * @param int $id_order
     * @return bool
     */
    protected function isFinalized($id_order)
    {
        $status = Db::getInstance()->getValue(
            'SELECT `status` FROM `' . $this->queueTable() . '` WHERE `id_order` = ' . (int) $id_order
        );

        return $status === self::STATUS_SENT || $status === self::STATUS_FAILED;
    }

    /**
     * @param int $id_order
     * @return array|false
     */
    public function getQueueStatus($id_order)
    {
        return Db::getInstance()->getRow(
            'SELECT * FROM `' . $this->queueTable() . '` WHERE `id_order` = ' . (int) $id_order
        );
    }

    /**
     * Returns the given order id first, followed by the oldest pending orders
     * (excluding the given order id), up to $limit extra ids.
     *
     * @param int $id_order
     * @param int $limit
     * @return int[]
     */
    protected function getPendingOrderIds($id_order, $limit)
    {
        $ids = [(int) $id_order];

        $rows = Db::getInstance()->executeS(
            'SELECT `id_order` FROM `' . $this->queueTable() . '`
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
     * send the same order at once. Also reclaims rows abandoned mid-send.
     *
     * @param int $id_order
     * @return bool
     */
    protected function claimOrder($id_order)
    {
        Db::getInstance()->execute(
            'UPDATE `' . $this->queueTable() . '`
             SET `status` = \'' . self::STATUS_SENDING . '\',
                 `locked_until` = DATE_ADD(NOW(), INTERVAL ' . self::LOCK_MINUTES . ' MINUTE),
                 `attempts` = `attempts` + 1,
                 `date_upd` = NOW()
             WHERE `id_order` = ' . (int) $id_order . '
                AND (`status` = \'' . self::STATUS_PENDING . '\'
                    OR (`status` = \'' . self::STATUS_SENDING . '\' AND `locked_until` < NOW()))'
        );

        return (int) Db::getInstance()->Affected_Rows() === 1;
    }

    /**
     * @param int $id_order
     * @param string $status
     * @param int|null $httpCode
     * @param string|null $error
     * @param string|null $reference
     */
    protected function updateQueueRow($id_order, $status, $httpCode = null, $error = null, $reference = null)
    {
        $sql = 'UPDATE `' . $this->queueTable() . '`
             SET `status` = \'' . pSQL($status) . '\',
                 `last_http_code` = ' . ($httpCode !== null ? (int) $httpCode : 'NULL') . ',
                 `last_error` = ' . ($error !== null ? '\'' . pSQL(Tools::substr((string) $error, 0, 2000)) . '\'' : 'NULL') . ',
                 `date_upd` = NOW()';

        if ($reference !== null) {
            $sql .= ', `dropday_reference` = \'' . pSQL((string) $reference) . '\'';
        }

        $sql .= ' WHERE `id_order` = ' . (int) $id_order;

        Db::getInstance()->execute($sql);
    }

    /**
     * Resets the queue row so the order is picked up and sent again,
     * regardless of its previous status.
     *
     * @param int $id_order
     */
    protected function resetForResend($id_order)
    {
        $id_order = (int) $id_order;

        Db::getInstance()->execute(
            'INSERT INTO `' . $this->queueTable() . '` (`id_order`, `status`, `attempts`, `date_add`, `date_upd`)
             VALUES (' . $id_order . ', \'' . self::STATUS_PENDING . '\', 0, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                `status` = \'' . self::STATUS_PENDING . '\',
                `attempts` = 0,
                `last_error` = NULL,
                `last_http_code` = NULL,
                `locked_until` = NULL,
                `date_upd` = NOW()'
        );
    }

    /**
     * Classifies a Dropday API result into one of:
     * - self::STATUS_SENT: the order is confirmed to exist at Dropday
     * - self::OUTCOME_RETRY: transient failure, worth retrying later
     * - self::STATUS_FAILED: permanent failure, won't be retried automatically
     *
     * @param string|null $curlError
     * @param int|null $httpCode
     * @return string
     */
    protected function classify($curlError, $httpCode)
    {
        if (!empty($curlError)) {
            return self::OUTCOME_RETRY;
        }

        if ($httpCode === 200 || $httpCode === 409) {
            return self::STATUS_SENT;
        }

        if ($httpCode === 408 || $httpCode === 429 || $httpCode >= 500) {
            return self::OUTCOME_RETRY;
        }

        return self::STATUS_FAILED;
    }

    /**
     * Claims, sends and classifies a single queued order. Updates the queue
     * row and writes the same log messages as before.
     *
     * @param int $id_order
     * @return string|false One of self::STATUS_SENT, self::OUTCOME_RETRY,
     *                       self::STATUS_FAILED, or false if the order could
     *                       not be claimed (already being processed elsewhere).
     */
    public function processOrder($id_order)
    {
        $id_order = (int) $id_order;

        if (!$this->claimOrder($id_order)) {
            return false;
        }

        $order = new Order($id_order);

        if (!Validate::isLoadedObject($order)) {
            $this->updateQueueRow($id_order, self::STATUS_FAILED, null, 'Order not found');
            return self::STATUS_FAILED;
        }

        $order_date = $this->getOrderData($order);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Api-Key: ' . $this->getApiKey(),
            'Account-Id: ' . $this->getAccountId(),
        ];

        $response = DropdayHttp::post($this->getApiUrl('orders'), $order_date, $headers);

        $curlError = $response['error'];
        $httpCode = empty($curlError) ? (int) $response['status'] : null;
        $result = empty($curlError) ? json_decode($response['body'], true) : null;

        $outcome = $this->classify($curlError, $httpCode);

        switch ($outcome) {
            case self::STATUS_SENT:
                $reference = is_array($result) && isset($result['reference']) ? $result['reference'] : null;
                $this->updateQueueRow($id_order, self::STATUS_SENT, $httpCode, null, $reference);
                Logger::addLog('[dropday] API request sent successfully :#' . $reference, 1, null, 'Order', $id_order, true);
                break;

            case self::OUTCOME_RETRY:
                if (!empty($curlError)) {
                    $this->updateQueueRow($id_order, self::STATUS_PENDING, null, $curlError);
                    Logger::addLog('[dropday] error: ' . $curlError, 3, null, 'Order', $id_order, true);
                } else {
                    $message = json_encode($result);
                    $this->updateQueueRow($id_order, self::STATUS_PENDING, $httpCode, $message);
                    Logger::addLog('[dropday] Unknown error: ' . $message, 3, $httpCode, 'Order', $id_order, true);
                }
                break;

            case self::STATUS_FAILED:
            default:
                if ($httpCode == 422) {
                    $message = is_array($result) && isset($result['errors']) ? json_encode($result['errors']) : null;
                    $this->updateQueueRow($id_order, self::STATUS_FAILED, $httpCode, $message);
                    Logger::addLog('[dropday] Error: ' . $message, 3, null, 'Order', $id_order, true);
                } else {
                    $message = json_encode($result);
                    $this->updateQueueRow($id_order, self::STATUS_FAILED, $httpCode, $message);
                    Logger::addLog('[dropday] Unknown error: ' . $message, 3, $httpCode, 'Order', $id_order, true);
                }
                break;
        }

        return $outcome;
    }

    /**
     * Determines if an order should be handled based on configured order statuses
     * 
     * @param OrderState $status
     * @return bool
     */
    private function shouldHandleOrderStatus(OrderState $status)
    {
        $configuredStatuses = Configuration::get('DROPDAY_ORDER_STATUSES');
        
        // If no custom configuration exists, use default behavior (check if paid)
        if (!$configuredStatuses) {
            return $status->paid;
        }
        
        // Parse configured statuses
        $configuredStatuses = json_decode($configuredStatuses, true);
        
        // If configuration is invalid or empty, fallback to default behavior
        if (!is_array($configuredStatuses) || count($configuredStatuses) === 0) {
            return $status->paid;
        }
        
        // Convert all values to integers for consistent comparison
        $configuredStatuses = array_map('intval', $configuredStatuses);
        
        // Check if current status is in configured statuses
        return in_array((int) $status->id, $configuredStatuses, true);
    }

    public function getOrderData(Order $order)
    {
        $cart = new Cart((int)$order->id_cart);
        
        $shipping_cost = $cart->getTotalShippingCost(null, true, null);
        $shop = new Shop((int) $order->id_shop);
        $customer = new Customer((int) $order->id_customer);
        $address = new Address((int) $order->id_address_delivery);
        $shippingInfo = $this->getShippingInfo($order, $cart);

        $orderData = [
            'external_id' => $order->reference,
            'source' => $shop->name,
            'total' => (float) $order->getOrdersTotalPaid(),
            'shipping_cost' => (float) $shipping_cost,
            'shipping' => $shippingInfo,
            'email' => $customer->email,
            'shipping_address' => [
                'first_name' => $address->firstname,
                'last_name' => $address->lastname,
                'company_name' => $address->company,
                'address1' => $address->address1,
                'address2' => ($address->address2 ? $address->address2 : $address->address2),
                'postcode' => $address->postcode,
                'city' => $address->city,
                'country' => Country::getNameById($order->id_lang, (int) $address->id_country),
                'phone' => $address->phone,
            ],
            'products' => []
        ];

        if ($state = State::getNameById($address->id_state)) {
            $orderData['shipping_address']['state'] = (string) $state;
        }

        if (!Configuration::get('DROPDAY_LIVE_MODE')) {
            $orderData['test'] = true;
        }
        
        $products = $order->getProducts();
        $customizationsByKey = $this->getCartCustomizations($cart, $order->id_lang);

        foreach ($products as $product) {
            
            $quantity = (int) (isset($product['customizationQuantityTotal']) && $product['customizationQuantityTotal'])
                ? $product['customizationQuantityTotal']
                : $product['product_quantity'];

            $stockQuantity = false;
            if (Configuration::get('PS_STOCK_MANAGEMENT')) {
                $stockAvailable = StockAvailable::getQuantityAvailableByProduct($product['product_id'], $product['product_attribute_id'], $this->context->shop->id);
                $stockQuantity = (int) $stockAvailable + (int) $product['product_quantity'];
            }

            $ean13 = false;
            if (Tools::strlen($product['product_ean13']) >= 13) {
                $ean13 = $product['product_ean13'];
            }

            $cat = new Category((int) $product['id_category_default'], (int) $order->id_lang);

            $productObj = new Product((int) $product['product_id'], false, (int) $order->id_lang);

            if (isset($product['image']) && isset($productObj->link_rewrite)) {
                $image_url = $this->context->link->getImageLink($productObj->link_rewrite, $product['image']->id, $this->imageTypeGetFormattedName('large'));
            }

            $height = (float) $productObj->height;
            $width = (float) $productObj->width;
            $depth = (float) $productObj->depth;

            if ((int) $product['product_attribute_id'] > 0) {
                $combinationDimensions = $this->getCombinationDimensions((int) $product['product_attribute_id']);
                if ($combinationDimensions) {
                    if ($combinationDimensions['height'] > 0) {
                        $height = $combinationDimensions['height'];
                    }
                    if ($combinationDimensions['width'] > 0) {
                        $width = $combinationDimensions['width'];
                    }
                    if ($combinationDimensions['depth'] > 0) {
                        $depth = $combinationDimensions['depth'];
                    }
                }
            }

            $product_data = [
                'external_id' => (int) $product['product_id'],
                'name' => (string) $product['product_name'],
                'reference' => (string) $this->getProductReference($product),
                'quantity' => (int) $product['product_quantity'],
                'price' => (float) $product['product_price'],
                'purchase_price' => isset($product['original_wholesale_price']) && $product['original_wholesale_price'] > 0 ? 
                    (float) $product['original_wholesale_price'] : (float) $product['wholesale_price'],
                'image_url' => $image_url,
                'brand' => (string) Manufacturer::getNameById((int) $product['id_manufacturer']),
                'category' => (string) $cat->name,
                'supplier' => (string) Supplier::getNameById((int) $product['id_supplier']),
                'height' => $height,
                'width' => $width,
                'length' => $depth,
                'weight' => (float) $product['product_weight'],
            ];

            if ($stockQuantity !== false) {
                $product_data['stock_quantity'] = $stockQuantity;
            }

            if ($ean13 !== false) {
                $product_data['ean13'] = $ean13;
            }

            $idCustomization = isset($product['id_customization']) ? (int) $product['id_customization'] : 0;
            if ($idCustomization > 0 && isset($customizationsByKey[$idCustomization])) {
                $product_data['custom'] = $customizationsByKey[$idCustomization];
            }

            $orderData['products'][$product['id_order_detail']] = $product_data;
        }

        $orderData['products'] = array_values($orderData['products']);

        return $orderData;
    }

    /**
     * Get shipping information for Dropday API
     * 
     * @param Order $order
     * @param Cart $cart
     * @return array
     */
    private function getShippingInfo(Order $order, Cart $cart)
    {
        $shippingInfo = [
            'name' => '',
            'description' => '',
            'cost' => (float) $cart->getTotalShippingCost(null, true, null),
            'note' => '',
            'delivery_date' => ''
        ];

        if ($order->id_carrier > 0) {
            $carrier = new Carrier((int) $order->id_carrier);
            if (Validate::isLoadedObject($carrier)) {
                $shippingInfo['name'] = $carrier->name;
                
                if (!empty($carrier->delay) && is_array($carrier->delay)) {
                    $currentLangId = $this->context->language->id;
                    if (isset($carrier->delay[$currentLangId])) {
                        $shippingInfo['description'] = $carrier->delay[$currentLangId];
                    } else {
                        $shippingInfo['description'] = reset($carrier->delay);
                    }
                } elseif (!empty($carrier->delay)) {
                    $shippingInfo['description'] = $carrier->delay;
                }
            }
        }
        
        $orderMessages = Message::getMessagesByOrderId($order->id, false);
        if (!empty($orderMessages)) {
            $notes = [];
            foreach ($orderMessages as $message) {
                if (!empty($message['message'])) {
                    $notes[] = $message['message'];
                }
            }
            if (!empty($notes)) {
                $shippingInfo['note'] = implode('; ', $notes);
            }
        }

        return $shippingInfo;
    }

    /**
     * @param $params
     */
    public function hookActionOrderStatusUpdate($params)
    {
        $id_order = (int) $params['id_order'];
        $status = $params['newOrderStatus'];

        if (!$id_order || !Validate::isLoadedObject($status) || !$this->shouldHandleOrderStatus($status)) {
            return;
        }

        if ($this->isFinalized($id_order)) {
            return;
        }

        $this->addToQueue($id_order);

        foreach ($this->getPendingOrderIds($id_order, self::QUEUE_BATCH_SIZE) as $queuedOrderId) {
            if ($this->processOrder($queuedOrderId) === self::OUTCOME_RETRY) {
                // Dropday looks unreachable right now; leave the rest pending
                // instead of waiting out a timeout for each of them.
                break;
            }
        }
    }

    /**
     * @param array $params
     * @return string
     */
    public function hookDisplayAdminOrder($params)
    {
        // On 1.7.7+ the order page also calls displayAdminOrderMain; render
        // there instead to avoid showing the block twice on the same page.
        if (version_compare(_PS_VERSION_, '1.7.7.0', '>=')) {
            return '';
        }

        return $this->renderOrderStatusBlock((int) $params['id_order'], false);
    }

    /**
     * @param array $params
     * @return string
     */
    public function hookDisplayAdminOrderMain($params)
    {
        if (version_compare(_PS_VERSION_, '1.7.7.0', '<')) {
            return '';
        }

        return $this->renderOrderStatusBlock((int) $params['id_order'], true);
    }

    /**
     * @param int $id_order
     * @param bool $newTheme Bootstrap 4 / material-icons theme (1.7.7+) vs
     *                       the legacy Bootstrap 3 admin theme (1.6 - 1.7.6).
     * @return string
     */
    protected function renderOrderStatusBlock($id_order, $newTheme)
    {
        if (Tools::isSubmit('dropday_resend')) {
            $this->resetForResend($id_order);
            $this->processOrder($id_order);

            // Strip the flag from the URL so a page refresh (or clicking
            // the browser back/forward button) can never silently re-fire
            // the resend and reset the attempts counter.
            header('Location: ' . $this->getCurrentUrl());
            exit;
        }

        $row = $this->getQueueStatus($id_order);
        $reference = $row ? $row['dropday_reference'] : null;

        $this->context->smarty->assign([
            'dropday_status' => $row ? $row['status'] : 'never',
            'dropday_attempts' => $row ? (int) $row['attempts'] : 0,
            'dropday_reference' => $reference,
            'dropday_order_url' => $reference ? $this->getOrderUrl($reference) : null,
            'dropday_last_error' => $row ? $row['last_error'] : null,
            'dropday_resend_url' => $this->getResendUrl(),
            'dropday_new_theme' => $newTheme,
        ]);

        return $this->context->smarty->fetch($this->local_path . 'views/templates/admin/order_status.tpl');
    }

    /**
     * @param string $reference Dropday order reference.
     * @return string
     */
    protected function getOrderUrl($reference)
    {
        return 'https://dropday.io/dashboard/order/' . urlencode($reference);
    }

    /**
     * The exact URL that rendered the current order page (legacy
     * query-string or new Symfony route - whichever this page is already
     * using, with its already-valid token), with any dropday_resend flag
     * stripped out.
     *
     * @return string
     */
    protected function getCurrentUrl()
    {
        $parts = parse_url($_SERVER['REQUEST_URI']);
        $path = isset($parts['path']) ? $parts['path'] : '';

        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        unset($query['dropday_resend']);

        return $path . (!empty($query) ? '?' . http_build_query($query) : '');
    }

    /**
     * Same page, with the resend flag added, so clicking the button lands
     * back on this very same page.
     *
     * @return string
     */
    protected function getResendUrl()
    {
        $separator = (strpos($this->getCurrentUrl(), '?') === false) ? '?' : '&';

        return $this->getCurrentUrl() . $separator . 'dropday_resend=1';
    }

    /**
     * Fixes backward compatibility issue
     *
     * @param string $size
     * @return mixed
     */
    private function imageTypeGetFormattedName($size = 'large')
    {
        if (method_exists('ImageType', 'getFormatedName')) {
            return ImageType::getFormatedName($size);
        }

        return ImageType::getFormattedName('large');
    }

    /**
     * Combination dimensions are not part of core PrestaShop; some shops add
     * height/width/depth columns to product_attribute via a third-party module.
     *
     * @param int $idProductAttribute
     * @return array{height: float, width: float, depth: float}|false
     */
    private function getCombinationDimensions($idProductAttribute)
    {
        $sql = new DbQuery();
        $sql->select('*');
        $sql->from('product_attribute');
        $sql->where('`id_product_attribute` = ' . (int) $idProductAttribute);

        $row = Db::getInstance()->getRow($sql);
        if (!$row || !isset($row['height'], $row['width'], $row['depth'])) {
            return false;
        }

        return [
            'height' => (float) $row['height'],
            'width' => (float) $row['width'],
            'depth' => (float) $row['depth'],
        ];
    }

    /**
     * Checks for combination and gets correct product reference
     * 
     * @param array $product
     * @return string
     */
    private function getProductReference($product)
    {
        $reference = $product['reference'];

        if ((int)$product['product_attribute_id'] > 0) {
            $combination = new Combination((int)$product['product_attribute_id']);
            if ((string)$combination->reference !== '') {
                $reference = $combination->reference;
            }
        }
        
        return $reference;
    }

    /**
     * Fetches all customization field names and values for a cart in a single query,
     * grouped by id_customization.
     * 
     * @param Cart $cart
     * @param int $id_lang
     * @return array<int, array<string, string>> Keyed by id_customization
     */
    private function getCartCustomizations(Cart $cart, $id_lang)
    {
        $sql = sprintf(
            'SELECT cd.id_customization, cd.type, cd.`index`, cd.value, cfl.name AS field_name
            FROM `%scustomized_data` cd
            INNER JOIN `%scustomization` c ON c.id_customization = cd.id_customization
            LEFT JOIN `%scustomization_field_lang` cfl 
                ON cfl.id_customization_field = cd.`index` 
                AND cfl.id_lang = %d 
                AND cfl.id_shop = %d
            WHERE c.id_cart = %d',
            _DB_PREFIX_,
            _DB_PREFIX_,
            _DB_PREFIX_,
            (int) $id_lang,
            (int) $this->context->shop->id,
            (int) $cart->id
        );

        $rows = DB::getInstance()->executeS($sql);
        if (!$rows) {
            return [];
        }

        $uploadBaseUrl = rtrim($this->context->link->getPageLink('index'), '/') . '/upload/';
        $result = [];
        $counters = [];

        foreach ($rows as $row) {
            $idCustomization = (int) $row['id_customization'];
            $type = (string) $row['type'];

            if ($type === (string) Product::CUSTOMIZE_TEXTFIELD) {
                $value = $row['value'];
            } elseif ($type === (string) Product::CUSTOMIZE_FILE) {
                $value = $uploadBaseUrl . $row['value'];
            } else {
                continue;
            }

            if (!isset($counters[$idCustomization])) {
                $counters[$idCustomization] = 1;
            }

            $name = $row['field_name'] ?: 'value_' . $counters[$idCustomization];
            $counters[$idCustomization]++;

            $result[$idCustomization][$name] = $value;
        }

        return $result;
    }
}
