<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApplicationCategory extends Model
{
    protected $fillable = ['name','slug','icon','position'];
    public function applications(): HasMany { return $this->hasMany(Application::class); }
}
