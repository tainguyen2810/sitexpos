<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * Prefer CreateWorkspace action (creates the OWNER membership); this factory
 * is for low-level tests only.
 *
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    protected $model = Workspace::class;

    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'owner_id' => User::factory(),
            'locale' => 'vi',
            'timezone' => 'Asia/Ho_Chi_Minh',
        ];
    }
}
