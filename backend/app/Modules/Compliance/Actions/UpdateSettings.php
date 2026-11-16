<?php

namespace App\Modules\Compliance\Actions;

use App\Models\User;
use App\Modules\Compliance\Exceptions\PremiumFeatureRequired;
use App\Modules\Compliance\Models\ComplianceSettings;
use App\Modules\Compliance\Support\Entitlements;

/**
 * Réglages des rappels et des alertes. Sans premium : un seul délai de rappel parmi les délais
 * gratuits, paliers d'alerte par défaut ; sinon 403 premium_required (custom_reminders,
 * custom_threshold_levels). Les emails se désactivent librement.
 */
class UpdateSettings
{
    public function __construct(private Entitlements $entitlements) {}

    /**
     * @param  array{emails_enabled?: bool, reminder_frequency?: string, reminder_offsets?: list<int>, threshold_levels?: list<int>}  $data
     *
     * @throws PremiumFeatureRequired
     */
    public function handle(User $user, array $data): ComplianceSettings
    {
        if (isset($data['reminder_offsets'])) {
            ['choices' => $choices, 'max' => $max] = $this->entitlements->reminderChoices($user->id);

            if (count($data['reminder_offsets']) > $max || array_diff($data['reminder_offsets'], $choices) !== []) {
                $this->entitlements->require('custom_reminders', $user->id);
            }
        }

        if (isset($data['threshold_levels']) && ! $this->entitlements->allowsCustomThresholdLevels($user->id)) {
            $default = array_map('intval', (array) config('compliance.thresholds.default_levels'));
            $requested = $data['threshold_levels'];
            sort($requested);

            if ($requested !== $default) {
                $this->entitlements->require('custom_threshold_levels', $user->id);
            }
        }

        $settings = ComplianceSettings::for($user->id);

        foreach (['emails_enabled', 'reminder_frequency'] as $field) {
            if (array_key_exists($field, $data)) {
                $settings->forceFill([$field => $data[$field]]);
            }
        }

        foreach (['reminder_offsets', 'threshold_levels'] as $field) {
            if (array_key_exists($field, $data)) {
                $values = array_values(array_unique(array_map('intval', $data[$field])));
                sort($values);
                $settings->forceFill([$field => $values]);
            }
        }

        $settings->save();

        return $settings;
    }
}
