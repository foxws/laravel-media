<?php

declare(strict_types=1);

namespace Foxws\Media\Delivery;

use Closure;
use Foxws\Media\Opener;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;

/**
 * The streams served by Route::mediaStream(), defined once, e.g. in a service provider.
 */
class StreamRegistry
{
    /** @var array<string, StreamDefinition> */
    protected array $definitions = [];

    public function __construct(protected Router $router) {}

    /**
     * Define how a stream is resolved from its route parameters, e.g.
     * fn (Video $video) => Media::fromDisk('videos')->open($video->renditions()).
     *
     * @param  Closure(mixed ...): (DirectStream|Opener)  $resolver
     */
    public function define(string $name, Closure $resolver): StreamDefinition
    {
        return $this->definitions[$name] = new StreamDefinition($name, $resolver);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function definition(string $name): StreamDefinition
    {
        return $this->definitions[$name] ?? throw new InvalidArgumentException("Media stream [{$name}] is not defined.");
    }

    /**
     * The URL of a stream's HLS master playlist, signed when the stream is.
     *
     * @param  array<string, mixed>  $parameters
     *
     * @throws InvalidArgumentException
     */
    public function url(string $name, array $parameters = []): string
    {
        return $this->routeUrl($name, 'master', $parameters);
    }

    /**
     * The URL of a stream's DASH manifest, signed when the stream is.
     *
     * @param  array<string, mixed>  $parameters
     *
     * @throws InvalidArgumentException
     */
    public function dashUrl(string $name, array $parameters = []): string
    {
        return $this->routeUrl($name, 'dash', $parameters);
    }

    /**
     * @param  array<string, mixed>  $parameters
     *
     * @throws InvalidArgumentException
     */
    protected function routeUrl(string $name, string $role, array $parameters): string
    {
        $definition = $this->definition($name);
        $route = $this->route($name, $role);

        return $definition->isSigned()
            ? URL::temporarySignedRoute($route, now()->addSeconds($definition->lifetime()), $parameters)
            : URL::route($route, $parameters);
    }

    /**
     * The name of one of the stream's routes, wherever Route::mediaStream() registered it.
     *
     * @throws InvalidArgumentException
     */
    protected function route(string $name, string $role): string
    {
        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            if (($route->defaults['mediaStream'] ?? null) === $name && str_ends_with((string) $route->getName(), ".{$role}")) {
                return (string) $route->getName();
            }
        }

        throw new InvalidArgumentException("Media stream [{$name}] has no route. Register one with Route::mediaStream().");
    }
}
