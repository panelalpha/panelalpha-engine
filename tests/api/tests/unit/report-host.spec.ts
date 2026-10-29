import { expect, test } from '@/fixtures/test-options';
import { testNetwork } from '@/helpers/test-network';
import {
  formatHttpHost,
  guessReportHost,
  hostnameFromUrl,
  isUnusableReportHost,
  reportListenUrl,
  rewriteLocalReportUrls,
} from '@/scripts/lib/report-host';

test.describe('report host guessing', () => {
  test('skips loopback and bind-all names', () => {
    expect(isUnusableReportHost('localhost')).toBe(true);
    expect(isUnusableReportHost(testNetwork.LOOPBACK)).toBe(true);
    expect(isUnusableReportHost(testNetwork.HOSTNAME_LOOPBACK)).toBe(true);
    expect(isUnusableReportHost(testNetwork.ANY)).toBe(true);
    expect(isUnusableReportHost('::1')).toBe(true);
    expect(isUnusableReportHost('panel-damian.mg-test2.com')).toBe(false);
    expect(isUnusableReportHost(testNetwork.routable(125))).toBe(false);
  });

  test('REPORT_HOST wins over a loopback API_BASE_URL', () => {
    expect(
      guessReportHost([
        'panel-damian.mg-test2.com',
        hostnameFromUrl(`https://${testNetwork.LOOPBACK}:2011/api/`),
        testNetwork.LOOPBACK,
      ])
    ).toBe('panel-damian.mg-test2.com');
  });

  test('falls through a loopback API host to hostname -f', () => {
    expect(
      guessReportHost([
        undefined,
        hostnameFromUrl('https://localhost:2011/api/'),
        'panel-damian.mg-test2.com',
        testNetwork.DOCKER_BRIDGE_GATEWAY,
      ])
    ).toBe('panel-damian.mg-test2.com');
  });

  test('reads the hostname out of API_BASE_URL', () => {
    expect(hostnameFromUrl('https://panel-damian.mg-test2.com:2011/api/')).toBe(
      'panel-damian.mg-test2.com'
    );
  });
});

test.describe('Playwright serving-line rewrite', () => {
  test('turns localhost into the VPS host, including ANSI colour', () => {
    const line =
      '\u001b[36m  Serving HTML report at http://localhost:9323. Press Ctrl+C to quit.\u001b[39m';
    expect(rewriteLocalReportUrls(line, 'panel-damian.mg-test2.com')).toBe(
      '\u001b[36m  Serving HTML report at http://panel-damian.mg-test2.com:9323. Press Ctrl+C to quit.\u001b[39m'
    );
  });

  test(`rewrites ${testNetwork.ANY} and ${testNetwork.LOOPBACK} the same way`, () => {
    expect(rewriteLocalReportUrls(`http://${testNetwork.ANY}:9323`, testNetwork.public(10))).toBe(
      `http://${testNetwork.public(10)}:9323`
    );
    expect(
      rewriteLocalReportUrls(`http://${testNetwork.LOOPBACK}:9323`, testNetwork.public(10))
    ).toBe(`http://${testNetwork.public(10)}:9323`);
  });

  test('brackets an IPv6 public host', () => {
    expect(formatHttpHost('2001:db8::1')).toBe('[2001:db8::1]');
    expect(reportListenUrl('2001:db8::1', '9323')).toBe('http://[2001:db8::1]:9323');
  });
});
