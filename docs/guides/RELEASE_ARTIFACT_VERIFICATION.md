# Release Artifact Verification

From a Git checkout with PHP, Composer, and tar available, select the exact commit prepared for `1.0.0-rc.2` and run:

```bash
bash scripts/ci/verify-release-artifact.sh <candidate-sha>
```

The script uses a clean checkout of the selected commit, builds Git and Composer archives, checks the extracted files, and records the target, full SHA, and exit status. The Quality job runs the same procedure on the PR head or the pushed commit. Its log retains the result.

Review the extracted release presentation for the same SHA. Confirm that the README badges and installation command identify `1.0.0-rc.2`, that earlier publication facts are scoped to qualification, that SECURITY identifies the RC lifecycle, and that CHANGELOG allocates changes to the undated target. Record the semantic review together with the automated result. Release qualification uses the actual candidate commit on `main`.
