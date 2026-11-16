<?php

namespace App\Models;

use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Zusätzliches Feld am Ticket (z. B. Bestellnummer, Vereinsnummer), je Mandant oder für alle.
 * Die Werte stehen im Ticket unter fields[key].
 */
class CustomField extends Model
{
    public const TYPES = [
        'text' => 'Text',
        'number' => 'Zahl',
        'date' => 'Datum',
        'select' => 'Auswahl',
        'checkbox' => 'Ja/Nein',
    ];

    protected $fillable = ['tenant_id', 'key', 'label', 'type', 'options', 'show_in_list', 'sort', 'active'];

    protected function casts(): array
    {
        return ['options' => 'array', 'show_in_list' => 'boolean', 'active' => 'boolean'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId));
    }

    /** Aktive Felder eines Mandanten in Anzeigereihenfolge */
    public static function for(int $tenantId): Collection
    {
        return static::query()->forTenant($tenantId)->where('active', true)->orderBy('sort')->orderBy('label')->get();
    }

    /** @return array<string, string> */
    public function choices(): array
    {
        $list = array_values(array_filter(array_map('trim', $this->options ?? []), 'strlen'));

        return array_combine($list, $list) ?: [];
    }

    public function formComponent(): Field
    {
        $name = 'fields.'.$this->key;

        return match ($this->type) {
            'number' => TextInput::make($name)->label($this->label)->numeric(),
            'date' => DatePicker::make($name)->label($this->label)->native(false)->displayFormat('d.m.Y'),
            'select' => Select::make($name)->label($this->label)->options($this->choices())->placeholder('–'),
            'checkbox' => Checkbox::make($name)->label($this->label),
            default => TextInput::make($name)->label($this->label)->maxLength(255),
        };
    }

    /** Wert für Anzeige und Liste */
    public function display(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($this->type) {
            'checkbox' => $value ? 'ja' : 'nein',
            'date' => rescue(fn () => Carbon::parse($value)->format('d.m.Y'), (string) $value, false),
            default => is_scalar($value) ? (string) $value : json_encode($value),
        };
    }

    /** Wert aus Formular oder API in die gespeicherte Form bringen; ungültige Werte werden zu null */
    public function normalize(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($this->type) {
            'number' => is_numeric($value) ? $value + 0 : null,
            'date' => rescue(fn () => Carbon::parse($value)->format('Y-m-d'), null, false),
            'checkbox' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
            'select' => in_array((string) $value, $this->choices(), true) ? (string) $value : null,
            default => is_scalar($value) ? mb_substr(trim((string) $value), 0, 255) : null,
        };
    }
}
