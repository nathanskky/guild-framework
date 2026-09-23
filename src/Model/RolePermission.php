<?php

declare(strict_types=1);

namespace Guild\Framework\Model;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A grant: this role holds the permission named $name.
 *
 * @property int $id
 * @property int $role_id
 * @property string $name  the value of a case of the application's Permission enum
 * @property bool $active
 * @property string $created_by
 * @property string|null $updated_by
 * @property-read Role $role
 */
#[Table('framework_role_permissions')]
class RolePermission extends Model
{
    protected $attributes = [
        'active' => false
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('active', true);
    }
}
