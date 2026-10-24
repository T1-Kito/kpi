<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use \App\Models\Concerns\CastsDatabaseIdentifiers;

    private ?array $scopePermissions = null;
    private ?array $requestPermissionCodes = null;
    private ?bool $requestSpAdmin = null;

    /** Request-local only: the JWT middleware reloads the account and grants every request. */
    public function snapshotAuthorizationForRequest(): void
    {
        $this->loadMissing('roles.permissions');
        $roles=$this->roles->where('tenant_id',$this->tenant_id);
        $this->requestSpAdmin=$roles->contains('code',\App\Support\SystemAccount::ROLE);
        $codes=$roles->flatMap(fn($role)=>$role->permissions->pluck('code'))->reject(fn($code)=>$code===\App\Support\SystemAccount::PERMISSION);
        if($this->requestSpAdmin)$codes->push(\App\Support\SystemAccount::PERMISSION);
        $this->requestPermissionCodes=array_fill_keys($codes->unique()->all(),true);
    }

    public function restrictDataScopeToPermissions(array $permissions): void
    {
        $this->scopePermissions = $permissions;
    }
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'tenant_id',
        'department_id',
        'position_id',
        'manager_id',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'token_version',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id'=>'integer','department_id'=>'integer','position_id'=>'integer','manager_id'=>'integer',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (User $user) {
            if ($user->isDirty('password') || $user->isDirty('is_active')) {
                // Atomic increment also handles concurrent security changes.
                $user->token_version = \Illuminate\Support\Facades\DB::raw('token_version + 1');
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function hasPermission(string $permissionCode): bool
    {
        if($this->requestPermissionCodes!==null)return isset($this->requestPermissionCodes[$permissionCode]);
        if ($permissionCode===\App\Support\SystemAccount::PERMISSION) return $this->isSpAdmin();
        return $this->roles()
            ->where('tenant_id',$this->tenant_id)
            ->whereHas('permissions', fn ($query) => $query->where('code', $permissionCode))
            ->exists();
    }
    public function isSpAdmin(): bool
    {
        if($this->requestSpAdmin!==null)return $this->requestSpAdmin;
        return $this->roles()->where('tenant_id',$this->tenant_id)->where('code',\App\Support\SystemAccount::ROLE)->exists();
    }

    public function effectivePermissionCodes(): \Illuminate\Support\Collection
    {
        if($this->requestPermissionCodes!==null)return collect(array_keys($this->requestPermissionCodes));
        $codes=$this->roles()->where('tenant_id',$this->tenant_id)->with('permissions')->get()->flatMap(fn($role)=>$role->permissions->pluck('code'))->reject(fn($code)=>$code===\App\Support\SystemAccount::PERMISSION);
        if($this->isSpAdmin())$codes->push(\App\Support\SystemAccount::PERMISSION);
        return $codes->unique()->values();
    }

    public function dataScope(?string $permission = null): string
    {
        $rank = ['own' => 1, 'warehouse' => 2, 'department' => 3, 'company' => 4];
        $roles = $this->relationLoaded('roles') ? $this->roles : $this->roles()->get();
        $permissions = $permission === null ? $this->scopePermissions : [$permission];
        if ($permissions !== null) {
            $roles = $roles->filter(fn ($role) => $role->permissions->pluck('code')->intersect($permissions)->isNotEmpty());
        }

        return $roles
            ->pluck('data_scope')
            ->filter()
            // Without an action context, never infer the widest scope across unrelated roles.
            ->sortBy(fn (string $scope) => ($permissions === null ? 1 : -1) * ($rank[$scope] ?? 0))
            ->first() ?: 'own';
    }
}
