<?php

namespace Laravel\Feature\Drivers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use Illuminate\Support\Lottery;
use Laravel\Feature\Contracts\Driver as DriverContract;
use Laravel\Feature\Contracts\FeatureScopeable;
use Laravel\Feature\Events\DynamicallyRegisteringFeature;
use Laravel\Feature\PendingScopedFeatureInteraction;

/**
 * @mixin \Laravel\Feature\PendingScopedFeatureInteraction
 */
class Decorator implements DriverContract
{
    /**
     * The driver name.
     *
     * @var string
     */
    protected $name;

    /**
     * The driver being decorated.
     *
     * @var \Laravel\Feature\Contracts\Driver
     */
    protected $driver;

    /**
     * The default scope resolver.
     *
     * @var callable(): mixed
     */
    protected $defaultScopeResolver;

    /**
     * The container instance.
     *
     * @var \Illuminate\Contracts\Container\Container
     */
    protected $container;

    /**
     * The in-memory feature state cache.
     *
     * @var \Illuminate\Support\Collection<int, array{ feature: string, scope: mixed, value: mixed }>
     */
    protected $cache;

    /**
     * Create a new driver decorator instance.
     *
     * @param  string  $name
     * @param  \Laravel\Feature\Contracts\Driver  $driver
     * @param  (callable(): mixed)  $defaultScopeResolver
     * @param  \Illuminate\Contracts\Container\Container  $container
     * @param  \Illuminate\Support\Collection<int, array{ feature: string, scope: mixed, value: mixed }>  $cache
     */
    public function __construct($name, $driver, $defaultScopeResolver, $container, $cache)
    {
        $this->name = $name;
        $this->driver = $driver;
        $this->defaultScopeResolver = $defaultScopeResolver;
        $this->container = $container;
        $this->cache = $cache;
    }

    /**
     * Register an initial feature flag state resolver.
     *
     * @param  string|class-string  $feature
     * @param  mixed  $resolver
     * @return void
     */
    public function register($feature, $resolver = null)
    {
        if (func_num_args() === 1) {
            [$feature, $resolver] = with($this->container[$feature], fn ($instance) => [
                $instance->name ?? $instance::class,
                fn ($scope) => $this->container[$feature]($scope),
            ]);
        }

        if (is_string($resolver) || ! is_callable($resolver)) {
            $resolver = fn () => $resolver;
        }

        $this->driver->register($feature, function ($scope) use ($resolver) {
            $value = $resolver($scope);

            return $value instanceof Lottery
                ? $value()
                : $value;
        });
    }

    /**
     * Retrieve the names of all registered features.
     *
     * @return array<string>
     */
    public function registered()
    {
        return $this->driver->registered();
    }

    /**
     * Retrieve a feature flag's value.
     *
     * @internal
     *
     * @param  string  $feature
     * @param  mixed  $scope
     * @return mixed
     */
    public function get($feature, $scope)
    {
        $scope = $scope instanceof FeatureScopeable
            ? $scope->toFeatureIdentifier($this->name)
            : $scope;

        $item = $this->cache
            ->whereStrict('scope', $scope)
            ->whereStrict('feature', $feature)
            ->first();

        if ($item !== null) {
            return $item['value'];
        }

        if (! in_array($feature, $this->driver->registered()) && method_exists($feature, '__invoke')) {
            $this->container['events']->dispatch(new DynamicallyRegisteringFeature($feature));

            $this->register($feature);
        }

        return tap($this->driver->get($feature, $scope), function ($value) use ($feature, $scope) {
            $this->putInCache($feature, $scope, $value);
        });
    }

    /**
     * Set a feature flag's value.
     *
     * @internal
     *
     * @param  string  $feature
     * @param  mixed  $scope
     * @param  mixed  $value
     * @return void
     */
    public function set($feature, $scope, $value)
    {
        $scope = $scope instanceof FeatureScopeable
            ? $scope->toFeatureIdentifier($this->name)
            : $scope;

        $this->driver->set($feature, $scope, $value);

        $this->putInCache($feature, $scope, $value);
    }

    /**
     * Delete a feature flag's value.
     *
     * @internal
     *
     * @param  string  $feature
     * @param  mixed  $scope
     * @return void
     */
    public function delete($feature, $scope)
    {
        $this->driver->delete($feature, $scope);

        $this->removeFromCache($feature, $scope);
    }

    /**
     * Purge the given feature from storage.
     *
     * @param  string|null  $feature
     * @return void
     */
    public function purge($feature = null)
    {
        $this->driver->purge($feature);

        if ($feature === null) {
            $this->cache = new Collection;
        } else {
            $this->cache->forget($this->cache->search(fn ($item) => $item['feature']));
        }
    }

