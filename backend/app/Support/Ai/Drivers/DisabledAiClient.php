<?php

namespace App\Support\Ai\Drivers;

use App\Support\Ai\AiClient;
use App\Support\Ai\AiRequest;
use App\Support\Ai\AiResponse;
use App\Support\Ai\Exceptions\AiProviderFailure;

/**
 * IA désactivée (AI_DRIVER=disabled, valeur par défaut) : AiGateway répond 503
 * ai_unavailable (reason "disabled") avant tout appel ; ce pilote n'est jamais appelé.
 */
final class DisabledAiClient implements AiClient
{
    public function name(): string
    {
        return 'disabled';
    }

    public function providerLabel(): ?string
    {
        return null;
    }

    public function enabled(): bool
    {
        return false;
    }

    public function generate(AiRequest $request): AiResponse
    {
        throw new AiProviderFailure('disabled');
    }
}
