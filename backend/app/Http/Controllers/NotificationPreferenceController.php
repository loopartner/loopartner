<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateNotificationPreferencesRequest;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Support\Locale;
use App\Support\Notifications\NotificationCategories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Préférences de notifications de l'utilisateur connecté (noyau) : catégories qu'il voit selon
 * ses rôles, obligatoires ou non, activées ou non pour chaque canal.
 */
class NotificationPreferenceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return $this->respond($request);
    }

    public function update(UpdateNotificationPreferencesRequest $request): JsonResponse
    {
        /** @var array{preferences: list<array{category: string, channel: string, enabled: bool}>} $data */
        $data = $request->validated();
        $user = self::user($request);

        // Une seule instruction atomique (index unique user_id, category, channel) : deux envois
        // simultanés ne tentent pas d'insérer deux fois la même préférence. Les paires sont
        // distinctes (validation), ce qu'exige ON CONFLICT sous SQLite.
        NotificationPreference::query()->upsert(
            array_map(fn (array $choice): array => [
                'user_id' => $user->id,
                'category' => $choice['category'],
                'channel' => $choice['channel'],
                'enabled' => $choice['enabled'],
            ], $data['preferences']),
            ['user_id', 'category', 'channel'],
            ['enabled'],
        );

        return $this->respond($request);
    }

    private function respond(Request $request): JsonResponse
    {
        return response()->json([
            'data' => NotificationCategories::forUser(self::user($request), Locale::fromRequest($request)),
        ]);
    }

    private static function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
