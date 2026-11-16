<?php

namespace App\Modules\Moderation\Actions;

use App\Models\User;
use App\Modules\Moderation\Enums\ModerationMail;
use App\Modules\Moderation\Enums\NoticePriority;
use App\Modules\Moderation\Enums\NoticeSource;
use App\Modules\Moderation\Enums\NoticeStatus;
use App\Modules\Moderation\Models\Notice;
use App\Modules\Moderation\Notifications\ModerationNotification;
use App\Modules\Moderation\Notifications\UrgentNoticeAlert;
use App\Modules\Moderation\Support\Admins;
use App\Modules\Moderation\Support\Categories;
use App\Modules\Moderation\Support\Keys;
use App\Modules\Moderation\Support\NoticeReceipt;
use App\Modules\Moderation\Support\TrackingToken;
use App\Support\Moderation\ModerationTargets;
use App\Support\Moderation\TargetContent;
use App\Support\RateLimit\ActionThrottle;
use App\Support\SecurityLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Action publique du module Moderation : enregistre un signalement (DSA art. 16).
 *
 * - Le contenu doit être visible du signalant (sinon 404, sans révéler s'il existe) et ne pas
 *   être le sien (422).
 * - Le contenu est copié en texte (jamais d'image) au moment du signalement, avec son empreinte
 *   de version, pour que la modération juge même s'il change ou disparaît.
 * - Un même signalant ne signale qu'une fois un contenu en cours d'examen (409).
 * - Signalant sans compte : nom et email (sauf catégorie à identité facultative), limite par
 *   email, jeton de suivi remis une fois, accusé de réception par email (art. 16 §4). Avec un
 *   compte, la réponse de l'API et « Mes signalements » valent accusé de réception.
 * - Catégorie urgente : alerte immédiate aux admins, une fois par contenu en cours d'examen.
 *
 * Le journal security ne reçoit que des identifiants, le type, la catégorie et la priorité :
 * jamais l'identité du signalant, son explication ni le contenu.
 */
class SubmitNotice
{
    /**
     * Formulaire public (POST /moderation/notices), avec ou sans compte.
     *
     * @param  array{target_type: string, target_ref: string, category: string, explanation: string, location_detail?: string|null, name?: string|null, email?: string|null}  $data
     * @return array{0: Notice, 1: string|null} le signalement et, sans compte, son jeton de suivi
     *
     * @throws NotFoundHttpException|ConflictHttpException|ValidationException
     */
    public function public(?User $reporter, array $data, string $locale): array
    {
        $email = $reporter === null && isset($data['email']) ? Str::lower(trim($data['email'])) : null;

        return $this->create(
            reporter: $reporter,
            type: $data['target_type'],
            ref: $data['target_ref'],
            category: $data['category'],
            source: NoticeSource::Public,
            explanation: $data['explanation'],
            locationDetail: $data['location_detail'] ?? null,
            name: $reporter === null ? ($data['name'] ?? null) : null,
            email: $email,
            locale: $locale,
            sourceReason: null,
            goodFaith: true,
        );
    }

    /**
     * Messagerie (module Directory) : le destinataire signale un message ou une demande qu'il a
     * reçus. Directory a déjà vérifié l'accès ; l'adaptateur le vérifie à nouveau.
     *
     * @throws NotFoundHttpException|ConflictHttpException|ValidationException
     */
    public function inApp(User $reporter, string $type, string $ref, string $category, string $sourceReason, ?string $comment): NoticeReceipt
    {
        [$notice] = $this->create(
            reporter: $reporter,
            type: $type,
            ref: $ref,
            category: $category,
            source: NoticeSource::InApp,
            explanation: $comment,
            locationDetail: null,
            name: null,
            email: null,
            locale: $reporter->locale,
            sourceReason: $sourceReason,
            goodFaith: false,
        );

        return new NoticeReceipt($notice->uuid, 'open', $notice->created_at);
    }

    /**
     * @return array{0: Notice, 1: string|null}
     */
    private function create(
        ?User $reporter,
        string $type,
        string $ref,
        string $category,
        NoticeSource $source,
        ?string $explanation,
        ?string $locationDetail,
        ?string $name,
        #[\SensitiveParameter] ?string $email,
        ?string $locale,
        ?string $sourceReason,
        bool $goodFaith,
    ): array {
        $content = ModerationTargets::get($type)->findVisible($ref, $reporter)
            ?? throw new NotFoundHttpException('Contenu introuvable.');

        if ($reporter !== null && $content->ownerId === $reporter->id) {
            throw ValidationException::withMessages(['target_ref' => 'Vous ne pouvez pas signaler votre propre contenu.']);
        }

        $emailHash = $email !== null ? SecurityLog::emailHash($email) : null;

        if ($emailHash !== null) {
            ActionThrottle::hit('moderation-notices-email', $emailHash, [
                'day' => [(int) config('security.rate_limits.moderation_notices.per_day_per_email'), 86400],
            ]);
        }

        $reporterKey = Keys::reporter($reporter, $emailHash);
        $priority = Categories::priority($category);
        [$token, $tokenHash] = $reporter === null ? TrackingToken::generate() : [null, null];

        $notice = new Notice;
        $notice->forceFill([
            'uuid' => (string) Str::uuid(),
            'target_type' => $type,
            'target_ref' => $content->ref,
            'target_owner_id' => $content->ownerId,
            'category' => $category,
            'priority' => $priority,
            'source' => $source,
            'source_reason' => $sourceReason,
            'explanation' => $explanation,
            'location_detail' => $locationDetail,
            'content_label' => $content->label,
            'content_snapshot' => self::snapshot($content),
            'content_version' => $content->version,
            'reporter_id' => $reporter?->id,
            'reporter_name' => $name,
            'reporter_email' => $email,
            'reporter_email_hash' => $emailHash,
            'reporter_locale' => $locale,
            'tracking_token_hash' => $tokenHash,
            'active_key' => $reporterKey !== null ? Keys::active($type, $content->ref, $reporterKey) : null,
            'good_faith_confirmed_at' => $goodFaith ? now() : null,
            'status' => NoticeStatus::Open,
            'due_at' => now()->addHours($priority->processingHours()),
        ]);

        try {
            DB::transaction(function () use ($notice, $priority): void {
                $notice->save();

                if ($priority === NoticePriority::Urgent) {
                    $this->alertAdmins($notice);
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw new ConflictHttpException('Vous avez déjà signalé ce contenu.');
        }

        SecurityLog::record('moderation.notice_submitted', [
            'user_id' => $reporter?->id,
            'notice' => $notice->uuid,
            'target_type' => $type,
            'category' => $category,
            'priority' => $priority->value,
            'source' => $source->value,
        ]);

        if ($email !== null && $token !== null) {
            ModerationNotification::toAddress($email, $locale, ModerationMail::NoticeReceived, 'moderation.notices', [
                'uuid' => $notice->uuid,
                'type' => $type,
                'path' => self::trackingPath($notice->uuid, $token),
            ]);
        }

        return [$notice, $token];
    }

    /**
     * Page de suivi d'un signalement fait sans compte : le jeton est dans le fragment, que le
     * navigateur n'envoie jamais aux serveurs.
     */
    public static function trackingPath(string $uuid, #[\SensitiveParameter] string $token): string
    {
        return '/moderation/notices/'.$uuid.'#token='.$token;
    }

    /**
     * Une alerte par contenu tant qu'il est en cours d'examen : la première notice urgente
     * ouverte la réserve (sous verrou des signalements ouverts du contenu).
     */
    private function alertAdmins(Notice $notice): void
    {
        $alreadyAlerted = Notice::query()
            ->where('target_type', $notice->target_type)
            ->where('target_ref', $notice->target_ref)
            ->where('status', NoticeStatus::Open->value)
            ->whereNotNull('urgent_alert_sent_at')
            ->lockForUpdate()
            ->exists();

        if ($alreadyAlerted) {
            return;
        }

        $notice->forceFill(['urgent_alert_sent_at' => now()])->save();
        $alert = new UrgentNoticeAlert($notice->category, $notice->priority->processingHours());

        foreach (Admins::recipients() as $admin) {
            UrgentNoticeAlert::sendTo($admin, $alert);
        }
    }

    private static function snapshot(TargetContent $content): string
    {
        return mb_substr($content->text, 0, (int) config('moderation.limits.snapshot'));
    }
}
