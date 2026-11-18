<?php

namespace App\Support\Ai;

use App\Models\User;
use App\Support\Ai\Exceptions\AiBusy;
use App\Support\Ai\Exceptions\AiInvalidResponse;
use App\Support\Ai\Exceptions\AiProviderFailure;
use App\Support\Ai\Exceptions\AiQuotaExceeded;
use App\Support\Ai\Exceptions\AiUnavailable;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\Cache;

/**
 * Seul point d'entrée des modules vers l'IA (décision 34).
 *
 * generate() :
 * 1. vérifie la disponibilité : pilote activé, prix du modèle connu, max_execution_time de PHP
 *    suffisant (décision 70) ; sinon 503 ai_unavailable ;
 * 2. prend un verrou par utilisateur : une génération à la fois (409 generation_in_progress) ;
 * 3. réserve le coût maximal sous les plafonds (AiBudget : 429 ou 503 capacity) ;
 * 4. appelle le pilote, puis enregistre le coût réel ; un échec du fournisseur donne 503
 *    (reason "provider"), une réponse inexploitable 502, sans rien coûter à l'utilisateur.
 *
 * Journal security : fonctionnalité, raison, jetons ; jamais le contenu ni la réponse.
 */
class AiGateway
{
    public function __construct(private AiClient $client, private AiBudget $budget) {}

    public function available(): bool
    {
        return $this->unavailableReason() === null;
    }

    /**
     * Raison de l'indisponibilité (disabled, pricing, execution_time) ou null.
     */
    public function unavailableReason(): ?string
    {
        return match (true) {
            ! $this->client->enabled() => 'disabled',
            ! AiPricing::has($this->model()) => 'pricing',
            ! self::executionTimeSufficient((string) ini_get('max_execution_time')) => 'execution_time',
            default => null,
        };
    }

    public function providerLabel(): ?string
    {
        return $this->available() ? $this->client->providerLabel() : null;
    }

    public function model(): string
    {
        return $this->client->name() === 'fake' ? Drivers\FakeAiClient::MODEL : (string) config('ai.model');
    }

    public function client(): AiClient
    {
        return $this->client;
    }

    /**
     * max_execution_time suffisant pour un appel : nul (illimité) ou au moins le délai d'appel
     * plus la marge (config/ai.php).
     */
    public static function executionTimeSufficient(string|int|false $maxExecutionTime): bool
    {
        $seconds = (int) $maxExecutionTime;

        return $seconds <= 0 || $seconds >= self::requiredExecutionTime();
    }

    public static function requiredExecutionTime(): int
    {
        return (int) config('ai.timeout_seconds') + (int) config('ai.execution_time_margin_seconds');
    }

    /**
     * @throws AiUnavailable
     * @throws AiBusy
     * @throws AiQuotaExceeded
     * @throws AiInvalidResponse
     */
    public function generate(User $user, AiRequest $request): AiResponse
    {
        $reason = $this->unavailableReason();

        if ($reason !== null) {
            SecurityLog::record('ai.unavailable', ['user_id' => $user->id, 'feature' => $request->feature, 'reason' => $reason], 'warning');

            throw new AiUnavailable($reason);
        }

        $lock = Cache::lock('ai:generation:'.$user->id, self::requiredExecutionTime());

        if (! $lock->get()) {
            throw new AiBusy;
        }

        try {
            return $this->call($user, $request);
        } finally {
            $lock->release();
        }
    }

    private function call(User $user, AiRequest $request): AiResponse
    {
        try {
            $usage = $this->budget->reserve($user, $request, $this->client->name(), $this->model());
        } catch (AiQuotaExceeded|AiUnavailable $exception) {
            SecurityLog::record('ai.budget_exceeded', [
                'user_id' => $user->id,
                'feature' => $request->feature,
                'scope' => $exception instanceof AiQuotaExceeded ? 'user' : 'global',
            ], 'warning');

            throw $exception;
        }

        try {
            $response = $this->client->generate($request);
        } catch (AiProviderFailure $failure) {
            $this->budget->settle($usage, $failure->reason);
            SecurityLog::record('ai.provider_failed', ['user_id' => $user->id, 'feature' => $request->feature, 'usage' => $usage->uuid, 'reason' => $failure->reason], 'warning');

            throw new AiUnavailable('provider');
        } catch (AiInvalidResponse $invalid) {
            $this->budget->settle($usage, 'invalid_response', $invalid->inputTokens, $invalid->outputTokens);
            SecurityLog::record('ai.invalid_response', ['user_id' => $user->id, 'feature' => $request->feature, 'usage' => $usage->uuid, 'reason' => $invalid->reason], 'warning');

            throw $invalid;
        }

        $this->budget->settle($usage, null, $response->inputTokens, $response->outputTokens, AiPricing::has($response->model) ? $response->model : $usage->model);

        SecurityLog::record('ai.completed', [
            'user_id' => $user->id,
            'feature' => $request->feature,
            'usage' => $usage->uuid,
            'model' => $usage->model,
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
        ]);

        return $response;
    }
}
