<?php

namespace App\Modules\Marketplace\Exceptions;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Identité professionnelle absente ou incomplète pour publier une offre ou envoyer un devis :
 * 409 avec un code stable et la liste de ce qui manque, documentés pour le frontend :
 *
 *   {"message": "…", "code": "seller_profile_incomplete", "missing": ["seller_profile"]}
 *
 * missing : seller_profile (aucune identité), self_certification, country (devis hors des
 * pays proposés), mediator_name, mediator_url (devis à un consommateur).
 */
class SellerProfileIncomplete extends HttpException
{
    public const CODE = 'seller_profile_incomplete';

    /**
     * @param  list<string>  $missing
     */
    public function __construct(public readonly array $missing)
    {
        parent::__construct(409, 'Complétez votre identité professionnelle avant de continuer.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => self::CODE,
            'missing' => $this->missing,
        ], 409);
    }
}
