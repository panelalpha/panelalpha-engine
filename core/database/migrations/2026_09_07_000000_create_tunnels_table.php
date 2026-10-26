<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tunnels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('domain_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('provider')->index();
            $table->string('hostname')->unique();
            $table->json('details')->nullable();
            $table->timestamps();

            $table->foreign('domain_id')
                ->references('id')
                ->on('domains')
                ->onDelete('cascade');
        });

        // Migrate v1 Cloudflare-provider domains → tunnels rows.
        $domains = DB::table('domains')->orderBy('id')->get();
        foreach ($domains as $domain) {
            $details = [];
            if (is_string($domain->details) && $domain->details !== '') {
                $decoded = json_decode($domain->details, true);
                if (is_array($decoded)) {
                    $details = $decoded;
                }
            } elseif (is_array($domain->details)) {
                $details = $domain->details;
            }

            $provider = strtolower((string) ($details['provider'] ?? 'local'));
            if ($provider !== 'cloudflare') {
                continue;
            }

            $hostname = strtolower((string) $domain->domain);
            $tunnelDetails = [];
            if (! empty($details['cloudflare_zone_id']) && is_string($details['cloudflare_zone_id'])) {
                $tunnelDetails['cloudflare_zone_id'] = $details['cloudflare_zone_id'];
            }
            if (! empty($details['cloudflare_dns_record_id']) && is_string($details['cloudflare_dns_record_id'])) {
                $tunnelDetails['cloudflare_dns_record_id'] = $details['cloudflare_dns_record_id'];
            }

            DB::table('tunnels')->insert([
                'domain_id' => $domain->id,
                'user_id' => $domain->user_id,
                'provider' => 'cloudflare',
                'hostname' => $hostname,
                'details' => $tunnelDetails === [] ? null : json_encode($tunnelDetails),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            unset($details['provider'], $details['cloudflare_zone_id'], $details['cloudflare_dns_record_id']);
            DB::table('domains')->where('id', $domain->id)->update([
                'details' => json_encode($details),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Best-effort: fold tunnels back onto domain.details for cloudflare rows
        // where hostname matches the domain name (v1 shape).
        if (Schema::hasTable('tunnels')) {
            $tunnels = DB::table('tunnels')->where('provider', 'cloudflare')->get();
            foreach ($tunnels as $tunnel) {
                $domain = DB::table('domains')->where('id', $tunnel->domain_id)->first();
                if ($domain === null) {
                    continue;
                }
                if (strtolower((string) $domain->domain) !== strtolower((string) $tunnel->hostname)) {
                    continue;
                }

                $details = [];
                if (is_string($domain->details) && $domain->details !== '') {
                    $decoded = json_decode($domain->details, true);
                    if (is_array($decoded)) {
                        $details = $decoded;
                    }
                }

                $details['provider'] = 'cloudflare';
                $tunnelDetails = [];
                if (is_string($tunnel->details) && $tunnel->details !== '') {
                    $decoded = json_decode($tunnel->details, true);
                    if (is_array($decoded)) {
                        $tunnelDetails = $decoded;
                    }
                }
                if (! empty($tunnelDetails['cloudflare_zone_id'])) {
                    $details['cloudflare_zone_id'] = $tunnelDetails['cloudflare_zone_id'];
                }
                if (! empty($tunnelDetails['cloudflare_dns_record_id'])) {
                    $details['cloudflare_dns_record_id'] = $tunnelDetails['cloudflare_dns_record_id'];
                }

                DB::table('domains')->where('id', $domain->id)->update([
                    'details' => json_encode($details),
                    'updated_at' => now(),
                ]);
            }
        }

        Schema::dropIfExists('tunnels');
    }
};
