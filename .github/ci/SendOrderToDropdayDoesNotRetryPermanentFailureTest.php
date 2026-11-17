<?php
/**
 * CI smoke test: a permanent failure (HTTP 422, e.g. duplicate external_id)
 * is marked failed and never automatically retried - a direct second
 * processOrder() call must not re-claim the row or send a second request.
 *
 * Assumes PrestaShop Docker auto-install already ran (shop up, demo orders present).
 */

class SendOrderToDropdayDoesNotRetryPermanentFailureTest
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

        Hook::exec('actionOrderStatusUpdate', [
            'id_order' => $orderId,
            'newOrderStatus' => new OrderState((int) Configuration::get('PS_OS_PAYMENT')),
        ], (int) Module::getModuleIdByName('dropday'));

        $this->assertFailed($orderId);

        // A direct second attempt (e.g. the order ending up in a later
        // batch) must not re-claim or re-send a permanently failed order.
        $module = Module::getInstanceByName('dropday');
        $outcome = $module->processOrder($orderId);

        if ($outcome !== false) {
            $this->fail('Expected a second processOrder() call on a failed order to return false, got: ' . var_export($outcome, true));
        }

        if (count(DropdayHttp::recorded()) !== 1) {
            $this->fail('Expected no additional HTTP request on retry, got ' . count(DropdayHttp::recorded()) . ' total');
        }

        echo "OK: permanently failed order #{$orderId} was not retried\n";
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
    private function assertFailed($orderId)
    {
        $queueOrder = new DropdayOrder($orderId);
        if (!Validate::isLoadedObject($queueOrder)) {
            $this->fail('No queue row found for order #' . $orderId);
        }

        if ($queueOrder->status !== DropdayOrder::STATUS_FAILED) {
            $this->fail('Expected queue status "failed" after a 422, got "' . $queueOrder->status . '"');
        }

        if ((int) $queueOrder->last_http_code !== 422) {
            $this->fail('Expected last_http_code 422, got ' . $queueOrder->last_http_code);
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

$test = new SendOrderToDropdayDoesNotRetryPermanentFailureTest();
$test->run();
exit(0);
