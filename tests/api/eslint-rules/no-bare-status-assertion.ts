import type { Rule } from 'eslint';

const STATUS_MATCHERS = new Set(['toBe', 'toEqual', 'toStrictEqual']);

/**
 * Flags `expect(x.status()).toBe(n)` and `expect(x.status).toBe(n)`: they hide the request and body.
 * A string or template literal expected value is a task or record state, not an HTTP status.
 */
export const noBareStatusAssertion: Rule.RuleModule = {
  meta: {
    type: 'suggestion',
    schema: [],
    messages: {
      bare: "Use expectStatus(response, expected) from '@/helpers/expect-status' — it reports method, URL and body.",
    },
  },
  create(context) {
    return {
      CallExpression(node) {
        const callee = node.callee;
        if (
          callee.type !== 'MemberExpression' ||
          callee.property.type !== 'Identifier' ||
          !STATUS_MATCHERS.has(callee.property.name)
        ) {
          return;
        }
        const expectCall = callee.object;
        if (
          expectCall.type !== 'CallExpression' ||
          expectCall.callee.type !== 'Identifier' ||
          expectCall.callee.name !== 'expect'
        ) {
          return;
        }
        const expected = node.arguments[0];
        if (expected?.type === 'TemplateLiteral') {
          return;
        }
        if (expected?.type === 'Literal' && typeof expected.value === 'string') {
          return;
        }
        const subject = expectCall.arguments[0];
        if (!subject) {
          return;
        }
        const target =
          subject.type === 'CallExpression' && subject.arguments.length === 0
            ? subject.callee
            : subject;
        if (
          target.type === 'MemberExpression' &&
          !target.computed &&
          target.property.type === 'Identifier' &&
          target.property.name === 'status'
        ) {
          context.report({ node: expectCall, messageId: 'bare' });
        }
      },
    };
  },
};
