<?php
  namespace App\Services;

  use App\Enums\DietStatus;
  use App\Models\Diet;
  use App\Models\User;

  class DietService {

    public function createDraft(User $user, string $name): Diet
    {
      return Diet::create([
        'name' => $name,
        'status' => DietStatus::DRAFT,
        'created_by' => $user->id,
      ]);
    }
  }
