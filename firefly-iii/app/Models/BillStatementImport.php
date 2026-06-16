<?php

declare(strict_types=1);

namespace FireflyIII\Models;

use FireflyIII\Support\Models\ReturnsIntegerIdTrait;
use FireflyIII\Support\Models\ReturnsIntegerUserIdTrait;
use FireflyIII\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillStatementImport extends Model
{
    use ReturnsIntegerIdTrait;
    use ReturnsIntegerUserIdTrait;

    protected $fillable = [
        'user_id',
        'bill_task_id',
        'bill_artifact_id',
        'source',
        'profile_id',
        'original_filename',
        'archived_filename',
        'exported_at',
        'period_start',
        'period_end',
        'row_count',
        'status',
        'metadata',
    ];

    public function artifact(): BelongsTo
    {
        return $this->belongsTo(BillArtifact::class, 'bill_artifact_id');
    }

    public function billTask(): BelongsTo
    {
        return $this->belongsTo(BillTask::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(BillStatementRow::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'created_at'                 => 'datetime',
            'updated_at'                 => 'datetime',
            'exported_at'                => 'datetime',
            'period_start'               => 'date',
            'period_end'                 => 'date',
            'metadata'                   => 'json',
            'user_id'                    => 'integer',
            'bill_task_id'               => 'integer',
            'bill_artifact_id'           => 'integer',
            'row_count'                  => 'integer',
        ];
    }
}
