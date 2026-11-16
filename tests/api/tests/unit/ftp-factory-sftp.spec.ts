import { expect, test } from '@/fixtures/test-options';
import type { EngineApi } from '@/clients/engine-api';
import { FtpFactory } from '@/test-data/factories/ftp.factory';
import type { CreateSftpAccountRequest, SftpAccount } from '@/types';

/** What FtpFactory hands a test after an SFTP create, against a fake Engine. */

const CREATED: SftpAccount = {
  id: 7,
  user_id: 3,
  username: 'pwowner0001_sftpabc123',
  auth_method: 'password',
  created_at: '2026-10-16T12:00:00.000000Z',
  updated_at: '2026-10-16T12:00:00.000000Z',
};

function fakeApi(): { api: EngineApi; sent: CreateSftpAccountRequest[] } {
  const sent: CreateSftpAccountRequest[] = [];
  const api = {
    createSftpAccount: (_username: string, data: CreateSftpAccountRequest) => {
      sent.push(data);
      return Promise.resolve({ data: CREATED });
    },
  };
  return { api: api as unknown as EngineApi, sent };
}

test.beforeEach(() => {
  process.env.API_BASE_URL ??= 'https://engine.test/api';
});

test('a password account is the record the Engine answered, plus the password sent', async () => {
  const { api, sent } = fakeApi();

  const account = await new FtpFactory(api).createSftpAccountWithPassword(
    'pwowner0001',
    'sftpabc123'
  );

  expect(sent).toHaveLength(1);
  expect(account).toEqual({ ...CREATED, password: sent[0].password });
});
