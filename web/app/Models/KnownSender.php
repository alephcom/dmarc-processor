<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KnownSender extends Model
{
    protected $fillable = ['name', 'category', 'asns', 'rdns_suffixes', 'spf_includes', 'dkim_domains', 'docs_url'];

    protected function casts(): array
    {
        return ['asns' => 'array', 'rdns_suffixes' => 'array', 'spf_includes' => 'array', 'dkim_domains' => 'array'];
    }
}
