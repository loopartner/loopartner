<?php

namespace App\Modules\Appointments\Enums;

/**
 * Exception aux plages hebdomadaires, à une date : fermeture (journée entière ou plage) ou
 * ouverture ponctuelle (plage).
 */
enum ExceptionKind: string
{
    case Closed = 'closed';
    case Open = 'open';
}
