import { BlockList, connect, isIPv6 } from 'node:net';
import type { HostExec } from './host-exec';

/** How the engine's host answered one TCP attempt. */
export type KnockOutcome = 'connected' | 'refused' | 'timeout' | 'error';

/** One TCP attempt at the engine's host on a port nothing allows. */
export function knock(host: string, port: number): Promise<KnockOutcome> {
  return new Promise((resolve) => {
    const socket = connect({ host, port, timeout: 2_000 });
    const done = (outcome: KnockOutcome): void => {
      socket.destroy();
      resolve(outcome);
    };
    socket
      .once('connect', () => done('connected'))
      .once('timeout', () => done('timeout'))
      .once('error', (error: NodeJS.ErrnoException) =>
        done(error.code === 'ECONNREFUSED' ? 'refused' : 'error')
      );
  });
}

/** The address this runner reaches the host from, as the host sees it on SSH. */
export async function runnerAddressSeenByHost(hostExec: HostExec): Promise<string | null> {
  const result = await hostExec.run('sh', ['-c', 'echo "${SSH_CLIENT%% *}"']);
  const address = result.stdout.trim();
  return result.exitCode === 0 && address !== '' ? address : null;
}

/**
 * The host's iptables rule that accepts every port from `address`, such as an
 * admin-access rule or a ufw allow for that source, or null when none does.
 */
export async function ruleAcceptingEveryPortFrom(
  hostExec: HostExec,
  address: string
): Promise<string | null> {
  const family = isIPv6(address) ? 'ipv6' : 'ipv4';
  const listing = await hostExec.run(family === 'ipv6' ? 'ip6tables' : 'iptables', ['-S']);
  if (listing.exitCode !== 0) {
    return null;
  }
  return (
    listing.stdout
      .split('\n')
      .find((rule) => acceptsEveryPortFrom(rule, address, family))
      ?.trim() ?? null
  );
}

function acceptsEveryPortFrom(rule: string, address: string, family: 'ipv4' | 'ipv6'): boolean {
  const source = /\s-s\s(\S+)/.exec(rule)?.[1];
  if (!source || !/\s-j ACCEPT\b/.test(rule) || /--dports?\s|\s-i\s/.test(rule)) {
    return false;
  }
  const [network, prefix] = source.split('/');
  const range = new BlockList();
  try {
    range.addSubnet(network, Number(prefix ?? (family === 'ipv6' ? 128 : 32)), family);
  } catch {
    return false;
  }
  return range.check(address, family);
}
