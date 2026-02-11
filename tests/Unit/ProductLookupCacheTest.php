<?php
/**
 * Tests for ProductLookupCache.
 *
 * @package Trotibike\EwheelImporter\Tests\Unit
 */

namespace Trotibike\EwheelImporter\Tests\Unit;

use Trotibike\EwheelImporter\Sync\ProductLookupCache;
use Trotibike\EwheelImporter\Tests\TestCase;

class ProductLookupCacheTest extends TestCase
{
    public function testFindBySkuReturnsZeroBeforeWarm(): void
    {
        $cache = new ProductLookupCache();
        $this->assertSame(0, $cache->find_by_sku('SKU-001'));
    }

    public function testFindBySkuReturnsZeroForEmptyString(): void
    {
        $cache = new ProductLookupCache();
        $this->assertSame(0, $cache->find_by_sku(''));
    }

    public function testFindByReferenceReturnsZeroForEmptyString(): void
    {
        $cache = new ProductLookupCache();
        $this->assertSame(0, $cache->find_by_reference(''));
    }

    public function testFindByReferenceBaseReturnsZeroForEmptyString(): void
    {
        $cache = new ProductLookupCache();
        $this->assertSame(0, $cache->find_by_reference_base(''));
    }

    public function testRecordAndFindBySku(): void
    {
        $cache = new ProductLookupCache();
        $cache->record(42, 'SKU-001', '', '');

        $this->assertSame(42, $cache->find_by_sku('SKU-001'));
        $this->assertSame(0, $cache->find_by_sku('SKU-999'));
    }

    public function testRecordAndFindByReference(): void
    {
        $cache = new ProductLookupCache();
        $cache->record(42, '', 'REF-001', '');

        $this->assertSame(42, $cache->find_by_reference('REF-001'));
        $this->assertSame(0, $cache->find_by_reference('REF-999'));
    }

    public function testRecordAndFindByReferenceBase(): void
    {
        $cache = new ProductLookupCache();
        $cache->record(42, '', '', 'BASE-001');

        $this->assertSame(42, $cache->find_by_reference_base('BASE-001'));
        $this->assertSame(0, $cache->find_by_reference_base('BASE-999'));
    }

    public function testRecordAllFieldsAtOnce(): void
    {
        $cache = new ProductLookupCache();
        $cache->record(42, 'SKU-001', 'REF-001', 'BASE-001');

        $this->assertSame(42, $cache->find_by_sku('SKU-001'));
        $this->assertSame(42, $cache->find_by_reference('REF-001'));
        $this->assertSame(42, $cache->find_by_reference_base('BASE-001'));
    }

    public function testRecordSkipsEmptyValues(): void
    {
        $cache = new ProductLookupCache();
        $cache->record(42, '', '', '');

        $stats = $cache->get_stats();
        $this->assertSame(0, $stats['sku_count']);
        $this->assertSame(0, $stats['reference_count']);
        $this->assertSame(0, $stats['base_count']);
    }

    public function testRecordOverwritesSkuAndReference(): void
    {
        $cache = new ProductLookupCache();
        $cache->record(10, 'SKU-001', 'REF-001', '');
        $cache->record(20, 'SKU-001', 'REF-001', '');

        // SKU and reference get overwritten (latest product wins)
        $this->assertSame(20, $cache->find_by_sku('SKU-001'));
        $this->assertSame(20, $cache->find_by_reference('REF-001'));
    }

    public function testRecordDoesNotOverwriteExistingBase(): void
    {
        $cache = new ProductLookupCache();
        $cache->record(10, '', '', 'BASE-001');
        $cache->record(20, '', '', 'BASE-001');

        // First product keeps the base mapping (matches LIMIT 1 behavior)
        $this->assertSame(10, $cache->find_by_reference_base('BASE-001'));
    }

    public function testRemoveSku(): void
    {
        $cache = new ProductLookupCache();
        $cache->record(42, 'old-parent', '', '');

        $this->assertSame(42, $cache->find_by_sku('old-parent'));

        $cache->remove_sku('old-parent');

        $this->assertSame(0, $cache->find_by_sku('old-parent'));
    }

    public function testRemoveNonexistentSkuDoesNotError(): void
    {
        $cache = new ProductLookupCache();
        $cache->remove_sku('nonexistent');

        $this->assertSame(0, $cache->find_by_sku('nonexistent'));
    }

    public function testGetStats(): void
    {
        $cache = new ProductLookupCache();
        $cache->record(1, 'S1', 'R1', 'B1');
        $cache->record(2, 'S2', 'R2', 'B2');
        $cache->record(3, '', 'R3', '');

        $stats = $cache->get_stats();

        $this->assertSame(2, $stats['sku_count']);
        $this->assertSame(3, $stats['reference_count']);
        $this->assertSame(2, $stats['base_count']);
    }

    public function testIsLoadedReturnsFalseInitially(): void
    {
        $cache = new ProductLookupCache();
        $this->assertFalse($cache->is_loaded());
    }

    public function testMultipleProductsWithDifferentKeys(): void
    {
        $cache = new ProductLookupCache();
        $cache->record(1, 'EWM140', 'EWM140-parent', 'EWM140');
        $cache->record(2, 'EWM141', 'EWM141-parent', 'EWM141');
        $cache->record(3, 'EWM142', 'EWM142-parent', 'EWM142');

        $this->assertSame(1, $cache->find_by_sku('EWM140'));
        $this->assertSame(2, $cache->find_by_sku('EWM141'));
        $this->assertSame(3, $cache->find_by_sku('EWM142'));
        $this->assertSame(0, $cache->find_by_sku('EWM143'));

        $this->assertSame(1, $cache->find_by_reference('EWM140-parent'));
        $this->assertSame(2, $cache->find_by_reference('EWM141-parent'));
    }
}
