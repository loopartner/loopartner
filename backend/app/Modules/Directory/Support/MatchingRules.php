<?php

namespace App\Modules\Directory\Support;

use App\Modules\Directory\Enums\ReportReason;
use App\Modules\Directory\Models\AbuseReport;
use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Directory\Models\Message;
use App\Rules\NoHtml;
use App\Rules\NoInvisibleCharacters;
use Illuminate\Validation\Rule;

/**
 * Règles de validation partagées de la mise en relation : texte brut uniquement (HTML refusé,
 * caractères invisibles et de contrôle refusés), longueurs bornées, listes fermées.
 */
final class MatchingRules
{
    /**
     * Message d'une demande (proposition ou contact).
     *
     * @return list<mixed>
     */
    public static function requestMessage(): array
    {
        return ['required', 'string', 'min:'.MatchRequest::MESSAGE_MIN_LENGTH, 'max:'.Message::MAX_LENGTH, new NoHtml, new NoInvisibleCharacters];
    }

    /**
     * Message de la messagerie.
     *
     * @return list<mixed>
     */
    public static function messageBody(): array
    {
        return ['required', 'string', 'max:'.Message::MAX_LENGTH, new NoHtml, new NoInvisibleCharacters];
    }

    /**
     * Signalement : motif dans une liste fermée et commentaire facultatif.
     *
     * @return array<string, list<mixed>>
     */
    public static function report(): array
    {
        return [
            'reason' => ['required', 'string', Rule::enum(ReportReason::class)],
            'comment' => ['nullable', 'string', 'max:'.AbuseReport::COMMENT_MAX_LENGTH, new NoHtml, new NoInvisibleCharacters],
        ];
    }
}
