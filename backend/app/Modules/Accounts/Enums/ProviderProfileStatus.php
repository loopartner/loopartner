<?php

namespace App\Modules\Accounts\Enums;

enum ProviderProfileStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
