<?php

class StatscheckupScoringTest extends StatscheckupTestCase
{
    protected function setUp()
    {
        parent::setUp();
        $this->seedDefaultsForCatalog();
    }

    public function testImageThresholdBands()
    {
        foreach (array(1 => 0, 2 => 1, 3 => 2, 4 => 3) as $id => $count) {
            statscheckup_insert_product($id, 'P' . $id, str_repeat('x', 200));
            statscheckup_insert_stock($id, 4);
            statscheckup_insert_images($id, $count);
        }
        list($html) = statscheckup_run_hook($this->module);
        $report = new ScoreReport($html);
        $expected = array(1 => 0, 2 => 1, 3 => 1, 4 => 2);
        foreach ($expected as $id => $band) {
            $cell = $report->getBodyCellHtml($id, 'Images');
            $this->assertSame($id - 1, $report->getLeadingInteger($cell));
            $report->assertCellColor($cell, $band);
        }
    }

    public function testImageCountIgnoresImagesOutsideShop()
    {
        statscheckup_insert_product(1, 'Img', str_repeat('x', 200));
        statscheckup_insert_stock(1, 4);
        statscheckup_insert_images(1, 1, 1);
        $pdo = statscheckup_test_pdo();
        for ($i = 0; $i < 4; ++$i) {
            $pdo->prepare('INSERT INTO ps_image (id_product) VALUES (?)')->execute(array(1));
        }
        $pdo->prepare('INSERT INTO ps_image (id_product) VALUES (?)')->execute(array(1));
        $idImage = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO ps_image_shop (id_image, id_shop, id_product) VALUES (?, 2, 1)')
            ->execute(array($idImage));
        list($html) = statscheckup_run_hook($this->module);
        $report = new ScoreReport($html);
        $cell = $report->getBodyCellHtml(1, 'Images');
        $this->assertSame(1, $report->getLeadingInteger($cell));
        $report->assertCellColor($cell, 1);
    }

    public function testStockThresholdBands()
    {
        foreach (array(1 => 0, 2 => 1, 3 => 3, 4 => 4) as $id => $qty) {
            statscheckup_insert_product($id, 'S' . $id, str_repeat('x', 200));
            statscheckup_insert_stock($id, $qty);
            statscheckup_insert_images($id, 3);
        }
        list($html) = statscheckup_run_hook($this->module);
        $report = new ScoreReport($html);
        $expected = array(1 => 0, 2 => 1, 3 => 1, 4 => 2);
        foreach ($expected as $id => $band) {
            $cell = $report->getBodyCellHtml($id, 'Available quantity for sale');
            $report->assertCellColor($cell, $band);
        }
    }

    public function testStockJoinIsRestrictedToCurrentShop()
    {
        statscheckup_insert_product(1, 'StockShop', str_repeat('x', 200));
        statscheckup_insert_stock(1, 4, 1);
        statscheckup_insert_stock(1, 0, 2);
        statscheckup_insert_images(1, 3);
        list($html) = statscheckup_run_hook($this->module);
        $report = new ScoreReport($html);
        $this->assertSame(array(1), $report->getBodyRowIds());
        $cell = $report->getBodyCellHtml(1, 'Available quantity for sale');
        $this->assertSame(4, $report->getLeadingInteger($cell));
        $report->assertCellColor($cell, 2);
    }

    public function testActiveFlagMapsToGreenOrRedOnly()
    {
        statscheckup_insert_product(1, 'On', str_repeat('x', 200), 1);
        statscheckup_insert_product(2, 'Off', str_repeat('x', 200), 0);
        statscheckup_insert_stock(1, 4);
        statscheckup_insert_stock(2, 4);
        list($html) = statscheckup_run_hook($this->module);
        $report = new ScoreReport($html);
        $report->assertCellColor($report->getBodyCellHtml(1, 'Active'), 2);
        $report->assertCellColor($report->getBodyCellHtml(2, 'Active'), 0);
    }

    public function testSalesUseDateWindowShopAndMonthlyRate()
    {
        $this->seedHealthyProduct(1, array('sales' => 40));
        $this->seedHealthyProduct(2, array('sales' => 10));
        $this->seedHealthyProduct(3, array('sales' => 90));
        statscheckup_insert_product(4, 'OutWindow', str_repeat('x', 200));
        statscheckup_insert_stock(4, 4);
        statscheckup_insert_images(4, 3);
        statscheckup_insert_order_line(4, 40, '2016-06-14 12:00:00');
        statscheckup_insert_product(5, 'OtherShop', str_repeat('x', 200));
        statscheckup_insert_stock(5, 4);
        statscheckup_insert_images(5, 3);
        statscheckup_insert_order_line(5, 40, '2016-06-15 12:00:00', 2);
        list($html) = statscheckup_run_hook($this->module);
        $report = new ScoreReport($html);
        $report->assertCellColor($report->getBodyCellHtml(1, 'Sales'), 1);
        $report->assertCellColor($report->getBodyCellHtml(2, 'Sales'), 0);
        $report->assertCellColor($report->getBodyCellHtml(3, 'Sales'), 2);
        $report->assertCellColor($report->getBodyCellHtml(4, 'Sales'), 0);
        $this->assertSame(0, $report->getLeadingInteger($report->getBodyCellHtml(4, 'Sales')));
        $report->assertCellColor($report->getBodyCellHtml(5, 'Sales'), 0);
        $this->assertSame(0, $report->getLeadingInteger($report->getBodyCellHtml(5, 'Sales')));
    }

