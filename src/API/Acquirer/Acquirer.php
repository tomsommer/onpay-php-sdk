<?php

declare(strict_types=1);

namespace OnPay\API\Acquirer;

/**
 * An acquirer configured on the gateway.
 *
 * Only name and active are common to every acquirer. Everything else depends on
 * which one it is - Nets carries card BINs, Clearhaus an API key - so the rest
 * is kept as given rather than flattened into properties that would be null for
 * most acquirers.
 */
class Acquirer
{
    public ?string $name;

    public ?bool $active;

    /** @var array<string, mixed> */
    public array $links;

    /**
     * Every field beyond name, active and links, exactly as the API returned it.
     *
     * @var array<string, mixed>
     */
    public array $settings;

    /**
     * @internal
     * @param array<string, mixed> $data
     */
    public function __construct(array $data)
    {
        $this->name = isset($data['name']) ? (string) $data['name'] : null;
        $this->active = isset($data['active']) ? (bool) $data['active'] : null;
        $this->links = isset($data['links']) && is_array($data['links']) ? $data['links'] : [];
        $this->settings = array_diff_key($data, array_flip(['name', 'active', 'links']));
    }

    /**
     * A single acquirer-specific setting, e.g. 'mcc', 'sca_mode', 'visa_bin'.
     */
    public function getSetting(string $name): mixed
    {
        return $this->settings[$name] ?? null;
    }

    /**
     * Whether the low-value SCA exemption is in force, when the acquirer reports
     * exemptions at all.
     */
    public function hasLowValueScaExemption(): ?bool
    {
        $exemptions = $this->settings['exemptions'] ?? null;
        if (!is_array($exemptions) || !isset($exemptions['sca_low_value'])) {
            return null;
        }

        return (bool) $exemptions['sca_low_value'];
    }
}
