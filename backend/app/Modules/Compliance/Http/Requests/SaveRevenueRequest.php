<?php

namespace App\Modules\Compliance\Http\Requests;

/**
 * PUT /me/compliance/companies/{company}/revenue/{AAAA-MM} : chiffre d'affaires du mois.
 *
 *   {"lines": {"services_commercial": 412050}}
 *
 * - Clés : types d'activité déclarés pour l'entreprise seulement.
 * - Montants : entiers JSON en centimes (pas de chaîne, pas de décimale), de 0 à
 *   compliance.revenue.max_amount. Un avoir se déduit du montant du mois : pas de négatif.
 */
class SaveRevenueRequest extends CompanyRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'array:'.implode(',', $this->company()->activity_types)],
            'lines.*' => ['required', 'integer:strict', 'min:0', 'max:'.(int) config('compliance.revenue.max_amount')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.array' => 'Les montants doivent être donnés pour les types d\'activité de l\'entreprise.',
            'lines.*.integer' => 'Un montant est un nombre entier de centimes (par exemple 412050 pour 4 120,50).',
        ];
    }
}
