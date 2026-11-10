<?php

namespace App\Modules\Directory\Enums;

enum ProjectStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Closed = 'closed';
}
