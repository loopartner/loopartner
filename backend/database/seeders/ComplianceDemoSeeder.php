<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Compliance\Actions\CreateCompany;
use App\Modules\Compliance\Actions\ManageDeadline;
use App\Modules\Compliance\Actions\ManageJourney;
use App\Modules\Compliance\Actions\ManageRevenue;
use App\Modules\Compliance\Actions\ManageTask;
use App\Modules\Compliance\Enums\RuleKind;
use App\Modules\Compliance\Enums\RuleStatus;
use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Models\ComplianceRule;
use App\Modules\Compliance\Support\RuleContent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Données de démonstration du suivi administratif (module Compliance), appelées par
 * DatabaseSeeder UNIQUEMENT si DemoDataGuard l'autorise (poste local). Aucun email.
 *
 * - Règles FICTIVES (is_demo, codes « demo.* », titres « [Démo] », valeurs rondes) : elles ne
 *   décrivent aucune règle réelle et ne sont utilisables qu'en environnement local
 *   (ComplianceRule::usable()). security:check-config échoue si une base de production en
 *   contient. Elles sont distinctes des brouillons à vérifier (fr-2027.draft.json).
 * - entrepreneur@example.com : une micro-entreprise de services, avec son calendrier, des
 *   tâches, un parcours, une échéance personnelle et un chiffre d'affaires proche du seuil.
 * - prestataire@example.com (premium, voir DatabaseSeeder) : deux entreprises.
 *
 * Idempotent : les règles sont repérées par leur code, et un utilisateur qui a déjà une
 * entreprise n'en reçoit pas d'autre.
 */
class ComplianceDemoSeeder extends Seeder
{
    /**
     * Seuil fictif de la micro-entreprise de démonstration pour les services (centimes) : le
     * chiffre d'affaires de l'entrepreneur est saisi à 85 % de ce montant.
     */
    public const DEMO_SERVICES_THRESHOLD = 5_000_000;

    public const DEMO_REVENUE_RATIO_PERCENT = 85;

    public function run(): void
    {
        if (! DemoDataGuard::allowsSeeding()) {
            throw new RuntimeException('Les données de démonstration du suivi administratif sont réservées au poste local (DemoDataGuard).');
        }

        Notification::fake();

        $this->seedDemoRules();

        $entrepreneur = User::query()->where('email', 'entrepreneur@example.com')->first();
        $provider = User::query()->where('email', 'prestataire@example.com')->first();

        if ($entrepreneur instanceof User && ! Company::query()->where('user_id', $entrepreneur->id)->exists()) {
            $this->seedEntrepreneur($entrepreneur);
        }

        if ($provider instanceof User && ! Company::query()->where('user_id', $provider->id)->exists()) {
            $this->seedProvider($provider);
        }
    }

