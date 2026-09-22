<!--
Thanks for contributing to PanelAlpha Engine!
Please fill in the sections below. Keep the PR small and focused where you can.
New here? See CONTRIBUTING.md.
-->

## What does this PR do?

<!-- A clear description of the change and why it's needed. -->

## Related issue

<!-- e.g. Closes #123. If there's no issue, briefly explain the motivation. -->

## Type of change

<!-- Tick all that apply. -->

- [ ] Bug fix (non-breaking change that fixes an issue)
- [ ] New feature (non-breaking change that adds functionality)
- [ ] Breaking change (existing behaviour changes)
- [ ] Documentation only
- [ ] Refactor / chore (no behaviour change)

## How was it tested?

<!--
Say how you verified this. Operator-facing changes belong in docs/.
Coding agents report measurements from AGENTS.md §3 — a status code alone is not a result.
-->

- [ ] `cd core && ./vendor/bin/phpunit --testsuite Unit` passes (or only the documented pre-existing failures remain)
- [ ] Relevant `tests/api/` specs pass (if this touches the API or a deploy)
- [ ] I proved any pre-existing test failures are unrelated (`git stash`, re-run, compare)

## Checklist

- [ ] My commits follow [Conventional Commits](https://www.conventionalcommits.org/) (`feat:`, `fix:`, `docs:`, `chore:`, `refactor:`, `test:`)
- [ ] I updated [`docs/`](../blob/main/docs/README.md) where an operator would notice the change
- [ ] This PR is focused on a single change
- [ ] This does **not** contain a security fix that should be disclosed privately first (see [`SECURITY.md`](../blob/main/SECURITY.md))

## Anything else?

<!-- Screenshots, trade-offs, follow-ups, or context for reviewers. -->
