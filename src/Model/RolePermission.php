<?php

declare(strict_types=1);

namespace Guild\Framework\Model;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('framework_role_permissions')]
class RolePermission extends Model
{
    protected $attributes = [
        'active' => false
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('active', true);
    }
}
