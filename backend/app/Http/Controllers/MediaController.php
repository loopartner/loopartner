<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMediaRequest;
use App\Http\Resources\MediaResource;
use App\Models\User;
use App\Support\Media\MediaLibrary;
use Illuminate\Http\JsonResponse;

/**
 * Upload d'images, partagé par tous les modules.
 *
 * Le média créé n'est rattaché à rien : c'est l'endpoint du module concerné (PATCH /me,
 * PUT /me/provider-profile…) qui le rattache par son uuid. Sans rattachement, il est
 * supprimé au bout de 24 heures.
 */
class MediaController extends Controller
{
    public function store(StoreMediaRequest $request, MediaLibrary $library): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $media = $library->store($user, $request->uploadedImage(), $request->purpose());

        return (new MediaResource($media))->response()->setStatusCode(201);
    }
}
