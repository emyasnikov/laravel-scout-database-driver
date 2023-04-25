<?php

namespace Intraset\LaravelScoutDatabaseDriver\Engines;

use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\LazyCollection;
use Intraset\LaravelScoutDatabaseDriver\Models\Index;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use Laravel\Scout\Jobs\RemoveableScoutCollection;

class DatabaseEngine extends Engine
{
    /**
     * Determines if soft deletes for Scout are enabled or not.
     *
     * @var bool
     */
    protected $softDelete;

    /**
     * Create a new engine instance.
     *
     * @param  bool  $softDelete
     * @return void
     */
    public function __construct($softDelete = false)
    {
        $this->softDelete = $softDelete;
    }

    /**
     * Update the given model in the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function update($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        if ($this->usesSoftDelete($models->first()) && $this->softDelete) {
            $models->each->pushSoftDeleteMetadata();
        }

        $objects = $models->map(function ($model) {
            if (empty($searchableData = $model->toSearchableArray())) {
                return;
            }

            return array_merge(
                $searchableData,
                $model->scoutMetadata(),
                [
                    'model_id' => $model->getScoutKey(),
                    'model_type' => $model->getMorphClass(),
                ],
            );
        })->filter()->values()->all();

        if (!empty($objects)) {
            foreach ($objects as $object) {
                $this->insert($object);
            }
        }
    }

    /**
     * Insert the given model to the index.
     *
     * @param  Intraset\LaravelScoutDatabaseDriver\Model  $model
     * @return void
     */
    public function insert($model)
    {
        $index = [
            'model_id' => $model['model_id'],
            'model_type' => $model['model_type'],
        ];

        Index::firstOrNew($index)->fill(array_merge($index, [
            'value' => collect($model)->filter(function ($value, $key) {
                return !in_array($key, ['model_id', 'model_type']);
            })->values()->flatten()->implode(' '),
        ]))->save();
    }

    /**
     * Remove the given model from the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function delete($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        $keys = $models instanceof RemovableScoutCollection
            ? $models->pluck($models->first()->getScoutKeyName())
            : $models->map->getScoutKey();

        foreach ($keys as $key) {
            Index::where('model_id', $key)->delete();
        }
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return mixed
     */
    public function search(Builder $builder)
    {
        return $this->performSearch($builder, array_filter([
            'numericFilters' => $this->filters($builder),
            'hitsPerPage' => $builder->limit,
        ]));
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  int  $perPage
     * @param  int  $page
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        return $this->performSearch($builder, array_filter([
            'numericFilters' => $this->filters($builder),
            'hitsPerPage' => $perPage,
            'page' => $page - 1,
        ]));
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  array  $options
     * @return mixed
     */
    protected function performSearch(Builder $builder, array $options = [])
    {
        $options = array_merge($builder->options, $options);

        if ($builder->callback) {
            return call_user_func(
                $builder->callback,
                $builder->query,
                $options
            );
        }

        $result = Index::where('value', 'like', '%'.$builder->query.'%')->get();

        $result['hits'] = $result->map(function ($item) {
            return ['objectID' => $item['model_id']];
        })->all();

        $result['nbHits'] = $result->count();

        return $result;
    }

    /**
     * Get the filter array for the query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return array
     */
    protected function filters(Builder $builder)
    {
        $wheres = collect($builder->wheres)->map(function ($value, $key) {
            return $key.' = '.$value;
        })->values();

        return $wheres->merge(collect($builder->whereIns)->map(function ($values, $key) {
            return collect($values)->map(function ($value) use ($key) {
                return $key.' = '.$value;
            })->all();
        })->values())->values()->all();
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param  mixed  $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        return collect($results['hits'])->pluck('objectID')->values();
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function map(Builder $builder, $results, $model)
    {
        if (count($results['hits']) === 0) {
            return $model->newCollection();
        }

        $objectIds = collect($results['hits'])->pluck('objectID')->values()->all();

        $objectIdPositions = array_flip($objectIds);

        return $model->getScoutModelsByIds(
            $builder, $objectIds
        )->filter(function ($model) use ($objectIds) {
            return in_array($model->getScoutKey(), $objectIds);
        })->sortBy(function ($model) use ($objectIdPositions) {
            return $objectIdPositions[$model->getScoutKey()];
        })->values();
    }

    /**
     * Map the given results to instances of the given model via a lazy collection.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Support\LazyCollection
     */
    public function lazyMap(Builder $builder, $results, $model)
    {
        if (count($results['hits']) === 0) {
            return LazyCollection::make($model->newCollection());
        }

        $objectIds = collect($results['hits'])->pluck('objectID')->values()->all();
        $objectIdPositions = array_flip($objectIds);

        return $model->queryScoutModelsByIds(
            $builder, $objectIds
        )->cursor()->filter(function ($model) use ($objectIds) {
            return in_array($model->getScoutKey(), $objectIds);
        })->sortBy(function ($model) use ($objectIdPositions) {
            return $objectIdPositions[$model->getScoutKey()];
        })->values();
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param  mixed  $results
     * @return int
     */
    public function getTotalCount($results)
    {
        return $results['nbHits'];
    }

    /**
     * Flush all of the model's records from the engine.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function flush($model)
    {
        Index::truncate();
    }

    /**
     * Create a search index.
     *
     * @param  string  $name
     * @param  array  $options
     * @return mixed
     */
    public function createIndex($name, array $options = [])
    {
        //
    }

    /**
     * Delete a search index.
     *
     * @param  string  $name
     * @return mixed
     */
    public function deleteIndex($name)
    {
        //
    }

    /**
     * Determine if the given model uses soft deletes.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     */
    protected function usesSoftDelete($model)
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model));
    }
}
