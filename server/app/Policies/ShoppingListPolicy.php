<?php

namespace App\Policies;

use App\Models\ShoppingList;
use App\Models\User;

class ShoppingListPolicy
{
    public function view(User $user, ShoppingList $list): bool
    {
        return $list->isAccessibleTo($user);
    }

    public function update(User $user, ShoppingList $list): bool
    {
        return $list->status === 'open' && $this->view($user, $list);
    }

    public function manageSharing(User $user, ShoppingList $list): bool
    {
        return (int) $list->created_by === (int) $user->id;
    }

    public function changeVisibility(User $user, ShoppingList $list): bool
    {
        return $this->manageSharing($user, $list);
    }

    public function close(User $user, ShoppingList $list): bool
    {
        return $list->status === 'open' && $this->manageSharing($user, $list);
    }
}
