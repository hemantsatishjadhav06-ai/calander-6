<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $key
 * @property mixed $value
 */
#[Fillable(['key', 'value'])]
#[WithoutIncrementing]
class InstanceSetting extends Model
{
    #[Override]
    protected $primaryKey = 'key';

    #[Override]
    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
