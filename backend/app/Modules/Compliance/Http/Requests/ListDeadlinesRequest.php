<?php

namespace App\Modules\Compliance\Http\Requests;

use App\Modules\Compliance\Enums\DeadlineStatus;
use App\Support\Pagination;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Calendrier (GET /me/compliance/deadlines et GET …/companies/{company}/deadlines) :
 * période (from, to : jours, 400 jours au plus ; par défaut des 31 derniers jours aux
 * 92 prochains), état, entreprise (liste globale), pagination standard.
 *
 * L'autorisation (rôle, entreprise du demandeur) est vérifiée par le contrôleur.
 */
class ListDeadlinesRequest extends FormRequest
{
    public const MAX_DAYS = 400;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31', $this->withinRange()],
            'status' => ['sometimes', 'string', Rule::in([...DeadlineStatus::values(), 'overdue'])],
            'company' => ['sometimes', 'uuid'],
            ...Pagination::rules(),
        ];
    }

    public function from(): string
    {
        return (string) ($this->validated('from') ?? now()->subDays(31)->toDateString());
    }

    public function to(): string
    {
        return (string) ($this->validated('to') ?? now()->addDays(92)->toDateString());
    }

    private function withinRange(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $from = strtotime((string) ($this->input('from') ?? now()->subDays(31)->toDateString()));
            $to = strtotime((string) $value);

            if ($from === false || $to === false || $to < $from || ($to - $from) / 86400 > self::MAX_DAYS) {
                $fail('La période doit aller de "from" à "to", sur '.self::MAX_DAYS.' jours au plus.');
            }
        };
    }
}
