<?php
/**
 * Tests for the ModelService class.
 *
 * @package Trotibike\EwheelImporter\Tests\Unit
 */

namespace Trotibike\EwheelImporter\Tests\Unit;

use Trotibike\EwheelImporter\Tests\TestCase;
use Trotibike\EwheelImporter\Service\ModelService;
use Brain\Monkey\Functions;

/**
 * Test case for ModelService.
 */
class ModelServiceTest extends TestCase
{
    /**
     * Test get_model_names reads from WP option and fills gaps from constant.
     */
    public function test_get_model_names_from_option(): void
    {
        $option_data = [
            '1'   => 'Custom Name One',
            '999' => 'Brand New Scooter',
        ];

        Functions\expect('get_option')
            ->once()
            ->with(ModelService::OPTION_KEY, [])
            ->andReturn($option_data);

        $service = new ModelService();
        $names = $service->get_model_names();

        // Option values should be present
        $this->assertEquals('Custom Name One', $names['1']);
        $this->assertEquals('Brand New Scooter', $names['999']);

        // Constant values not overridden should still appear
        $this->assertEquals('Ninebot ES4', $names['2']);
    }

    /**
     * Test get_model_names falls back to constant when option is empty.
     */
    public function test_get_model_names_fallback_to_constant(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with(ModelService::OPTION_KEY, [])
            ->andReturn([]);

        $service = new ModelService();
        $names = $service->get_model_names();

        $this->assertEquals('Ninebot ES2', $names['1']);
        $this->assertEquals(ModelService::MODEL_NAMES, $names);
    }

    /**
     * Test get_model_names returns non-empty array when option not set.
     */
    public function test_get_model_names_when_option_not_set(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with(ModelService::OPTION_KEY, [])
            ->andReturn([]);

        $service = new ModelService();
        $names = $service->get_model_names();

        $this->assertIsArray($names);
        $this->assertNotEmpty($names);
        $this->assertArrayHasKey('1', $names);
    }

    /**
     * Test option values take precedence over constant.
     */
    public function test_option_takes_precedence_over_constant(): void
    {
        $option_data = [
            '1' => 'Renamed Scooter',
        ];

        Functions\expect('get_option')
            ->once()
            ->with(ModelService::OPTION_KEY, [])
            ->andReturn($option_data);

        $service = new ModelService();
        $names = $service->get_model_names();

        // Option overrides constant
        $this->assertEquals('Renamed Scooter', $names['1']);
        // Non-overridden keys come from constant
        $this->assertEquals('Ninebot ES4', $names['2']);
    }

    /**
     * Test save_model_name persists to WP option.
     */
    public function test_save_model_name(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with(ModelService::OPTION_KEY, [])
            ->andReturn(['1' => 'Ninebot ES2']);

        Functions\expect('update_option')
            ->once()
            ->with(
                ModelService::OPTION_KEY,
                \Mockery::on(function ($value) {
                    return is_array($value)
                        && isset($value['1'])
                        && $value['1'] === 'Ninebot ES2'
                        && isset($value['500'])
                        && $value['500'] === 'New Scooter Model';
                })
            )
            ->andReturn(true);

        $service = new ModelService();
        $result = $service->save_model_name('500', 'New Scooter Model');

        $this->assertTrue($result);
    }

    /**
     * Test save_model_name rejects empty model ID.
     */
    public function test_save_model_name_rejects_empty_id(): void
    {
        $service = new ModelService();
        $result = $service->save_model_name('', 'Some Name');

        $this->assertFalse($result);
    }

    /**
     * Test save_model_name rejects empty name.
     */
    public function test_save_model_name_rejects_empty_name(): void
    {
        $service = new ModelService();
        $result = $service->save_model_name('123', '');

        $this->assertFalse($result);
    }

    /**
     * Test remove_model_name removes from WP option.
     */
    public function test_remove_model_name(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with(ModelService::OPTION_KEY, [])
            ->andReturn(['1' => 'Ninebot ES2', '500' => 'Custom']);

        Functions\expect('update_option')
            ->once()
            ->with(
                ModelService::OPTION_KEY,
                \Mockery::on(function ($value) {
                    return is_array($value)
                        && isset($value['1'])
                        && !isset($value['500']);
                })
            )
            ->andReturn(true);

        $service = new ModelService();
        $result = $service->remove_model_name('500');

        $this->assertTrue($result);
    }

    /**
     * Test remove_model_name returns false for nonexistent key.
     */
    public function test_remove_model_name_nonexistent_key(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with(ModelService::OPTION_KEY, [])
            ->andReturn(['1' => 'Ninebot ES2']);

        $service = new ModelService();
        $result = $service->remove_model_name('9999');

        $this->assertFalse($result);
    }

    /**
     * Test seed_default_names populates option when it does not exist.
     */
    public function test_seed_default_names_populates_when_missing(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with(ModelService::OPTION_KEY, null)
            ->andReturn(null);

        Functions\expect('update_option')
            ->once()
            ->with(ModelService::OPTION_KEY, ModelService::MODEL_NAMES)
            ->andReturn(true);

        $service = new ModelService();
        $service->seed_default_names();

        // Assertion is implicit in the update_option expectation
        $this->assertTrue(true);
    }

    /**
     * Test seed_default_names does not overwrite existing option.
     */
    public function test_seed_default_names_skips_when_exists(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with(ModelService::OPTION_KEY, null)
            ->andReturn(['1' => 'Custom']);

        Functions\expect('update_option')->never();

        $service = new ModelService();
        $service->seed_default_names();

        $this->assertTrue(true);
    }

    /**
     * Test seed_default_names preserves intentionally empty array.
     */
    public function test_seed_default_names_preserves_empty_array(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with(ModelService::OPTION_KEY, null)
            ->andReturn([]);

        Functions\expect('update_option')->never();

        $service = new ModelService();
        $service->seed_default_names();

        $this->assertTrue(true);
    }
}
