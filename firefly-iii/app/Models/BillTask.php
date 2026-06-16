<?php

declare(strict_types=1);

namespace FireflyIII\Models;

use FireflyIII\Support\Models\ReturnsIntegerIdTrait;
use FireflyIII\Support\Models\ReturnsIntegerUserIdTrait;
use FireflyIII\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BillTask extends Model
{
    use ReturnsIntegerIdTrait;
    use ReturnsIntegerUserIdTrait;

    protected $fillable = [
        'user_id',
        'bill_mail_message_id',
        'source',
        'profile_id',
        'status',
        'received_at',
        'summary',
        'current_secret_challenge_id',
        'error_code',
        'error_message',
        'metadata',
    ];

    /**
     * @throws NotFoundHttpException
     */
    public static function routeBinder(self|string $value): self
    {
        if ($value instanceof self) {
            $value = (int) $value->id;
        }
        if (auth()->check()) {
            $taskId = (int) $value;

            /** @var User $user */
            $user   = auth()->user();

            /** @var null|BillTask $task */
            $task   = $user->billTasks()->find($taskId);
            if (null !== $task) {
                return $task;
            }
        }

        throw new NotFoundHttpException();
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(BillArtifact::class);
    }

    public function currentSecretChallenge(): BelongsTo
    {
        return $this->belongsTo(BillSecretChallenge::class, 'current_secret_challenge_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(BillTaskEvent::class);
    }

    public function mailMessage(): BelongsTo
    {
        return $this->belongsTo(BillMailMessage::class, 'bill_mail_message_id');
    }

    public function secretChallenges(): HasMany
    {
        return $this->hasMany(BillSecretChallenge::class);
    }

    public function statementImports(): HasMany
    {
        return $this->hasMany(BillStatementImport::class);
    }

    public function statementRows(): HasMany
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
            'created_at'                  => 'datetime',
            'updated_at'                  => 'datetime',
            'received_at'                 => 'datetime',
            'metadata'                    => 'json',
            'user_id'                     => 'integer',
            'bill_mail_message_id'        => 'integer',
            'current_secret_challenge_id' => 'integer',
        ];
    }
}
