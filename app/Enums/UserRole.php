<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Hr = 'hr';
    case AssistantHr = 'assistant_hr';
    case Candidate = 'candidate';
}
