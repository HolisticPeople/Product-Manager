# RECOVERY_ONLY_NOT_SELECTED — Product-Manager 2.5.11 code carrier

This source/CI-only isolated candidate is prepared for a possible **pre-field,
code-only** recovery. It is not selected, not a new GO, not sourceRuntimeReady,
and must not be merged or dispatched during preparation. The sole Publisher
retains selection, qualification, promotion and production execution. No shared
dev/main ref, credentials, runner, policy, target or native data was changed.

## Immutable package and controller

The branch starts from frozen forward
`989de9b1654b4501916f4ab61eb6b484859bfa25` (full tree
`81cae30353283368597003d284d660dc2f70761f`) and explicitly restores only declared
deployed runtime paths to actual prior main
`2ca89ffdfc65ff59a3727c1f1510bad6710d2708` (full tree
`083336aa9ecad2ff350d4579cfffcb6582910ed6`). Thus forward989 is an ancestor and
normal promotion after that forward can actually restore old runtime. A
controls-only branch from old main would not do that in a three-way merge.

The complete filtered deployed payload has 27 files. Every byte equals source
2ca and the Publisher's actual prior-code map, including the original 2.5.11
header/constant (entry SHA256
`b53755654cc9eea7f9413f6cdbeb83ba752adfd27f3912e39625c368684381b1`). The retained
prior archive SHA256 is
`819b2cbddc11a918ca51091a29a5bd3c82398e145e63358fb59b2fa8c219b381`.
Its historical `products-manager-270-before-code.tar` filename is misleading;
its bound bytes are 2.5.11. Do not rename/rewrite the original receipt/archive.

The explicit delta from forward restores README.md, docs/plan/parking-lot.md,
hp-contract.json and products-manager.php; it removes the forward-only reviewed
import contract and three composition/import PHP files. All other deployed
files already match. Runtime paths are restored whole from the Git blobs, never
edited or version-stamped. No order/native migration is performed. This is an
explicit potential recovery delta, not a silent exclusion of the cumulative
collection predicate from the forward candidate.

These controller/dependency bodies stay exact989 bytes:

- `.github/workflows/deploy.yml` and `verify-deployment-controls.yml`;
- `.github/deploy-exclude.txt`;
- `.github/hp-deploy/deploy.py`, `remote-guard.py`, `package-equivalence.py`;
- `.github/hp-deploy/test-deployment-controls.py`;
- `.github/hp-deploy/staging-lease-check.py` and its test;
- `tests/deploy-workflow-contract-test.php`.

The workflow still uses immutable Git-archive packaging, original destination
excludes with `--delete`, literal target concurrency/cancel=false, exact SHA/tree,
fresh branch-head admission, validated active existing WP/root/site/environment,
strict required post-effects and retained unknown outcomes. It derives version
from the selected archive; it is not hardcoded to forward 2.7.2. This draft
recovery ref has successful source CI only; target transport/effect qualification
and installed-state evidence remain Publisher prerequisites.

## Planned isolated-candidate qualification and protected promotion

Use the canonical hp-go-live `isolated_candidate` path, not origin/dev or a
shared branch repoint. The external recovery packet binds the exact prepared
remote ref, candidate SHA/tree, all 27 file hashes, exact989 control hashes,
independent review and exact-head source CI. That packet is the concrete
package/controller identity; the held draft PR to main is not executable GO.

At actual recovery decision time, reconcile the original attempt first. The
current main must contain forward989 and its full tree must equal the qualified
forward tree above. Bind its actual SHA in the strict action-time sidecar. If
main/runtime advanced elsewhere, or unknown delivery/native outcome remains,
**hold and reprepare**; do not merge an old PR, reset, force-push or silently
substitute a candidate. Current effective branch protections/checks and human
scope still apply; an empty protection configuration is not permission to skip
normal independent review or CI.

After the Publisher selects this exact recovery candidate and separately
qualifies its staging/target path, its normal reviewed PR promotes the frozen
recovery head to main with the canonical expected-head procedure. Independently
verify that the resulting protected merge's full tree equals the prepared
recovery full tree and the controller/deployed map still match. A local
`git merge-tree --write-tree <qualified-forward-main> <recovery-head>` proof
shows the planned tree result; it does not perform a shared/production merge.
Fresh actual main head must match immediately before dispatch and the controller
rechecks it immediately before first copy.

## Concrete controller invocation inputs — planned, never executed here

The candidate remote ref is
`refs/heads/codex/pm-recovery-controller-2511-20261101`. Its exact SHA/tree and
source CI are recorded outside the source tree in the recovery owner packet.
Once its selected normal promotion is complete, the workflow invocation is:

```sh
# Values MUST come from the selected recovery journal/action-time readback.
# This is an execution recipe, not a command run during preparation.
test -n "$RECOVERY_MAIN_SHA"
test "$RECOVERY_MAIN_TREE" = "$QUALIFIED_RECOVERY_TREE"
test -n "$RECONCILED_ACTIVE_VERSION"
gh workflow run deploy.yml --repo HolisticPeople/Product-Manager --ref main \
  -f deploy=production \
  -f expected_main_sha="$RECOVERY_MAIN_SHA" \
  -f expected_main_tree="$RECOVERY_MAIN_TREE" \
  -f expected_site_url=https://holisticpeople.com \
  -f expected_current_version="$RECONCILED_ACTIVE_VERSION"
```

`RECOVERY_MAIN_SHA` is the actual selected post-promotion main commit, not 2ca,
the prepared branch head or the old forward head. `RECOVERY_MAIN_TREE` must be
the prepared exact recovery tree. `RECONCILED_ACTIVE_VERSION` is the actual
already-active target version observed after the failed/unknown original
attempt. Never fill it from an assumed successful forward deploy, an archive
filename, or this document. Missing reconciliation means not runnable **now**,
not permission to guess. The prepared controller itself is concrete and needs
no new code authoring to deploy the exact prior payload once those required
state-bound inputs and gates qualify.

## Field and dependency boundary — code rollback alone is insufficient

This option is only for a verified pre-field state. Once any FIELD/native import
or partial source boundary exists, retain the installed qualified owner runtime
and all journals/markers while the owner inspects current data and the reviewed
inverse/recovery route. Dependency order and completed/partial native outcomes
must be reconciled before any code downgrade. Never delete a journal, pending
marker or field, replay an uncertain operation, or assume prior code can safely
interpret newer field data. The code controller does not independently inspect
those native journals; the Publisher must bind the owner-inspected field/boundary
proof in the selected action-time record. A workflow version check cannot replace
it. Removing typed provider code can also affect Catalog/Zen consumers, so their
public fail-soft/dependency qualification remains named owner work.

Any copy or required post-effect failure is possibly mutated/unknown. Preserve
the run/receipt and stop. Read-only actual-state reconciliation comes before a
new selection; no automatic retry, reverse copy, DB restore or downgrade follows.
No native ERP rebuild, order/customer/ledger read/write or field operation is
part of this recovery carrier's source qualification.
