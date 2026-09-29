<?php

namespace App\Http\Controllers;

use App\Http\Requests\Ip\AddSubnetRequest;
use App\Http\Requests\Ip\AssignIpRequest;
use App\Http\Requests\Ip\UnassignIpRequest;
use App\Http\Resources\IpAssignedCollection;
use App\Http\Resources\IpAssignedResource;
use App\Http\Resources\IpSubnetCollection;
use App\Http\Resources\IpSubnetResource;
use App\System;
use App\Models\IpAssigned;
use App\Models\IpSubnet;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\IpUtils;
use OpenApi\Attributes as OA;

class IpController extends Controller
{
    #[OA\Get(
        path: '/ip/subnets',
        summary: 'List IP subnets',
        security: [['bearerAuth' => []]],
        tags: ['IP Management'],
        responses: [
            new OA\Response(response: 200, description: 'IP subnets', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/IpSubnet'))],
            )),
        ],
    )]
    public function listSubnets(): IpSubnetCollection
    {
        $subnets = IpSubnet::with('ipAssigned.user.domains')->get();

        $defaultIpv4 = Setting::get('default_ipv4');
        $defaultIpv4Assignments = [];
        if (filter_var($defaultIpv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            /** @var Collection */
            $result = User::with('domains')
                ->whereDoesntHave('assignedIpAddresses', function (Builder $q) {
                    $q->whereHas('ipSubnet', function (Builder $qq) {
                        $qq->where('family', 4);
                    });
                })->get();
            /** @var array<User> */
            $usersNoIpv4 = $result->all();
            foreach ($usersNoIpv4 as $user) {
                $defaultIpv4Assignments[] = [
                    'user_id' => $user->id,
                    'username' => $user->username,
                    'domain_names' => $user->listAllDomainNames(),
                ];
            }
        }

        $defaultIpv6 = Setting::get('default_ipv6');
        $defaultIpv6Assignments = [];
        if (filter_var($defaultIpv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            /** @var Collection */
            $result = User::with('domains')
                ->whereDoesntHave('assignedIpAddresses', function (Builder $q) {
                    $q->whereHas('ipSubnet', function (Builder $qq) {
                        $qq->where('family', 6);
                    });
                })->get();
            /** @var array<User> */
            $usersNoIpv6 = $result->all();
            foreach ($usersNoIpv6 as $user) {
                $defaultIpv6Assignments[] = [
                    'user_id' => $user->id,
                    'username' => $user->username,
                    'domain_names' => $user->listAllDomainNames(),
                ];
            }
        }

        return (new IpSubnetCollection($subnets))->additional([
            'meta' => [
                'default_ipv4' => [
                    'address' => $defaultIpv4,
                    'assignments' => $defaultIpv4Assignments,
                ],
                'default_ipv6' => [
                    'address' => $defaultIpv6,
                    'assignments' => $defaultIpv6Assignments,
                ]
            ]
        ]);
    }

    private function getNetwork4Ip(string $ip, int $mask): ?string
    {
        $packed = inet_pton($ip);
        $unpacked = unpack('N', $packed);
        if ($unpacked === false || !array_key_exists(1, $unpacked) || !is_int($unpacked[1])) {
            return null;
        }
        $long = $unpacked[1];
        $mask = $mask === 0 ? 0 : (0xFFFFFFFF << (32 - $mask)) & 0xFFFFFFFF;
        $networkLong = $long & $mask;
        $networkIp = inet_ntop(pack('N', $networkLong));
        return $networkIp;
    }

    private function getNetwork6Ip(string $ip, int $mask): ?string
    {
        $packed = inet_pton($ip);
        $bytes  = unpack('C*', $packed);
        if ($bytes === false) {
            return null;
        }
        $bits = '';
        foreach ($bytes as $byte) {
            if (!is_int($byte)) {
                return null;
            }
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }
        $networkBits = substr($bits, 0, $mask) . str_repeat('0', 128 - $mask);
        $networkBytes = [];
        for ($i = 0; $i < 16; $i++) {
            $networkBytes[] = bindec(substr($networkBits, $i * 8, 8));
        }
        $networkIp = inet_ntop(pack('C*', ...$networkBytes));
        return $networkIp;
    }

    #[OA\Post(
        path: '/ip/subnets',
        summary: 'Add an IP subnet',
        security: [['bearerAuth' => []]],
        tags: ['IP Management'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['ip', 'mask'],
            properties: [
                new OA\Property(property: 'ip', type: 'string', example: '192.168.1.0'),
                new OA\Property(property: 'mask', type: 'integer', example: 24),
                new OA\Property(property: 'is_shared', type: 'boolean', nullable: true),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Subnet created', content: new OA\JsonContent(ref: '#/components/schemas/IpSubnet')),
        ],
    )]
    public function addSubnet(AddSubnetRequest $request): IpSubnetResource
    {
        /**
         * @var array{
         *   ip: string,
         *   mask: int,
         *   is_shared?: ?bool 
         * }
         */
        $params = $request->validated();
        $family = 4;
        $isShared = !empty($params['is_shared']);

        if (filter_var($params['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if ($params['mask'] < 0 || $params['mask'] > 32) {
                throw ValidationException::withMessages([
                    'IPv4 mask must be between 0 and 32.',
                ]);
            }
            $networkIp = $this->getNetwork4Ip($params['ip'], $params['mask']);
            if ($params['ip'] !== $networkIp) {
                throw ValidationException::withMessages([
                    'IPv4 must be the network address (e.g. ' . ($networkIp === null ? '192.168.0.0/24' :  ($networkIp . '/' . $params['mask'])) . ').',
                ]);
            }
        } elseif (filter_var($params['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if ($params['mask'] < 0 || $params['mask'] > 128) {
                throw ValidationException::withMessages([
                    'IPv6 mask must be between 0 and 128.',
                ]);
            }
            $networkIp = $this->getNetwork6Ip($params['ip'], $params['mask']);
            if ($params['ip'] !== $networkIp) {
                throw ValidationException::withMessages([
                    'IPv6 must be the network address (e.g. ' . ($networkIp === null ? '2001:0db8:abcd:0012::/64' : ($networkIp . '/' . $params['mask'])) . ').',
                ]);
            }
            $family = 6;
            $isShared = false;
        }

        if (IpSubnet::query()->where([
            'ip' => $params['ip'],
            'mask' => $params['mask'],
        ])->exists()) {
            throw ValidationException::withMessages([
                "Subnet {$params['ip']}/{$params['mask']} already exist.",
            ]);
        }

        $subnet = IpSubnet::create([
            'ip' => $params['ip'],
            'mask' => $params['mask'],
            'family' => $family,
            'is_shared' => $isShared,
        ]);

        try {
            $system = new System();
            $cmd = $system->network()->generateIpRouteAddCommand($params['ip'], $params['mask'], $family);
            $system->runProcessOnHost($cmd);
        } catch (\Exception $e) {
            Log::warning("Could not add IP route for subnet {$params['ip']}/{$params['mask']}", [
                'exception_message' => $e->getMessage(),
            ]);
        }

        return new IpSubnetResource($subnet);
    }

    #[OA\Delete(
        path: '/ip/subnets/{id}',
        summary: 'Delete an IP subnet',
        security: [['bearerAuth' => []]],
        tags: ['IP Management'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Subnet deleted', content: new OA\JsonContent(ref: '#/components/schemas/IpSubnet')),
        ],
    )]
    public function deleteSubnet(int $id): IpSubnetResource
    {
        $subnet = IpSubnet::findOrFail($id);

        if ($subnet->ipAssigned()->count()) {
            throw ValidationException::withMessages([
                'Cannot delete IP subnet with assigned IP addresses',
            ]);
        }

        $subnet->delete();

        try {
            $system = new System();
            $cmd = $system->network()->generateIpRouteDelCommand($subnet->ip, $subnet->mask, $subnet->family);
            $system->runProcessOnHost($cmd);
        } catch (\Exception $e) {
            Log::warning("Could not delete IP route for subnet {$subnet->ip}/{$subnet->mask}", [
                'exception_message' => $e->getMessage(),
            ]);
        }

        return new IpSubnetResource($subnet);
    }

    #[OA\Get(
        path: '/ip/assigned',
        summary: 'List assigned IP addresses',
        security: [['bearerAuth' => []]],
        tags: ['IP Management'],
        responses: [
            new OA\Response(response: 200, description: 'Assigned IPs', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssignedIp'))],
            )),
        ],
    )]
    public function listAssignedIps(): IpAssignedCollection
    {
        $ips = IpAssigned::get();
        return new IpAssignedCollection($ips);
    }

    #[OA\Post(
        path: '/ip/assign',
        summary: 'Assign an IP address to a project',
        security: [['bearerAuth' => []]],
        tags: ['IP Management'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['ip_subnet_id', 'ip_address'],
            properties: [
                new OA\Property(property: 'user_id', type: 'integer', nullable: true),
                new OA\Property(property: 'username', type: 'string', nullable: true),
                new OA\Property(property: 'ip_subnet_id', type: 'integer'),
                new OA\Property(property: 'ip_address', type: 'string'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'IP assigned', content: new OA\JsonContent(ref: '#/components/schemas/AssignedIp')),
        ],
    )]
    public function assignIpAddress(AssignIpRequest $request): IpAssignedResource
    {
        /**
         * @var array{
         *   user_id?: int,
         *   username?: string,
         *   ip_subnet_id: int,
         *   ip_address: string
         * }
         */
        $params = $request->validated();
        if (empty($params['user_id']) && empty($params['username'])) {
            throw ValidationException::withMessages([
                'user_id or username is required.',
            ]);
        }

        $user = !empty($params['user_id'])
            ? User::find($params['user_id'])
            : User::findByUsername($params['username']);
        if (!$user) {
            throw ValidationException::withMessages([
                'User not found.',
            ]);
        }

        $ipSubnet = IpSubnet::find($params['ip_subnet_id']);
        if (!$ipSubnet) {
            throw ValidationException::withMessages([
                'IP subnet not found.',
            ]);
        }

        if (!IpUtils::checkIp($params['ip_address'], $ipSubnet->getCidr())) {
            throw ValidationException::withMessages([
                "IP address {$params['ip_address']} is not within the selected subnet {$ipSubnet->getCidr()}.",
            ]);
        }

        /** @var ?IpAssigned */
        $alreadyAssigned = IpAssigned::query()
            ->where('ip_subnet_id', $params['ip_subnet_id'])
            ->where('ip_address', $params['ip_address'])
            ->first();

        if ($alreadyAssigned && $alreadyAssigned->user_id == $user->id) {
            throw ValidationException::withMessages([
                "IP address {$params['ip_address']} is already assigned to the user.",
            ]);
        }

        if (!$ipSubnet->is_shared && $alreadyAssigned) {
            throw ValidationException::withMessages([
                "IP address {$params['ip_address']} is not shared and it's already assigned.",
            ]);
        }

        $ip = IpAssigned::create([
            'user_id' => $user->id,
            'ip_subnet_id' => $params['ip_subnet_id'],
            'ip_address' => $params['ip_address'],
        ]);

        $system = new System();
        try {
            $cmd = $system->network()->generateIpAddressAddCommand($params['ip_address'], $ipSubnet->mask, $ipSubnet->family);
            $system->runProcessOnHost($cmd);
        } catch (\Exception $e) {
            Log::warning("Could not add IP address {$params['ip_address']} to network interface", [
                'exception_message' => $e->getMessage(),
            ]);
        }
        $reloaded = $system->rebuildDomains();

        return (new IpAssignedResource($ip))->additional(['reload_pending' => !$reloaded]);
    }

    #[OA\Post(
        path: '/ip/unassign',
        summary: 'Unassign an IP address from a project',
        security: [['bearerAuth' => []]],
        tags: ['IP Management'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['ip_subnet_id', 'ip_address'],
            properties: [
                new OA\Property(property: 'ip_subnet_id', type: 'integer', example: 1),
                new OA\Property(property: 'ip_address', type: 'string', example: '192.168.1.10'),
                new OA\Property(property: 'user_id', type: 'integer', nullable: true, description: 'Identify the project by id; one of user_id or username is required.'),
                new OA\Property(property: 'username', type: 'string', nullable: true, description: 'Identify the project by name; one of user_id or username is required.'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'IP unassigned', content: new OA\JsonContent(ref: '#/components/schemas/AssignedIp')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function unassignIpAddress(UnassignIpRequest $request): IpAssignedResource
    {
        /**
         * @var array{
         *   user_id?: int,
         *   username?: string,
         *   ip_subnet_id: int,
         *   ip_address: string
         * }
         */
        $params = $request->validated();
        if (empty($params['user_id']) && empty($params['username'])) {
            throw ValidationException::withMessages([
                'user_id or username is required.',
            ]);
        }

        $user = !empty($params['user_id'])
            ? User::find($params['user_id'])
            : User::findByUsername($params['username']);
        if (!$user) {
            throw ValidationException::withMessages([
                'User not found.',
            ]);
        }

        $ipSubnet = IpSubnet::find($params['ip_subnet_id']);
        if (!$ipSubnet) {
            throw ValidationException::withMessages([
                'IP subnet not found.',
            ]);
        }

        /** @var ?IpAssigned */
        $ip = IpAssigned::query()
            ->where('user_id', $user->id)
            ->where('ip_subnet_id', $params['ip_subnet_id'])
            ->where('ip_address', $params['ip_address'])
            ->first();
        if (!$ip) {
            throw ValidationException::withMessages([
                'IP address is not assigned to the user.',
            ]);
        }

        $ip->delete();

        $system = new System();
        $stillUsed = $ipSubnet->is_shared
            && IpAssigned::query()
            ->where('ip_subnet_id', $ipSubnet->id)
            ->where('ip_address', $ip->ip_address)
            ->exists();
        if (!$stillUsed) {
            try {
                $cmd = $system->network()->generateIpAddressDelCommand($ip->ip_address, $ipSubnet->mask, $ipSubnet->family);
                $system->runProcessOnHost($cmd);
            } catch (\Exception $e) {
                Log::warning("Could not delete IP address {$ip->ip_address} from network interface", [
                    'exception_message' => $e->getMessage(),
                ]);
            }
        }
        $reloaded = $system->rebuildDomains();

        return (new IpAssignedResource($ip))->additional(['reload_pending' => !$reloaded]);
    }
}
