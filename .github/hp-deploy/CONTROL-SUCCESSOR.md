# Product-Manager deployment control successor — proposed, unselected

This is a source/CI-only option. The complete rsync-deployed tracked payload and
2.7.2 header/constant remain byte-equal to accepted dev
`8c2e777288025e4010a75c6455a96dd831e38d23`; control/docs/tests live under existing
excluded `.github/` or `tests/`. Full Git trees differ. The immutable package
comparison receipt proves those separate facts; equal runtime is not equal tree.
No merge, workflow dispatch, target probe or native mutation belongs to this
preparation task. Source CI does not establish route READY. The existing sole
Publisher must select and qualify a successor, without silently amending the
issued wave-7 freeze or relabeling the rejected old workflow as qualified.

## Roles and exact inputs

Both native jobs retain the existing GitHub environments and SSH secrets. They
serialize per plugin/environment with literal `hp-products-manager-staging` and
`hp-products-manager-production` groups and `cancel-in-progress: false`, across
all dispatch refs. Validation-only PR CI has its own cancellable group. Pending
runs can still be superseded by GitHub; a cancelled unstarted run is not a target
outcome. Human cancellation/interrupted running attempts need actual-state review.

Production is `workflow_dispatch`, `deploy=production`, from `refs/heads/main`.
Its required execution inputs are:

- `expected_main_sha`: exact selected **post-promotion main** commit, 40 lowercase hex characters.
- `expected_main_tree`: exact selected complete Git tree, 40 lowercase hex characters.
- `expected_site_url`: exact target HTTPS home origin, without credentials/path.
- `expected_current_version`: reviewed existing already-active plugin version.

The current pre-promotion main SHA/tree is a separate read-only packet datum. It
must not be substituted for the selected post-promotion identity. Checkout pins
the expected main SHA, verifies HEAD AND full tree, requires the dispatch SHA to
match, and refreshes the exact remote main immediately before first copy.

Staging pins the immutable dev-push/explicit branch-dispatch SHA and its complete
checked-out tree, rechecks the selected branch head after waits, and retains the
existing exact-plugin staging lease. No ignore-lease input is offered. The
lease is re-read inside the performer after archive/root/WP preflight and before
the final head guard and first copy, so a lease acquired during those waits blocks.
Production does not consume this staging-only check. The known
staging home is explicit in the workflow; a different target needs normal source
review. Both jobs pin the same
Python 3.12 control runtime used by PR CI. No new runner class, credential,
permission setting, branch-policy change or review bypass is introduced.

## Packaging and target admission

The performer creates a selected-commit Git archive OUTSIDE its checkout. It
applies the selected archive's actual `.github/deploy-exclude.txt`, records every
resulting tracked file hash, and transfers that immutable filtered payload only.
Dirty/untracked checkout bytes and the local outcome receipt cannot enter it.
The final `rsync --delete` ALSO consumes the original exclusion file from that
immutable archive, preserving its target-side protection of excluded existing
files. A real local rsync regression proves excluded vendor/control sentinels
survive while an unexcluded stale target file is deleted. Only the destination
is rebound locally in that test; all actual copy flags/source/excludes remain.

Configured `*_PLUGINS_BASE` must match the supported absolute
`public/wp-content/plugins` layout with ASCII alphanumeric, underscore or hyphen
directory components only. Spaces, dots and shell characters in the configured
base are unsupported and refuse before SSH; no general remote rsync argument
quoting compatibility is claimed. The remote reader resolves and validates
canonical existing root/content/plugin directories and `wp-load.php`,
`wp-config.php`, and the plugin entry. It verifies actual `ABSPATH`,
`WP_CONTENT_DIR`, `WP_PLUGIN_DIR`, HTTPS home and environment from WP, plus native
plugin-active state and the reviewed production version baseline. Missing,
symlinked/mismatched/unsupported layout, wrong site/environment, inactive plugin
or a first-install path refuses before copy. It does not guess a custom layout,
create a directory, enable a plugin, or change a setting during preflight.

