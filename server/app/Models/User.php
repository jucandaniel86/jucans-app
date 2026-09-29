<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = [
        'username',
        'avatar',
        'pin',
    ];

    protected $hidden = [
        'pin',
    ];

    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class, 'created_by');
    }

    public function setPinAttribute(string $pin): void
    {
        if (! preg_match('/^\d{4,}$/', $pin)) {
            throw ValidationException::withMessages([
                'pin' => 'The PIN must contain at least 4 digits.',
            ]);
        }

        $this->attributes['pin'] = Hash::make($pin);
    }
}
