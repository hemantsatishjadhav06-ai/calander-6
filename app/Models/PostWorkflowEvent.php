<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

class PostWorkflowEvent extends Model
{
    use HasUuids;

    #[Override]
    protected $guarded = [];
}
