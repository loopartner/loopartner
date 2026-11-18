<?php

namespace App\Support\Ai;

use App\Support\Ai\Exceptions\AiInvalidResponse;
use App\Support\Ai\Exceptions\AiProviderFailure;

/**
 * Contrat d'un fournisseur d'IA (décision 34). Les modules ne l'appellent jamais directement :
 * ils passent par AiGateway, qui vérifie la disponibilité, réserve le coût et journalise.
 *
 * Un pilote :
 * - envoie seulement le contenu de la requête (aucune donnée ajoutée) ;
 * - ne journalise jamais le contenu envoyé ni la réponse ;
 * - lève AiProviderFailure (fournisseur injoignable, refus, délai dépassé) ou
 *   AiInvalidResponse (réponse inexploitable), jamais une exception qui contiendrait le corps
 *   d'une requête ou d'une réponse.
 */
interface AiClient
{
    /**
     * Clé du pilote (disabled, fake, mistral).
     */
    public function name(): string;

    /**
     * Nom affichable du fournisseur (information des utilisateurs), null si l'IA est désactivée.
     */
    public function providerLabel(): ?string;

    /**
     * Le pilote peut-il appeler un fournisseur (clé et configuration présentes et sûres) ?
     */
    public function enabled(): bool;

    /**
     * @throws AiProviderFailure
     * @throws AiInvalidResponse
     */
    public function generate(AiRequest $request): AiResponse;
}
