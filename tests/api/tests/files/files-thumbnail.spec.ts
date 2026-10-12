import { expect, test } from '@/fixtures/test-options';
import { CleanupSteps } from '@/helpers/cleanup-step';
import { expectStatus } from '@/helpers/expect-status';
import { getDomainBasePath, removeFileIfPresent } from '@/helpers/file-path-helpers';
import { uniqueId } from '@/helpers/random';

/** A 40x30 png; the grid resizes it, so a small source keeps the fixture readable. */
const PHOTO_PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAACgAAAAeAQMAAABkE86eAAAAIGNIUk0AAHomAACAhAAA+gAAAIDoAAB1MAAA6mAAADqYAAAXcJy6UTwAAAAGUExURf8AAP///0EdNBEAAAABYktHRAH/Ai3eAAAAB3RJTUUH6goLEgcSReCddQAAAAxJREFUCNdjYBh6AAAAtAABXnGQvwAAACV0RVh0ZGF0ZTpjcmVhdGUAMjAyNi0xMC0xMVQxODowNzoxOCswMDowMOhROn0AAAAldEVYdGRhdGU6bW9kaWZ5ADIwMjYtMTAtMTFUMTg6MDc6MTgrMDA6MDCZDILBAAAAKHRFWHRkYXRlOnRpbWVzdGFtcAAyMDI2LTEwLTExVDE4OjA3OjE4KzAwOjAwzhmjHgAAAABJRU5ErkJggg==',
  'base64'
);

/** Two frames: a red 100x80 first, a blue 200x40 second. */
const ANIMATED_GIF = Buffer.from(
  'R0lGODlhZABQAPAAAP8AAAAAACH/C05FVFNDQVBFMi4wAwEAAAAh+QQACgAAACwAAAAAZABQAAACZISPqcvtD6OctNqLs968+w+G4kiW5omm6sq27gvH8kzX9o3n+s73/g8MCofEovGITCqXzKbzCY1Kp9Sq9YrNarfcrvcLDovH5LL5jE6r1+y2+w2Py+f0uv2Oz+v3/L7/DxgYWAAAIfkEAAoAAAAsAAAAAMgAKACAAAD/AAAAAmSEj6nL7Q+jnLTai7PevPsPhuJIluaJpurKtu4Lx/JM1/aN5/rO9/4PDAqHxKLxiEwql8ym8wmNSqfUqvWKzWq33K73Cw6Lx+Sy+YxOq9fstvsNj8vn9Lr9js/r9/y+/w8YGFgAADs=',
  'base64'
);

/** A 69-byte png whose IHDR claims 20000x20000, with no pixels behind it. */
const HUGE_PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAATiAAAE4gCAIAAABsEtFuAAAADElEQVR4nGNgoD0AAABkAAGGZDw1AAAAAElFTkSuQmCC',
  'base64'
);

/** An SVG that ImageMagick's own detection would happily read if it were allowed to choose. */
const SVG = Buffer.from(
  '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10" fill="red"/></svg>',
  'utf-8'
);

/**
 * The file-manager grid asks for one thumbnail per image cell. The source is a
 * user file, so it is resized by the account-confined reader, and the response
 * carries an ETag the panel and the browser cache on.
 */
