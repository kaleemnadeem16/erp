<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\LogsActivityWithDefaults;
use App\Modules\Administration\Notifications\MemberPasswordReset;
use App\Modules\Administration\Support\MemberStatus;
use App\Support\Concerns\BelongsToCompany;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/** @property MemberStatus $status */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToCompany, HasApiTokens, HasFactory, HasRoles, LogsActivityWithDefaults, Notifiable;

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new MemberPasswordReset($token));
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'status',
        'invited_at',
        'invited_by',
        'suspended_at',
        'suspended_by',
    ];

    /**
     * Postgres is case-sensitive; normalise to lowercase in the app rather than
     * reaching for citext — see docs/architecture/database.md.
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => strtolower($value),
        );
    }

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
            'status' => MemberStatus::class,
            'invited_at' => 'datetime',
            'suspended_at' => 'datetime',
            'last_login_at' => 'datetime',
            'access_updated_at' => 'datetime',
            'must_change_password' => 'boolean',
            'credential_version' => 'integer',
        ];
    }
}
