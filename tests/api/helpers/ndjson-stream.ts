import http2 from 'node:http2';
import https from 'node:https';
import { URL } from 'node:url';

export type StreamProtocol = 'http/1.1' | 'h2';

export interface NdjsonRead {
  /** 0 when no answer arrived before the timeout. */
  status: number;
  contentType: string;
  frames: Record<string, unknown>[];
  /** True when `until` matched and the stream was closed from this side. */
  matched: boolean;
}

/** Reads NDJSON frames until `until` matches, the stream ends or `timeoutMs` passes. */
export function readNdjson(
  url: string,
  token: string,
  until: (frame: Record<string, unknown>) => boolean,
  timeoutMs = 60_000,
  protocol: StreamProtocol = 'h2'
): Promise<NdjsonRead> {
  return new Promise((resolve, reject) => {
    const read: NdjsonRead = { status: 0, contentType: '', frames: [], matched: false };
    let settled = false;
    let close: () => void = () => undefined;
    const finish = (matched: boolean): void => {
      if (settled) {
        return;
      }
      settled = true;
      clearTimeout(timer);
      close();
      resolve({ ...read, matched });
    };
    const fail = (error: Error): void => {
      if (!settled) {
        settled = true;
        clearTimeout(timer);
        close();
        reject(error);
      }
    };
    const timer = setTimeout(() => finish(false), timeoutMs);
    let buffered = '';
    const onData = (chunk: string): void => {
      buffered += chunk;
      let newline = buffered.indexOf('\n');
      while (newline >= 0 && !settled) {
        const line = buffered.slice(0, newline).trim();
        buffered = buffered.slice(newline + 1);
        newline = buffered.indexOf('\n');
        if (line === '') {
          continue;
        }
        const frame = parseFrame(line);
        read.frames.push(frame);
        if (until(frame)) {
          finish(true);
        }
      }
    };
    const target = new URL(url);
    const headers = { Authorization: `Bearer ${token}`, Accept: 'application/x-ndjson' };

    if (protocol === 'h2') {
      const session = http2.connect(target.origin, { rejectUnauthorized: false });
      session.on('error', fail);
      const stream = session.request({
        ':path': `${target.pathname}${target.search}`,
        authorization: headers.Authorization,
        accept: headers.Accept,
      });
      close = () => {
        stream.close();
        session.close();
      };
      stream.setEncoding('utf8');
      stream.on('response', (answer) => {
        read.status = Number(answer[':status'] ?? 0);
        read.contentType = String(answer['content-type'] ?? '');
      });
      stream.on('data', onData);
      stream.on('end', () => finish(false));
      stream.on('error', fail);
    } else {
      const request = https.get(target, { rejectUnauthorized: false, headers }, (response) => {
        read.status = response.statusCode ?? 0;
        read.contentType = String(response.headers['content-type'] ?? '');
        response.setEncoding('utf8');
        response.on('data', onData);
        response.on('end', () => finish(false));
        response.on('error', fail);
      });
      request.on('error', fail);
      close = () => request.destroy();
    }
  });
}

function parseFrame(line: string): Record<string, unknown> {
  try {
    const parsed: unknown = JSON.parse(line);
    return typeof parsed === 'object' && parsed !== null
      ? (parsed as Record<string, unknown>)
      : { value: parsed };
  } catch {
    return { unparsed: line };
  }
}
