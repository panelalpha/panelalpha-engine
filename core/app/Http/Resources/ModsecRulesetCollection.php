<?php

namespace App\Http\Resources;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ModsecRulesetCollection extends ResourceCollection
{
    public function toArray(Request $request): array
    {
        $labels = [
            'owasp-crs' => "OWASP Core Rule Set",
            'panelalpha-wordpress' => "PanelAlpha WordPress hardening",
        ];
        $config = Setting::getModsecConfig();

        $data = [];
        foreach ($this->collection as $resource) {
            $set = $resource->resource;
            assert(
                is_array($set)
                    && array_key_exists('name', $set)
                    && is_string($set['name'])
            );

            $data[] = [
                'name' => $set['name'],
                'label' => !empty($labels[$set['name']]) ? $labels[$set['name']] : $set['name'],
                'enabled' => in_array($set['name'], $config['enabled_rulesets']),
                'config_files' => $set['config_files'],
            ];
        }

        return [
            'data' => $data,
        ];
    }
}
