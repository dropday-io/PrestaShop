<?php
/**
 * Bootstrap bestand voor PHPUnit tests
 */

// Bootstrapping PrestaShop
if (!defined('_PS_ROOT_DIR_')) {
    define('_PS_ROOT_DIR_', realpath(__DIR__ . '/../../..'));
}

// Include PrestaShop configuration
if (file_exists(_PS_ROOT_DIR_ . '/config/config.inc.php')) {
    require_once(_PS_ROOT_DIR_ . '/config/config.inc.php');
} else {
    // Mock for running tests outside of PrestaShop environment
    class Configuration {
        public static function get($key) {
            return null;
        }
        
        public static function updateValue($key, $value) {
            return true;
        }
    }
    
    class Context {
        public static function getContext() {
            return new self();
        }
    }
    
    class Logger {
        public static function addLog($message, $severity = 1, $errorCode = null, $objectType = null, $objectId = null, $allowDuplicate = false, $idEmployee = null) {
            return true;
        }
    }
}

// Autoload composer dependencies
require_once __DIR__ . '/../vendor/autoload.php'; 