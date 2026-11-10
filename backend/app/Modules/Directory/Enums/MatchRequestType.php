<?php

namespace App\Modules\Directory\Enums;

/**
 * Type de demande de mise en relation.
 *
 * - proposal : un prestataire propose ses services sur un projet publié ;
 * - contact : un particulier ou un entrepreneur contacte un prestataire depuis son profil public.
 */
enum MatchRequestType: string
{
    case Proposal = 'proposal';
    case Contact = 'contact';
}
