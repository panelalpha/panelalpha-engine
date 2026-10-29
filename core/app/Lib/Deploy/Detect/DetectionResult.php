<?php

namespace App\Lib\Deploy\Detect;

/**
 * The shape every detection answer has.
 *
 * The pipeline reads these keys by name across a process boundary, so a
 * platform that says nothing about `output_directory` still has to produce
 * the key — a missing one and a null one are the same answer, and only one
 * of them survives a `json_encode`.
 */
final class DetectionResult
{
    /** @var array<string, null> */
    private const OPTIONAL_KEYS = [
        'runtime' => null,
        'output_directory' => null,
        'package_manager' => null,
        'install_command' => null,
        'build_command' => null,
        'start_command' => null,
        'env' => null,
        'static_index' => null,
    ];

    /**
     * @param  array<string, mixed>  $extra  everything the platform decided
     * @return array<string, mixed>
     */
    public static function of(
        string $strategy,
        string $label,
        ?string $composePath = null,
        ?string $dockerfile = null,
        ?int $portHint = null,
        array $extra = []
    ): array {
        $identity = [
            'strategy' => $strategy,
            'label' => $label,
            'compose_path' => $composePath,
            'dockerfile' => $dockerfile,
            'port_hint' => $portHint,
        ];

        return array_merge($identity, self::OPTIONAL_KEYS, $extra, $identity);
    }

    /**
     * @param  array<string, mixed>  $decision  as a platform described it
     * @return array<string, mixed>
     */
    public static function fromPlatform(array $decision): array
    {
        return self::of(
            (string) $decision['strategy'],
            (string) $decision['label'],
            $decision['compose_path'] ?? null,
            $decision['dockerfile'] ?? null,
            $decision['port_hint'] ?? null,
            $decision
        );
    }
}
