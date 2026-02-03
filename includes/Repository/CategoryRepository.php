<?php
/**
 * Category Repository.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Repository;

/**
 * Repository for WooCommerce product categories.
 *
 * Single Responsibility: Only handles category data access.
 */
class CategoryRepository implements RepositoryInterface {

    /**
     * Meta key for ewheel reference.
     */
    private const EWHEEL_REF_META = '_ewheel_reference';

    /**
     * Taxonomy name.
     */
    private const TAXONOMY = 'product_cat';

    /**
     * Find category by ID.
     *
     * @param int $id Category ID.
     * @return \WP_Term|null
     */
    public function find( int $id ): ?\WP_Term {
        $term = get_term( $id, self::TAXONOMY );
        return $term instanceof \WP_Term ? $term : null;
    }

    /**
     * Find category by slug (reference).
     *
     * @param string $reference Category slug.
     * @return \WP_Term|null
     */
    public function find_by_reference( string $reference ): ?\WP_Term {
        $term = get_term_by( 'slug', sanitize_title( $reference ), self::TAXONOMY );
        return $term instanceof \WP_Term ? $term : null;
    }

    /**
     * Find category by ewheel reference meta.
     *
     * @param string $ewheel_ref The ewheel reference.
     * @return int|null The term ID or null.
     */
    public function find_by_ewheel_reference( string $ewheel_ref ): ?int {
        $terms = get_terms(
            [
                'taxonomy'   => self::TAXONOMY,
                'hide_empty' => false,
                'meta_query' => [
                    [
                        'key'   => self::EWHEEL_REF_META,
                        'value' => $ewheel_ref,
                    ],
                ],
                'number'     => 1,
            ]
        );

        if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
            return $terms[0]->term_id;
        }

        return null;
    }

    /**
     * Save a category.
     *
     * @param array $data Category data with 'reference', 'name', 'parent_reference'.
     * @return int Category ID.
     */
    public function save( array $data ): int {
        $reference = $data['reference'] ?? '';
        $name      = $data['name'] ?? $reference;
        $parent_ref = $data['parent_reference'] ?? null;

        $existing_id = $this->find_by_ewheel_reference( $reference );

        if ( $existing_id ) {
            return $this->update( $existing_id, $name, $parent_ref );
        }

        return $this->create( $reference, $name, $parent_ref );
    }

    /**
     * Create a new category.
     *
     * @param string      $reference  Ewheel reference.
     * @param string      $name       Category name.
     * @param string|null $parent_ref Parent reference.
     * @return int Category ID.
     * @throws \RuntimeException On failure.
     */
    private function create( string $reference, string $name, ?string $parent_ref ): int {
        $parent_id = 0;
        if ( $parent_ref ) {
            $parent_id = $this->find_by_ewheel_reference( $parent_ref ) ?? 0;
        }

        $result = wp_insert_term(
            $name,
            self::TAXONOMY,
            [
                'slug'   => sanitize_title( $reference ),
                'parent' => $parent_id,
            ]
        );

        if ( is_wp_error( $result ) ) {
            // If term exists with same slug, try to get it
            if ( $result->get_error_code() === 'term_exists' ) {
                $existing = $result->get_error_data();
                if ( is_int( $existing ) ) {
                    update_term_meta( $existing, self::EWHEEL_REF_META, $reference );
                    return $existing;
                }
            }
            throw new \RuntimeException( 'Failed to create category: ' . $result->get_error_message() );
        }

        $term_id = $result['term_id'];
        update_term_meta( $term_id, self::EWHEEL_REF_META, $reference );

        return $term_id;
    }

    /**
     * Update an existing category.
     *
     * @param int         $term_id    Term ID.
     * @param string      $name       Category name.
     * @param string|null $parent_ref Parent reference.
     * @return int Category ID.
     */
    private function update( int $term_id, string $name, ?string $parent_ref ): int {
        $args = [ 'name' => $name ];

        if ( $parent_ref ) {
            $parent_id = $this->find_by_ewheel_reference( $parent_ref );
            if ( $parent_id ) {
                $args['parent'] = $parent_id;
            }
        }

        wp_update_term( $term_id, self::TAXONOMY, $args );

        return $term_id;
    }

    /**
     * Delete a category.
     *
     * @param int $id Category ID.
     * @return bool
     */
    public function delete( int $id ): bool {
        $result = wp_delete_term( $id, self::TAXONOMY );
        return $result === true;
    }

    /**
     * Get all category mappings (ewheel reference => WooCommerce ID).
     *
     * @return array<string, int>
     */
    public function get_mapping(): array {
        $terms = get_terms(
            [
                'taxonomy'   => self::TAXONOMY,
                'hide_empty' => false,
                'meta_query' => [
                    [
                        'key'     => self::EWHEEL_REF_META,
                        'compare' => 'EXISTS',
                    ],
                ],
            ]
        );

        if ( is_wp_error( $terms ) ) {
            return [];
        }

        $mapping = [];
        foreach ( $terms as $term ) {
            $ref = get_term_meta( $term->term_id, self::EWHEEL_REF_META, true );
            if ( $ref ) {
                $mapping[ $ref ] = $term->term_id;
            }
        }

        return $mapping;
    }
}
