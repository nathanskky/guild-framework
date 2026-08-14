<?php declare(strict_types=1);

namespace Guild\Framework\Model;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Table('framework_groups')]
class Group extends Model
{
    protected $attributes = [
        'active' => false
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'framework_groups_roles');
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('active', true);
    }
}