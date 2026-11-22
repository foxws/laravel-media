<?php

declare(strict_types=1);

namespace Foxws\Media\Delivery;

use Closure;
use Foxws\Media\Opener;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use ReflectionFunction;
use ReflectionNamedType;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A named stream served by Route::mediaStream(): resolves the stream from the route parameters.
 */
class StreamDefinition
{
    protected bool $signed = false;

    protected ?int $lifetime = null;

    /**
     * @param  Closure(mixed ...): (DirectStream|Opener)  $resolver
     */
    public function __construct(
        public readonly string $name,
        protected Closure $resolver,
    ) {}

    /**
     * Only serve requests with a valid signature, and sign every URL in the playlists.
     *
     * @param  int|null  $lifetime  Seconds the URLs stay valid; null for media.delivery.url_lifetime.
     */
    public function signed(?int $lifetime = null): static
    {
        $this->signed = true;
        $this->lifetime = $lifetime;

        return $this;
    }

    public function isSigned(): bool
    {
        return $this->signed;
    }

    /**
     * Seconds signed URLs stay valid.
     */
    public function lifetime(): int
    {
        return $this->lifetime ?? Config::integer('media.delivery.url_lifetime', 3600);
    }

    /**
     * Call the resolver with the route parameters. Parameters typed as a model (or any UrlRoutable)
     * are bound like implicit route model binding, and anything else is injected by the container.
     * With the route, its binding fields ("{video:slug}") and scoped bindings apply.
     *
     * @param  array<string, mixed>  $parameters
     *
     * @throws NotFoundHttpException
     */
    public function resolve(array $parameters, ?Route $route = null): DirectStream
    {
        $stream = App::call($this->resolver, $this->bind($parameters, $route));

        return match (true) {
            $stream instanceof DirectStream => $stream,
            $stream instanceof Opener => $stream->stream(),
            default => throw new InvalidArgumentException("The [{$this->name}] media stream must resolve to a DirectStream or an Opener."),
        };
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    protected function bind(array $parameters, ?Route $route): array
    {
        $types = $this->routableTypes();
        $names = $route?->parameterNames() ?? [];

        // In the route's order, so a parent is bound before the child that's scoped to it.
        foreach (array_unique([...array_intersect($names, array_keys($types)), ...array_keys($types)]) as $name) {
            $value = $parameters[$name] ?? null;

            if ($value === null || $value instanceof UrlRoutable) {
                continue;
            }

            $field = $route?->bindingFieldFor($name);
            $parent = $this->scopingParent($parameters, $name, $route);

            if ($parent !== null) {
                $bound = $parent->resolveChildRouteBinding($name, $value, $field);
            } else {
                /** @var UrlRoutable $routable */
                $routable = App::make($types[$name]);
                $bound = $routable->resolveRouteBinding($value, $field);
            }

            $parameters[$name] = $bound ?? throw new NotFoundHttpException("No [{$types[$name]}] found for [{$name}].");
        }

        return $parameters;
    }

    /**
     * The resolver's parameters that are bound, with their type.
     *
     * @return array<string, class-string<UrlRoutable>>
     */
    protected function routableTypes(): array
    {
        $types = [];

        foreach (new ReflectionFunction($this->resolver)->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && is_a($type->getName(), UrlRoutable::class, true)) {
                $types[$parameter->getName()] = $type->getName();
            }
        }

        return $types;
    }

    /**
     * The bound parameter before this one, when the route scopes this one to it like Laravel does.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function scopingParent(array $parameters, string $name, ?Route $route): ?UrlRoutable
    {
        if ($route === null || $route->preventsScopedBindings()) {
            return null;
        }

        if (! $route->enforcesScopedBindings() && ! array_key_exists($name, $route->bindingFields())) {
            return null;
        }

        $names = $route->parameterNames();
        $position = array_search($name, $names, true);
        $parent = is_int($position) && $position > 0 ? ($parameters[$names[$position - 1]] ?? null) : null;

        return $parent instanceof UrlRoutable ? $parent : null;
    }
}
