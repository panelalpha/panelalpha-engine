<?php

namespace App\Mcp\Tools;

use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use App\Mcp\Tools\Concerns\OmitsTitle;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('metrics_latest')]
#[Description('The most recent server resource sample -- CPU, RAM, disk I/O, network I/O -- read straight from the metrics table. metrics_current is the same reading through the API.')]
#[IsReadOnly]
#[IsIdempotent]
class MetricsLatestTool extends Tool
{
    use OmitsTitle;

    public function handle(Request $request): Response
    {
        $row = DB::table('server_metrics')
            ->orderByDesc('timestamp')
            ->first();

        if (! $row) {
            return Response::error('No metrics available yet.');
        }

        return Response::json([
            'timestamp' => $row->timestamp,
            'cpu_percent' => $row->cpu_percent,
            'cpu_load_avg' => [
                '1m' => $row->cpu_load_avg_1,
                '5m' => $row->cpu_load_avg_5,
                '15m' => $row->cpu_load_avg_15,
            ],
            'ram_percent' => $row->ram_percent,
            'swap_percent' => $row->swap_percent,
            'disk_io' => [
                'read_bps' => $row->disk_read_bps,
                'write_bps' => $row->disk_write_bps,
                'read_iops' => $row->disk_read_iops,
                'write_iops' => $row->disk_write_iops,
            ],
            'network_io' => [
                'in_bps' => $row->net_in_bps,
                'out_bps' => $row->net_out_bps,
                'in_pps' => $row->net_in_pps,
                'out_pps' => $row->net_out_pps,
            ],
        ]);
    }
}
