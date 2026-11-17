<?php

namespace Database\Factories\Modules\Compliance;

use App\Modules\Compliance\Enums\RuleKind;
use App\Modules\Compliance\Enums\RuleStatus;
use App\Modules\Compliance\Models\ComplianceRule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Règle de TEST, en brouillon par défaut : statut juridique français fictif, valide sans fin
 * depuis le 1er janvier 2026. Les valeurs des états (seuils, taux, dates) sont fictives et ne
 * servent qu'aux tests : elles ne décrivent aucune règle réelle.
 *
 * @extends Factory<ComplianceRule>
 */
class ComplianceRuleFactory extends Factory
{
    protected $model = ComplianceRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'country' => 'FR',
            'kind' => RuleKind::LegalForm,
            'code' => 'fr.legal_form.test-'.Str::lower(Str::random(8)),
            'valid_from' => '2026-01-01',
            'valid_until' => null,
            'status' => RuleStatus::Draft,
            'version' => 1,
            'title' => ['fr' => 'Statut de test', 'en' => 'Test legal form'],
            'summary' => null,
            'conditions' => ['legal_forms' => [], 'activity_types' => [], 'vat_regimes' => [], 'options' => []],
            'payload' => [
                'activity_types' => ['services_commercial', 'sales'],
                'vat_regimes' => ['franchise', 'simplified'],
                'options' => ['declaration_frequency'],
            ],
            'source_url' => 'https://entreprendre.service-public.fr/vosdroits/F23961',
            'source_label' => 'Source de test',
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (): array => [
            'status' => RuleStatus::Verified,
            'last_verified_at' => now(),
            'verification_note' => 'Vérifié pour les tests par le cabinet Exemple le 2026-01-02 sur la page source.',
        ]);
    }

    public function retired(): static
    {
        return $this->state(fn (): array => [
            'status' => RuleStatus::Retired,
            'retired_at' => now(),
            'retirement_reason' => 'Retirée pour les tests.',
        ]);
    }

    public function demo(): static
    {
        return $this->state(fn (): array => ['is_demo' => true]);
    }

    /**
     * Statut juridique de code donné.
     *
     * @param  list<string>  $activities
     * @param  list<string>  $vatRegimes
     * @param  list<string>  $options
     */
    public function legalForm(string $code, array $activities = ['services_commercial', 'sales'], array $vatRegimes = ['franchise', 'simplified'], array $options = ['declaration_frequency']): static
    {
        return $this->state(fn (): array => [
            'kind' => RuleKind::LegalForm,
            'code' => $code,
            'title' => ['fr' => 'Statut '.$code, 'en' => null],
            'payload' => ['activity_types' => $activities, 'vat_regimes' => $vatRegimes, 'options' => $options],
        ]);
    }

    /**
     * Échéance de code donné, avec sa récurrence et ses conditions.
     *
     * @param  array<string, mixed>  $schedule
     * @param  array<string, mixed>  $conditions
     * @param  list<array<string, mixed>>  $tasks
     */
    public function deadline(string $code, array $schedule, array $conditions = [], array $tasks = []): static
    {
        return $this->state(fn (): array => [
            'kind' => RuleKind::Deadline,
            'code' => $code,
            'title' => ['fr' => 'Échéance '.$code, 'en' => 'Deadline '.$code],
            'conditions' => [...['legal_forms' => [], 'activity_types' => [], 'vat_regimes' => [], 'options' => []], ...$conditions],
            'payload' => ['schedule' => $schedule, 'skip_creation_year' => false, 'tasks' => $tasks, 'expects_document' => false],
        ]);
    }

    /**
     * Seuil de chiffre d'affaires (montant en centimes, fictif).
     *
     * @param  list<string>  $activities
     * @param  array<string, mixed>  $conditions
     */
    public function threshold(string $code, ?int $amount, array $activities = ['services_commercial'], bool $prorate = false, array $conditions = []): static
    {
        return $this->state(fn (): array => [
            'kind' => RuleKind::Threshold,
            'code' => $code,
            'title' => ['fr' => 'Seuil '.$code, 'en' => null],
            'conditions' => [...['legal_forms' => [], 'activity_types' => [], 'vat_regimes' => [], 'options' => []], ...$conditions],
            'payload' => [
                'amount' => $amount,
                'activity_types' => $activities,
                'prorate_first_year' => $prorate,
                'consequence' => ['fr' => 'Conséquence de test du dépassement.', 'en' => null],
            ],
        ]);
    }

    /**
     * Taux en points de base (fictif).
     *
     * @param  list<string>  $activities
     * @param  array<string, mixed>  $conditions
     */
    public function rate(string $code, ?int $basisPoints, array $activities = ['services_commercial'], string $category = 'social', array $conditions = []): static
    {
        return $this->state(fn (): array => [
            'kind' => RuleKind::Rate,
            'code' => $code,
            'title' => ['fr' => 'Taux '.$code, 'en' => null],
            'conditions' => [...['legal_forms' => [], 'activity_types' => [], 'vat_regimes' => [], 'options' => []], ...$conditions],
            'payload' => ['basis_points' => $basisPoints, 'activity_types' => $activities, 'category' => $category],
        ]);
    }

    /**
     * Taux de TVA (module Marketplace) : libellé et champ d'application remplis.
     *
     * @param  array{fr: string, en: string|null}|null  $mention
     * @param  array{fr: string, en: string|null}|null  $clientCertification
     */
    public function vatRate(string $code, ?int $basisPoints, bool $exclusive = false, ?array $mention = null, ?array $clientCertification = null): static
    {
        return $this->state(fn (): array => [
            'kind' => RuleKind::VatRate,
            'code' => $code,
            'title' => ['fr' => 'TVA '.$code, 'en' => null],
            'conditions' => ['legal_forms' => [], 'activity_types' => [], 'vat_regimes' => [], 'options' => []],
            'payload' => [
                'basis_points' => $basisPoints,
                'label' => ['fr' => 'Taux '.$code, 'en' => 'Rate '.$code],
                'scope' => ['fr' => "Champ d'application de ".$code, 'en' => null],
                'mention' => $mention,
                'client_certification' => $clientCertification,
                'exclusive' => $exclusive,
            ],
        ]);
    }

    /**
     * Parcours type.
     *
     * @param  list<array<string, mixed>>  $steps
     */
    public function journey(string $code, array $steps): static
    {
        return $this->state(fn (): array => [
            'kind' => RuleKind::Journey,
            'code' => $code,
            'title' => ['fr' => 'Parcours '.$code, 'en' => null],
            'payload' => ['steps' => $steps],
        ]);
    }
}
