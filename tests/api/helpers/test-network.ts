import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import dotenv from 'dotenv';

/**
 * Every IP address an API-suite file uses. A file writes no address literal
 * (core's TestNetworkLiteralsTest enforces it): scenario addresses are hosts of
 * the TEST_NET_* networks in tests/test-network.env, which an environment
 * variable of the same name overrides, and addresses fixed by a standard or by
 * the engine are the constants, as in Tests\Support\TestNetwork.
 *
 * A scenario address keeps its host number: `testNetwork.public(7)` is the 7th
 * address of the public test network.
 */
const CONFIG = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../test-network.env');

export type TestNet =
  | 'PUBLIC'
  | 'PUBLIC_2'
  | 'PUBLIC_3'
  | 'ROUTABLE'
  | 'PRIVATE'
  | 'PRIVATE_2'
  | 'CONTAINER'
  | 'CONTAINER_2';

let configured: Record<string, string> | undefined;

function configuredValue(variable: string): string | undefined {
  configured ??= dotenv.parse(readFileSync(CONFIG));
  return process.env[variable] ?? configured[variable];
}

function toNumber(address: string): number {
  const octets = address.split('.').map(Number);
  if (octets.length !== 4 || octets.some((o) => !Number.isInteger(o) || o < 0 || o > 255)) {
    throw new Error(`${address} is not an IPv4 address.`);
  }
  return ((octets[0] << 24) | (octets[1] << 16) | (octets[2] << 8) | octets[3]) >>> 0;
}

function toAddress(value: number): string {
  return [24, 16, 8, 0].map((shift) => (value >>> shift) & 255).join('.');
}

function network(name: TestNet): { base: string; bits: number } {
  const variable = `TEST_NET_${name}`;
  const value = configuredValue(variable);
  const match = value?.match(/^(\d{1,3}(?:\.\d{1,3}){3})\/(\d{1,2})$/);
  if (!match || Number(match[2]) > 30) {
    throw new Error(
      `${variable} must be an IPv4 network such as 203.0.113.0/24; define it in ${CONFIG}.`
    );
  }
  toNumber(match[1]);
  return { base: match[1], bits: Number(match[2]) };
}

function host(name: TestNet, n: number): string {
  const { base, bits } = network(name);
  if (!Number.isInteger(n) || n < 0 || n >= 2 ** (32 - bits)) {
    throw new Error(`Host ${n} is outside TEST_NET_${name} (${base}/${bits}).`);
  }
  return toAddress(toNumber(base) + n);
}

export const testNetwork = {
  ANY: '0.0.0.0',
  ANY_CIDR: '0.0.0.0/0',
  THIS_NETWORK: '0.0.0.1',
  LOOPBACK: '127.0.0.1',
  LOOPBACK_CIDR: '127.0.0.0/8',
  HOSTNAME_LOOPBACK: '127.0.1.1',
  LINK_LOCAL_CIDR: '169.254.0.0/16',
  METADATA: '169.254.169.254',
  PRIVATE_10_CIDR: '10.0.0.0/8',
  PRIVATE_172_CIDR: '172.16.0.0/12',
  PRIVATE_192_CIDR: '192.168.0.0/16',
  PRIVATE_10: '10.0.0.1',
  PRIVATE_172: '172.16.0.1',
  PRIVATE_192: '192.168.0.1',
  CGNAT_CIDR: '100.64.0.0/10',
  CGNAT: '100.64.0.1',
  DOCUMENTATION_1: '192.0.2.10',
  DOCUMENTATION_2: '198.51.100.10',
  DOCUMENTATION_3: '203.0.113.10',
  IETF_PROTOCOL: '192.0.0.8',
  BENCHMARK: '198.18.0.1',
  MULTICAST: '224.0.0.1',
  FUTURE_USE: '240.0.0.1',
  MASK_24: '255.255.255.0',
  DOCKER_DNS: '127.0.0.11',
  DOCKER_BRIDGE_GATEWAY: '172.17.0.1',
  DOCKER_BRIDGE_CIDR: '172.17.0.0/16',
  RESOLVED_STUB: '127.0.0.53',
  ENGINE_DNS_PROXY: '127.0.0.54',
  HEALTH_PROBE_CLIENT: '192.0.2.1',
  TENANT_NETWORK_CIDR: '10.200.0.0/16',

  /** An address on the public internet; the default network is documentation only (RFC 5737). */
  public: (n = 1): string => host('PUBLIC', n),
  public2: (n = 1): string => host('PUBLIC_2', n),
  public3: (n = 1): string => host('PUBLIC_3', n),
  /** A public address that also passes checks refusing documentation ranges. */
  routable: (n = 1): string => host('ROUTABLE', n),
  /** A private (RFC 1918) address, as a host's own LAN address. */
  private: (n = 1): string => host('PRIVATE', n),
  private2: (n = 1): string => host('PRIVATE_2', n),
  /** An address on a container network. */
  container: (n = 1): string => host('CONTAINER', n),
  container2: (n = 1): string => host('CONTAINER_2', n),

  /** A configured network in CIDR form. */
  net: (name: TestNet): string => {
    const { base, bits } = network(name);
    return `${base}/${bits}`;
  },
  /** The address `delta` places after `address`. */
  add: (address: string, delta: number): string => toAddress(toNumber(address) + delta),
  /** The network of `bits` that holds `address`, in CIDR form. */
  prefix: (address: string, bits: number): string => {
    const mask = bits === 0 ? 0 : (0xffffffff << (32 - bits)) >>> 0;
    return `${toAddress((toNumber(address) & mask) >>> 0)}/${bits}`;
  },
} as const;
