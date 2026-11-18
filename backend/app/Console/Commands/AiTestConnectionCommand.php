<?php

namespace App\Console\Commands;

use App\Support\Ai\AiClient;
use App\Support\Ai\AiPricing;
use App\Support\Ai\AiRequest;
use App\Support\Ai\Drivers\MistralAiClient;
use App\Support\Ai\Exceptions\AiInvalidResponse;
use App\Support\Ai\Exceptions\AiProviderFailure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Premier essai du fournisseur d'IA par l'exploitant (docs/v1/workspaces-rules.md, section 7) :
 * une requête **fixe, sans aucune donnée d'utilisateur**, qui vérifie l'endpoint de l'UE, le
 * modèle, la sortie JSON par schéma et le décompte des jetons. Elle n'est comptée dans aucun
 * quota ; son coût (quelques micro-dollars) est affiché pour comparaison avec la console du
 * fournisseur. Jamais la clé ni la réponse complète.
 */
#[Signature('ai:test-connection')]
#[Description('Envoie une requête fixe, sans donnée d\'utilisateur, au fournisseur d\'IA configuré')]
class AiTestConnectionCommand extends Command
{
    public function handle(AiClient $client): int
    {
        if (! $client->enabled()) {
            $this->error('L\'IA n\'est pas activée (AI_DRIVER="'.config('ai.driver').'", clé ou endpoint de l\'UE manquant).');

            return self::FAILURE;
        }

        if ($client instanceof MistralAiClient) {
            $this->line('Endpoint : '.$client->endpoint());
        }

        $request = new AiRequest(
            feature: 'ai.test_connection',
            promptVersion: 'test-1',
            system: 'Réponds uniquement par un objet JSON conforme au schéma.',
            content: '{"question":"Donne le mot « bonjour » et le nombre 3."}',
            schemaName: 'test_connection',
            schema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['word', 'number'],
                'properties' => ['word' => ['type' => 'string'], 'number' => ['type' => 'integer']],
            ],
            maxOutputTokens: 100,
        );

        try {
            $response = $client->generate($request);
        } catch (AiProviderFailure $failure) {
            $this->error('Le fournisseur a refusé ou n\'a pas répondu : '.$failure->reason.'.');

            return self::FAILURE;
        } catch (AiInvalidResponse $invalid) {
            $this->error('Réponse inexploitable : '.$invalid->reason.'.');

            return self::FAILURE;
        }

        $this->info('Connexion réussie.');
        $this->line('Modèle : '.$response->model.(AiPricing::has($response->model) ? '' : ' (aucun prix dans config/ai.php : à ajouter)'));
        $this->line('Jetons : '.$response->inputTokens.' en entrée, '.$response->outputTokens.' en sortie.');
        $this->line('Coût estimé : '.AiPricing::cost($response->model, $response->inputTokens, $response->outputTokens).' micro-'.config('ai.currency').'.');
        $this->line('Clés JSON reçues : '.implode(', ', array_keys($response->data)).'.');

        return self::SUCCESS;
    }
}
