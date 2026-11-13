<?php

namespace App\Modules\Compliance\Actions;

use App\Models\User;
use App\Modules\Compliance\Enums\DeadlineStatus;
use App\Modules\Compliance\Enums\ReminderFrequency;
use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Models\ComplianceSettings;
use App\Modules\Compliance\Models\Deadline;
use App\Modules\Compliance\Models\RevenueEntry;
use App\Modules\Compliance\Models\ThresholdAlert;
use App\Modules\Compliance\Notifications\ComplianceDigestNotification;
use App\Modules\Compliance\Support\DeadlineTitle;
use App\Modules\Compliance\Support\Entitlements;
use App\Modules\Compliance\Support\RuleCatalog;
use App\Support\Locale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Récapitulatif par email du suivi administratif (tâche compliance:send-reminders, chaque jour
 * à 06:00 UTC) : UN email par utilisateur et par jour au plus, pour ménager le quota Resend.
 *
 * Contenu, pour les entreprises actives (pas celles verrouillées après la fin du premium) :
 * - rappels : échéances à faire arrivées à un délai de rappel (daily), ou, le lundi, les
 *   échéances des 14 jours suivants (weekly) ;
 * - paliers de seuil atteints et dépassements projetés pas encore signalés ;
 * - calendrier modifié par un changement de règles ;
 * - le 1er décembre, l'annonce de la purge annuelle du chiffre d'affaires le plus ancien.
 *
 * Sans doublon : le récapitulatif du jour (compliance_digests) puis chaque rappel
 * (compliance_reminder_deliveries) sont réservés par un INSERT sur un index unique AVANT
 * l'envoi ; une exécution concurrente ou relancée n'envoie rien de plus.
 *
 * Jamais de montant, de numéro d'entreprise ni de note : titres, dates, paliers en pourcentage.
 */
class SendDigests
{
    private const WEEKLY_DAYS = 14;

    public function __construct(private Entitlements $entitlements) {}

    /**
     * @return int nombre de récapitulatifs envoyés
     */
    public function handle(): int
    {
        $sent = 0;

        User::query()
            ->whereIn('id', Company::query()->select('user_id'))
            ->whereNotNull('email_verified_at')
            ->chunkById(200, function (Collection $users) use (&$sent): void {
                foreach ($users as $user) {
                    /** @var User $user */
                    $sent += $this->sendTo($user) ? 1 : 0;
                }
            });

        return $sent;
    }

