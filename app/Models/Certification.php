<?php

namespace App\Models;

use App\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Certification extends Model
{
    use BelongsToStore, HasFactory;

    protected $fillable = [
        'store_id',
        'lab',
        'certificate_number',
        'issue_date',
        'report_type',
        'shape',
        'carat_weight',
        'color_grade',
        'clarity_grade',
        'cut_grade',
        'polish',
        'symmetry',
        'fluorescence',
        'measurements',
        'proportions',
        'inscription',
        'comments',
        'verification_url',
        'pdf_path',
        'scan_image_path',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'carat_weight' => 'decimal:3',
            'measurements' => 'array',
            'proportions' => 'array',
            'raw_data' => 'array',
        ];
    }

    public function gemstones(): HasMany
    {
        return $this->hasMany(Gemstone::class);
    }

    public function getVerificationUrl(): ?string
    {
        if ($this->verification_url) {
            return $this->verification_url;
        }

        return match (strtoupper($this->lab)) {
            'GIA' => "https://www.gia.edu/report-check?reportno={$this->certificate_number}",
            'AGS' => "https://www.agslab.com/report-check?reportno={$this->certificate_number}",
            'IGI' => "https://www.igi.org/verify.php?r={$this->certificate_number}",
            default => null,
        };
    }

    public function getGradesSummary(): string
    {
        return sprintf(
            '%s / %s / %s',
            $this->color_grade ?? '-',
            $this->clarity_grade ?? '-',
            $this->cut_grade ?? '-'
        );
    }

    public function isGIA(): bool
    {
        return strtoupper($this->lab) === 'GIA';
    }

    public function isAGS(): bool
    {
        return strtoupper($this->lab) === 'AGS';
    }

    public function scopeFromLab($query, string $lab)
    {
        return $query->where('lab', $lab);
    }

    public function scopeGIA($query)
    {
        return $query->where('lab', 'GIA');
    }
}
