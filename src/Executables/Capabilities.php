<?php

declare(strict_types=1);

namespace Foxws\Media\Executables;

use Foxws\Media\Exceptions\MissingCapabilitiesException;
use Foxws\Media\Process\Runner;

/**
 * What the configured ffmpeg was built with: its encoders, decoders, muxers, demuxers,
 * filters, protocols, bitstream filters and hardware accelerations. Being compiled in
 * doesn't prove that a GPU can be opened; HardwareAcceleration checks that.
 */
final readonly class Capabilities
{
    /**
     * @param  array<string, list<string>>  $names  Component names keyed by Capability value.
     */
    public function __construct(public array $names = []) {}

    /**
     * Ask ffmpeg for each list. This runs ffmpeg once per kind, so check in a command,
     * health check or deploy step rather than on every request.
     */
    public static function detect(?Runner $runner = null): self
    {
        $runner ??= Runner::make();

        $names = [];

        foreach (Capability::cases() as $capability) {
            $result = $runner->run(Executable::FFMpeg, ['-hide_banner', $capability->argument()], timeout: 30, logWarnings: false);

            $names[$capability->value] = $capability->parse($result->output);
        }

        return new self($names);
    }

    public function has(Capability $capability, string $name): bool
    {
        return in_array($name, $this->names[$capability->value] ?? [], true);
    }

    /**
     * @return list<string>
     */
    public function all(Capability $capability): array
    {
        return $this->names[$capability->value] ?? [];
    }

    /**
     * The required names ffmpeg doesn't have, keyed by Capability value, e.g.
     * missing(['encoders' => ['libx264', 'libsvtav1'], 'hwaccels' => ['vaapi']]).
     *
     * @param  array<string, list<string>>  $required  Names keyed by Capability value.
     * @return array<string, list<string>>
     */
    public function missing(array $required): array
    {
        $missing = [];

        foreach ($required as $kind => $names) {
            $capability = Capability::from($kind);

            $absent = array_values(array_filter($names, fn (string $name): bool => ! $this->has($capability, $name)));

            if ($absent !== []) {
                $missing[$capability->value] = $absent;
            }
        }

        return $missing;
    }

    /**
     * Throw when ffmpeg lacks any of the required names, keyed by Capability value.
     *
     * @param  array<string, list<string>>  $required
     *
     * @throws MissingCapabilitiesException
     */
    public function require(array $required): self
    {
        $missing = $this->missing($required);

        if ($missing !== []) {
            throw MissingCapabilitiesException::for($missing);
        }

        return $this;
    }

    /**
     * @return array<string, list<string>>
     */
    public function toArray(): array
    {
        return $this->names;
    }
}