    public function sendTo(User $user): bool
    {
        $settings = ComplianceSettings::for($user->id);

        if (! $settings->emails_enabled) {
            return false;
        }

        $locale = in_array($user->locale, Locale::SUPPORTED, true) ? $user->locale : Locale::DEFAULT;
        $companies = Company::query()->where('user_id', $user->id)->orderBy('id')->get();
        $active = $companies->reject(fn (Company $company): bool => $this->entitlements->isLocked($company))->values();
        $named = $companies->count() > 1;

        $reminders = $this->reminderCandidates($active, $settings);
        $alerts = ThresholdAlert::query()->whereIn('company_id', $active->pluck('id'))->whereNull('notified_at')->orderBy('id')->get();
        $changed = $active->filter(fn (Company $company): bool => $company->calendar_changed_at !== null)->values();
        $retentionYear = $this->retentionYear($companies);

        if ($reminders === [] && $alerts->isEmpty() && $changed->isEmpty() && $retentionYear === null) {
            return false;
        }

        $claimed = DB::table('compliance_digests')->insertOrIgnore([
            'user_id' => $user->id,
            'sent_on' => now()->toDateString(),
            'created_at' => now(),
        ]);

        if ($claimed !== 1) {
            return false;
        }

        $deadlines = [];

        foreach ($reminders as [$deadline, $offset]) {
            /** @var Deadline $deadline */
            if ($offset !== null) {
                $reserved = DB::table('compliance_reminder_deliveries')->insertOrIgnore([
                    'deadline_id' => $deadline->id,
                    'offset_days' => $offset,
                    'sent_at' => now(),
                ]);

                if ($reserved !== 1) {
                    continue;
                }
            }

            $deadlines[$deadline->id] = [
                'date' => $deadline->due_on->toDateString(),
                'title' => DeadlineTitle::of($deadline, $locale),
                'company' => $named ? $deadline->company->name : null,
            ];
        }

        ThresholdAlert::query()->whereKey($alerts->pluck('id'))->whereNull('notified_at')->update(['notified_at' => now()]);
        Company::query()->whereKey($changed->pluck('id'))->update(['calendar_changed_at' => null]);

        $alertLines = $alerts->map(function (ThresholdAlert $alert) use ($active, $locale, $named): array {
            /** @var Company $company */
            $company = $active->firstWhere('id', $alert->company_id);
            $rule = RuleCatalog::find($company->country, $alert->rule_code, CarbonImmutable::create($alert->year, 1, 1));

            return [
                'title' => $rule?->titleIn($locale) ?? $alert->rule_code,
                'kind' => $alert->kind,
                'level' => $alert->level,
                'year' => $alert->year,
                'company' => $named ? $company->name : null,
            ];
        })->all();

        $payload = [
            'deadlines' => array_values(collect($deadlines)->sortBy('date')->all()),
            'alerts' => $alertLines,
            'changed' => $changed->map(fn (Company $company): ?string => $named ? $company->name : null)->all(),
            'retention_year' => $retentionYear,
        ];

        if ($payload['deadlines'] === [] && $payload['alerts'] === [] && $payload['changed'] === [] && $retentionYear === null) {
            return false;
        }

        ComplianceDigestNotification::sendTo($user, $payload);

        return true;
    }

    /**
     * Échéances à rappeler : [échéance, délai] (délai null pour le récapitulatif hebdomadaire).
     *
     * @param  Collection<int, Company>  $companies
     * @return list<array{0: Deadline, 1: int|null}>
     */
    private function reminderCandidates(Collection $companies, ComplianceSettings $settings): array
    {
        $candidates = [];
        $offsets = $this->entitlements->reminderOffsets($settings);

        foreach ($companies as $company) {
            $today = CarbonImmutable::parse(CarbonImmutable::now(RuleCatalog::timezone($company->country))->toDateString());
            $base = Deadline::query()
                ->where('company_id', $company->id)
                ->where('status', DeadlineStatus::Todo->value)
                ->whereNull('obsolete_at')
                ->with(['rule', 'company']);

            if ($settings->reminder_frequency === ReminderFrequency::Weekly) {
                if ($today->isMonday()) {
                    $window = (clone $base)
                        ->whereDate('due_on', '>=', $today->toDateString())
                        ->whereDate('due_on', '<=', $today->addDays(self::WEEKLY_DAYS)->toDateString())
                        ->orderBy('due_on');

                    foreach ($window->get() as $deadline) {
                        $candidates[] = [$deadline, null];
                    }
                }

                continue;
            }

            foreach ($offsets as $offset) {
                foreach ((clone $base)->whereDate('due_on', $today->addDays($offset)->toDateString())->get() as $deadline) {
                    $candidates[] = [$deadline, $offset];
                }
            }
        }

        return $candidates;
    }

    /**
     * Le 1er décembre : année de chiffre d'affaires qui sera supprimée au 1er janvier
     * (security.retention.compliance_revenue_years), si l'utilisateur en a saisi.
     *
     * @param  Collection<int, Company>  $companies
     */
    private function retentionYear(Collection $companies): ?int
    {
        $today = CarbonImmutable::now();

        if ($today->month !== 12 || $today->day !== 1) {
            return null;
        }

        $purgedBefore = $today->year + 1 - (int) config('security.retention.compliance_revenue_years');
        $exists = RevenueEntry::query()
            ->whereIn('company_id', $companies->pluck('id'))
            ->whereDate('period', '<', sprintf('%d-01-01', $purgedBefore))
            ->exists();

        return $exists ? $purgedBefore - 1 : null;
    }
}
