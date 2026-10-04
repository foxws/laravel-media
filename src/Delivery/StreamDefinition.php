<?php

declare(strict_types=1);

namespace Foxws\Media\Delivery;

use Closure;
use Foxws\Media\Opener;
use Illuminate\Contracts\Routing\UrlRoutable;
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
     *
     * @param  array<string, mixed>  $parameters
     *
     * @throws NotFoundHttpException
     */
    public function resolve(array $parameters): DirectStream
    {
        $stream = App::call($this->resolver, $this->bind($parameters));

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
    protected function bind(array $parameters): array
    {
        foreach (new ReflectionFunction($this->resolver)->getParameters() as $parameter) {
            $type = $parameter->getType();
            $value = $parameters[$parameter->getName()] ?? null;

            if (! $type instanceof ReflectionNamedType || ! is_a($type->getName(), UrlRoutable::class, true) || $value === null || $value instanceof UrlRoutable) {
                continue;
            }

            /** @var UrlRoutable $routable */
            $routable = App::make($type->getName());

            $parameters[$parameter->getName()] = $routable->resolveRouteBinding($value)
                ?? throw new NotFoundHttpException("No [{$type->getName()}] found for [{$parameter->getName()}].");
        }

        return $parameters;
    }
}
