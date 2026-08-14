<?php declare(strict_types=1);

namespace Guild\Framework\Model;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table('framework_roles')]
class Role extends Model
{
    protected $attributes = [
        'active' => false
    ];

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'framework_groups_roles');
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('active', true);
    }
}