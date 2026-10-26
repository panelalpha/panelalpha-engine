<?php

namespace App\Http\Controllers;

use App\Http\Requests\Ip\AddSubnetRequest;
use App\Http\Requests\Ip\AssignIpRequest;
use App\Http\Requests\Ip\UnassignIpRequest;
use App\Http\Resources\IpAssignedCollection;
use App\Http\Resources\IpAssignedResource;
use App\Http\Resources\IpSubnetCollection;
use App\Http\Resources\IpSubnetResource;
use App\Models\IpAssigned;
use App\Models\IpSubnet;
use App\Models\Setting;
use App\Models\User;
use App\System;
use App\System\Network;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\Process\Process;

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

        return (new IpSubnetCollection($subnets))->additional([
            'meta' => [
                'default_ipv4' => $this->defaultAddress('default_ipv4', FILTER_FLAG_IPV4, 4),
                'default_ipv6' => $this->defaultAddress('default_ipv6', FILTER_FLAG_IPV6, 6),
            ],
        ]);
    }

    /**
     * The default address setting and the projects that use it, having none of their own in that family.
     *
     * @return array{address: mixed, assignments: list<array{user_id: int, username: string, domain_names: mixed}>}
     */
    private function defaultAddress(string $setting, int $familyFlag, int $family): array
    {
        $address = Setting::get($setting);

        return [
            'address' => $address,
            'assignments' => filter_var($address, FILTER_VALIDATE_IP, $familyFlag) ? $this->projectsWithoutAddress($family) : [],
        ];
    }

    /** @return list<array{user_id: int, username: string, domain_names: mixed}> */
    private function projectsWithoutAddress(int $family): array
    {
        /** @var Collection */
        $result = User::with('domains')
            ->whereDoesntHave('assignedIpAddresses', function (Builder $q) use ($family) {
                $q->whereHas('ipSubnet', function (Builder $qq) use ($family) {
                    $qq->where('family', $family);
                });
            })->get();
        /** @var array<User> */
        $users = $result->all();
        $assignments = [];
        foreach ($users as $user) {
            $assignments[] = [
                'user_id' => $user->id,
                'username' => $user->username,
                'domain_names' => $user->listAllDomainNames(),
            ];
        }

        return $assignments;
    }

    private function getNetwork4Ip(string $ip, int $mask): ?string
    {
        $packed = inet_pton($ip);
        $unpacked = unpack('N', $packed);
        if ($unpacked === false || ! array_key_exists(1, $unpacked) || ! is_int($unpacked[1])) {
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
        $bytes = unpack('C*', $packed);
        if ($bytes === false) {
            return null;
        }
        $bits = '';
        foreach ($bytes as $byte) {
            if (! is_int($byte)) {
                return null;
            }
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }
        $networkBits = substr($bits, 0, $mask).str_repeat('0', 128 - $mask);
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
            new OA\Response(response: 201, description: 'Subnet created', content: new OA\JsonContent(ref: '#/components/schemas/IpSubnet')),
            new OA\Response(response: 422, description: 'Validation error, or the host could not route the subnet (no default route of its family, or `ip route add` did not take); nothing is stored', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
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
        $family = $this->subnetFamily($params['ip'], $params['mask']);
        $this->assertSubnetIsNew($params['ip'], $params['mask']);

        $subnet = IpSubnet::create([
            'ip' => $params['ip'],
            'mask' => $params['mask'],
            'family' => $family,
            'is_shared' => $family === 4 && ! empty($params['is_shared']),
        ]);

        $this->addRouteToHostOrRollBack(app(System::class), $subnet);

        return new IpSubnetResource($subnet);
    }

    /**
     * A subnet the host does not route is unusable, so the route is read back and the subnet
     * removed again when it is not there.
     *
     * @throws ValidationException
     */
    private function addRouteToHostOrRollBack(System $system, IpSubnet $subnet): void
    {
        $cidr = $subnet->getCidr();
        try {
            $network = $system->network();
            $added = $system->runProcessOnHost($network->generateIpRouteAddCommand($subnet->ip, $subnet->mask, $subnet->family));
            $routed = $network->hasRoute($subnet->ip, $subnet->mask, $subnet->family);
        } catch (\Exception $e) {
            $this->rollBack($subnet, "IP subnet {$cidr}");

            throw ValidationException::withMessages([
                'ip' => "Could not add the host route for subnet {$cidr}: {$e->getMessage()}",
            ]);
        }

        if (! $routed) {
            $this->rollBack($subnet, "IP subnet {$cidr}");

            throw ValidationException::withMessages(['ip' => self::routeNotTaken($cidr, $added)]);
        }
    }

    private static function routeNotTaken(string $cidr, Process $added): string
    {
        $reason = trim($added->getErrorOutput() ?: $added->getOutput());

        return "The host does not route {$cidr} after adding it, so the subnet was not added"
            .($reason !== '' ? ": {$reason}" : '.');
    }

    /**
     * The address family of a subnet whose address is its own network address; IPv6 subnets are never shared.
     *
     * @throws ValidationException
     */
    private function subnetFamily(string $ip, int|string $mask): int
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $this->assertNetworkAddress($ip, $mask, 'IPv4', 32, '192.168.0.0/24', $this->getNetwork4Ip(...));

            return 4;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $this->assertNetworkAddress($ip, $mask, 'IPv6', 128, '2001:0db8:abcd:0012::/64', $this->getNetwork6Ip(...));

            return 6;
        }

        return 4;
    }

    /** @param callable(string, int|string): ?string $networkOf */
    private function assertNetworkAddress(string $ip, int|string $mask, string $label, int $maxMask, string $example, callable $networkOf): void
    {
        if ($mask < 0 || $mask > $maxMask) {
            throw ValidationException::withMessages([
                'mask' => "{$label} mask must be between 0 and {$maxMask}.",
            ]);
        }
        $networkIp = $networkOf($ip, $mask);
        if ($ip !== $networkIp) {
            throw ValidationException::withMessages([
                'ip' => "{$label} must be the network address (e.g. ".($networkIp === null ? $example : ($networkIp.'/'.$mask)).').',
            ]);
        }
    }

    private function assertSubnetIsNew(string $ip, int|string $mask): void
    {
        if (IpSubnet::query()->where([
            'ip' => $ip,
            'mask' => $mask,
        ])->exists()) {
            throw ValidationException::withMessages([
                'ip' => "Subnet {$ip}/{$mask} already exist.",
            ]);
        }
    }

    /**
     * Runs a host network command best-effort: a failure is logged and the API change stands.
     *
     * @param  callable(Network): string  $command
     */
    private function runOnHostOrWarn(System $system, callable $command, string $warning): void
    {
        try {
            $system->runProcessOnHost($command($system->network()));
        } catch (\Exception $e) {
            Log::warning($warning, [
                'exception_message' => $e->getMessage(),
            ]);
        }
    }

    #[OA\Delete(
        path: '/ip/subnets/{id}',
        summary: 'Delete an IP subnet',
        security: [['bearerAuth' => []]],
        tags: ['IP Management'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Subnet deleted', content: new OA\JsonContent(ref: '#/components/schemas/IpSubnet')),
            new OA\Response(response: 409, description: 'The subnet still has assigned IP addresses', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function deleteSubnet(int $id): IpSubnetResource
    {
        $subnet = IpSubnet::findOrFail($id);

        if ($subnet->ipAssigned()->count()) {
            abort(new JsonResponse(['message' => 'Cannot delete IP subnet with assigned IP addresses'], 409));
        }

        $subnet->delete();

        $this->runOnHostOrWarn(
            new System,
            fn (Network $network) => $network->generateIpRouteDelCommand($subnet->ip, $subnet->mask, $subnet->family),
            "Could not delete IP route for subnet {$subnet->ip}/{$subnet->mask}",
        );

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
            new OA\Response(response: 201, description: 'IP assigned', content: new OA\JsonContent(ref: '#/components/schemas/AssignedIp')),
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
        $user = $this->projectFromParams($params);
        $ipSubnet = $this->subnetFromParams($params);
        $address = self::canonicalAddress($params['ip_address']);
        $this->assertAssignable($address, $ipSubnet, $user);

        $ip = IpAssigned::create([
            'user_id' => $user->id,
            'ip_subnet_id' => $params['ip_subnet_id'],
            'ip_address' => $address,
        ]);

        $system = app(System::class);
        $this->addAddressToHostOrRollBack($system, $ip, $ipSubnet);
        $reloaded = $system->rebuildDomains();

        return (new IpAssignedResource($ip))->additional(['reload_pending' => ! $reloaded]);
    }

    /**
     * @param  array{user_id?: int, username?: string}  $params
     *
     * @throws ValidationException
     */
    private function projectFromParams(array $params): User
    {
        if (empty($params['user_id']) && empty($params['username'])) {
            throw ValidationException::withMessages([
                'user_id' => 'user_id or username is required.',
            ]);
        }

        $user = ! empty($params['user_id'])
            ? User::find($params['user_id'])
            : User::findByUsername($params['username']);
        if (! $user) {
            throw ValidationException::withMessages([
                (! empty($params['user_id']) ? 'user_id' : 'username') => 'Project not found.',
            ]);
        }

        return $user;
    }

    /**
     * @param  array{ip_subnet_id: int}  $params
     *
     * @throws ValidationException
     */
    private function subnetFromParams(array $params): IpSubnet
    {
        $ipSubnet = IpSubnet::find($params['ip_subnet_id']);
        if (! $ipSubnet) {
            throw ValidationException::withMessages([
                'ip_subnet_id' => 'IP subnet not found.',
            ]);
        }

        return $ipSubnet;
    }

    /** @throws ValidationException */
    private function assertAssignable(string $address, IpSubnet $ipSubnet, User $user): void
    {
        if (! IpUtils::checkIp($address, $ipSubnet->getCidr())) {
            throw ValidationException::withMessages([
                'ip_address' => "IP address {$address} is not within the selected subnet {$ipSubnet->getCidr()}.",
            ]);
        }

        /** @var ?IpAssigned */
        $alreadyAssigned = IpAssigned::query()
            ->where('ip_subnet_id', $ipSubnet->id)
            ->where('ip_address', $address)
            ->first();

        if ($alreadyAssigned && $alreadyAssigned->user_id == $user->id) {
            throw ValidationException::withMessages([
                'ip_address' => "IP address {$address} is already assigned to the user.",
            ]);
        }

        if (! $ipSubnet->is_shared && $alreadyAssigned) {
            throw ValidationException::withMessages([
                'ip_address' => "IP address {$address} is not shared and it's already assigned.",
            ]);
        }
    }

    /**
     * Sites rebuilt onto an address the host lacks would be dead, so the interface is
     * read back and the assignment rolled back when the address is not there.
     *
     * @throws ValidationException
     */
    private function addAddressToHostOrRollBack(System $system, IpAssigned $ip, IpSubnet $ipSubnet): void
    {
        $address = $ip->ip_address;
        try {
            $network = $system->network();
            $cmd = $network->generateIpAddressAddCommand($address, $ipSubnet->mask, $ipSubnet->family);
            $system->runProcessOnHost($cmd);
            $applied = $network->interfaceHasAddress($address, $ipSubnet->mask, $ipSubnet->family);
        } catch (\Exception $e) {
            $this->rollBack($ip, "IP assignment {$address}");

            Log::warning("Could not add IP address {$address} to network interface", [
                'exception_message' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'ip_address' => "Could not add IP address {$address} to the host network interface: {$e->getMessage()}",
            ]);
        }

        if (! $applied) {
            $this->rollBack($ip, "IP assignment {$address}");
            $this->removeUnusedAddress($system, $ip, $ipSubnet);

            throw ValidationException::withMessages([
                'ip_address' => "The host network interface does not carry {$address}/{$ipSubnet->mask} after adding it, "
                    .'so the assignment was rolled back.',
            ]);
        }
    }

    /** The spelling the kernel prints, so stored, compared and looked-up addresses agree. */
    private static function canonicalAddress(string $address): string
    {
        $packed = inet_pton($address);

        return $packed === false ? $address : (string) inet_ntop($packed);
    }

    /** Take back an address the failed assignment added, unless another project on a shared subnet holds it. */
    private function removeUnusedAddress(System $system, IpAssigned $ip, IpSubnet $ipSubnet): void
    {
        if ($this->addressStillUsed($ip, $ipSubnet)) {
            return;
        }
        $this->runOnHostOrWarn(
            $system,
            fn (Network $network) => $network->generateIpAddressDelCommand($ip->ip_address, $ipSubnet->mask, $ipSubnet->family),
            "Could not delete IP address {$ip->ip_address} from network interface",
        );
    }

    /** Undo one record whose host step did not take, best-effort. */
    private function rollBack(Model $record, string $what): void
    {
        try {
            $record->delete();
        } catch (\Throwable $e) {
            Log::warning("Could not roll back {$what}", [
                'exception_message' => $e->getMessage(),
            ]);
        }
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
        $user = $this->projectFromParams($params);
        $ipSubnet = $this->subnetFromParams($params);

        /** @var ?IpAssigned */
        $ip = IpAssigned::query()
            ->where('user_id', $user->id)
            ->where('ip_subnet_id', $params['ip_subnet_id'])
            ->where('ip_address', self::canonicalAddress($params['ip_address']))
            ->first();
        if (! $ip) {
            throw ValidationException::withMessages([
                'ip_address' => 'IP address is not assigned to the user.',
            ]);
        }

        $ip->delete();

        $system = new System;
        if (! $this->addressStillUsed($ip, $ipSubnet)) {
            $this->runOnHostOrWarn(
                $system,
                fn (Network $network) => $network->generateIpAddressDelCommand($ip->ip_address, $ipSubnet->mask, $ipSubnet->family),
                "Could not delete IP address {$ip->ip_address} from network interface",
            );
        }
        $reloaded = $system->rebuildDomains();

        return (new IpAssignedResource($ip))->additional(['reload_pending' => ! $reloaded]);
    }

    /** A shared address stays on the interface while another project still holds it. */
    private function addressStillUsed(IpAssigned $ip, IpSubnet $ipSubnet): bool
    {
        return $ipSubnet->is_shared
            && IpAssigned::query()
                ->where('ip_subnet_id', $ipSubnet->id)
                ->where('ip_address', $ip->ip_address)
                ->exists();
    }
}
