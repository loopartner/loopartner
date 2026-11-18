<?php

namespace App\Modules\Workspaces\Support;

use App\Models\User;
use App\Modules\Workspaces\Enums\SuggestionKind;
use App\Modules\Workspaces\Models\CopilotEntry;
use App\Support\Ai\Exceptions\AiQuotaExceeded;
use App\Support\SecurityLog;
use Carbon\CarbonImmutable;

/**
 * Quotas du copilote (décision 61), comptés sur les générations **réussies**
 * (workspace_copilot_entries, gardées tant que le compte existe) :
 * - gratuit : copilot.roadmap_generations feuilles de route à vie (une et une régénération),
 *   pas de cahier des charges : 403 premium_required ;
 * - Premium : copilot.monthly_generations par mois civil et copilot.daily_generations par jour
 *   (UTC), toutes générations confondues : 429 ai_quota_exceeded avec resets_at.
 */
final class CopilotQuota
{
    public function __construct(private Plan $plan) {}

    /**
     * @throws AiQuotaExceeded
     */
    public function assertAvailable(User $user, SuggestionKind $kind): void
    {
        if ($kind === SuggestionKind::Specification && ! $this->plan->allows($user, 'copilot.specifications')) {
            $this->plan->requirePremium($user, 'copilot_specifications');
        }

        $lifetime = $this->plan->limit($user, 'copilot.roadmap_generations');

        if ($kind === SuggestionKind::Roadmap && $lifetime !== null && $this->count($user, SuggestionKind::Roadmap) >= $lifetime) {
            $this->plan->requirePremium($user, 'copilot_roadmaps');
        }

        $now = CarbonImmutable::now('UTC');

        foreach (['daily' => [$now->startOfDay(), $now->startOfDay()->addDay()], 'monthly' => [$now->startOfMonth(), $now->startOfMonth()->addMonth()]] as $scope => [$from, $resets]) {
            $limit = $this->plan->limit($user, 'copilot.'.$scope.'_generations');

            if ($limit !== null && $this->count($user, null, $from) >= $limit) {
                SecurityLog::record('copilot.quota_exceeded', ['user_id' => $user->id, 'scope' => $scope], 'warning');

                throw new AiQuotaExceeded($scope, $resets);
            }
        }
    }

    public function record(User $user, SuggestionKind $kind): void
    {
        (new CopilotEntry)->forceFill(['user_id' => $user->id, 'kind' => $kind, 'generated_at' => now()])->save();
    }

    /**
     * État des quotas pour GET /me/copilot.
     *
     * @return array<string, mixed>
     */
    public function status(User $user): array
    {
        $now = CarbonImmutable::now('UTC');
        $lifetime = $this->plan->limit($user, 'copilot.roadmap_generations');
        $daily = $this->plan->limit($user, 'copilot.daily_generations');
        $monthly = $this->plan->limit($user, 'copilot.monthly_generations');

        return [
            'premium' => $this->plan->isPremium($user),
            'roadmaps' => ['used' => $this->count($user, SuggestionKind::Roadmap), 'limit' => $lifetime],
            'specifications' => $this->plan->allows($user, 'copilot.specifications'),
            'daily' => $daily === null ? null : ['used' => $this->count($user, null, $now->startOfDay()), 'limit' => $daily, 'resets_at' => $now->startOfDay()->addDay()->toIso8601ZuluString()],
            'monthly' => $monthly === null ? null : ['used' => $this->count($user, null, $now->startOfMonth()), 'limit' => $monthly, 'resets_at' => $now->startOfMonth()->addMonth()->toIso8601ZuluString()],
        ];
    }

    private function count(User $user, ?SuggestionKind $kind, ?CarbonImmutable $since = null): int
    {
        return CopilotEntry::query()
            ->where('user_id', $user->id)
            ->when($kind !== null, fn ($query) => $query->where('kind', $kind?->value))
            ->when($since !== null, fn ($query) => $query->where('generated_at', '>=', $since))
            ->count();
    }
}
