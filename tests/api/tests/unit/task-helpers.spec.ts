import { expect, test } from '@/fixtures/test-options';
import { taskIdFromBody } from '@/helpers/task-helpers';

test.describe('taskIdFromBody', () => {
  test('reads the task an answer returns as data', () => {
    expect(
      taskIdFromBody({ data: { id: 1328, status: 'queued', job_type: 'DeployProject' } })
    ).toBe(1328);
  });

  test('prefers task_id over the id of the project an answer returns as data', () => {
    expect(
      taskIdFromBody({ data: { id: 1256, username: 'pwabc', status: 'active' }, task_id: 1340 })
    ).toBe(1340);
  });

  test('has no id for a body that names no task', () => {
    expect(taskIdFromBody({ message: 'nope' })).toBeUndefined();
    expect(taskIdFromBody(null)).toBeUndefined();
  });
});
