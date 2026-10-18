# Source Packaging

The packager creates an archive and checks structural integrity. It does **not** certify installation, account authorization, synchronization or credit behavior. A locally built archive is not an RC or a supported release.

## Commands

```sh
python3 -m unittest discover -s tests -p test_build_release.py -v
python3 tools/build_release.py --source upload/source/plugin/pintogya --output build/Togya-Plugin-1.0.0-alpha.7-x5.zip
```

The output filename must match the plugin's declared version. The source must contain matching installation metadata, lifecycle entry points and verified migration hashes. The builder includes the original and third-party license notices present in the source, along with the public documentation.

For a privacy review, pass `--deny-stdin` and provide a JSON array of private identifiers on standard input from a private source. The builder uses those values in memory; it does not put them in the repository or artifact, or echo them. Do not paste real identifiers into public scripts, CI logs or issue comments.

## Reproducibility And Hygiene

ZIP entry order, timestamps and regular-file modes are fixed. The archive includes a SHA-256 manifest, plus an external archive checksum. Symlinks, deployment configuration, runtime files, common credential patterns and private absolute paths are rejected. Apple metadata is omitted and counted.

Validation must still inspect the produced archive on Linux, check every manifest entry and verify the downloaded release checksum. A passed packager test or a checksum match cannot replace P2/P3 runtime gates. See [STATUS.md](STATUS.md) for the actual release state.

The Alpha source candidate is for disposable evaluation, not production certification. Publication and remaining gates are recorded in [STATUS.md](STATUS.md); building locally does not mean a GitHub Release exists.
