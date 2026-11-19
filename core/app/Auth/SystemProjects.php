<?php

namespace App\Auth;

use App\Lib\Project\ApiAccess;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Which system projects a caller may not see. A token sees none of them in a list, and reaches by name only the
 * project whose API access holds it. The console carries no token and sees everything.
 */
final class SystemProjects
{
    private function __construct(
        private readonly ?PersonalAccessToken $token,
    ) {}

    public static function for(?PersonalAccessToken $token): self
    {
        return new self($token);
    }

    public static function forRequest(?Request $request = null): self
    {
        return new self(RequestToken::of($request ?? request()));
    }

    /** The project the caller's token belongs to, or null for any other token and for the console. */
    public function own(): ?string
    {
        return $this->token === null ? null : app(ApiAccess::class)->projectHolding($this->token);
    }

    /**
     * The system projects this caller may not reach by name.
     *
     * @return list<string>
     */
    public function hidden(): array
    {
        $system = $this->unlisted();

        return $system === [] ? [] : array_values(array_diff($system, array_filter([$this->own()])));
    }

    /**
     * The system projects left out of every list this caller reads, its own included.
     *
     * @return list<string>
     */
    public function unlisted(): array
    {
        return $this->token === null ? [] : User::systemUsernames();
    }

    public function hides(?string $username): bool
    {
        return $username !== null && in_array($username, $this->hidden(), true);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function unlist(Builder $query, string $column = 'username'): Builder
    {
        $unlisted = $this->unlisted();

        return $unlisted === [] ? $query : $query->whereNotIn($column, $unlisted);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function withoutHidden(Builder $query, string $column = 'username'): Builder
    {
        $hidden = $this->hidden();

        return $hidden === [] ? $query : $query->whereNotIn($column, $hidden);
    }

    /**
     * The items of a list whose project this caller may see listed.
     *
     * @template TItem
     *
     * @param  iterable<TItem>  $items
     * @param  Closure(TItem): ?string  $projectOf
     * @return list<TItem>
     */
    public function listable(iterable $items, Closure $projectOf): array
    {
        $unlisted = $this->unlisted();
        $kept = [];
        foreach ($items as $item) {
            if (! in_array($projectOf($item), $unlisted, true)) {
                $kept[] = $item;
            }
        }

        return $kept;
    }
}
