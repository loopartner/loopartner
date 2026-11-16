<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\Notifications\NotificationCategories;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /me/notification-preferences :
 * {"preferences": [{"category": "events.reminders", "channel": "mail", "enabled": false}]}
 *
 * Seules les catégories que l'utilisateur voit (selon ses rôles) sont acceptées ; une
 * catégorie obligatoire ne peut pas être désactivée (422). Chaque couple catégorie et canal
 * n'apparaît qu'une fois. Aucune autre clé n'est acceptée.
 */
class UpdateNotificationPreferencesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();
        $visible = NotificationCategories::visibleTo($user);
        $channels = NotificationCategories::channels();

        return [
            'preferences' => ['required', 'array', 'list', 'min:1', 'max:'.max(1, count($visible) * count($channels)), $this->distinctPairs()],
            'preferences.*' => ['required', 'array:category,channel,enabled'],
            'preferences.*.category' => ['required', 'string', 'max:64', Rule::in($visible), $this->notMandatory()],
            'preferences.*.channel' => ['required', 'string', Rule::in($channels)],
            'preferences.*.enabled' => ['required', 'boolean:strict'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'preferences.*.category.in' => 'Cette catégorie de notifications n\'existe pas pour votre compte.',
        ];
    }

    private function notMandatory(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $index = explode('.', $attribute)[1] ?? null;
            $enabled = $this->input("preferences.{$index}.enabled");

            if (is_string($value) && NotificationCategories::isMandatory($value) && $enabled === false) {
                $fail('Cette catégorie de notifications est obligatoire : elle ne peut pas être désactivée.');
            }
        };
    }

    private function distinctPairs(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                return;
            }

            $pairs = [];

            foreach ($value as $item) {
                if (is_array($item) && is_string($item['category'] ?? null) && is_string($item['channel'] ?? null)) {
                    $pairs[] = $item['category'].'|'.$item['channel'];
                }
            }

            if (count($pairs) !== count(array_unique($pairs))) {
                $fail('Chaque catégorie n\'apparaît qu\'une fois par canal.');
            }
        };
    }
}
