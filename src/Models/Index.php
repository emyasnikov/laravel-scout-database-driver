<?php

namespace Intraset\LaravelScoutDatabaseDriver\Models;

use Illuminate\Database\Eloquent\Model;

class Index extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'model_id',
        'model_type',
        'value',
    ];

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'index';

    /**
     * Related model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function model()
    {
        return $this->morphTo();
    }
}
