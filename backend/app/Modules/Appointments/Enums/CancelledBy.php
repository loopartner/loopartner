<?php

namespace App\Modules\Appointments\Enums;

/**
 * Auteur d'une annulation : une partie, ou la plateforme (suppression d'un compte).
 */
enum CancelledBy: string
{
    case Client = 'client';
    case Provider = 'provider';
    case System = 'system';
}
