<?php

class StatscheckupTestCase extends PHPUnit_Framework_TestCase
{
    /** @var statscheckup */
    protected $module;

    protected function setUp()
    {
        statscheckup_reset_static_state();
        StatscheckupHarnessDb::getInstance();
        statscheckup_truncate_all();
        statscheckup_seed_language_en();
        $this->resetEmployeeDates();
        $this->module = new statscheckup();
    }

    protected function tearDown()
    {
        statscheckup_reset_static_state();
    }

    protected function resetEmployeeDates()
    {
        $employee = Context::getContext()->employee;
        $employee->stats_date_from = '2016-06-15';
        $employee->stats_date_to = '2016-06-15';
    }

    protected function seedDefaultsForCatalog()
    {
        $defaults = array(
            'CHECKUP_DESCRIPTIONS_LT' => 100,
            'CHECKUP_DESCRIPTIONS_GT' => 400,
            'CHECKUP_IMAGES_LT' => 1,
            'CHECKUP_IMAGES_GT' => 2,
            'CHECKUP_SALES_LT' => 1,
            'CHECKUP_SALES_GT' => 2,
            'CHECKUP_STOCK_LT' => 1,
            'CHECKUP_STOCK_GT' => 3,
        );
        foreach ($defaults as $name => $value) {
            Configuration::updateValue($name, $value);
        }
    }

    protected function seedHealthyProduct($idProduct, $overrides = array())
    {
        $name = isset($overrides['name']) ? $overrides['name'] : 'Product ' . $idProduct;
        $description = isset($overrides['description']) ? $overrides['description'] : str_repeat('x', 450);
        $active = isset($overrides['active']) ? $overrides['active'] : 1;
        statscheckup_insert_product($idProduct, $name, $description, $active);
        statscheckup_insert_stock($idProduct, isset($overrides['stock']) ? $overrides['stock'] : 4);
        statscheckup_insert_images($idProduct, isset($overrides['images']) ? $overrides['images'] : 3);
        if (isset($overrides['sales'])) {
            statscheckup_insert_order_line(
                $idProduct,
                $overrides['sales'],
                isset($overrides['invoice_date']) ? $overrides['invoice_date'] : '2016-06-15 12:00:00'
            );
        }
    }

    protected function parseAdminLink($url)
    {
        $queryString = parse_url($url, PHP_URL_QUERY);
        parse_str($queryString, $params);

        return $params;
    }
}
