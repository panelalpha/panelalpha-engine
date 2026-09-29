<?php

namespace App\Mcp\Tools;

use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use App\Mcp\Tools\Concerns\OmitsTitle;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('project_list_summary')]
#[Description('Every hosting project on this server: name, status, and how many domains it has. Read straight from the database, so it is one call and never paginates. The name is what every other tool takes as `name` (the API representation, from project_list, calls it `username`).')]
#[IsReadOnly]
#[IsIdempotent]
class ProjectListSummaryTool extends Tool
{
    use OmitsTitle;

    public function handle(Request $request): Response
    {
        $projects = User::withCount('domains')
            ->get(['id', 'username', 'status', 'created_at'])
            ->map(fn ($u): array => [
                'id' => $u->id,
                'name' => $u->username,
                'status' => $u->status,
                'domain_count' => $u->domains_count,
                'created_at' => $u->created_at,
            ])
            ->values()
            ->all();

        return Response::json([
            'count' => count($projects),
            'projects' => $projects,
        ]);
    }
}