    /**
     * Règles fictives, vérifiées, en vigueur depuis le 1er janvier de l'année précédente.
     */
    private function seedDemoRules(): void
    {
        $from = (CarbonImmutable::now()->year - 1).'-01-01';
        $empty = ['legal_forms' => [], 'activity_types' => [], 'vat_regimes' => [], 'options' => []];
        $micro = [...$empty, 'legal_forms' => ['demo.legal_form.micro']];

        $rules = [
            [RuleKind::LegalForm, 'demo.legal_form.micro', '[Démo] Micro-entreprise fictive', $empty, [
                'activity_types' => ['services_commercial', 'services_liberal', 'sales'],
                'vat_regimes' => ['franchise', 'simplified'],
                'options' => ['declaration_frequency', 'income_tax_withholding'],
            ]],
            [RuleKind::LegalForm, 'demo.legal_form.company', '[Démo] Société fictive', $empty, [
                'activity_types' => ['services_commercial', 'services_liberal', 'sales'],
                'vat_regimes' => ['simplified', 'normal'],
                'options' => ['has_employees'],
            ]],
            [RuleKind::Deadline, 'demo.revenue-declaration.quarterly', '[Démo] Déclaration trimestrielle fictive', [...$micro, 'options' => ['declaration_frequency' => 'quarterly']], [
                'schedule' => ['type' => 'quarterly', 'months' => [1, 4, 7, 10], 'day' => 31],
                'tasks' => [['key' => 'declarer', 'title' => ['fr' => '[Démo] Déclarer le chiffre d\'affaires', 'en' => null], 'service_category' => null]],
            ]],
            [RuleKind::Deadline, 'demo.revenue-declaration.monthly', '[Démo] Déclaration mensuelle fictive', [...$micro, 'options' => ['declaration_frequency' => 'monthly']], [
                'schedule' => ['type' => 'monthly', 'day' => 31],
            ]],
            [RuleKind::Deadline, 'demo.annual-contribution', '[Démo] Cotisation annuelle fictive', $empty, [
                'schedule' => ['type' => 'yearly', 'month' => 12, 'day' => 15],
                'skip_creation_year' => true,
                'tasks' => [
                    ['key' => 'verifier', 'title' => ['fr' => '[Démo] Vérifier le montant', 'en' => null], 'service_category' => 'tenue-comptable'],
                    ['key' => 'payer', 'title' => ['fr' => '[Démo] Payer', 'en' => null], 'service_category' => null],
                ],
            ]],
            [RuleKind::Deadline, 'demo.accounts-approval', '[Démo] Approbation des comptes fictive', [...$empty, 'legal_forms' => ['demo.legal_form.company']], [
                'schedule' => ['type' => 'after_fiscal_year_end', 'offset_months' => 6, 'days' => 0],
                'tasks' => [['key' => 'comptes', 'title' => ['fr' => '[Démo] Faire établir les comptes', 'en' => null], 'service_category' => 'tenue-comptable']],
            ]],
            [RuleKind::Threshold, 'demo.micro.ceiling.services', '[Démo] Plafond fictif des services', $micro, [
                'amount' => self::DEMO_SERVICES_THRESHOLD,
                'activity_types' => ['services_commercial', 'services_liberal'],
                'prorate_first_year' => true,
                'consequence' => ['fr' => '[Démo] Texte fictif : au-delà, le régime fictif de démonstration ne s\'applique plus.', 'en' => null],
            ]],
            [RuleKind::Threshold, 'demo.vat.franchise.services', '[Démo] Franchise de TVA fictive', [...$empty, 'vat_regimes' => ['franchise']], [
                'amount' => 4_000_000,
                'activity_types' => ['services_commercial', 'services_liberal'],
                'prorate_first_year' => false,
                'consequence' => ['fr' => '[Démo] Texte fictif : au-delà, la TVA fictive de démonstration s\'applique.', 'en' => null],
            ]],
            [RuleKind::Rate, 'demo.micro.social.services', '[Démo] Cotisations fictives des services', $micro, [
                'basis_points' => 2_000, 'activity_types' => ['services_commercial', 'services_liberal'], 'category' => 'social',
            ]],
            [RuleKind::Rate, 'demo.micro.training', '[Démo] Formation fictive', $micro, [
                'basis_points' => 100, 'activity_types' => ['services_commercial', 'services_liberal', 'sales'], 'category' => 'training',
            ]],
            [RuleKind::Journey, 'demo.journey.create-company', '[Démo] Créer mon entreprise', $empty, [
                'steps' => [
                    ['key' => 'statut', 'title' => ['fr' => '[Démo] Choisir son statut', 'en' => null], 'description' => null, 'service_category' => 'creation-statuts', 'source_url' => null],
                    ['key' => 'banque', 'title' => ['fr' => '[Démo] Ouvrir un compte bancaire', 'en' => null], 'description' => null, 'service_category' => null, 'source_url' => null],
                    ['key' => 'compta', 'title' => ['fr' => '[Démo] Organiser sa comptabilité', 'en' => null], 'description' => null, 'service_category' => 'tenue-comptable', 'source_url' => null],
                ],
            ]],
        ];

        foreach ($rules as [$kind, $code, $title, $conditions, $payload]) {
            if (ComplianceRule::query()->where('code', $code)->where('is_demo', true)->exists()) {
                continue;
            }

            $rule = new ComplianceRule;
            $rule->forceFill([
                'uuid' => (string) Str::uuid(),
                'country' => 'FR',
                'kind' => $kind,
                'code' => $code,
                'valid_from' => $from,
                'valid_until' => null,
                'status' => RuleStatus::Verified,
                'version' => 1,
                'title' => ['fr' => $title, 'en' => null],
                'summary' => ['fr' => 'Règle FICTIVE de démonstration : elle ne décrit aucune règle réelle.', 'en' => null],
                'conditions' => RuleContent::conditions($conditions),
                'payload' => RuleContent::payload($kind, $payload),
                'source_url' => 'https://www.example.com/regle-fictive-de-demonstration',
                'source_label' => 'Règle fictive de démonstration',
                'last_verified_at' => now(),
                'verification_note' => 'Règle FICTIVE de démonstration, créée par le seeder local : aucune vérification réelle.',
                'internal_notes' => 'Données de démonstration (is_demo) : ne jamais copier en production.',
                'is_demo' => true,
            ])->save();
        }
    }

