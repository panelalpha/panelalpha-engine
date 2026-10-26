<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskLogResource extends JsonResource
{
    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $row = $this->resource;

        $parsed = self::parsePayload($row->log);

        return [
            'id' => $row->id,
            'log' => $parsed['msg'],
            'level' => $parsed['level'],
            'stage' => $parsed['stage'],
            'created_at' => $row->created_at,
        ];
    }

    /**
     * Deploy lines are stored as JSON {"ts","stage","level","msg"}; plain
     * strings from AttachTask::logTask() stay as the message with null meta.
     *
     * @return array{msg: string, level: ?string, stage: ?string}
     */
    public static function parsePayload(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (
            is_array($decoded)
            && array_key_exists('msg', $decoded)
            && is_string($decoded['msg'])
        ) {
            return [
                'msg' => $decoded['msg'],
                'level' => isset($decoded['level']) && is_string($decoded['level'])
                    ? $decoded['level']
                    : null,
                'stage' => isset($decoded['stage']) && is_string($decoded['stage'])
                    ? $decoded['stage']
                    : null,
            ];
        }

        return [
            'msg' => $raw,
            'level' => null,
            'stage' => null,
        ];
    }
}