test.describe('file thumbnails', () => {
  test('a png becomes a cached 176x176 cover webp', async ({ api, authedRequest, setupUser }) => {
    const path = `${getDomainBasePath(setupUser.domain)}/${uniqueId('thumb-')}.png`;

    const cleanup = new CleanupSteps();
    try {
      const upload = await api.uploadFileFromBuffer(
        setupUser.username,
        getDomainBasePath(setupUser.domain),
        path.split('/').pop()!,
        PHOTO_PNG,
        'image/png'
      );
      await expectStatus(upload, 200, 'the fixture png uploads');

      const response = await authedRequest.get(
        thumbnailUrl(setupUser.username, { path, width: 176, height: 176, fit: 'cover' })
      );

      await expectStatus(response, 200);
      expect(response.headers()['content-type']).toBe('image/webp');
      expect(response.headers()['cache-control']).toContain('max-age=86400');
      expect(response.headers().etag).toBeTruthy();

      const body = await response.body();
      expect(body.subarray(0, 4).toString('ascii')).toBe('RIFF');
      expect(body.subarray(8, 12).toString('ascii')).toBe('WEBP');
      expect(body.length, 'a grid thumbnail stays small').toBeLessThan(30 * 1024);
      expect(webpSize(body)).toEqual({ width: 176, height: 176 });
    } catch (error) {
      cleanup.bodyFailed(error);
      throw error;
    } finally {
      await cleanup.run(`remove ${path}`, () => removeFileIfPresent(api, setupUser.username, path));
      await cleanup.finish();
    }
  });

  test('cover crops to the box and contain fits inside it', async ({
    api,
    authedRequest,
    setupUser,
  }) => {
    const path = `${getDomainBasePath(setupUser.domain)}/${uniqueId('thumb-')}.png`;

    const cleanup = new CleanupSteps();
    try {
      const name = path.split('/').pop()!;
      const upload = await api.uploadFileFromBuffer(
        setupUser.username,
        getDomainBasePath(setupUser.domain),
        name,
        PHOTO_PNG,
        'image/png'
      );
      await expectStatus(upload, 200, 'the fixture png uploads');

      const cover = await authedRequest.get(
        thumbnailUrl(setupUser.username, { path, width: 176, height: 176, fit: 'cover' })
      );
      const contain = await authedRequest.get(
        thumbnailUrl(setupUser.username, { path, width: 176, height: 176, fit: 'contain' })
      );
      await expectStatus(cover, 200);
      await expectStatus(contain, 200);

      // A 40x30 source is 4:3: cover fills the square, contain keeps the ratio.
      expect(webpSize(await cover.body())).toEqual({ width: 176, height: 176 });
      expect(webpSize(await contain.body())).toEqual({ width: 176, height: 132 });
      expect(cover.headers().etag).not.toBe(contain.headers().etag);
    } catch (error) {
      cleanup.bodyFailed(error);
      throw error;
    } finally {
      await cleanup.run(`remove ${path}`, () => removeFileIfPresent(api, setupUser.username, path));
      await cleanup.finish();
    }
  });

  test('a gif thumbnails its first frame', async ({ api, authedRequest, setupUser }) => {
    const path = `${getDomainBasePath(setupUser.domain)}/${uniqueId('thumb-')}.gif`;

    const cleanup = new CleanupSteps();
    try {
      const upload = await api.uploadFileFromBuffer(
        setupUser.username,
        getDomainBasePath(setupUser.domain),
        path.split('/').pop()!,
        ANIMATED_GIF,
        'image/gif'
      );
      await expectStatus(upload, 200, 'the fixture gif uploads');

      const response = await authedRequest.get(
        thumbnailUrl(setupUser.username, { path, width: 176, height: 176, fit: 'contain' })
      );
      await expectStatus(response, 200);

      // 176x141 is the 100x80 first frame; the 200x40 second would be 176x35.
      expect(webpSize(await response.body())).toEqual({ width: 176, height: 141 });
    } catch (error) {
      cleanup.bodyFailed(error);
      throw error;
    } finally {
      await cleanup.run(`remove ${path}`, () => removeFileIfPresent(api, setupUser.username, path));
      await cleanup.finish();
    }
  });

  test('a matching etag is answered 304 without a body', async ({
    api,
    authedRequest,
    setupUser,
  }) => {
    const path = `${getDomainBasePath(setupUser.domain)}/${uniqueId('thumb-')}.png`;

    const cleanup = new CleanupSteps();
    try {
      const upload = await api.uploadFileFromBuffer(
        setupUser.username,
        getDomainBasePath(setupUser.domain),
        path.split('/').pop()!,
        PHOTO_PNG,
        'image/png'
      );
      await expectStatus(upload, 200, 'the fixture png uploads');

      const url = thumbnailUrl(setupUser.username, { path });
      const first = await authedRequest.get(url);
      await expectStatus(first, 200);
      const etag = first.headers().etag;
      expect(etag).toBeTruthy();

      const second = await authedRequest.get(url, { headers: { 'If-None-Match': etag } });

      await expectStatus(second, 304);
      expect((await second.body()).length).toBe(0);
    } catch (error) {
      cleanup.bodyFailed(error);
      throw error;
    } finally {
      await cleanup.run(`remove ${path}`, () => removeFileIfPresent(api, setupUser.username, path));
      await cleanup.finish();
    }
  });

  test('a text file behind a .jpg name is refused as unsupported', async ({
    api,
    authedRequest,
    setupUser,
  }) => {
    const path = `${getDomainBasePath(setupUser.domain)}/${uniqueId('thumb-')}.jpg`;

    const cleanup = new CleanupSteps();
    try {
      const upload = await api.uploadFileFromBuffer(
        setupUser.username,
        getDomainBasePath(setupUser.domain),
        path.split('/').pop()!,
        Buffer.from('this is definitely not an image\n', 'utf-8'),
        'image/jpeg'
      );
      await expectStatus(upload, 200, 'the text fixture uploads');

      const response = await authedRequest.get(thumbnailUrl(setupUser.username, { path }));

      await expectStatus(response, 422);
      expect(await problemCodes(response)).toContain('unsupported_image');
    } catch (error) {
      cleanup.bodyFailed(error);
      throw error;
    } finally {
      await cleanup.run(`remove ${path}`, () => removeFileIfPresent(api, setupUser.username, path));
      await cleanup.finish();
    }
  });

  test('an svg behind a .png name is refused as unsupported', async ({
    api,
    authedRequest,
    setupUser,
  }) => {
    const path = `${getDomainBasePath(setupUser.domain)}/${uniqueId('thumb-')}.png`;

    const cleanup = new CleanupSteps();
    try {
      const upload = await api.uploadFileFromBuffer(
        setupUser.username,
        getDomainBasePath(setupUser.domain),
        path.split('/').pop()!,
        SVG,
        'image/png'
      );
      await expectStatus(upload, 200, 'the svg fixture uploads');

      const response = await authedRequest.get(thumbnailUrl(setupUser.username, { path }));

      await expectStatus(response, 422);
      expect(await problemCodes(response)).toContain('unsupported_image');
    } catch (error) {
      cleanup.bodyFailed(error);
      throw error;
    } finally {
      await cleanup.run(`remove ${path}`, () => removeFileIfPresent(api, setupUser.username, path));
      await cleanup.finish();
    }
  });

  test('a 20000x20000 png is refused as too large', async ({ api, authedRequest, setupUser }) => {
    const path = `${getDomainBasePath(setupUser.domain)}/${uniqueId('thumb-')}.png`;

    const cleanup = new CleanupSteps();
    try {
      const upload = await api.uploadFileFromBuffer(
        setupUser.username,
        getDomainBasePath(setupUser.domain),
        path.split('/').pop()!,
        HUGE_PNG,
        'image/png'
      );
      await expectStatus(upload, 200, 'the fixture png uploads');

      const response = await authedRequest.get(thumbnailUrl(setupUser.username, { path }));

      await expectStatus(response, 422);
      expect(await problemCodes(response)).toContain('image_too_large');
    } catch (error) {
      cleanup.bodyFailed(error);
      throw error;
    } finally {
      await cleanup.run(`remove ${path}`, () => removeFileIfPresent(api, setupUser.username, path));
      await cleanup.finish();
    }
  });

  test('a missing file is 404 and an out-of-range width is 422', async ({
    authedRequest,
    setupUser,
  }) => {
    const missing = `${getDomainBasePath(setupUser.domain)}/${uniqueId('thumb-')}.png`;

    const notFound = await authedRequest.get(thumbnailUrl(setupUser.username, { path: missing }));
    await expectStatus(notFound, 404);
    expect((await notFound.json()) as { message?: string }).toMatchObject({
      message: 'Invalid path',
    });

    const tooSmall = await authedRequest.get(
      thumbnailUrl(setupUser.username, { path: missing, width: 8 })
    );
    await expectStatus(tooSmall, 422);

    const tooLarge = await authedRequest.get(
      thumbnailUrl(setupUser.username, { path: missing, width: 2048 })
    );
    await expectStatus(tooLarge, 422);

    const badFit = await authedRequest.get(
      thumbnailUrl(setupUser.username, { path: missing, fit: 'stretch' })
    );
    await expectStatus(badFit, 422);
  });
});

function thumbnailUrl(username: string, query: Record<string, string | number>): string {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(query)) {
    params.set(key, String(value));
  }

  return `projects/${username}/files/thumbnail?${params.toString()}`;
}

/** Canvas size out of the lossy VP8 frame header the encoder writes. */
function webpSize(body: Buffer): { width: number; height: number } {
  const chunk = body.subarray(12, 16).toString('ascii');
  if (chunk !== 'VP8 ') {
    throw new Error(`expected a VP8 webp frame, got chunk "${chunk}"`);
  }

  return {
    width: body.readUInt16LE(26) & 0x3fff,
    height: body.readUInt16LE(28) & 0x3fff,
  };
}

async function problemCodes(response: { json: () => Promise<unknown> }): Promise<string[]> {
  const body = (await response.json()) as { problems?: { code?: string }[] };

  return (body.problems ?? []).map((problem) => problem.code ?? '');
}
