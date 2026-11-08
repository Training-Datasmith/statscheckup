<?php

class StatscheckupHookTest extends StatscheckupTestCase
{
    protected function setUp()
    {
        parent::setUp();
        $this->seedDefaultsForCatalog();
    }

    public function testEmptyCatalogReturnsNoProductMessage()
    {
        list($html, $buffer) = statscheckup_run_hook($this->module);
        $this->assertSame('No product was found.', $html);
        $this->assertSame('', $buffer);
    }

    public function testSubmitCheckupPersistsIntegerThresholdsAndEchoesConfirmation()
    {
        statscheckup_insert_product(1, 'Threshold', str_repeat('x', 200));
        statscheckup_insert_stock(1, 4);
        $_POST = array(
            'submitCheckup' => '1',
            'CHECKUP_DESCRIPTIONS_LT' => '150',
            'CHECKUP_DESCRIPTIONS_GT' => '450',
            'CHECKUP_IMAGES_LT' => '2<script>',
            'CHECKUP_IMAGES_GT' => '4',
            'CHECKUP_SALES_LT' => '3',
            'CHECKUP_SALES_GT' => '6',
            'CHECKUP_STOCK_LT' => '4',
            'CHECKUP_STOCK_GT' => '8',
        );
        list($html, $buffer) = statscheckup_run_hook($this->module);
        $this->assertContains('Settings updated', $buffer);
        $this->assertNotContains('<script>', $buffer);
        $pdo = statscheckup_test_pdo();
        $values = $pdo->query("SELECT name, value FROM ps_configuration WHERE name LIKE 'CHECKUP\\_%'")
            ->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame('150', $values['CHECKUP_DESCRIPTIONS_LT']);
        $this->assertSame('450', $values['CHECKUP_DESCRIPTIONS_GT']);
        $this->assertSame('2', $values['CHECKUP_IMAGES_LT']);
        $report = new ScoreReport($html);
        $this->assertSame('2', $report->getFormInputValue('CHECKUP_IMAGES_LT'));
    }

    public function testFormShowsStoredThresholds()
    {
        statscheckup_insert_product(1, 'Threshold', str_repeat('x', 200));
        statscheckup_insert_stock(1, 4);
        Configuration::updateValue('CHECKUP_DESCRIPTIONS_LT', 150);
        list($html) = statscheckup_run_hook($this->module);
        $report = new ScoreReport($html);
        $this->assertSame('150', $report->getFormInputValue('CHECKUP_DESCRIPTIONS_LT'));
    }

    public function testSubmitCheckupOrderStoresIntegerAndSortsBySales()
    {
        $this->seedHealthyProduct(1, array('sales' => 1));
        $this->seedHealthyProduct(2, array('sales' => 5));
        $_POST = array('submitCheckupOrder' => '3');
        list($html, $buffer) = statscheckup_run_hook($this->module);
        $this->assertSame(3, (int) Context::getContext()->cookie->checkup_order);
        $this->assertContains('Settings updated', $buffer);
        $report = new ScoreReport($html);
        $this->assertSame(2, $report->getBodyRowIds()[0]);
    }

    public function testDefaultOrderIsProductId()
    {
        statscheckup_insert_product(20, 'Zulu', str_repeat('x', 200));
        statscheckup_insert_product(10, 'Alpha', str_repeat('x', 200));
        statscheckup_insert_stock(20, 4);
        statscheckup_insert_stock(10, 4);
        list($html) = statscheckup_run_hook($this->module);
        $this->assertSame(1, (int) Context::getContext()->cookie->checkup_order);
        $report = new ScoreReport($html);
        $this->assertSame(10, $report->getBodyRowIds()[0]);
    }

    public function testOrderByName()
    {
        Context::getContext()->cookie->checkup_order = 2;
        statscheckup_insert_product(1, 'Bravo', str_repeat('x', 200));
        statscheckup_insert_product(2, 'Alpha', str_repeat('x', 200));
        statscheckup_insert_stock(1, 4);
        statscheckup_insert_stock(2, 4);
        list($html) = statscheckup_run_hook($this->module);
        $report = new ScoreReport($html);
        $this->assertSame(2, $report->getBodyRowIds()[0]);
        $this->assertTrue($report->optionIsSelected('2'));
        $this->assertFalse($report->optionIsSelected('3'));
    }

    public function testProductNameIsTruncatedTo42Characters()
    {
        $name = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789abcdefghijklmn';
        statscheckup_insert_product(1, $name, str_repeat('x', 200));
        statscheckup_insert_stock(1, 4);
        list($html) = statscheckup_run_hook($this->module);
        $report = new ScoreReport($html);
        $cell = $report->getBodyCellHtml(1, 'Item');
        $this->assertContains('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789abcdef', $cell);
        $this->assertNotContains('abcdefghijklmn', $cell);
    }

    public function testProductNameIsHtmlEscaped()
    {
        $name = "A & B <C> 'D'";
        statscheckup_insert_product(1, $name, str_repeat('x', 200));
        statscheckup_insert_stock(1, 4);
        list($html) = statscheckup_run_hook($this->module);
        $this->assertContains('A &amp; B &lt;C&gt; &#039;D&#039;', $html);
        $this->assertNotContains('<C>', $html);
        $report = new ScoreReport($html);
        $this->assertSame("A & B <C> 'D'", $report->getBodyLinkText(1));
    }

    public function testAdminLinksCarryModuleAndProductIds()
    {
        statscheckup_insert_product(5, 'Linkable', str_repeat('x', 200));
        statscheckup_insert_stock(5, 4);
        list($html) = statscheckup_run_hook($this->module);
        $report = new ScoreReport($html);
        $forms = $this->xpathAttr($html, '//form[contains(@class,"checkup")]/@action');
        $this->assertNotEmpty($forms);
        $params = $this->parseAdminLink($forms[0]);
        $this->assertSame('AdminStats', $params['controller']);
        $this->assertSame('statscheckup', $params['module']);
        $productCell = $report->getBodyCellHtml(5, 'Item');
        if (preg_match('/href="([^"]+)"/', $productCell, $matches)) {
            $linkParams = $this->parseAdminLink(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'));
            $this->assertSame('AdminProducts', $linkParams['controller']);
            $this->assertSame('1', (string) $linkParams['updateproduct']);
            $this->assertSame('5', (string) $linkParams['id_product']);
        } else {
            $this->fail('Product link not found');
        }
    }

    public function testLanguageColumnsUseUppercaseIso()
    {
        statscheckup_seed_language_fr();
        statscheckup_insert_product(1, 'Multi', str_repeat('x', 200));
        statscheckup_insert_stock(1, 4);
        list($html) = statscheckup_run_hook($this->module);
        $report = new ScoreReport($html);
        $this->assertTrue($report->hasResultHeader('Desc. (EN)'));
        $this->assertTrue($report->hasResultHeader('Desc. (FR)'));
    }

    private function xpathAttr($html, $query)
    {
        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        $xpath = new DOMXPath($document);
        $nodes = $xpath->query($query);
        $values = array();
        foreach ($nodes as $node) {
            $values[] = $node->nodeValue;
        }

        return $values;
    }
}
