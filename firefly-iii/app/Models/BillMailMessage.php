<?php

declare(strict_types=1);

namespace FireflyIII\Models;

use FireflyIII\Support\Models\ReturnsIntegerIdTrait;
use FireflyIII\Support\Models\ReturnsIntegerUserIdTrait;
use FireflyIII\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillMailMessage extends Model
{
    use ReturnsIntegerIdTrait;
    use ReturnsIntegerUserIdTrait;

    protected $fillable = [
        'user_id',
        'message_id',
        'mailbox',
        'from_address',
        'to_address',
        'subject',
        'received_at',
        'raw_path',
        'body_text_path',
        'body_html_path',
        'checksum',
        'sync_cursor',
    ];

    public function billTasks(): HasMany
    {
        return $this->hasMany(BillTask::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'created_at'  => 'datetime',
            'updated_at'  => 'datetime',
            'received_at' => 'datetime',
            'user_id'     => 'integer',
        ];
    }
}
