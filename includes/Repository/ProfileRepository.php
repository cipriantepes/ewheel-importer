<?php
/**
 * Profile Repository.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Repository;

use Trotibike\EwheelImporter\Model\Profile;

/**
 * Repository for import profiles.
 *
 * Single Responsibility: Only handles profile data access.
 */
class ProfileRepository implements RepositoryInterface
{

    /**
     * Table name without prefix.
     */
    public const TABLE_NAME = 'ewheel_profiles';

    /**
     * Get the full table name with prefix.
     *
     * @return string
     */
    private function get_table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * Find profile by ID.
     *
     * @param int $id Profile ID.
     * @return Profile|null
     */
    public function find(int $id): ?Profile
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->get_table_name()} WHERE id = %d",
                $id
            ),
            ARRAY_A
        );

        return $row ? Profile::from_array($row) : null;
    }

    /**
     * Find profile by slug (reference).
     *
     * @param string $reference Profile slug.
     * @return Profile|null
     */
    public function find_by_reference(string $reference): ?Profile
    {
        return $this->find_by_slug($reference);
    }

    /**
     * Find profile by slug.
     *
     * @param string $slug Profile slug.
     * @return Profile|null
     */
    public function find_by_slug(string $slug): ?Profile
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->get_table_name()} WHERE slug = %s",
                $slug
            ),
            ARRAY_A
        );

        return $row ? Profile::from_array($row) : null;
    }

    /**
     * Get all profiles.
     *
     * @return Profile[]
     */
    public function find_all(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT * FROM {$this->get_table_name()} ORDER BY id ASC",
            ARRAY_A
        );

        return array_map(fn($row) => Profile::from_array($row), $rows ?: []);
    }

    /**
     * Get all active profiles.
     *
     * @return Profile[]
     */
    public function find_active(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT * FROM {$this->get_table_name()} WHERE is_active = 1 ORDER BY id ASC",
            ARRAY_A
        );

        return array_map(fn($row) => Profile::from_array($row), $rows ?: []);
    }

    /**
     * Get the default profile.
     *
     * @return Profile|null
     */
    public function find_default(): ?Profile
    {
        return $this->find_by_slug('default');
    }

    /**
     * Save a profile (create or update).
     *
     * @param array|Profile $data Profile data or Profile object.
     * @return int Profile ID.
     * @throws \RuntimeException On failure.
     */
    public function save($data): int
    {
        $profile = $data instanceof Profile ? $data : Profile::from_array($data);

        if ($profile->get_id()) {
            return $this->update($profile);
        }

        return $this->create($profile);
    }

    /**
     * Create a new profile.
     *
     * @param Profile $profile Profile to create.
     * @return int Profile ID.
     * @throws \RuntimeException On failure.
     */
    private function create(Profile $profile): int
    {
        global $wpdb;

        // Generate slug if not set
        if (empty($profile->get_slug())) {
            $profile->set_slug($profile->get_name());
        }

        // Ensure slug is unique
        $slug = $this->ensure_unique_slug($profile->get_slug());
        $profile->set_slug($slug);

        $result = $wpdb->insert(
            $this->get_table_name(),
            [
                'name'              => $profile->get_name(),
                'slug'              => $profile->get_slug(),
                'is_active'         => $profile->is_active() ? 1 : 0,
                'filters'           => wp_json_encode($profile->get_filters()),
                'settings'          => wp_json_encode($profile->get_settings()),
                'category_mappings' => wp_json_encode($profile->get_category_mappings()),
                'last_sync'         => $profile->get_last_sync(),
                'created_at'        => current_time('mysql', true),
                'updated_at'        => current_time('mysql', true),
            ],
            ['%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if ($result === false) {
            throw new \RuntimeException('Failed to create profile: ' . $wpdb->last_error);
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Update an existing profile.
     *
     * @param Profile $profile Profile to update.
     * @return int Profile ID.
     * @throws \RuntimeException On failure.
     */
    private function update(Profile $profile): int
    {
        global $wpdb;

        $id = $profile->get_id();

        // Don't allow changing slug of default profile
        $existing = $this->find($id);
        if ($existing && $existing->is_default() && $profile->get_slug() !== 'default') {
            $profile->set_slug('default');
        }

        $result = $wpdb->update(
            $this->get_table_name(),
            [
                'name'              => $profile->get_name(),
                'slug'              => $profile->get_slug(),
                'is_active'         => $profile->is_active() ? 1 : 0,
                'filters'           => wp_json_encode($profile->get_filters()),
                'settings'          => wp_json_encode($profile->get_settings()),
                'category_mappings' => wp_json_encode($profile->get_category_mappings()),
                'last_sync'         => $profile->get_last_sync(),
                'updated_at'        => current_time('mysql', true),
            ],
            ['id' => $id],
            ['%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );

        if ($result === false) {
            throw new \RuntimeException('Failed to update profile: ' . $wpdb->last_error);
        }

        return $id;
    }

    /**
     * Update the last sync timestamp for a profile.
     *
     * @param int         $id        Profile ID.
     * @param string|null $timestamp Timestamp (null for current time).
     * @return bool
     */
    public function update_last_sync(int $id, ?string $timestamp = null): bool
    {
        global $wpdb;

        $timestamp = $timestamp ?? gmdate('Y-m-d H:i:s');

        $result = $wpdb->update(
            $this->get_table_name(),
            [
                'last_sync'  => $timestamp,
                'updated_at' => current_time('mysql', true),
            ],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Delete a profile.
     *
     * @param int $id Profile ID.
     * @return bool
     * @throws \RuntimeException If trying to delete default profile.
     */
    public function delete(int $id): bool
    {
        $profile = $this->find($id);

        if (!$profile) {
            return false;
        }

        // Prevent deletion of default profile
        if ($profile->is_default()) {
            throw new \RuntimeException('Cannot delete the default profile.');
        }

        global $wpdb;

        $result = $wpdb->delete(
            $this->get_table_name(),
            ['id' => $id],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Ensure a slug is unique by appending a number if necessary.
     *
     * @param string   $slug        Base slug.
     * @param int|null $exclude_id  ID to exclude from check (for updates).
     * @return string Unique slug.
     */
    private function ensure_unique_slug(string $slug, ?int $exclude_id = null): string
    {
        global $wpdb;

        $original_slug = $slug;
        $counter = 1;
        $table = $this->get_table_name();

        while (true) {
            $sql = "SELECT COUNT(*) FROM {$table} WHERE slug = %s";
            $params = [$slug];

            if ($exclude_id) {
                $sql .= " AND id != %d";
                $params[] = $exclude_id;
            }

            $prepared = $wpdb->prepare($sql, ...$params);
            $exists = $wpdb->get_var($prepared);

            if (!$exists) {
                break;
            }

            $slug = $original_slug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Check if the profiles table exists.
     *
     * @return bool
     */
    public function table_exists(): bool
    {
        global $wpdb;

        $table = $this->get_table_name();
        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $table
            )
        );

        return $result === $table;
    }

    /**
     * Get profile count.
     *
     * @return int
     */
    public function count(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->get_table_name()}"
        );
    }

    /**
     * Get profiles with scheduled sync (daily/weekly).
     *
     * @return Profile[]
     */
    public function find_scheduled(): array
    {
        $profiles = $this->find_active();

        return array_filter($profiles, function (Profile $profile) {
            $frequency = $profile->get_setting('sync_frequency');
            return in_array($frequency, ['daily', 'weekly'], true);
        });
    }

    /**
     * Get profiles that are due for sync based on their schedule.
     *
     * @return Profile[]
     */
    public function find_due_for_sync(): array
    {
        $scheduled = $this->find_scheduled();
        $now = time();

        return array_filter($scheduled, function (Profile $profile) use ($now) {
            $frequency = $profile->get_setting('sync_frequency');
            $last_sync = $profile->get_last_sync();

            if (!$last_sync) {
                // Never synced, should sync
                return true;
            }

            $last_sync_time = strtotime($last_sync);
            if ($last_sync_time === false) {
                return true;
            }

            $interval = $frequency === 'daily' ? DAY_IN_SECONDS : WEEK_IN_SECONDS;

            return ($now - $last_sync_time) >= $interval;
        });
    }
}
