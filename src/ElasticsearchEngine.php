<?php

namespace ScoutEngines\Elasticsearch;

use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use Elasticsearch\Client as Elastic;
use Illuminate\Database\Eloquent\Collection;

class ElasticsearchEngine extends Engine
{
    /**
     * Default index where the models will be saved.
     *
     * @var string
     */
    protected $index;

    /**
     * Elastic where the instance of Elastic|\Elasticsearch\Client is stored.
     *
     * @var object
     */
    protected $elastic;

    /**
     * If the index should be treated as a prefix.
     *
     * @var bool
     */
    protected $indexIsPrefix;

    /**
     * If the index should be set per model.
     *
     * @var bool
     */
    protected $perModelIndex;

    /**
     * Create a new engine instance.
     *
     * @param  \Elasticsearch\Client  $elastic
     * @return void
     */
    public function __construct(Elastic $elastic, $index, $indexIsPrefix = false, $perModelIndex = false)
    {
        $this->elastic = $elastic;
        $this->index = $index;
        $this->indexIsPrefix = $indexIsPrefix;
        $this->perModelIndex = $perModelIndex;
    }

    /**
     * Retrieves the index for the given model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return string
     */
    protected function getIndex($model)
    {
        if ($this->perModelIndex)
        {
            return $this->index . $model->searchableAs();
        }

        if (!$this->indexIsPrefix)
        {
            return $this->index;
        }

        $suffix = method_exists($model, 'searchableIndex') ? $model->searchableIndex() : $model->searchableAs();

        return $this->index . $suffix;
    }

    /**
     * Retrieves the type for the given model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return string
     */
    protected function getType($model)
    {
        return method_exists($model, 'searchableType') ? $model->searchableType() : $model->searchableAs();
    }

    /**
     * Retrieve the id, index, and type for the given model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array
     */
    protected function getIdIndexType($model)
    {
        return [
            '_id' => $model->getKey(),
            '_index' => $this->getIndex($model),
            '_type' => $this->getType($model),
        ];
    }

    /**
     * Update the given model in the index.
     *
     * @param  Collection  $models
     * @return void
     */
    public function update($models)
    {
        $params['body'] = [];

        $models->each(function($model) use (&$params)
        {
            $params['body'][] = [
                'update' => $this->getIdIndexType($model)
            ];
            $params['body'][] = [
                'doc' => $model->toSearchableArray(),
                'doc_as_upsert' => true
            ];
        });

        $result = $this->elastic->bulk($params);

        if (isset($result['errors']) === true && $result['errors'] === true)
        {
            throw new \Exception(json_encode($result));
        }
    }

    /**
     * Remove the given model from the index.
     *
     * @param  Collection  $models
     * @return void
     */
    public function delete($models)
    {
        $params['body'] = [];

        $models->each(function($model) use (&$params)
        {
            $params['body'][] = [
                'delete' => [
                    '_id' => $model->getKey(),
                    '_index' => $this->getIndex($model),
                    '_type' => $model->searchableAs(),
                ]
            ];
        });

        $this->elastic->bulk($params);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  Builder  $builder
     * @return mixed
     */
    public function search(Builder $builder)
    {
        return $this->performSearch($builder, array_filter([
            'numericFilters' => $this->filters($builder),
            'size' => $builder->limit,
        ]));
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  Builder  $builder
     * @param  int  $perPage
     * @param  int  $page
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        $result = $this->performSearch($builder, [
            'numericFilters' => $this->filters($builder),
            'from' => (($page * $perPage) - $perPage),
            'size' => $perPage,
        ]);

       $result['nbPages'] = $result['hits']['total']/$perPage;

        return $result;
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  Builder  $builder
     * @param  array  $options
     * @return mixed
     */
    protected function performSearch(Builder $builder, array $options = [])
    {
        $params = [
            'index' => $builder->index ?: $this->getIndex($builder->model),
            'type' => $builder->index ?: $this->getType($builder->model),
            'body' => [
                'query' => [
                    'bool' => [
                        'must' => [['query_string' => [ 'query' => "*{$builder->query}*"]]]
                    ]
                ]
            ]
        ];

        if ($sort = $this->sort($builder)) {
            $params['body']['sort'] = $sort;
        }

        if (isset($options['from'])) {
            $params['body']['from'] = $options['from'];
        }

        if (isset($options['size'])) {
            $params['body']['size'] = $options['size'];
        }

        if (isset($options['numericFilters']) && count($options['numericFilters'])) {
            $params['body']['query']['bool']['must'] = array_merge($params['body']['query']['bool']['must'],
                $options['numericFilters']);
        }

        if ($builder->callback) {
            return call_user_func(
                $builder->callback,
                $this->elastic,
                $builder->query,
                $params
            );
        }

        return $this->elastic->search($params);
    }

    /**
     * Get the filter array for the query.
     *
     * @param  Builder  $builder
     * @return array
     */
    protected function filters(Builder $builder)
    {
        return collect($builder->wheres)->map(function ($value, $key) {
            if (is_array($value)) {
                return ['terms' => [$key => $value]];
            }

            return ['match_phrase' => [$key => $value]];
        })->values()->all();
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param  mixed  $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        return collect($results['hits']['hits'])->pluck('_id')->values();
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return Collection
     */
    public function map(Builder $builder, $results, $model)
    {
        if ($results['hits']['total'] === 0) {
            return $model->newCollection();
        }

        $keys = collect($results['hits']['hits'])->pluck('_id')->values()->all();

        $modelIdPositions = array_flip($keys);

        return $model->getScoutModelsByIds(
                $builder, $keys
            )->filter(function ($model) use ($keys) {
                return in_array($model->getScoutKey(), $keys);
            })->sortBy(function ($model) use ($modelIdPositions) {
                return $modelIdPositions[$model->getScoutKey()];
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
        return $results['hits']['total'];
    }

    /**
     * Flush all of the model's records from the engine.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function flush($model)
    {
        $model->newQuery()
            ->orderBy($model->getKeyName())
            ->unsearchable();
    }

    /**
     * Generates the sort if theres any.
     *
     * @param  Builder $builder
     * @return array|null
     */
    protected function sort($builder)
    {
        if (count($builder->orders) == 0) {
            return null;
        }

        return collect($builder->orders)->map(function($order) {
            return [$order['column'] => $order['direction']];
        })->toArray();
    }
}
