# Changelog

## 0.11.0 – 2026-11-12

- No longer requires `denosyscore/contracts`.

## 0.10.0 – 2026-11-12

### Changed

- Released from the [denosyscore/framework](https://github.com/denosyscore/framework)
  monorepo, where this package now lives under `packages/container`. All DenoSys
  packages share one version line, so this release is 0.10.0.
- **v1.0.0 is superseded.** It was tagged in May 2026 from the same history,
  but no DenoSys package ever required it: they all used `^0.2`. 0.10.0
  contains everything in 1.0.0. Require `^0.10`; the container's 1.0 API
  promise will come with a later 1.0 release of the whole framework.
- Requirements on other DenoSys packages are `^0.10`.

