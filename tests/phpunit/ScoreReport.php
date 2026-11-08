<?php

class ScoreReport
{
    /** @var DOMXPath */
    private $xpath;

    /** @var array */
    private $bodyHeaderIndex = array();

    /** @var array */
    private $footerHeaderIndex = array();

    public function __construct($html)
    {
        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        $this->xpath = new DOMXPath($document);
        $this->mapBodyHeaders();
        $this->mapFooterHeaders();
    }

    private function mapBodyHeaders()
    {
        $nodes = $this->xpath->query('//table[contains(@class,"checkup2")]/thead/tr[1]/th');
        $index = 0;
        foreach ($nodes as $node) {
            $label = $this->normalizeText($node->textContent);
            if ($label !== '') {
                $this->bodyHeaderIndex[$label] = $index;
            }
            ++$index;
        }
    }

    private function mapFooterHeaders()
    {
        $nodes = $this->xpath->query('//table[contains(@class,"checkup2")]/tfoot/tr[1]/th');
        $index = 0;
        foreach ($nodes as $node) {
            $label = $this->normalizeText($node->textContent);
            if ($label !== '') {
                $this->footerHeaderIndex[$label] = $index;
            }
            ++$index;
        }
    }

  /** @return int[] */
    public function getBodyRowIds()
    {
        $rows = $this->xpath->query('//table[contains(@class,"checkup2")]/tbody/tr');
        $ids = array();
        foreach ($rows as $row) {
            $cell = $row->getElementsByTagName('td')->item(0);
            if ($cell) {
                $ids[] = (int) trim($cell->textContent);
            }
        }

        return $ids;
    }

    public function getBodyCellHtml($productId, $headerLabel)
    {
        $rows = $this->xpath->query('//table[contains(@class,"checkup2")]/tbody/tr');
        foreach ($rows as $row) {
            $cells = $row->getElementsByTagName('td');
            if (!$cells->length) {
                continue;
            }
            if ((int) trim($cells->item(0)->textContent) !== (int) $productId) {
                continue;
            }
            $index = $this->bodyHeaderIndex[$headerLabel];

            return $this->innerHtml($cells->item($index));
        }

        throw new InvalidArgumentException('Product row not found: ' . $productId);
    }

    public function getFooterCellHtml($headerLabel)
    {
        $index = $this->footerHeaderIndex[$headerLabel];
        $node = $this->xpath->query('//table[contains(@class,"checkup2")]/tfoot/tr[2]/td')->item($index);
        if (!$node) {
            throw new InvalidArgumentException('Footer column not found: ' . $headerLabel);
        }

        return $this->innerHtml($node);
    }

    public function assertCellColor($html, $expectedBand)
    {
        $map = array(
            0 => array('red.png', 'Bad'),
            1 => array('orange.png', 'Average'),
            2 => array('green.png', 'Good'),
        );
        PHPUnit_Framework_Assert::assertContains($map[$expectedBand][0], $html);
        PHPUnit_Framework_Assert::assertContains('alt="' . $map[$expectedBand][1] . '"', $html);
    }

    public function getLeadingInteger($html)
    {
        if (preg_match('/^\s*(\d+)/', trim(strip_tags($html)), $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function innerHtml(DOMNode $node)
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument->saveHTML($child);
        }

        return $html;
    }

    private function normalizeText($text)
    {
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    public function getRawHtml()
    {
        return $this->xpath->document->saveHTML();
    }

    public function getFormInputValue($name)
    {
        $nodes = $this->xpath->query('//form[contains(@class,"checkup")]//input[@name="' . $name . '"]');
        if (!$nodes->length) {
            return null;
        }

        return $nodes->item(0)->getAttribute('value');
    }

    public function getBodyLinkText($productId)
    {
        $rows = $this->xpath->query('//table[contains(@class,"checkup2")]/tbody/tr');
        foreach ($rows as $row) {
            $cells = $row->getElementsByTagName('td');
            if (!$cells->length || (int) trim($cells->item(0)->textContent) !== (int) $productId) {
                continue;
            }
            $links = $cells->item(1)->getElementsByTagName('a');
            if ($links->length) {
                return $links->item(0)->textContent;
            }
        }

        return null;
    }

    public function optionIsSelected($value)
    {
        $nodes = $this->xpath->query('//select[@name="submitCheckupOrder"]/option[@value="' . $value . '"]');
        if (!$nodes->length) {
            return false;
        }

        return $nodes->item(0)->hasAttribute('selected');
    }

    public function hasResultHeader($label)
    {
        return array_key_exists($label, $this->bodyHeaderIndex);
    }
}
