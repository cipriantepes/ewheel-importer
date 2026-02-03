<?php
/**
 * Service Container for Dependency Injection.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Container;

/**
 * Simple service container implementing dependency injection.
 */
class ServiceContainer {

    /**
     * Registered service factories.
     *
     * @var array<string, callable>
     */
    private array $factories = [];

    /**
     * Cached singleton instances.
     *
     * @var array<string, object>
     */
    private array $instances = [];

    /**
     * Register a service factory.
     *
     * @param string   $id      Service identifier.
     * @param callable $factory Factory callable that receives the container.
     * @return self
     */
    public function register( string $id, callable $factory ): self {
        $this->factories[ $id ] = $factory;
        return $this;
    }

    /**
     * Register a singleton service.
     *
     * @param string   $id      Service identifier.
     * @param callable $factory Factory callable.
     * @return self
     */
    public function singleton( string $id, callable $factory ): self {
        $this->factories[ $id ] = function ( ServiceContainer $container ) use ( $factory, $id ) {
            if ( ! isset( $this->instances[ $id ] ) ) {
                $this->instances[ $id ] = $factory( $container );
            }
            return $this->instances[ $id ];
        };
        return $this;
    }

    /**
     * Get a service instance.
     *
     * @param string $id Service identifier.
     * @return mixed The service instance.
     * @throws \InvalidArgumentException If service not found.
     */
    public function get( string $id ) {
        if ( ! isset( $this->factories[ $id ] ) ) {
            throw new \InvalidArgumentException( "Service not found: {$id}" );
        }

        return $this->factories[ $id ]( $this );
    }

    /**
     * Check if a service is registered.
     *
     * @param string $id Service identifier.
     * @return bool
     */
    public function has( string $id ): bool {
        return isset( $this->factories[ $id ] );
    }

    /**
     * Set an instance directly.
     *
     * @param string $id       Service identifier.
     * @param object $instance The instance.
     * @return self
     */
    public function set( string $id, object $instance ): self {
        $this->instances[ $id ] = $instance;
        $this->factories[ $id ] = fn() => $this->instances[ $id ];
        return $this;
    }
}
