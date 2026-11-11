<?php

namespace App\Http\Responses;

use App\Http\Resources\TaskResource;
use App\Lib\Findings\Hints\FindingPresenter;
use App\Lib\Project\Create\QueuedDeploy;
use Illuminate\Http\JsonResponse;

/**
 * 202 with the queued task, and `data.inspection`: what the repository
 * inspection found, or null when none ran.
 */
final class AcceptedTask
{
    public static function of(QueuedDeploy $queued): JsonResponse
    {
        $response = TaskResource::make($queued->task)->response();
        $payload = $response->getData(true);
        $payload['data']['inspection'] = FindingPresenter::inspection($queued->inspection?->toResponse(), $queued->task->username);

        return $response->setData($payload)->setStatusCode(202);
    }
}
