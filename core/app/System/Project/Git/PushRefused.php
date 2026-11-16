<?php

namespace App\System\Project\Git;

/**
 * A push the repository rejected or did not authorize: a 422 with a problem code, as the caller has to change
 * something before pushing again.
 */
class PushRefused extends Exception
{
    public const string REJECTED = 'git_push_rejected';

    public const string UNAUTHORIZED = 'git_push_unauthorized';

    /**
     * The line git reports a refused ref on, for a non-fast-forward and a declined push alike. It is read first: a
     * forge declining a protected branch says "not allowed to push" too, and that is no missing credential.
     */
    private const string REJECTED_LINE = '/^.*\[(remote )?rejected\].*$/mi';

    /** Git's and the forges' words for a push without credentials, or with credentials that may not write. */
    private const string UNAUTHORIZED_OUTPUT = '/could not read (username|password)|unable to read askpass response'
        .'|terminal prompts disabled|authentication failed|invalid username or (password|token)|http basic: access denied'
        .'|returned error: 40[13]|permission to \S+ denied|write access to repository not granted|not allowed to push'
        .'|marked as read only|does not have write access/i';

    /** $failure as the refusal it is when git says the repository turned the push down, otherwise $failure itself. */
    public static function of(Exception $failure): Exception
    {
        if ($failure->httpStatus !== 400) {
            return $failure;
        }
        $output = $failure->getMessage();

        if (preg_match(self::REJECTED_LINE, $output, $line) === 1) {
            return new self('The repository rejected the push ('.self::squeezed($line[0]).'): the branch has commits '
                .'this checkout does not, or a protected branch or a server hook declined it. Nothing was pushed.',
                422, self::REJECTED);
        }
        if (preg_match(self::UNAUTHORIZED_OUTPUT, $output) === 1) {
            return new self('The repository did not let this project push to it: no token is stored, or the token or '
                .'deploy key may not write to it. Store a token with write access, or give the deploy key write access, '
                .'and push again. Nothing was pushed.', 422, self::UNAUTHORIZED);
        }

        return $failure;
    }

    private static function squeezed(string $line): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $line));
    }
}
