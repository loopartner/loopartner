<?php

namespace App\Support\Ai\Exceptions;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Une génération de cet utilisateur est déjà en cours : 409
 * {"message", "code": "generation_in_progress"}.
 */
class AiBusy extends HttpException
{
    public const CODE = 'generation_in_progress';

    public function __construct()
    {
        parent::__construct(409, 'Une génération est déjà en cours. Attendez sa fin avant d\'en lancer une autre.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => self::CODE], 409);
    }
}
