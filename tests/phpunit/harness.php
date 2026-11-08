<?php

if (!defined('_STATSCHECKUP_TEST_ROOT')) {
    define('_STATSCHECKUP_TEST_ROOT', dirname(dirname(__DIR__)));
}

function pSQL($string)
{
    return str_replace(array('\\', "\0", "\n", "\r", "'", '"', "\x1a"), array(
        '\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z',
    ), $string);
}

function bqSQL($string)
{
    return str_replace('`', '\\`', $string);
}

class StatscheckupHarnessDb
{
  /** @var StatscheckupHarnessDb */
    private static $instance;

  /** @var PDO */
    private $pdo;

    private function __construct()
    {
        $dsn = getenv('STATSCHECKUP_DSN');
        $user = getenv('STATSCHECKUP_USER');
        $pass = getenv('STATSCHECKUP_PASSWORD');
        if (!$dsn || $user === false || $pass === false) {
            throw new RuntimeException('Missing STATSCHECKUP_DSN, STATSCHECKUP_USER, or STATSCHECKUP_PASSWORD');
        }
        $this->pdo = new PDO($dsn, $user, $pass, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ));
        $this->pdo->exec("SET NAMES utf8mb4");
        $this->pdo->exec("SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    }

    public static function resetInstance()
    {
        self::$instance = null;
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function getPdo()
    {
        return $this->pdo;
    }

    public function executeS($sql)
    {
        try {
            $stmt = $this->pdo->query($sql);
            if ($stmt === false) {
                throw new RuntimeException('Query failed');
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($rows === false) {
                return array();
            }

            return $rows;
        } catch (PDOException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }
}

class Db
{
    public static function getInstance($slave = false)
    {
        return StatscheckupHarnessDb::getInstance();
    }
}

class Configuration
{
    public static function get($name)
    {
        $pdo = StatscheckupHarnessDb::getInstance()->getPdo();
        $stmt = $pdo->prepare('SELECT value FROM ps_configuration WHERE name = ?');
        $stmt->execute(array($name));
        $value = $stmt->fetchColumn();
        if ($value === false) {
            return false;
        }

        return $value;
    }

    public static function updateValue($name, $value)
    {
        $pdo = StatscheckupHarnessDb::getInstance()->getPdo();
        $stmt = $pdo->prepare('REPLACE INTO ps_configuration (name, value) VALUES (?, ?)');
        $stmt->execute(array($name, (string) $value));
    }
}

class Shop
{
    const SHARE_ORDER = 'share_order';
    const SHARE_STOCK = 'share_stock';

  /** @var int */
    private static $context_id_shop = 1;

    public static function resetContext()
    {
        self::$context_id_shop = 1;
    }

    public static function getContextShopID($null_value_without_multishop = false)
    {
        return self::$context_id_shop;
    }

    public static function addSqlRestriction($share = false, $alias = null)
    {
        if ($alias) {
            $alias .= '.';
        }

        return ' AND ' . $alias . 'id_shop IN (1) ';
    }

    public static function addSqlAssociation($table, $alias, $inner_join = true, $on = null, $force_not_default = false)
    {
        $table_alias = $table . '_shop';
        if (strpos($table, '.') !== false) {
            list($table_alias, $table) = explode('.', $table);
        }

        $sql = (($inner_join) ? ' INNER' : ' LEFT') . ' JOIN ' . _DB_PREFIX_ . $table . '_shop ' . $table_alias
            . ' ON (' . $table_alias . '.id_' . $table . ' = ' . $alias . '.id_' . $table
            . ' AND ' . $table_alias . '.id_shop = ' . (int) self::$context_id_shop . ')';

        return $sql;
    }

    public static function addSqlRestrictionOnLang($alias = null, $id_shop = null)
    {
        if ($id_shop === null) {
            $id_shop = (int) self::$context_id_shop;
        }

        return ' AND ' . (($alias) ? $alias . '.' : '') . 'id_shop = ' . (int) $id_shop . ' ';
    }
}

class Combination
{
    public static function isFeatureActive()
    {
        return false;
    }
}

class Product
{
    public static function sqlStock($product_alias, $product_attribute = null, $inner_join = false, $shop = null)
    {
        $id_shop = ($shop !== null ? (int) $shop->id : null);
        if ($id_shop === null) {
            $id_shop = (int) Shop::getContextShopID();
        }

        $sql = (($inner_join) ? ' INNER ' : ' LEFT ')
            . 'JOIN ' . _DB_PREFIX_ . 'stock_available stock
            ON (stock.id_product = `' . bqSQL($product_alias) . '`.id_product';

        if ($product_attribute !== null) {
            if (!Combination::isFeatureActive()) {
                $sql .= ' AND stock.id_product_attribute = 0';
            } elseif (is_numeric($product_attribute)) {
                $sql .= ' AND stock.id_product_attribute = ' . (int) $product_attribute;
            }
        }

        $sql .= ' AND stock.id_shop = ' . (int) $id_shop . ' AND stock.id_shop_group = 0 )';

        return $sql;
    }
}

class ModuleGraph
{
    public static function getDateBetween($employee = null)
    {
        if ($employee === null) {
            $employee = Context::getContext()->employee;
        }

        return ' \'' . pSQL($employee->stats_date_from) . ' 00:00:00\' AND \'' . pSQL($employee->stats_date_to) . ' 23:59:59\' ';
    }
}

class Tools
{
    public static function isSubmit($submit)
    {
        return (
            isset($_POST[$submit]) || isset($_POST[$submit . '_x']) || isset($_POST[$submit . '_y'])
            || isset($_GET[$submit]) || isset($_GET[$submit . '_x']) || isset($_GET[$submit . '_y'])
        );
    }

    public static function getValue($key, $default_value = false)
    {
        if (!isset($key) || empty($key) || !is_string($key)) {
            return false;
        }

        $value = (isset($_POST[$key]) ? $_POST[$key] : (isset($_GET[$key]) ? $_GET[$key] : $default_value));

        if (is_string($value)) {
            return stripslashes(urldecode(preg_replace('/((\%5C0+)|(\%00+))/i', '', urlencode($value))));
        }

        return $value;
    }

    public static function safeOutput($string, $html = false)
    {
        if (!$html) {
            $string = strip_tags($string);
        }

        return self::htmlentitiesUTF8($string, ENT_QUOTES);
    }

    public static function htmlentitiesUTF8($string, $type = ENT_QUOTES)
    {
        if (is_array($string)) {
            return array_map(array('Tools', 'htmlentitiesUTF8'), $string);
        }

        return htmlentities((string) $string, $type, 'utf-8');
    }

    public static function strlen($str, $encoding = 'UTF-8')
    {
        if (is_array($str)) {
            return false;
        }
        $str = html_entity_decode($str, ENT_COMPAT, 'UTF-8');
        if (function_exists('mb_strlen')) {
            return mb_strlen($str, $encoding);
        }

        return strlen($str);
    }

    public static function substr($str, $start, $length = false, $encoding = 'utf-8')
    {
        if (is_array($str)) {
            return false;
        }
        if (function_exists('mb_substr')) {
            return mb_substr($str, (int) $start, ($length === false ? Tools::strlen($str) : (int) $length), $encoding);
        }

        return substr($str, $start, ($length === false ? Tools::strlen($str) : (int) $length));
    }

    public static function strtoupper($str)
    {
        if (is_array($str)) {
            return false;
        }
        if (function_exists('mb_strtoupper')) {
            return mb_strtoupper($str, 'utf-8');
        }

        return strtoupper($str);
    }
}

class Link
{
    public function getAdminLink($controller, $withToken = true, $sfRouteParams = array(), $params = array())
    {
        $query = array_merge(array('controller' => $controller), $params);

        return 'index.php?' . http_build_query($query);
    }
}

class Employee
{
    public $id = 1;
    public $stats_date_from = '2016-06-15';
    public $stats_date_to = '2016-06-15';
}

class Context
{
  /** @var Context */
    private static $instance;

    public $shop;
    public $language;
    public $employee;
    public $link;
    public $cookie;

    public static function resetContext()
    {
        self::$instance = null;
    }

    public static function getContext()
    {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->shop = (object) array('id' => 1);
            self::$instance->language = (object) array('id' => 1);
            self::$instance->employee = new Employee();
            self::$instance->link = new Link();
            self::$instance->cookie = new stdClass();
        }

        return self::$instance;
    }
}

class Module
{
    public $name;
    public $tab;
    public $version;
    public $author;
    public $need_instance;
    public $displayName;
    public $description;
    public $ps_versions_compliancy;
    public $context;
    public $id;

    public function __construct()
    {
        $this->context = Context::getContext();
    }

    public function trans($string)
    {
        return $string;
    }

    public function install()
    {
        $pdo = StatscheckupHarnessDb::getInstance()->getPdo();
        $stmt = $pdo->prepare('INSERT INTO ps_module (name, active) VALUES (?, 1)');
        $stmt->execute(array($this->name));
        $this->id = (int) $pdo->lastInsertId();

        return true;
    }

    public function registerHook($hookName)
    {
        $pdo = StatscheckupHarnessDb::getInstance()->getPdo();
        $stmt = $pdo->prepare('SELECT id_hook FROM ps_hook WHERE name = ?');
        $stmt->execute(array($hookName));
        $idHook = $stmt->fetchColumn();
        if ($idHook === false) {
            $pdo->prepare('INSERT INTO ps_hook (name) VALUES (?)')->execute(array($hookName));
            $idHook = (int) $pdo->lastInsertId();
        }
        if (!$this->id) {
            $stmt = $pdo->prepare('SELECT id_module FROM ps_module WHERE name = ?');
            $stmt->execute(array($this->name));
            $this->id = (int) $stmt->fetchColumn();
        }
        $pdo->prepare('INSERT INTO ps_hook_module (id_hook, id_module, id_shop) VALUES (?, ?, 1)')
            ->execute(array((int) $idHook, (int) $this->id));

        return true;
    }

    public function unregisterHook($hookName)
    {
        $pdo = StatscheckupHarnessDb::getInstance()->getPdo();
        $stmt = $pdo->prepare(
            'DELETE hm FROM ps_hook_module hm
            INNER JOIN ps_hook h ON h.id_hook = hm.id_hook
            INNER JOIN ps_module m ON m.id_module = hm.id_module
            WHERE h.name = ? AND m.name = ?'
        );
        $stmt->execute(array($hookName, $this->name));

        return true;
    }
}

function statscheckup_test_pdo()
{
    return StatscheckupHarnessDb::getInstance()->getPdo();
}

function statscheckup_install_schema()
{
    $schema = file_get_contents(__DIR__ . '/schema.sql');
  /** @var PDO $pdo */
    $pdo = statscheckup_test_pdo();
    foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function statscheckup_reset_static_state()
{
    Context::resetContext();
    StatscheckupHarnessDb::resetInstance();
    Shop::resetContext();
    $_GET = array();
    $_POST = array();
}

function statscheckup_truncate_all()
{
    $tables = array(
        'ps_hook_module',
        'ps_hook',
        'ps_module',
        'ps_configuration',
        'ps_order_detail',
        'ps_orders',
        'ps_stock_available',
        'ps_image_shop',
        'ps_image',
        'ps_product_lang',
        'ps_product_shop',
        'ps_product',
        'ps_lang_shop',
        'ps_lang',
    );
    $pdo = statscheckup_test_pdo();
    foreach ($tables as $table) {
        $pdo->exec('TRUNCATE TABLE ' . $table);
    }
}

function statscheckup_seed_language_en()
{
    $pdo = statscheckup_test_pdo();
    $pdo->exec("INSERT INTO ps_lang (id_lang, iso_code, name, active) VALUES (1, 'en', 'English', 1)");
    $pdo->exec('INSERT INTO ps_lang_shop (id_lang, id_shop) VALUES (1, 1)');
}

function statscheckup_seed_language_fr()
{
    $pdo = statscheckup_test_pdo();
    $pdo->exec("INSERT INTO ps_lang (id_lang, iso_code, name, active) VALUES (2, 'fr', 'French', 1)");
    $pdo->exec('INSERT INTO ps_lang_shop (id_lang, id_shop) VALUES (2, 1)');
}

function statscheckup_insert_product($idProduct, $name, $description, $active = 1, $idLang = 1, $idShop = 1)
{
    $pdo = statscheckup_test_pdo();
    $pdo->prepare('INSERT INTO ps_product (id_product) VALUES (?)')->execute(array($idProduct));
    $pdo->prepare('INSERT INTO ps_product_shop (id_product, id_shop, active) VALUES (?, ?, ?)')
        ->execute(array($idProduct, $idShop, $active));
    if ($description === null) {
        $pdo->prepare('INSERT INTO ps_product_lang (id_product, id_lang, id_shop, name, description) VALUES (?, ?, ?, ?, NULL)')
            ->execute(array($idProduct, $idLang, $idShop, $name));
    } else {
        $pdo->prepare('INSERT INTO ps_product_lang (id_product, id_lang, id_shop, name, description) VALUES (?, ?, ?, ?, ?)')
            ->execute(array($idProduct, $idLang, $idShop, $name, $description));
    }
}

function statscheckup_insert_stock($idProduct, $quantity, $idShop = 1)
{
    $pdo = statscheckup_test_pdo();
    $pdo->prepare(
        'INSERT INTO ps_stock_available (id_product, id_product_attribute, id_shop, id_shop_group, quantity)
        VALUES (?, 0, ?, 0, ?)'
    )->execute(array($idProduct, $idShop, $quantity));
}

function statscheckup_insert_images($idProduct, $count, $idShop = 1)
{
    $pdo = statscheckup_test_pdo();
    for ($i = 0; $i < $count; ++$i) {
        $pdo->prepare('INSERT INTO ps_image (id_product) VALUES (?)')->execute(array($idProduct));
        $idImage = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO ps_image_shop (id_image, id_shop, id_product) VALUES (?, ?, ?)')
            ->execute(array($idImage, $idShop, $idProduct));
    }
}

function statscheckup_insert_order_line($idProduct, $quantity, $invoiceDate, $idShop = 1)
{
    $pdo = statscheckup_test_pdo();
    $pdo->prepare('INSERT INTO ps_orders (id_shop, invoice_date) VALUES (?, ?)')
        ->execute(array($idShop, $invoiceDate));
    $idOrder = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO ps_order_detail (id_order, product_id, product_quantity) VALUES (?, ?, ?)')
        ->execute(array($idOrder, $idProduct, (int) $quantity));
}

function statscheckup_run_hook($module)
{
    ob_start();
    $html = $module->hookDisplayAdminStatsModules();
    $buffer = ob_get_clean();

    return array($html, $buffer);
}
