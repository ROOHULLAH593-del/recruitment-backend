<?php

namespace App\Enums;

enum InvitationStatus: string
{
    case PendingUse = 'pending_use';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';
}
