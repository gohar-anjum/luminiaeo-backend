<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Backlink extends Model
{
    protected $fillable = [
        'domain',
        'source_url',
        'anchor',
        'link_type',
        'source_domain',
        'domain_rank',
        'task_id',
    ];
}
