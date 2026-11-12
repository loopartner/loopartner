<?php

namespace App\Modules\Blog\Exceptions;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Quota gratuit d'articles atteint : 403 avec un code stable, documenté pour le frontend, qui
 * affiche alors une invitation à passer premium.
 *
 *   {"message": "…", "code": "premium_required", "quota": {"limit": 2, "used": 2, "remaining": 0}}
 */
class PremiumRequired extends HttpException
{
    public const CODE = 'premium_required';

    public function __construct(private int $limit, private int $used)
    {
        parent::__construct(403, sprintf(
            'Vous avez utilisé vos %d articles gratuits. Passez premium pour soumettre de nouveaux articles.',
            $limit,
        ));
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => self::CODE,
            'quota' => [
                'limit' => $this->limit,
                'used' => $this->used,
                'remaining' => max(0, $this->limit - $this->used),
            ],
        ], 403);
    }
}
