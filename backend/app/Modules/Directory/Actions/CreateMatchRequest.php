<?php

namespace App\Modules\Directory\Actions;

use App\Models\User;
use App\Modules\Directory\Enums\MatchRequestStatus;
use App\Modules\Directory\Enums\MatchRequestType;
use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Directory\Models\Project;
use App\Modules\Directory\Models\UserBlock;
use App\Modules\Directory\Notifications\MatchingNotification;
use App\Modules\Directory\Notifications\MatchRequestReceivedNotification;
use App\Support\SecurityLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Crée une demande de mise en relation en attente (utilisée par SendProposal et
 * SendContactRequest), puis prévient son destinataire par email.
 *
 * - Blocage dans un sens ou dans l'autre : 403 (déjà vérifié par la Policy, revérifié ici).
 * - Au plus MatchRequest::MAX_PENDING_SENT demandes en attente par auteur : 409. La ligne
 *   users de l'auteur est verrouillée pendant le comptage (envois simultanés).
 * - Au plus une demande active par couple : 409. L'index unique sur active_key le garantit
 *   même si deux envois passent la vérification en même temps.
 */
class CreateMatchRequest
{
    /**
     * @param  array{message: string, price_amount?: int|null, price_currency?: string|null}  $data
     * @param  bool  $notify  false quand le module appelant prévient lui-même le destinataire
     *                        (réservation d'un rendez-vous sur la page publique d'un prestataire)
     *
     * @throws AccessDeniedHttpException
     * @throws ConflictHttpException
     */
    public function handle(User $author, MatchRequestType $type, int $recipientId, ?Project $project, array $data, string $duplicateMessage, bool $notify = true): MatchRequest
    {
        $key = MatchRequest::activeKey($type, $author->id, $type === MatchRequestType::Proposal && $project !== null ? $project->id : $recipientId);

        try {
            $matchRequest = DB::transaction(function () use ($author, $type, $recipientId, $project, $data, $key, $duplicateMessage, $notify): MatchRequest {
                User::query()->whereKey($author->id)->lockForUpdate()->first();

                if (UserBlock::existsBetween($author->id, $recipientId)) {
                    throw new AccessDeniedHttpException('Vous ne pouvez pas envoyer de demande à ce destinataire.');
                }

                if (MatchRequest::query()->where('active_key', $key)->exists()) {
                    throw new ConflictHttpException($duplicateMessage);
                }

                $pending = MatchRequest::query()
                    ->where('author_id', $author->id)
                    ->where('status', MatchRequestStatus::Pending->value)
                    ->count();

                if ($pending >= MatchRequest::MAX_PENDING_SENT) {
                    throw new ConflictHttpException(sprintf(
                        'Vous avez déjà %d demandes en attente de réponse : retirez-en une avant d\'en envoyer une nouvelle.',
                        MatchRequest::MAX_PENDING_SENT,
                    ));
                }

                $amount = $data['price_amount'] ?? null;

                $matchRequest = new MatchRequest([
                    'message' => $data['message'],
                    'price_amount' => $amount,
                    'price_currency' => $amount === null ? null : ($data['price_currency'] ?? null),
                ]);

                $matchRequest->forceFill([
                    'uuid' => (string) Str::uuid(),
                    'type' => $type,
                    'author_id' => $author->id,
                    'recipient_id' => $recipientId,
                    'project_id' => $project?->id,
                    'status' => MatchRequestStatus::Pending,
                    'active_key' => $key,
                    'expires_at' => now()->addDays(MatchRequest::EXPIRATION_DAYS),
                ])->save();

                if ($notify) {
                    $recipient = User::query()->findOrFail($recipientId);
                    MatchingNotification::sendTo($recipient, new MatchRequestReceivedNotification($matchRequest));
                }

                return $matchRequest;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ConflictHttpException($duplicateMessage);
        }

        SecurityLog::record('match_requests.created', [
            'user_id' => $author->id,
            'type' => $type->value,
            'match_request' => $matchRequest->uuid,
            'recipient_id' => $recipientId,
        ]);

        return $matchRequest;
    }
}
