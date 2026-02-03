<?php
/**
 * Repository Interface.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Repository;

/**
 * Base repository interface for CRUD operations.
 *
 * Interface Segregation: Defines minimal contract for repositories.
 *
 * @template T
 */
interface RepositoryInterface {

    /**
     * Find entity by ID.
     *
     * @param int $id Entity ID.
     * @return mixed|null The entity or null.
     */
    public function find( int $id );

    /**
     * Find entity by external reference (SKU, ewheel reference, etc).
     *
     * @param string $reference External reference.
     * @return mixed|null The entity or null.
     */
    public function find_by_reference( string $reference );

    /**
     * Save an entity (create or update).
     *
     * @param array $data Entity data.
     * @return int The entity ID.
     */
    public function save( array $data ): int;

    /**
     * Delete an entity.
     *
     * @param int $id Entity ID.
     * @return bool Success.
     */
    public function delete( int $id ): bool;
}
