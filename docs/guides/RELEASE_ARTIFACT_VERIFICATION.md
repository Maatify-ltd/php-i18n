# Release Artifact Verification

From a Git checkout with PHP, Composer, and tar available, select the exact commit prepared for `1.0.0-rc.3` and run:

```bash
bash scripts/ci/verify-release-artifact.sh <candidate-sha>
```

The script uses a clean checkout of the selected commit, builds Git and Composer archives, checks the extracted files, and records the target, full SHA, and exit status. The Quality job runs the same procedure on the PR head or the pushed commit. Its log retains the result. The script also adds synthetic credential, dependency, and development files to its disposable checkout and verifies that the resulting Composer archive matches the clean archive.

Before publication, review the extracted presentation on the exact candidate SHA on `main`. Confirm that the README badges and installation command identify `1.0.0-rc.3`, that publication facts are scoped to qualification, that SECURITY identifies the RC lifecycle, and that CHANGELOG lists the prepared target under an undated heading. Record the semantic review together with the automated result. After publication, the live CHANGELOG heading carries the actual release date.
