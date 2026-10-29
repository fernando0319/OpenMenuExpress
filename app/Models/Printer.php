<?php

namespace App\Models;

use App\Enums\PrintFont;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $store_id
 * @property int $print_agent_id
 * @property string $system_name Name of the printer in the operating system.
 * @property string $label
 * @property int $paper_width 58 or 80 (mm)
 * @property int $copies
 * @property PrintFont $font
 * @property bool $is_active
 */
#[Fillable(['store_id', 'print_agent_id', 'system_name', 'label', 'paper_width', 'copies', 'font', 'is_active'])]
class Printer extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'paper_width' => 'integer',
            'copies' => 'integer',
            'font' => PrintFont::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * Characters per line in the default font.
     */
    public function columns(): int
    {
        return $this->paper_width <= 58 ? 32 : 48;
    }

    /**
     * @return BelongsTo<PrintAgent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(PrintAgent::class, 'print_agent_id');
    }

    /**
     * @return HasMany<PrintJob, $this>
     */
    public function jobs(): HasMany
    {
        return $this->hasMany(PrintJob::class);
    }
}
