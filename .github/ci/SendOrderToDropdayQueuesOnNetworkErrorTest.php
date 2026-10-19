<?php
/**
 * CI smoke test: when Dropday is unreachable (empty reply / curl error),
 * the order stays queued as pending for a later retry - it must not be
 * dropped or marked as permanently failed.
 *
 * Assumes PrestaShop Docker auto-install already ran (shop up, demo orders present).
 */

class SendOrderToDropdayQueuesOnNetworkErrorTest
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
            return ['status' => 0, 'body' => '', 'error' => 'Empty reply from server'];
        });

        Hook::exec('actionOrderStatusUpdate', [
            'id_order' => $orderId,
            'newOrderStatus' => new OrderState((int) Configuration::get('PS_OS_PAYMENT')),
        ], (int) Module::getModuleIdByName('dropday'));

        $this->assertStillQueuedForRetry($orderId);
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
     */
    private function assertStillQueuedForRetry($orderId)
    {
        $recorded = DropdayHttp::recorded();
        if (count($recorded) !== 1) {
            $this->fail('Expected exactly one HTTP attempt, got ' . count($recorded));
        }

        $queueOrder = new DropdayOrder($orderId);
        if (!Validate::isLoadedObject($queueOrder)) {
            $this->fail('No queue row found for order #' . $orderId);
        }

        if ($queueOrder->status !== DropdayOrder::STATUS_PENDING) {
            $this->fail('Expected queue status to stay "pending" for retry, got "' . $queueOrder->status . '"');
        }

        if ((int) $queueOrder->attempts !== 1) {
            $this->fail('Expected 1 attempt recorded, got ' . (int) $queueOrder->attempts);
        }

        if (empty($queueOrder->last_error) || strpos($queueOrder->last_error, 'Empty reply from server') === false) {
            $this->fail('Expected last_error to mention the network error, got: ' . $queueOrder->last_error);
        }

        echo "OK: order #{$orderId} stayed queued (pending) for retry after a network error\n";
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

$test = new SendOrderToDropdayQueuesOnNetworkErrorTest();
$test->run();
exit(0);
