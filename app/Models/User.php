<?php

namespace App\Models;

use App\Support\PlatformSettings;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_super_admin',
        'last_active_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'last_active_at' => 'datetime',
        ];
    }

    /**
     * When platform email verification is off, treat all users as verified
     * so the console `verified` middleware and MustVerifyEmail checks pass.
     */
    public function hasVerifiedEmail(): bool
    {
        if (app()->bound('session') && session()->has('impersonator_id')
            && static::query()->whereKey(session('impersonator_id'))->where('is_super_admin', true)->exists()) {
            return true;
        }

        if (! app(PlatformSettings::class)->emailVerificationRequired()) {
            return true;
        }

        return ! is_null($this->email_verified_at);
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class)
            ->withPivot(['role', 'is_active'])
            ->withTimestamps();
    }
}
