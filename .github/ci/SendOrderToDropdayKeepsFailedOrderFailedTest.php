<?php
/**
 * CI smoke test: once an order has permanently failed at Dropday, firing
 * the order status hook again (e.g. an unrelated status change on the same
 * order) must not reset it back into the retry queue (isFinalized() guard).
 *
 * Assumes PrestaShop Docker auto-install already ran (shop up, demo orders present).
 */

class SendOrderToDropdayKeepsFailedOrderFailedTest
{
    public function run()
    {
        $this->bootPrestaShop();
        $this->installDropdayWithEmptySettings();

        $order = $this->firstOrder();
        $orderId = (int) $order->id;
        $this->resetQueueRow($orderId);

        $context = Context::getContext();
        $context->cart = new Cart((int) $order->id_cart);
        $context->customer = new Customer((int) $order->id_customer);

        DropdayHttp::fake(function () {
            return DropdayHttp::response(
                ['errors' => ['external_id' => ['Order with same external ID already exists.']]],
                422
            );
        });

        $params = [
            'id_order' => $orderId,
            'newOrderStatus' => new OrderState((int) Configuration::get('PS_OS_PAYMENT')),
        ];
        $idModule = (int) Module::getModuleIdByName('dropday');

        // First trigger: Dropday permanently rejects it.
        Hook::exec('actionOrderStatusUpdate', $params, $idModule);
        $this->assertStatus($orderId, DropdayOrder::STATUS_FAILED, 'after the initial failure');

        // Second, unrelated trigger on the same order (standing in for e.g.
        // a later "shipped" status change) must not resurrect the failed
        // row back into "pending".
        Hook::exec('actionOrderStatusUpdate', $params, $idModule);
        $this->assertStatus($orderId, DropdayOrder::STATUS_FAILED, 'after an unrelated status change');

        if (count(DropdayHttp::recorded()) !== 1) {
            $this->fail('Expected no additional HTTP request on the second trigger, got ' . count(DropdayHttp::recorded()) . ' total');
        }

        echo "OK: failed order #{$orderId} stayed failed after an unrelated status change\n";
    }

    private function bootPrestaShop()
    {
        if (!defined('_PS_ADMIN_DIR_')) {
            define('_PS_ADMIN_DIR_', dirname(__FILE__));
        }

        require_once '/var/www/html/config/config.inc.php';

        // PS 9+: AppKernel is abstract; use AdminKernel. PS 8: AppKernel is concrete.
        global $kernel;
        if (!is_object($kernel)) {
            if (class_exists('AdminKernel')) {
                $kernel = new AdminKernel('dev', true);
            } elseif (class_exists('AppKernel')) {
                $kernel = new AppKernel('dev', true);
            }

            if (isset($kernel) && is_object($kernel)) {
                $kernel->boot();
            }
        }

        $context = Context::getContext();
        $context->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
        $context->language = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        $context->shop = new Shop((int) Configuration::get('PS_SHOP_DEFAULT'));
        $context->country = new Country((int) Configuration::get('PS_COUNTRY_DEFAULT'));

        $employees = Employee::getEmployees();
        if (!empty($employees[0]['id_employee'])) {
            $context->employee = new Employee((int) $employees[0]['id_employee']);
        }

        Shop::setContext(Shop::CONTEXT_SHOP, (int) $context->shop->id);

        require_once _PS_MODULE_DIR_ . 'dropday/classes/Http.php';
        require_once _PS_MODULE_DIR_ . 'dropday/classes/DropdayOrder.php';
    }

    private function installDropdayWithEmptySettings()
    {
        $module = Module::getInstanceByName('dropday');
        // Uninstalled modules have no id — do not use Validate::isLoadedObject() here.
        if (!$module instanceof Module) {
            $this->fail('Dropday module not found on disk at ' . _PS_MODULE_DIR_ . 'dropday');
        }
        if (!Module::isInstalled('dropday') && !$module->install()) {
            $this->fail('Failed to install dropday');
        }
        if (!Module::isEnabled('dropday')) {
            $module->enable();
        }

        Configuration::deleteByName('DROPDAY_ACCOUNT_ID');
        Configuration::deleteByName('DROPDAY_ACCOUNT_APIKEY');
        Configuration::deleteByName('DROPDAY_ORDER_STATUSES');
        Configuration::updateValue('DROPDAY_LIVE_MODE', false);
    }

    /**
     * @return Order
     */
    private function firstOrder()
    {
        $orderId = (int) Db::getInstance()->getValue(
            'SELECT `id_order` FROM `' . _DB_PREFIX_ . 'orders` ORDER BY `id_order` ASC'
        );
        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            $this->fail('First order not found');
        }

        return $order;
    }

    /**
     * Other CI tests may have already queued/sent this same demo order -
     * start from a clean slate so this test is independent of run order.
     *
     * @param int $orderId
     */
    private function resetQueueRow($orderId)
    {
        Db::getInstance()->execute(
            'DELETE FROM `' . DropdayOrder::tableName() . '` WHERE `id_order` = ' . (int) $orderId
        );
    }

    /**
     * @param int $orderId
     * @param string $expectedStatus
     * @param string $when
     */
    private function assertStatus($orderId, $expectedStatus, $when)
    {
        $queueOrder = new DropdayOrder($orderId);
        if (!Validate::isLoadedObject($queueOrder)) {
            $this->fail('No queue row found for order #' . $orderId);
        }

        if ($queueOrder->status !== $expectedStatus) {
            $this->fail('Expected queue status "' . $expectedStatus . '" ' . $when . ', got "' . $queueOrder->status . '"');
        }
    }

    /**
     * @param string $message
     */
    private function fail($message)
    {
        echo $message . "\n";
        exit(1);
    }
}

$test = new SendOrderToDropdayKeepsFailedOrderFailedTest();
$test->run();
exit(0);
