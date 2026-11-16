<?php

namespace App\Lib\Project\Create;

use App\Lib\Deploy\Source\GitRepoInput;
use App\Lib\Deploy\Source\GitUrl;
use App\Lib\Git\DeployKey;
use App\Lib\Upload\ArchiveSource;
use App\Models\User;

/**
 * Where a project's code comes from, as the create's `source` words it: git from the project's git details, the
 * other types from what its create or attach recorded. Never a token or key.
 */
final class ProjectSourceView
{
    /**
     * @return array<string, mixed>
     */
    public static function of(User $user): array
    {
        return self::git($user) ?? self::recorded($user) ?? ['type' => CreateShape::SOURCE_NONE];
    }

    /** @return ?array<string, mixed> */
    private static function git(User $user): ?array
    {
        $repo = $user->getGitRepo();
        if ($repo !== null && $repo !== '') {
            return self::gitView($user, $repo, $user->getGitBranch(), $user->getGitToken() !== null, 'clone');
        }

        $checkout = $user->getTemplate() === 'dind' ? $user->getSiteGit('project') : null;
        if (! is_array($checkout) || $checkout['repo_url'] === '') {
            return null;
        }

        return self::gitView($user, $checkout['repo_url'], $checkout['branch'], ! empty($checkout['token']), 'checkout');
    }

    /**
     * @param  string  $rebuild  `clone`: a rebuild clones the repository again; `checkout`: it builds the
     *                           checkout as it is, and a git pull brings new commits
     * @return array<string, mixed>
     */
    private static function gitView(User $user, string $url, ?string $branch, bool $hasToken, string $rebuild): array
    {
        $auth = match (true) {
            GitRepoInput::isSsh($url) && DeployKey::stored($user) !== null => CreateShape::AUTH_DEPLOY_KEY,
            $hasToken => CreateShape::AUTH_TOKEN,
            default => CreateShape::AUTH_NONE,
        };

        return [
            'type' => CreateShape::SOURCE_GIT,
            'url' => GitUrl::sanitize($url),
            'branch' => $branch !== '' ? $branch : null,
            'auth' => $auth,
            'rebuild' => $rebuild,
        ];
    }

    /** @return ?array<string, mixed> */
    private static function recorded(User $user): ?array
    {
        $source = $user->getDetails()['source'] ?? null;
        if (! is_array($source) || ! in_array($source['type'] ?? null, CreateShape::SOURCE_TYPES, true)) {
            return null;
        }

        $shown = array_intersect_key($source, array_flip(['type', 'upload_id', 'url', 'path', 'from']));
        if ($shown['type'] === CreateShape::SOURCE_ARCHIVE && is_string($shown['url'] ?? null)) {
            $shown['url'] = ArchiveSource::shownUrl($shown['url']);
        }

        return $shown;
    }
}
