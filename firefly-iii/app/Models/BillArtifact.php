<?php

declare(strict_types=1);

namespace FireflyIII\Models;

use FireflyIII\Support\Models\ReturnsIntegerIdTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

    /**
     * @throws NotFoundHttpException
     */
    public static function routeBinder(self|string $value): self
    {
        if ($value instanceof self) {
            $value = (int) $value->id;
        }
        if (auth()->check()) {
            /** @var null|self $artifact */
            $artifact = self::query()
                ->whereHas('billTask', static function ($query): void {
                    $query->where('user_id', auth()->id());
                })
                ->find((int) $value)
            ;
            if (null !== $artifact) {
                return $artifact;
            }
        }

        throw new NotFoundHttpException();
    }

    public function billTask(): BelongsTo
    {
        return $this->belongsTo(BillTask::class);
    }

    public function derivedFromArtifact(): BelongsTo
    {
        return $this->belongsTo(self::class, 'derived_from_artifact_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'derived_from_artifact_id');
    }

    public function statementImport(): HasOne
    {
        return $this->hasOne(BillStatementImport::class);
    }

    public function isInternalProcessingArtifact(): bool
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];

        return true === ($metadata['internal'] ?? false)
            || 'boc_pdf_text_extract' === ($metadata['source'] ?? null);
    }

    public function scopeVisibleToUser(Builder $query): Builder
    {
        [$internalExpression, $internalBindings] = $this->metadataTextExpression($query, 'internal');
        [$sourceExpression, $sourceBindings]     = $this->metadataTextExpression($query, 'source');

        return $query
            ->whereRaw(sprintf("COALESCE(%s, '') NOT IN ('true', '1')", $internalExpression), $internalBindings)
            ->whereRaw(sprintf("COALESCE(%s, '') <> ?", $sourceExpression), [...$sourceBindings, 'boc_pdf_text_extract'])
        ;
    }

    public function scopeWhereMetadataSource(Builder $query, string $source): Builder
    {
        [$expression, $bindings] = $this->metadataTextExpression($query, 'source');

        return $query->whereRaw(sprintf('%s = ?', $expression), [...$bindings, $source]);
    }

    /**
     * @return array{0:string,1:array<int,string>}
     */
    private function metadataTextExpression(Builder $query, string $key): array
    {
        $column = $query->getModel()->qualifyColumn('metadata');

        return match ($query->getConnection()->getDriverName()) {
            'pgsql'         => [sprintf("jsonb_extract_path_text(NULLIF((%s)::text, '')::jsonb, ?)", $column), [$key]],
            'sqlite'        => [sprintf("CAST(json_extract(NULLIF(%s, ''), ?) AS TEXT)", $column), ['$.'.$key]],
            default         => [sprintf("JSON_UNQUOTE(JSON_EXTRACT(NULLIF(%s, ''), ?))", $column), ['$.'.$key]],
        };
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
