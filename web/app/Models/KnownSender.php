<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnownSender extends Model
{
    protected $fillable = ['name', 'category', 'asns', 'rdns_suffixes', 'spf_includes', 'dkim_domains', 'docs_url'];

    public function ipIntel(): HasMany
    {
        return $this->hasMany(IpIntel::class);
    }

    protected function casts(): array
    {
        return ['asns' => 'array', 'rdns_suffixes' => 'array', 'spf_includes' => 'array', 'dkim_domains' => 'array'];
    }
}
