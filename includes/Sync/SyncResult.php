<?php
/**
 * Sync Result Value Object.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Sync;

/**
 * Encapsulates sync operation results.
 *
 * Single Responsibility: Only holds and provides access to sync results.
 */
class SyncResult {

    /**
     * Number of created items.
     *
     * @var int
     */
    private int $created = 0;

    /**
     * Number of updated items.
     *
     * @var int
     */
    private int $updated = 0;

    /**
     * Number of skipped items.
     *
     * @var int
     */
    private int $skipped = 0;

    /**
     * Number of errors.
     *
     * @var int
     */
    private int $errors = 0;

    /**
     * Error messages.
     *
     * @var array<string>
     */
    private array $error_messages = [];

    /**
     * Preview data (for dry runs).
     *
     * @var array
     */
    private array $preview = [];

    /**
     * Increment created count.
     *
     * @return self
     */
    public function increment_created(): self {
        $this->created++;
        return $this;
    }

    /**
     * Increment updated count.
     *
     * @return self
     */
    public function increment_updated(): self {
        $this->updated++;
        return $this;
    }

    /**
     * Increment skipped count.
     *
     * @return self
     */
    public function increment_skipped(): self {
        $this->skipped++;
        return $this;
    }

    /**
     * Increment error count.
     *
     * @return self
     */
    public function increment_errors(): self {
        $this->errors++;
        return $this;
    }

    /**
     * Add an error message.
     *
     * @param string $message Error message.
     * @return self
     */
    public function add_error( string $message ): self {
        $this->error_messages[] = $message;
        $this->errors++;
        return $this;
    }

    /**
     * Add preview data.
     *
     * @param array $data Preview item.
     * @return self
     */
    public function add_preview( array $data ): self {
        $this->preview[] = $data;
        $this->skipped++;
        return $this;
    }

    /**
     * Add category results.
     *
     * @param SyncResult $result Category sync result.
     * @return self
     */
    public function add_categories( SyncResult $result ): self {
        // Categories are always "updated" (created or updated)
        return $this;
    }

    /**
     * Record an outcome.
     *
     * @param string $outcome 'created', 'updated', or 'error'.
     * @return self
     */
    public function record( string $outcome ): self {
        switch ( $outcome ) {
            case 'created':
                return $this->increment_created();
            case 'updated':
                return $this->increment_updated();
            case 'error':
                return $this->increment_errors();
            default:
                return $this->increment_skipped();
        }
    }

    /**
     * Merge another result into this one.
     *
     * @param SyncResult $other Other result.
     * @return self
     */
    public function merge( SyncResult $other ): self {
        $this->created        += $other->get_created();
        $this->updated        += $other->get_updated();
        $this->skipped        += $other->get_skipped();
        $this->errors         += $other->get_errors();
        $this->error_messages  = array_merge( $this->error_messages, $other->get_error_messages() );
        $this->preview         = array_merge( $this->preview, $other->get_preview() );

        return $this;
    }

    /**
     * Get created count.
     *
     * @return int
     */
    public function get_created(): int {
        return $this->created;
    }

    /**
     * Get updated count.
     *
     * @return int
     */
    public function get_updated(): int {
        return $this->updated;
    }

    /**
     * Get skipped count.
     *
     * @return int
     */
    public function get_skipped(): int {
        return $this->skipped;
    }

    /**
     * Get error count.
     *
     * @return int
     */
    public function get_errors(): int {
        return $this->errors;
    }

    /**
     * Get error messages.
     *
     * @return array<string>
     */
    public function get_error_messages(): array {
        return $this->error_messages;
    }

    /**
     * Get preview data.
     *
     * @return array
     */
    public function get_preview(): array {
        return $this->preview;
    }

    /**
     * Get total processed count.
     *
     * @return int
     */
    public function get_total(): int {
        return $this->created + $this->updated + $this->skipped + $this->errors;
    }

    /**
     * Check if sync was successful (no errors).
     *
     * @return bool
     */
    public function is_successful(): bool {
        return $this->errors === 0;
    }

    /**
     * Convert to array.
     *
     * @return array
     */
    public function to_array(): array {
        return [
            'created'        => $this->created,
            'updated'        => $this->updated,
            'skipped'        => $this->skipped,
            'errors'         => $this->errors,
            'error_messages' => $this->error_messages,
            'total'          => $this->get_total(),
            'successful'     => $this->is_successful(),
        ];
    }

    /**
     * Get summary message.
     *
     * @return string
     */
    public function get_summary(): string {
        return sprintf(
            'Created: %d, Updated: %d, Skipped: %d, Errors: %d',
            $this->created,
            $this->updated,
            $this->skipped,
            $this->errors
        );
    }
}
