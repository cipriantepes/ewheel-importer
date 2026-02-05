<?php
/**
 * Profile Model.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Model;

/**
 * Represents an import profile with filters and settings.
 *
 * Single Responsibility: Data structure for import profiles.
 */
class Profile
{

    /**
     * Profile ID.
     *
     * @var int|null
     */
    private ?int $id = null;

    /**
     * Profile name.
     *
     * @var string
     */
    private string $name = '';

    /**
     * Profile slug (unique identifier).
     *
     * @var string
     */
    private string $slug = '';

    /**
     * Whether profile is active.
     *
     * @var bool
     */
    private bool $is_active = true;

    /**
     * API filters for this profile.
     *
     * @var array
     */
    private array $filters = [];

    /**
     * Profile-specific settings (pricing, field mappings, sync protection).
     *
     * @var array
     */
    private array $settings = [];

    /**
     * Category mapping overrides for this profile.
     *
     * @var array
     */
    private array $category_mappings = [];

    /**
     * Last sync timestamp.
     *
     * @var string|null
     */
    private ?string $last_sync = null;

    /**
     * Created at timestamp.
     *
     * @var string|null
     */
    private ?string $created_at = null;

    /**
     * Updated at timestamp.
     *
     * @var string|null
     */
    private ?string $updated_at = null;

    /**
     * Default filters.
     */
    public const DEFAULT_FILTERS = [
        'category'         => '',      // Filter by category reference
        'active'           => false,   // Only active products
        'hasImages'        => false,   // Only products with images
        'hasVariants'      => false,   // Only products with variants
        'productReference' => '',      // Filter by SKU (partial match)
        'productsIds'      => [],      // Specific product IDs
        'NewerThan'        => '',      // For incremental syncs
    ];

    /**
     * Default settings.
     */
    public const DEFAULT_SETTINGS = [
        // Pricing (null = use global)
        'exchange_rate'     => null,
        'markup_percent'    => null,

        // Field mappings (null = use global)
        'sync_fields'       => null,
        'sync_protection'   => null,
        'custom_patterns'   => null,
        'variation_mode'    => null,

        // Sync settings
        'sync_frequency'    => 'manual', // manual, daily, weekly
        'test_limit'        => 0,        // 0 = all products
    ];

    /**
     * Get profile ID.
     *
     * @return int|null
     */
    public function get_id(): ?int
    {
        return $this->id;
    }

    /**
     * Set profile ID.
     *
     * @param int|null $id Profile ID.
     * @return self
     */
    public function set_id(?int $id): self
    {
        $this->id = $id;
        return $this;
    }

    /**
     * Get profile name.
     *
     * @return string
     */
    public function get_name(): string
    {
        return $this->name;
    }

    /**
     * Set profile name.
     *
     * @param string $name Profile name.
     * @return self
     */
    public function set_name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Get profile slug.
     *
     * @return string
     */
    public function get_slug(): string
    {
        return $this->slug;
    }

    /**
     * Set profile slug.
     *
     * @param string $slug Profile slug.
     * @return self
     */
    public function set_slug(string $slug): self
    {
        $this->slug = sanitize_title($slug);
        return $this;
    }

    /**
     * Check if profile is active.
     *
     * @return bool
     */
    public function is_active(): bool
    {
        return $this->is_active;
    }

    /**
     * Set profile active state.
     *
     * @param bool $is_active Active state.
     * @return self
     */
    public function set_active(bool $is_active): self
    {
        $this->is_active = $is_active;
        return $this;
    }

    /**
     * Get all filters.
     *
     * @return array
     */
    public function get_filters(): array
    {
        return array_merge(self::DEFAULT_FILTERS, $this->filters);
    }

    /**
     * Set all filters.
     *
     * @param array $filters Filters array.
     * @return self
     */
    public function set_filters(array $filters): self
    {
        $this->filters = $filters;
        return $this;
    }

    /**
     * Get a specific filter value.
     *
     * @param string $key Filter key.
     * @return mixed
     */
    public function get_filter(string $key)
    {
        return $this->filters[$key] ?? (self::DEFAULT_FILTERS[$key] ?? null);
    }

    /**
     * Set a specific filter value.
     *
     * @param string $key   Filter key.
     * @param mixed  $value Filter value.
     * @return self
     */
    public function set_filter(string $key, $value): self
    {
        $this->filters[$key] = $value;
        return $this;
    }

    /**
     * Get all settings.
     *
     * @return array
     */
    public function get_settings(): array
    {
        return array_merge(self::DEFAULT_SETTINGS, $this->settings);
    }

    /**
     * Set all settings.
     *
     * @param array $settings Settings array.
     * @return self
     */
    public function set_settings(array $settings): self
    {
        $this->settings = $settings;
        return $this;
    }

    /**
     * Get a specific setting value.
     *
     * @param string $key Setting key.
     * @return mixed
     */
    public function get_setting(string $key)
    {
        return $this->settings[$key] ?? (self::DEFAULT_SETTINGS[$key] ?? null);
    }