    /**
     * Eagerly preload multiple feature flag values.
     *
     * @param  string|array<int|string, mixed>  $features
     * @return array<string, array<int, mixed>>
     */
    public function load($features)
    {
        $features = $this->normalizeFeaturesToLoad($features);

        return tap($this->driver->load($features->all()), function ($results) use ($features) {
            $features->flatMap(fn ($scopes, $key) => Collection::make($scopes)
                ->zip($results[$key])
                ->map(fn ($scopes) => $scopes->push($key)))
                ->each(fn ($value) => $this->putInCache($value[2], $value[0], $value[1]));
        });
    }

    /**
     * Eagerly preload multiple feature flag values that are missing.
     *
     * @param  string|array<int|string, mixed>  $features
     * @return array<string, array<int, mixed>>
     */
    public function loadMissing($features)
    {
        return $this->normalizeFeaturesToLoad($features)
            ->map(fn ($scopes, $feature) => Collection::make($scopes)
                ->reject(fn ($scope) => $this->isCached($feature, $scope))
                ->all())
            ->reject(fn ($scopes) => $scopes === [])
            ->pipe(fn ($features) => $this->load($features->all()));
    }

    /**
     * Normalize the features to load.
     *
     * @param  string|array<int|string, mixed>  $features
     * @return \Illuminate\Support\Collection<string, array<int, mixed>>
     */
    protected function normalizeFeaturesToLoad($features)
    {
        return Collection::wrap($features)
            ->mapWithKeys(fn ($value, $key) => is_int($key)
                ? [$value => Collection::make([$this->defaultScope()])]
                : [$key => Collection::wrap($value)])
            ->map(fn ($scopes) => $scopes
                ->map(fn ($scope) => $scope instanceof FeatureScopeable
                    ? $scope->toFeatureIdentifier($this->name)
                    : $scope)
                ->all());
    }

    /**
     * Determine if a feature's value is in the cache for the given scope.
     *
     * @param  string  $feature
     * @param  mixed  $scope
     * @return bool
     */
    protected function isCached($feature, $scope)
    {
        return $this->cache->search(
            fn ($item) => $item['feature'] === $feature && $item['scope'] === $scope
        ) !== false;
    }

    /**
     * Put the given feature's value into the cache.
     *
     * @param  string  $feature
     * @param  mixed  $scope
     * @param  mixed  $value
     * @return void
     */
    protected function putInCache($feature, $scope, $value)
    {
        $position = $this->cache->search(
            fn ($item) => $item['feature'] === $feature && $item['scope'] === $scope
        );

        if ($position === false) {
            $this->cache[] = ['feature' => $feature, 'scope' => $scope, 'value' => $value];
        } else {
            $this->cache[$position] = ['feature' => $feature, 'scope' => $scope, 'value' => $value];
        }
    }

    /**
     * Remove the given feature's value from the cache.
     *
     * @param  string  $feature
     * @param  mixed  $scope
     * @return void
     */
    protected function removeFromCache($feature, $scope)
    {
        $position = $this->cache->search(
            fn ($item) => $item['feature'] === $feature && $item['scope'] === $scope
        );

        if ($position !== false) {
            unset($this->cache[$position]);
        }
    }

    /**
     * Retrieve the default scope.
     *
     * @return mixed
     */
    protected function defaultScope()
    {
        return ($this->defaultScopeResolver)();
    }

    /**
     * Flush the in-memory cache of feature values.
     *
     * @return void
     */
    public function flushCache()
    {
        $this->cache = new Collection;
    }

    /**
     * Get the underlying feature driver.
     *
     * @return \Laravel\Feature\Contracts\Driver
     */
    public function getDriver()
    {
        return $this->driver;
    }

    /**
     * Set the container instance used by the decorator.
     *
     * @param  \Illuminate\Contracts\Container\Container  $container
     * @return $this
     */
    public function setContainer(Container $container)
    {
        $this->container = $container;

        return $this;
    }

    /**
     * Dynamically create a pending feature interaction.
     *
     * @param  string  $name
     * @param  array<mixed>  $parameters
     * @return mixed
     */
    public function __call($name, $parameters)
    {
        return tap(new PendingScopedFeatureInteraction($this), function ($interaction) use ($name) {
            if ($name !== 'for' && ($this->defaultScopeResolver)() !== null) {
                $interaction->for(($this->defaultScopeResolver)());
            }
        })->{$name}(...$parameters);
    }
}
