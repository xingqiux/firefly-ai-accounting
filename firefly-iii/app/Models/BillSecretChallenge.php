<?php

declare(strict_types=1);

namespace FireflyIII\Models;

use FireflyIII\Support\Models\ReturnsIntegerIdTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillSecretChallenge extends Model
{
    use ReturnsIntegerIdTrait;

    protected $fillable = [
        'bill_task_id',
        'kind',
        'prompt',
        'status',
        'attempts',
        'consumed_at',
    ];

    public function billTask(): BelongsTo
    {
        return $this->belongsTo(BillTask::class);
    }

    protected function casts(): array
    {
        return [
            'created_at'   => 'datetime',
            'updated_at'   => 'datetime',
            'consumed_at'  => 'datetime',
            'attempts'     => 'integer',
            'bill_task_id' => 'integer',
        ];
    }
}
