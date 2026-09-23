<?php

declare(strict_types=1);

namespace Guild\Framework\Model;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A Grouper group registered with the application.
 *
 * @property int $id
 * @property string $name  a friendly local label, freely editable
 * @property string $group_identifier  the Grouper system name; what membership is matched on
 * @property bool $active
 * @property string $created_by
 * @property string|null $updated_by
 * @property-read Collection<int, Role> $roles
 */
#[Table('framework_groups')]
class Group extends Model
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
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'framework_groups_roles');
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
