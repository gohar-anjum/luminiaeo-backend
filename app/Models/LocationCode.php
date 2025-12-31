<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocationCode extends Model
{
    protected $fillable = [
        'location_code',
        'location_name',
        'location_code_parent',
        'country_iso_code',
        'location_type',
    ];

    protected $casts = [
        'location_code' => 'integer',
        'location_code_parent' => 'integer',
    ];

    /**
     * Get only countries (filter by location_type = 'Country')
     */
    public function scopeCountries($query)
    {
        return $query->where('location_type', 'Country');
    }

    /**
     * Get only regions
     */
    public function scopeRegions($query)
    {
        return $query->where('location_type', 'Region');
    }
}
