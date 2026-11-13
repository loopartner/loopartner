<?php

namespace App\Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Compliance\Actions\UpdateSettings;
use App\Modules\Compliance\Http\Requests\UpdateSettingsRequest;
use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Models\ComplianceSettings;
use App\Modules\Compliance\Support\Disclaimer;
use App\Modules\Compliance\Support\Entitlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Réglages des rappels et des alertes (GET et PATCH /me/compliance/settings). Les valeurs
 * « effective_* » sont celles réellement appliquées (sans premium, les réglages premium
 * enregistrés sont ramenés aux valeurs gratuites, sans être effacés).
 */
class SettingsController extends Controller
{
    public function __construct(private Entitlements $entitlements) {}

    public function show(Request $request): JsonResponse
    {
        Gate::authorize('use', Company::class);

        return $this->respond($request, ComplianceSettings::for(self::user($request)->id));
    }

    public function update(UpdateSettingsRequest $request, UpdateSettings $updateSettings): JsonResponse
    {
        /** @var array{emails_enabled?: bool, reminder_frequency?: string, reminder_offsets?: list<int>, threshold_levels?: list<int>} $data */
        $data = $request->validated();

        return $this->respond($request, $updateSettings->handle(self::user($request), $data));
    }

    private function respond(Request $request, ComplianceSettings $settings): JsonResponse
    {
        $premium = $this->entitlements->isPremium($settings->user_id);

        return response()->json([
            'data' => [
                'emails_enabled' => $settings->emails_enabled,
                'reminder_frequency' => $settings->reminder_frequency->value,
                'reminder_offsets' => $settings->reminder_offsets,
                'effective_reminder_offsets' => $this->entitlements->reminderOffsets($settings),
                'threshold_levels' => $settings->threshold_levels,
                'effective_threshold_levels' => $this->entitlements->thresholdLevels($settings),
                'choices' => [
                    'reminder_offsets' => array_map('intval', (array) config($premium ? 'compliance.reminders.premium_offsets' : 'compliance.reminders.free_offsets')),
                    'max_reminder_offsets' => $premium ? (int) config('compliance.reminders.premium_max_offsets') : 1,
                    'custom_threshold_levels' => $premium,
                ],
            ],
            'disclaimer' => Disclaimer::forRequest($request),
        ]);
    }

    private static function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