    /**
     * Set a specific setting value.
     *
     * @param string $key   Setting key.
     * @param mixed  $value Setting value.
     * @return self
     */
    public function set_setting(string $key, $value): self
    {
        $this->settings[$key] = $value;
        return $this;
    }

    /**
     * Get category mappings.
     *
     * @return array
     */
    public function get_category_mappings(): array
    {
        return $this->category_mappings;
    }

    /**
     * Set category mappings.
     *
     * @param array $mappings Category mappings.
     * @return self
     */
    public function set_category_mappings(array $mappings): self
    {
        $this->category_mappings = $mappings;
        return $this;
    }

    /**
     * Get last sync timestamp.
     *
     * @return string|null
     */
    public function get_last_sync(): ?string
    {
        return $this->last_sync;
    }

    /**
     * Set last sync timestamp.
     *
     * @param string|null $last_sync Last sync timestamp.
     * @return self
     */
    public function set_last_sync(?string $last_sync): self
    {
        $this->last_sync = $last_sync;
        return $this;
    }

    /**
     * Get created at timestamp.
     *
     * @return string|null
     */
    public function get_created_at(): ?string
    {
        return $this->created_at;
    }

    /**
     * Set created at timestamp.
     *
     * @param string|null $created_at Created at timestamp.
     * @return self
     */
    public function set_created_at(?string $created_at): self
    {
        $this->created_at = $created_at;
        return $this;
    }

    /**
     * Get updated at timestamp.
     *
     * @return string|null
     */
    public function get_updated_at(): ?string
    {
        return $this->updated_at;
    }

    /**
     * Set updated at timestamp.
     *
     * @param string|null $updated_at Updated at timestamp.
     * @return self
     */
    public function set_updated_at(?string $updated_at): self
    {
        $this->updated_at = $updated_at;
        return $this;
    }

    /**
     * Check if this is the default profile.
     *
     * @return bool
     */
    public function is_default(): bool
    {
        return $this->slug === 'default';
    }

    /**
     * Convert profile to array for storage/display.
     *
     * @return array
     */
    public function to_array(): array
    {
        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'slug'              => $this->slug,
            'is_active'         => $this->is_active,
            'filters'           => $this->filters,
            'settings'          => $this->settings,
            'category_mappings' => $this->category_mappings,
            'last_sync'         => $this->last_sync,
            'created_at'        => $this->created_at,
            'updated_at'        => $this->updated_at,
        ];
    }

    /**
     * Create profile from array.
     *
     * @param array $data Profile data.
     * @return self
     */
    public static function from_array(array $data): self
    {
        $profile = new self();

        if (isset($data['id'])) {
            $profile->set_id((int) $data['id']);
        }
        if (isset($data['name'])) {
            $profile->set_name($data['name']);
        }
        if (isset($data['slug'])) {
            $profile->set_slug($data['slug']);
        }
        if (isset($data['is_active'])) {
            $profile->set_active((bool) $data['is_active']);
        }
        if (isset($data['filters'])) {
            $filters = is_string($data['filters']) ? json_decode($data['filters'], true) : $data['filters'];
            $profile->set_filters($filters ?: []);
        }
        if (isset($data['settings'])) {
            $settings = is_string($data['settings']) ? json_decode($data['settings'], true) : $data['settings'];
            $profile->set_settings($settings ?: []);
        }
        if (isset($data['category_mappings'])) {
            $mappings = is_string($data['category_mappings']) ? json_decode($data['category_mappings'], true) : $data['category_mappings'];
            $profile->set_category_mappings($mappings ?: []);
        }
        if (isset($data['last_sync'])) {
            $profile->set_last_sync($data['last_sync']);
        }
        if (isset($data['created_at'])) {
            $profile->set_created_at($data['created_at']);
        }
        if (isset($data['updated_at'])) {
            $profile->set_updated_at($data['updated_at']);
        }

        return $profile;
    }

    /**
     * Get API filters formatted for the ewheel API request.
     *
     * Returns only non-empty filter values that should be sent to the API.
     *
     * @return array
     */
    public function get_api_filters(): array
    {
        $filters = $this->get_filters();
        $api_filters = [];

        // String filters (only if not empty)
        if (!empty($filters['category'])) {
            $api_filters['category'] = $filters['category'];
        }
        if (!empty($filters['productReference'])) {
            $api_filters['productReference'] = $filters['productReference'];
        }
        if (!empty($filters['NewerThan'])) {
            $api_filters['NewerThan'] = $filters['NewerThan'];
        }

        // Boolean filters (only if true)
        if (!empty($filters['active'])) {
            $api_filters['active'] = true;
        }
        if (!empty($filters['hasImages'])) {
            $api_filters['hasImages'] = true;
        }
        if (!empty($filters['hasVariants'])) {
            $api_filters['hasVariants'] = true;
        }

        // Array filters (only if not empty)
        if (!empty($filters['productsIds']) && is_array($filters['productsIds'])) {
            $api_filters['productsIds'] = $filters['productsIds'];
        }

        return $api_filters;
    }
}
