<?php

namespace App\Policies;

use App\Models\Dispute;
use App\Models\User;

class DisputePolicy
{
    public function view(User $user, Dispute $dispute): bool
    {
        return $user->id === $dispute->buyer_id || $user->id === $dispute->seller_id || $user->can('disputes.manage');
    }

    public function reply(User $user, Dispute $dispute): bool
    {
        return $dispute->isOpen() && $this->view($user, $dispute);
    }
}
