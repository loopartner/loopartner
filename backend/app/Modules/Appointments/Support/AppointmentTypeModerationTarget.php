<?php

namespace App\Modules\Appointments\Support;

use App\Models\User;
use App\Modules\Accounts\Actions\GetProviderSummaries;
use App\Modules\Appointments\Enums\TypeVisibility;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Models\AppointmentType;
use App\Modules\Directory\Actions\ManageAppointmentContact;
use App\Support\Moderation\ModerationTarget;
use App\Support\Moderation\Restriction;
use App\Support\Moderation\TargetContent;
use Illuminate\Support\Facades\DB;

/**
 * Type de rendez-vous signalable (module Moderation), désigné par son uuid : titre,
 * description et détail du lieu sont écrits par le prestataire.
 *
 * Visible (donc signalable) par un visiteur s'il est actif, non masqué, et publié sur une page
 * de réservation publique ouverte ; par un client en relation acceptée avec le prestataire ou
 * qui a un rendez-vous de ce type. Masqué, il disparaît de la page publique et n'est plus
 * réservable ; les rendez-vous déjà pris gardent leur copie, sans le titre.
 */
class AppointmentTypeModerationTarget implements ModerationTarget
{
    public function publiclyReportable(): bool
    {
        return true;
    }

    public function findVisible(string $ref, ?User $viewer): ?TargetContent
    {
        $type = AppointmentType::query()->with('calendar')->where('uuid', $ref)->where('is_active', true)->whereNull('moderation_restricted_at')->first();

        if ($type === null || ! $this->visibleTo($type, $viewer)) {
            return null;
        }

        return $this->content($type);
    }

    public function inspect(string $ref): ?TargetContent
    {
        $type = AppointmentType::query()->with('calendar')->where('uuid', $ref)->first();

        return $type === null ? null : $this->content($type);
    }

    public function restrict(string $ref, string $decisionUuid): void
    {
        DB::transaction(function () use ($ref, $decisionUuid): void {
            $type = AppointmentType::query()->where('uuid', $ref)->lockForUpdate()->first();

            if ($type !== null) {
                Restriction::apply($type, $decisionUuid);
            }
        });
    }

    public function lift(string $ref, string $decisionUuid): void
    {
        DB::transaction(function () use ($ref, $decisionUuid): void {
            $type = AppointmentType::query()->where('uuid', $ref)->lockForUpdate()->first();

            if ($type !== null) {
                Restriction::lift($type, $decisionUuid);
            }
        });
    }

    private function visibleTo(AppointmentType $type, ?User $viewer): bool
    {
        $providerId = $type->calendar->user_id;

        if ($type->visibility === TypeVisibility::Public && $type->calendar->is_enabled && app(Plan::class)->publicPageOpen($type->calendar)
            && (app(GetProviderSummaries::class)->handle([$providerId])[$providerId]['slug'] ?? null) !== null) {
            return true;
        }

        if ($viewer === null) {
            return false;
        }

        return Appointment::query()->where('type_id', $type->id)->where('client_id', $viewer->id)->exists()
            || app(ManageAppointmentContact::class)->acceptedBetween($viewer->id, $providerId) !== null;
    }

    private function content(AppointmentType $type): TargetContent
    {
        $providerId = $type->calendar->user_id;
        $slug = app(GetProviderSummaries::class)->handle([$providerId])[$providerId]['slug'] ?? null;

        return new TargetContent(
            type: 'appointment_type',
            ref: $type->uuid,
            ownerId: $providerId,
            label: $type->title,
            text: TargetContent::joinText([
                'Titre' => $type->title,
                'Description' => $type->description,
                'Lieu' => $type->location_details,
            ]),
            version: TargetContent::fingerprint([
                'title' => $type->title,
                'description' => $type->description,
                'location_details' => $type->location_details,
            ]),
            restrictedBy: Restriction::decisionOf($type),
            publicPath: $slug === null ? null : '/providers/'.$slug.'/booking',
        );
    }
}
