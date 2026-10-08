<?php

namespace Tests\Unit\Staging;

use App\Http\Resources\UserResource;
use App\Models\Ipv4NatMap;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Tests\TestCase;

class UserResourceStagingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Setting::setRuntimeSettings(['disable-user-ip-assign' => '1']);
        Ipv4NatMap::setNatModeEnabledOverride(false);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        Ipv4NatMap::clearNatModeEnabledOverride();
        parent::tearDown();
    }

    public function test_resource_exposes_pair_usernames_from_loaded_relations(): void
    {
        $live = new User();
        $live->id = 1;
        $live->username = 'liveapp';
        $live->domain = 'live.test';
        $live->email = 'a@b.c';
        $live->status = 'active';
        $live->details = [];

        $staging = new User();
        $staging->id = 2;
        $staging->username = 'stgapp';
        $staging->domain = 'stg.test';
        $staging->email = 'a@b.c';
        $staging->status = 'pending';
        $staging->details = [];
        $staging->staging = 1;
        $staging->setRelation('liveUser', $live);
        $staging->setRelation('stagingUser', null);
        $live->setRelation('liveUser', null);
        $live->setRelation('stagingUser', $staging);

        $stgJson = (new UserResource($staging))->toArray(Request::create('/'));
        $this->assertSame('liveapp', $stgJson['staging_of']);
        $this->assertNull($stgJson['staging']);

        $liveJson = (new UserResource($live))->toArray(Request::create('/'));
        $this->assertNull($liveJson['staging_of']);
        $this->assertSame('stgapp', $liveJson['staging']);
    }
}
