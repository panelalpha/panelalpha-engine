<?php

namespace App\Http\Resources;

use App\Lib\Project\ProjectListFacts;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class UserCollection extends ResourceCollection
{
    /**
     * The `app` and `usage` blocks of every listed project come from one load,
     * not from a few queries per project.
     *
     * @param  Request  $request
     * @return array<int|string, mixed>
     */
    public function toArray($request)
    {
        $request->attributes->set(
            ProjectListFacts::class,
            ProjectListFacts::load($this->collection->map(fn (UserResource $resource) => $resource->resource))
        );

        return parent::toArray($request);
    }
}
