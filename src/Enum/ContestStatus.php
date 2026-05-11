<?php

namespace App\Enum;

enum ContestStatus: string
{
    case DRAFT = 'draft';
    case SCHEDULED = 'scheduled';
    case ACTIVE = 'active';
    case FINISHED = 'finished';
}
