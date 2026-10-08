import * as tls from 'tls';
import { isIpLiteral } from '@/config/site-domain';
import { TEST_SSL_CERT_CN } from '@/test-data/static/test-ssl-cert';

export function getPeerCertificate(
  host: string,
  servername: string,
  port = 443
): Promise<tls.PeerCertificate> {
  return getTlsHandshake(host, servername, port).then(({ cert }) => cert);
}

export function getTlsHandshake(
  host: string,
  servername: string,
  port = 443
): Promise<{
  cert: tls.PeerCertificate;
  authorized: boolean;
  authorizationError: string | undefined;
}> {
  return new Promise((resolve, reject) => {
    let settled = false;
    const fail = (error: Error) => {
      if (settled) {
        return;
      }
      settled = true;
      reject(error);
      socket.destroy();
    };
    // Node rejects IP literals in `servername`; IP-SAN certs handshake without SNI.
    const connectOpts: tls.ConnectionOptions = {
      host,
      port,
      rejectUnauthorized: false,
      timeout: 15_000,
    };
    if (servername && !isIpLiteral(servername)) {
      connectOpts.servername = servername;
    }
    const socket = tls.connect(connectOpts, () => {
      if (settled) {
        return;
      }
      const cert = socket.getPeerCertificate();
      const authorized = socket.authorized;
      // Node can return a string error code despite the Error type in @types/node.
      const error: Error | string | undefined = socket.authorizationError;
      const authorizationError = error instanceof Error ? error.message : error;
      if (!cert?.raw?.length) {
        fail(new Error(`TLS peer presented no certificate for ${host}:${port}`));
        return;
      }
      settled = true;
      resolve({ cert, authorized, authorizationError });
      socket.destroy();
    });
    socket.on('error', fail);
    socket.once('close', () => {
      fail(new Error(`TLS connection closed before handshake completed for ${host}:${port}`));
    });
    socket.on('timeout', () => {
      fail(new Error(`TLS handshake timed out for ${host}:${port}`));
    });
  });
}

export function isLetsEncryptIssued(cert: tls.PeerCertificate): boolean {
  return cert.issuer?.O === "Let's Encrypt";
}

export function certificateText(cert: tls.PeerCertificate): string {
  const parts = [cert.subject?.CN, cert.subjectaltname, JSON.stringify(cert.subject)].filter(
    Boolean
  );
  return parts.join(' ');
}

/** True when the peer cert matches the fixed {@link TEST_SSL_CERT} installed by API tests. */
export function installedTestSslCertificatePresented(cert: tls.PeerCertificate): boolean {
  return certificateText(cert).toLowerCase().includes(TEST_SSL_CERT_CN);
}
