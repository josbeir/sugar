<?php
declare(strict_types=1);

/**
 * Test suite bootstrap
 */

require dirname(__DIR__) . '/vendor/autoload.php';

// Define fixture path constants
define('SUGAR_TEST_FIXTURES_PATH', __DIR__ . '/fixtures');
define('SUGAR_TEST_TEMPLATES_PATH', SUGAR_TEST_FIXTURES_PATH . '/templates');
define('SUGAR_TEST_TEMPLATE_INHERITANCE_PATH', SUGAR_TEST_TEMPLATES_PATH . '/template-inheritance');
define('SUGAR_TEST_COMPONENTS_PATH', SUGAR_TEST_TEMPLATES_PATH . '/components');
