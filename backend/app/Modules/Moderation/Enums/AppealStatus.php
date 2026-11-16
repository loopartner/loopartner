<?php

namespace App\Modules\Moderation\Enums;

enum AppealStatus: string
{
    case Pending = 'pending';
    case Upheld = 'upheld';
    case Rejected = 'rejected';
}
