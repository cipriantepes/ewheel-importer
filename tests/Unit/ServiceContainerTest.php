<?php
/**
 * Tests for the ServiceContainer class.
 *
 * @package Trotibike\EwheelImporter\Tests\Unit
 */

namespace Trotibike\EwheelImporter\Tests\Unit;

use Trotibike\EwheelImporter\Tests\TestCase;
use Trotibike\EwheelImporter\Container\ServiceContainer;

/**
 * Test case for ServiceContainer.
 */
class ServiceContainerTest extends TestCase {

    /**
     * Test registering and retrieving a service.
     */
    public function test_register_and_get_service(): void {
        $container = new ServiceContainer();
        $container->register( 'test_service', fn() => new \stdClass() );

        $service = $container->get( 'test_service' );

        $this->assertInstanceOf( \stdClass::class, $service );
    }

    /**
     * Test register creates new instance each time (not singleton).
     */
    public function test_register_creates_new_instances(): void {
        $container = new ServiceContainer();
        $container->register( 'test_service', fn() => new \stdClass() );

        $service1 = $container->get( 'test_service' );
        $service2 = $container->get( 'test_service' );

        // register() creates new instance each time, not a singleton
        $this->assertNotSame( $service1, $service2 );
    }

    /**
     * Test singleton method creates singleton.
     */
    public function test_singleton_returns_same_instance(): void {
        $container = new ServiceContainer();
        $container->singleton( 'test_service', fn() => new \stdClass() );

        $service1 = $container->get( 'test_service' );
        $service2 = $container->get( 'test_service' );

        $this->assertSame( $service1, $service2 );
    }

    /**
     * Test getting non-existent service throws exception.
     */
    public function test_get_nonexistent_throws_exception(): void {
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'Service not found: nonexistent' );

        $container = new ServiceContainer();
        $container->get( 'nonexistent' );
    }

    /**
     * Test has method.
     */
    public function test_has_service(): void {
        $container = new ServiceContainer();
        $container->register( 'test_service', fn() => new \stdClass() );

        $this->assertTrue( $container->has( 'test_service' ) );
        $this->assertFalse( $container->has( 'nonexistent' ) );
    }

    /**
     * Test factory receives container.
     */
    public function test_factory_receives_container(): void {
        $container = new ServiceContainer();

        $container->singleton( 'dependency', fn() => (object) [ 'value' => 'dependency_value' ] );
        $container->register(
            'main_service',
            function ( ServiceContainer $c ) {
                return (object) [ 'dep' => $c->get( 'dependency' )->value ];
            }
        );

        $service = $container->get( 'main_service' );

        $this->assertEquals( 'dependency_value', $service->dep );
    }

    /**
     * Test set method for direct instance registration.
     */
    public function test_set_instance(): void {
        $container = new ServiceContainer();
        $instance  = new \stdClass();
        $instance->value = 'test_value';

        $container->set( 'config_value', $instance );

        $this->assertSame( $instance, $container->get( 'config_value' ) );
        $this->assertEquals( 'test_value', $container->get( 'config_value' )->value );
    }

    /**
     * Test overwriting service with register.
     */
    public function test_register_overwrites_previous(): void {
        $container = new ServiceContainer();
        $container->register( 'service', fn() => (object) [ 'name' => 'first' ] );
        $container->register( 'service', fn() => (object) [ 'name' => 'second' ] );

        $service = $container->get( 'service' );

        // Second registration overwrites first
        $this->assertEquals( 'second', $service->name );
    }

    /**
     * Test register returns self for chaining.
     */
    public function test_register_returns_self(): void {
        $container = new ServiceContainer();

        $result = $container->register( 'test', fn() => new \stdClass() );

        $this->assertSame( $container, $result );
    }

    /**
     * Test singleton returns self for chaining.
     */
    public function test_singleton_returns_self(): void {
        $container = new ServiceContainer();

        $result = $container->singleton( 'test', fn() => new \stdClass() );

        $this->assertSame( $container, $result );
    }

    /**
     * Test set returns self for chaining.
     */
    public function test_set_returns_self(): void {
        $container = new ServiceContainer();

        $result = $container->set( 'test', new \stdClass() );

        $this->assertSame( $container, $result );
    }
}