    public function testDescriptionLengthStripsTagsAndUsesCharacters()
    {
        $cases = array(
            1 => array('text' => str_repeat('x', 99), 'len' => 99, 'band' => 0),
            2 => array('text' => str_repeat('x', 100), 'len' => 100, 'band' => 1),
            3 => array('text' => str_repeat('x', 400), 'len' => 400, 'band' => 1),
            4 => array('text' => str_repeat('x', 401), 'len' => 401, 'band' => 2),
            5 => array(
                'text' => str_repeat('<span class="abcdefghij">', 30) . str_repeat('x', 50) . str_repeat('</span>', 30),
                'len' => 50,
                'band' => 0,
            ),
            6 => array('text' => str_repeat('é', 250), 'len' => 250, 'band' => 1),
        );
        foreach ($cases as $id => $case) {
            statscheckup_insert_product($id, 'D' . $id, $case['text']);
            statscheckup_insert_stock($id, 4);
            statscheckup_insert_images($id, 3);
        }
        list($html) = statscheckup_run_hook($this->module);
        $report = new ScoreReport($html);
        foreach ($cases as $id => $case) {
            $cell = $report->getBodyCellHtml($id, 'Desc. (EN)');
            $this->assertSame($case['len'], $report->getLeadingInteger($cell));
            $report->assertCellColor($cell, $case['band']);
        }
    }

    public function testNullDescriptionDoesNotWarnAndScoresZero()
    {
        statscheckup_insert_product(1, 'NullDesc', null);
        statscheckup_insert_stock(1, 4);
        statscheckup_insert_images(1, 3);
        list($html) = statscheckup_run_hook($this->module);
        $this->assertInternalType('string', $html);
        $this->assertContains('1', $html);
        $report = new ScoreReport($html);
        $cell = $report->getBodyCellHtml(1, 'Desc. (EN)');
        $this->assertSame(0, $report->getLeadingInteger($cell));
        $report->assertCellColor($cell, 0);
    }

    public function testMissingLanguageRowShowsZeroRed()
    {
        statscheckup_seed_language_fr();
        statscheckup_insert_product(1, 'EnOnly', str_repeat('x', 450));
        statscheckup_insert_stock(1, 4);
        statscheckup_insert_images(1, 3);
        list($html) = statscheckup_run_hook($this->module);
        $report = new ScoreReport($html);
        $cell = $report->getBodyCellHtml(1, 'Desc. (FR)');
        $this->assertSame(0, $report->getLeadingInteger($cell));
        $report->assertCellColor($cell, 0);
    }

    public function testProductWithoutShopAssociationIsExcluded()
    {
        $pdo = statscheckup_test_pdo();
        $pdo->prepare('INSERT INTO ps_product (id_product) VALUES (7)')->execute();
        statscheckup_insert_product(8, 'Visible', str_repeat('x', 200));
        statscheckup_insert_stock(8, 4);
        list($html) = statscheckup_run_hook($this->module);
        $report = new ScoreReport($html);
        $this->assertSame(array(8), $report->getBodyRowIds());
    }

    public function testRowGlobalBand()
    {
        statscheckup_insert_product(1, 'RowGlobal', str_repeat('x', 50));
        statscheckup_insert_stock(1, 4);
        statscheckup_insert_images(1, 3);
        statscheckup_insert_order_line(1, 40, '2016-06-15 12:00:00');
        list($html) = statscheckup_run_hook($this->module);
        $report = new ScoreReport($html);
        $report->assertCellColor($report->getBodyCellHtml(1, 'Global'), 1);
    }

    public function testFooterGlobalExcludesProductCount()
    {
        statscheckup_insert_product(1, 'Footer', str_repeat('x', 450));
        statscheckup_insert_stock(1, 0);
        statscheckup_insert_images(1, 0);
        list($html) = statscheckup_run_hook($this->module);
        $report = new ScoreReport($html);
        $report->assertCellColor($report->getFooterCellHtml('Global'), 0);
        $report->assertCellColor($report->getFooterCellHtml('Active'), 2);
        $report->assertCellColor($report->getFooterCellHtml('Images'), 0);
        $report->assertCellColor($report->getFooterCellHtml('Sales'), 0);
        $report->assertCellColor($report->getFooterCellHtml('Available quantity for sale'), 0);
        $report->assertCellColor($report->getFooterCellHtml('Desc. (EN)'), 2);
    }

    public function testFooterGlobalAllGoodStaysGreen()
    {
        $this->seedHealthyProduct(1, array('sales' => 90, 'description' => str_repeat('x', 450)));
        list($html) = statscheckup_run_hook($this->module);
        $report = new ScoreReport($html);
        $report->assertCellColor($report->getFooterCellHtml('Global'), 2);
    }

    public function testFooterImagesMeanRebucketsAtOne()
    {
        statscheckup_insert_product(1, 'NoImg', str_repeat('x', 200));
        statscheckup_insert_product(2, 'ManyImg', str_repeat('x', 200));
        statscheckup_insert_stock(1, 4);
        statscheckup_insert_stock(2, 4);
        statscheckup_insert_images(2, 3);
        list($html) = statscheckup_run_hook($this->module);
        $report = new ScoreReport($html);
        $report->assertCellColor($report->getFooterCellHtml('Images'), 1);
    }
}