Compatible WP reads/effects use `--skip-plugins --skip-themes`; core, MU plugins,
drop-ins and WP-CLI bootstrap remain possible. This is zero operator-requested
pre-copy mutation, not an audited universal zero-SQL assertion. It does not load
customer plugin records merely to establish identity. Existing host trust/keyscan
and target CLI capabilities still require Publisher target qualification; an
existing SSH key alone does not prove an execution route.

Product-Manager is **not** one of the 12 atomic-kit adopters in the canonical
safe-deploy guide. This minimal option retains its legacy workflow rsync route;
it does not add the MU swap helper, an atomic-adoption rollout, global OPcache
reset or a new runtime version. No atomic-copy or FPM-cache guarantee is claimed.

## Mutation, required effects and receipts

After source/target/queue admission, the performer persists a local copy intent.
Its fresh `git ls-remote origin <selected-ref>` expected-head check is the last
external check immediately before `rsync`: no host mkdir/pre-copy write occurs
between them. A changed head refuses the copy. The environment-specific GitHub
concurrency group remains held through required effects and the run receipt.

The post-copy remote performer revalidates root/site/environment, proves the
plugin is STILL already active and reads candidate version. Required commands
then run once in order: idempotent `plugin activate products-manager`, active
readback, object-cache flush, Kinsta page-cache purge and final version readback.
Every nonzero required result propagates. Activation is an already-active
idempotence check, not first-install/inactive-activation authority. No command is
swallowed with `|| true`; no native command or copy is automatically retried.

A safe receipt contains source SHA/tree/version, package file hashes, method
limits and phase/outcome. Known structured remote errors retain the failed WP
command (e.g. cache flush) with a diagnostic SHA, without an environment,
credential, SSH-argv or unbounded stderr dump. Upload is attempted on all job
outcomes; missing receipts or upload failure remain explicit run evidence gaps.

- `refused_before_copy` / exit1: the operator did not dispatch rsync. Retain the
  exact failed admission. WP bootstrap read limitations still apply.
- `copy_intent_unknown_if_interrupted`: a crash/cancel/transport gap must be
  reconciled; do not infer that the target was untouched.
- Any rsync nonzero, interruption after dispatch, or required post-effect failure
  is `mutation_outcome_unknown` / exit2. The target may have changed. Workflow
  failure cannot be turned into a safe retry or an assumed rollback.
- `required_effects_verified` / exit0 means those commands and native version/
  active readbacks succeeded. Exact installed/served bytes, FPM/browser behavior,
  logs, human/device acceptance and publication completion remain separate.

## Recovery and selection packet

For an unknown or post-copy failure, preserve the run, local receipt, stdout/
stderr and actual selected main/tree; stop writes. The Publisher first obtains
read-only actual target identity and affected-state evidence. There is no
automatic reverse rsync, branch reset, downgrade, cache workaround or invented
backup. A rollback is a separately selected normal release of the exact qualified
previous package with current main/head/baseline guards and its existing scope
approval. Customer/native-data recovery stays with the native owner.

Before selection, bind current effective branch/source-CI policy, successful
exact-head PR CI, independently reviewed controls, full candidate tree, complete
payload equivalence, actual target/root/CLI/SSH admission, required-effect and
recovery evidence, and the new successor freeze. This document is not that freeze.
The existing PM/Zen/Catalog source/native/human holds remain owner-specific.

PR CI executes the actual performer with command doubles, actual remote guard
code with fake WP-CLI, real local Git archive/rsync copy regressions, staging
lease parser and workflow coupling. No target host is contacted. Packaging CI
records comparison against that PR's actual base/head; it does not permanently
require all future runtime feature work to equal historical 2.7.2. This particular
control-only candidate must independently prove complete payload equality to
accepted `8c2e777` before Publisher selection.