    private function seedEntrepreneur(User $user): void
    {
        $now = CarbonImmutable::now('Europe/Paris');
        $company = app(CreateCompany::class)->handle($user, [
            'name' => 'Atelier Démo Conseil',
            'country' => 'FR',
            'legal_form' => 'demo.legal_form.micro',
            'activity_types' => ['services_commercial'],
            'vat_regime' => 'franchise',
            'created_on' => ($now->year - 1).'-03-01',
            'options' => ['declaration_frequency' => 'quarterly', 'income_tax_withholding' => false],
            'registration_number' => '00000000000000',
        ]);

        // Chiffre d'affaires de l'année réparti sur les mois écoulés, à 85 % du seuil fictif.
        $months = max(1, $now->month - 1);
        $monthly = intdiv(intdiv(self::DEMO_SERVICES_THRESHOLD * self::DEMO_REVENUE_RATIO_PERCENT, 100), $months);

        foreach (range(1, $months) as $month) {
            app(ManageRevenue::class)->save($company, sprintf('%d-%02d', $now->year, $month), ['services_commercial' => $monthly]);
        }

        app(ManageJourney::class)->start($company, 'demo.journey.create-company');
        app(ManageDeadline::class)->create($company, [
            'title' => '[Démo] Rendez-vous avec la banque',
            'due_on' => $now->addDays(10)->toDateString(),
            'notes' => 'Échéance personnelle de démonstration.',
        ]);
        app(ManageTask::class)->create($company, ['title' => '[Démo] Comparer les assurances professionnelles', 'service_category' => 'assurances']);
    }

    private function seedProvider(User $user): void
    {
        $now = CarbonImmutable::now('Europe/Paris');
        $studio = app(CreateCompany::class)->handle($user, [
            'name' => 'Studio Démo',
            'country' => 'FR',
            'legal_form' => 'demo.legal_form.micro',
            'activity_types' => ['services_commercial', 'sales'],
            'vat_regime' => 'franchise',
            'created_on' => ($now->year - 2).'-09-01',
            'options' => ['declaration_frequency' => 'monthly'],
        ]);

        app(ManageRevenue::class)->save($studio, $now->subMonthNoOverflow()->format('Y-m'), ['services_commercial' => 300_000, 'sales' => 50_000]);

        app(CreateCompany::class)->handle($user, [
            'name' => 'Démo Conseil SAS',
            'country' => 'FR',
            'legal_form' => 'demo.legal_form.company',
            'activity_types' => ['services_liberal'],
            'vat_regime' => 'simplified',
            'created_on' => ($now->year - 1).'-01-15',
            'fiscal_year_end' => '12-31',
            'options' => ['has_employees' => true],
        ]);
    }
}
