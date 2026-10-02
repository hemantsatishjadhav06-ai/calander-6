<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property string $id
 * @property string $account_set_id
 * @property string $connected_account_id
 */
#[Table(name: 'account_set_members')]
#[WithoutIncrementing]
class AccountSetMember extends Pivot
{
    use HasUuids;
}
