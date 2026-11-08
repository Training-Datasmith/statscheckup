<?php

class StatscheckupInstallTest extends StatscheckupTestCase
{
    public function testInstallWritesDefaultThresholds()
    {
        $this->assertSame(true, $this->module->install());
        $pdo = statscheckup_test_pdo();
        $rows = $pdo->query("SELECT name, value FROM ps_configuration WHERE name LIKE 'CHECKUP\\_%' ORDER BY name")
            ->fetchAll(PDO::FETCH_ASSOC);
        $expected = array(
            'CHECKUP_DESCRIPTIONS_GT' => '400',
            'CHECKUP_DESCRIPTIONS_LT' => '100',
            'CHECKUP_IMAGES_GT' => '2',
            'CHECKUP_IMAGES_LT' => '1',
            'CHECKUP_SALES_GT' => '2',
            'CHECKUP_SALES_LT' => '1',
            'CHECKUP_STOCK_GT' => '3',
            'CHECKUP_STOCK_LT' => '1',
        );
        $this->assertCount(8, $rows);
        foreach ($rows as $row) {
            $this->assertArrayHasKey($row['name'], $expected);
            $this->assertSame($expected[$row['name']], $row['value']);
        }
    }

    public function testInstallPreservesExplicitZeroThreshold()
    {
        $pdo = statscheckup_test_pdo();
        $pdo->prepare('INSERT INTO ps_configuration (name, value) VALUES (?, ?)')
            ->execute(array('CHECKUP_STOCK_LT', '0'));
        $this->module->install();
        $this->assertSame('0', Configuration::get('CHECKUP_STOCK_LT'));
        $this->assertSame('3', Configuration::get('CHECKUP_STOCK_GT'));
    }

    public function testInstallDoesNotOverwriteExistingPositiveThreshold()
    {
        Configuration::updateValue('CHECKUP_IMAGES_GT', 9);
        $this->module->install();
        $this->assertSame('9', Configuration::get('CHECKUP_IMAGES_GT'));
    }

    public function testInstallRegistersDisplayAdminStatsModulesHook()
    {
        $this->module->install();
        $pdo = statscheckup_test_pdo();
        $hooks = $pdo->query(
            'SELECT h.name FROM ps_hook_module hm
            INNER JOIN ps_module m ON m.id_module = hm.id_module
            INNER JOIN ps_hook h ON h.id_hook = hm.id_hook
            WHERE m.name = "statscheckup"'
        )->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(array('displayAdminStatsModules'), $hooks);
    }

    public function testConstructorMetadata()
    {
        $this->assertSame('statscheckup', $this->module->name);
        $this->assertSame('administration', $this->module->tab);
        $this->assertSame('2.0.4', $this->module->version);
        $this->assertSame('PrestaShop', $this->module->author);
        $this->assertSame(0, $this->module->need_instance);
        $this->assertSame('1.7.1.0', $this->module->ps_versions_compliancy['min']);
        $this->assertSame(_PS_VERSION_, $this->module->ps_versions_compliancy['max']);
        $this->assertSame('Catalog evaluation', $this->module->displayName);
        $this->assertSame(
            'Enrich your stats, give your catalog a quick evaluation to better analyze your activity.',
            $this->module->description
        );
        $configXml = file_get_contents(dirname(dirname(__DIR__)) . '/config.xml');
        $this->assertContains('<version><![CDATA[2.0.4]]></version>', $configXml);
    }
}
