<?php

declare(strict_types=1);

namespace FireflyIII\Models;

use FireflyIII\Support\Models\ReturnsIntegerIdTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillArtifact extends Model
{
    use ReturnsIntegerIdTrait;

    protected $fillable = [
        'bill_task_id',
        'derived_from_artifact_id',
        'kind',
        'filename',
        'path',
        'checksum',
        'encrypted',
        'metadata',
    ];

    public function billTask(): BelongsTo
    {
        return $this->belongsTo(BillTask::class);
    }

    public function derivedFromArtifact(): BelongsTo
    {
        return $this->belongsTo(self::class, 'derived_from_artifact_id');
    }

    protected function casts(): array
    {
        return [
            'created_at'               => 'datetime',
            'updated_at'               => 'datetime',
            'encrypted'                => 'boolean',
            'metadata'                 => 'json',
            'bill_task_id'             => 'integer',
            'derived_from_artifact_id' => 'integer',
        ];
    }
}
