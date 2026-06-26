<?php

declare(strict_types=1);

namespace Vimatech\DocumentNumbering\Concerns;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Vimatech\DocumentNumbering\Facades\Numbering;
use Vimatech\DocumentNumbering\NumberingManager;

/**
 * Assigns a configured document number to a model on creation.
 *
 * The number is allocated during the `creating` event. For gap-free types the
 * model's first save is wrapped in a transaction so the allocation and the
 * INSERT commit together — if the INSERT fails, the number is released.
 *
 * Configure the model with either properties or method overrides:
 *
 *   protected string $documentNumberType = 'invoice';
 *   protected string $documentNumberColumn = 'number';          // default: 'number'
 *   protected string $documentNumberScopeColumn = 'company_id';  // optional
 *
 * Or override documentNumberType() / documentNumberScope() / documentNumberColumn().
 *
 * @mixin Model
 */
trait HasDocumentNumber
{
    public static function bootHasDocumentNumber(): void
    {
        static::creating(static function (Model $model): void {
            if (method_exists($model, 'assignDocumentNumber')) {
                $model->assignDocumentNumber();
            }
        });
    }

    /**
     * Wrap the initial save in a transaction for gap-free types so that the
     * number allocation and the row INSERT are committed atomically.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        if (! $this->shouldWrapSaveForDocumentNumber()) {
            return parent::save($options);
        }

        return (bool) $this->getConnection()->transaction(
            fn (): bool => parent::save($options),
        );
    }

    /**
     * Allocate and assign the number if one is not already present.
     */
    public function assignDocumentNumber(): void
    {
        $column = $this->documentNumberColumn();

        if (! empty($this->getAttribute($column))) {
            return;
        }

        $this->setAttribute($column, Numbering::for(
            $this->documentNumberScope(),
            $this->documentNumberType(),
        )->next());
    }

    /**
     * The configured type key (must exist in config/numbering.php "types").
     */
    public function documentNumberType(): string
    {
        $value = $this->documentNumberOption('documentNumberType');

        if (is_string($value) && $value !== '') {
            return $value;
        }

        throw new LogicException(sprintf(
            'Model [%s] uses HasDocumentNumber but does not define a document number type. '
            .'Set protected string $documentNumberType or override documentNumberType().',
            static::class,
        ));
    }

    /**
     * The attribute that receives the allocated number.
     */
    public function documentNumberColumn(): string
    {
        $value = $this->documentNumberOption('documentNumberColumn');

        return is_string($value) && $value !== '' ? $value : 'number';
    }

    /**
     * The allocation scope. Defaults to the value of $documentNumberScopeColumn
     * (e.g. company/tenant id) when defined, otherwise a single global scope.
     *
     * When a scope column is configured it must hold a value: silently falling
     * back to the global scope could mix one tenant's numbers into another's
     * sequence, so a missing value fails loudly instead.
     */
    public function documentNumberScope(): string
    {
        $column = $this->documentNumberOption('documentNumberScopeColumn');

        if (! is_string($column) || $column === '') {
            return 'default';
        }

        $value = $this->getAttribute($column);

        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }

        throw new LogicException(sprintf(
            'Model [%s] must have attribute [%s] set before saving, as it scopes the document number.',
            static::class,
            $column,
        ));
    }

    /**
     * True when this save is the gap-free allocation of a new model.
     */
    protected function shouldWrapSaveForDocumentNumber(): bool
    {
        if ($this->exists) {
            return false;
        }

        if (! empty($this->getAttribute($this->documentNumberColumn()))) {
            return false;
        }

        $manager = app(NumberingManager::class);
        $type = $this->documentNumberType();

        return $manager->knows($type) && $manager->configFor($type)['gap_free'];
    }

    /**
     * Read an optional trait-configuration property without tripping over
     * models that do not declare it.
     */
    private function documentNumberOption(string $property): mixed
    {
        return property_exists($this, $property) ? $this->{$property} : null;
    }
}
