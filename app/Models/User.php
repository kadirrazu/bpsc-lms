<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use App\Services\Library\MembershipNumbers;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\DB;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, \Illuminate\Database\Eloquent\SoftDeletes, Notifiable;

    protected $attributes = ['role' => 'library_member', 'is_active' => true, 'auth_version' => 1, 'preferred_locale' => 'en'];

    protected $fillable = [
        'mobile_number',
        'name',
        'email',
        'password',
        'designation_id',
        'unit',
        'role',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'auth_version',
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
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'auth_version' => 'integer',
        ];
    }

    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $email) => mb_strtolower(trim($email)));
    }

    public function save(array $options = []): bool
    {
        if ($this->role !== UserRole::Member || $this->getRawOriginal('membership_number')) {
            return parent::save($options);
        }

        return DB::transaction(function () use ($options) {
            $existing = $this->exists
                ? self::withTrashed()->whereKey($this->id)->lockForUpdate()->value('membership_number')
                : null;
            $this->membership_number = $existing ?? app(MembershipNumbers::class)->next();

            return parent::save($options);
        });
    }

    protected static function booted(): void
    {
        static::deleting(fn (self $user) => $user->tokens()->delete());

        static::updating(function (self $user) {
            if ($user->isDirty(['password', 'role', 'is_active', 'email', 'deleted_at'])) {
                $user->auth_version = ((int) $user->getRawOriginal('auth_version')) + 1;
                $user->remember_token = null;
                $user->tokens()->delete();
            }
        });
    }

    public function loans(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BookLoan::class);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }
}
