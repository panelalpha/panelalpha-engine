<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PushDiffResource extends JsonResource
{
    /**
     * @return array{
     *     tables_structure_diff: list<array{database: string, name: string}>,
     *     tables_data_diff: list<array{database: string, name: string}>
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'tables_structure_diff' => $this->resource['tables_structure_diff'],
            'tables_data_diff' => $this->resource['tables_data_diff'],
        ];
    }
}
