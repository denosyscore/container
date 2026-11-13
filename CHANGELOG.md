# Changelog

## 0.21.0 – 2026-11-13

- Released with the framework's 0.21 line. Requires its DenoSys dependencies
  at `^0.21`; no other changes.

## 0.20.0 – 2026-11-13

- Released with the framework's 0.20 line. Requires its DenoSys dependencies
  at `^0.20`; no other changes.

## 0.19.0 – 2026-11-13

- Released with the framework's 0.19 line. Requires its DenoSys dependencies
  at `^0.19`; no other changes.

## 0.18.0 – 2026-11-13

- Released with the framework's 0.18 line. Requires its DenoSys dependencies
  at `^0.18`; no other changes.

## 0.17.0 – 2026-11-12

- Released with the framework's 0.17 line. Requires its DenoSys dependencies
  at `^0.17`; no other changes.

## 0.16.0 – 2026-11-12

- Released with the framework's 0.16 line; no changes to this package.

## 0.15.0 – 2026-11-12

- Released with the framework's 0.15 line; no changes to this package.

## 0.14.0 – 2026-11-12

- Released with the framework's 0.14 line; no changes to this package.

## 0.13.0 – 2026-11-12

- **Breaking:** rewritten as a small PSR-11 container (about 550 lines). It
  keeps bindings, singletons, instances, aliases, `extend`, the deferred
  resolver and autowiring, and removes contextual bindings, tagging, scoped
  and lazy bindings, decorators, middleware, `call()`, mocks, spies, the
  validator, the profiler, resolution events and the compiler.
- **Breaking:** `get()` throws `NotFoundException` for an identifier the
  container cannot provide, as PSR-11 requires.
- Adds `#[Config]`, `#[Inject]` and the `ParameterResolver` interface.
- Requires `denosyscore/contracts` instead of `denosyscore/events`.

## 0.12.0 – 2026-11-12

- Released with the framework's 0.12 line; no changes to this package.

## 0.11.1 – 2026-11-12

### Fixed

- Requirements on other DenoSys packages are `^0.11`. In 0.11.0 they were
  still `^0.10`, which for a 0.x version allows only 0.10 releases, so 0.11.0
  packages resolved their siblings to 0.10 or could not be installed. Use
  0.11.1 instead of 0.11.0.

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

