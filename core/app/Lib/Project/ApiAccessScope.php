<?php

namespace App\Lib\Project;

use App\Auth\TokenAbilities;
use App\Exceptions\ProblemException;
use App\Models\PersonalAccessToken;
use App\Models\User;

/** An Engine API scope, `self`, `all` or a list of projects: how it is spelled, what it reaches, why one is refused. */
final class ApiAccessScope
{
    /**
     * A scope as an operator reads it: `self`, `all`, or the projects named.
     *
     * @param  string|list<string>  $scope
     */
    public static function label(string|array $scope): string
    {
        if ($scope === []) {
            return 'no project';
        }

        return is_array($scope) ? implode(', ', $scope) : $scope;
    }

    /**
     * The entry's own spelling of the scope: self, all, or the named projects, in
     * one order, so the same set of projects reads the same however it was named.
     *
     * @param  string|list<string>  $scope
     * @return string|list<string>
     */
    public static function canonical(string|array $scope): string|array
    {
        if (is_string($scope)) {
            return $scope;
        }

        $names = array_values(array_unique(array_map('strval', $scope)));
        sort($names);

        return $names;
    }

    /**
     * @param  string|list<string>  $scope
     * @return list<string>|null null for every project on this host
     */
    public static function projects(string $project, string|array $scope): ?array
    {
        if ($scope === ApiAccess::SCOPE_ALL) {
            return null;
        }
        if ($scope === ApiAccess::SCOPE_SELF) {
            return [$project];
        }
        if (! is_array($scope)) {
            throw self::problem($scope);
        }

        return self::existingProjects($scope);
    }

    /**
     * @return null|list<string> null when the token is gone or reaches every project
     */
    public static function reachedBy(?int $tokenId): ?array
    {
        $token = $tokenId === null ? null : PersonalAccessToken::query()->find($tokenId);

        return $token instanceof PersonalAccessToken ? TokenAbilities::of($token)->projects() : null;
    }

    public static function problem(mixed $scope): ProblemException
    {
        return ProblemException::one(
            'scope',
            ApiAccess::SCOPE_INVALID,
            sprintf(
                'A scope is "%s", "%s", or a non-empty list of project names, not %s.',
                ApiAccess::SCOPE_SELF,
                ApiAccess::SCOPE_ALL,
                is_array($scope) ? 'an empty list' : gettype($scope)
            )
        );
    }

    /**
     * @param  list<mixed>  $scope
     * @return list<string>
     */
    private static function existingProjects(array $scope): array
    {
        $names = [];
        foreach ($scope as $name) {
            if (! is_string($name) || ! User::existsByUsername($name)) {
                throw ProblemException::one(
                    'scope',
                    ApiAccess::PROJECT_UNKNOWN,
                    sprintf("'%s' is not a project on this engine.", is_string($name) ? $name : gettype($name))
                );
            }
            $names[] = $name;
        }

        return array_values(array_unique($names));
    }
}
