<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class LawChunk extends Model
{
    protected $table = 'law_chunks';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id','tenant_id','source','title','citation','url','text','embedding_model'
    ];

    protected static function booted() {
        static::creating(function ($m) {
            if (!$m->id) $m->id = (string) Str::uuid();
        });
    }
}
