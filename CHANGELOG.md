# Changelog

This package continues `moselwal/frankenphp` (last release 5.3.5) as
`koh/frankenphp` with a fresh history. Releases from 5.4.0 on are listed here.

## [5.13.0](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/compare/v5.12.1...v5.13.0) (2026-11-11)

### :sparkles: Features

* **worker:** keep the CSP and Fluid configuration services in the boot state behind KOH_PERSISTENT_CONFIG_SERVICES ([ea06e70](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/ea06e7030994e25a2329c4941dc4619920122dc6))
* **page:** resolve the page layout on first use behind KOH_LAZY_PAGE_LAYOUT ([e8a96ca](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/e8a96ca8a3a84023c284c28c18fd12236e1659b5))

## [5.12.1](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/compare/v5.12.0...v5.12.1) (2026-11-11)

### :bug: Fixes

* **early-hints:** satisfy phpstan (no empty(), nullable oldest key) ([98c1536](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/98c153613fc9be30968fcc7f5b7ebce0d8fa6f73))

### :fast_forward: Performance

* **early-hints:** send the 103 before the render, with the head's stylesheets and fonts ([8a7fb24](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/8a7fb248433ebba66b88f8507f13343517715c92))

## [5.12.0](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/compare/v5.11.1...v5.12.0) (2026-11-11)

### :sparkles: Features

* **cache:** let page TSconfig keep a page's siblings out of the edge purge ([b898612](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/b89861249aa05e1fdd9879820eb3af16e3587cdf))

## [5.11.1](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/compare/v5.11.0...v5.11.1) (2026-11-10)

### :bug: Fixes

* **worker:** reset what requests write into objects kept by the persistent bootstrap ([1212f66](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/1212f666a0f22204e462a42d2f91e031d7b129c9))

### :white_check_mark: Tests

* **worker:** do not assume an exact kept-object count in the audit test ([a205b19](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/a205b19b8651fa55de3e9cb0e8caecc7ec37212b))
* **worker:** check each page's head and menu in the worker alternation test ([da10eb1](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/da10eb1aa00ab1b33d834f73a193cdd01b28fd70))

## [5.11.0](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/compare/v5.10.1...v5.11.0) (2026-11-10)

### :sparkles: Features

* **worker:** worker memos for cache payloads and Fluid file lookups ([e543816](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/e543816e3536d2a7633bbf34c3a849d0d93dce46))

### :white_check_mark: Tests

* **worker:** pin the memo switches, bounds and lookup keys ([373f6f8](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/373f6f80e86250e84f8bca07ad666945e79c0b86))
* **worker:** run the worker alternation test with the worker memos on ([46b1822](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/46b182292ad641c74f0951ee07f1cfc600c49fff))

## [5.10.1](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/compare/v5.10.0...v5.10.1) (2026-11-09)

### :bug: Fixes

* **worker:** keep the second boot, not one made on cold caches ([d7077f5](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/d7077f54aa458f40ed98a18fa0ec6c7d5a9a98b3))

### :white_check_mark: Tests

* fill in form framework forms in the worker alternation test ([670c5c6](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/670c5c64ccdc91ebc2f57b73411a322dec30b8bb))

## [5.10.0](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/compare/v5.9.0...v5.10.0) (2026-11-09)

### :sparkles: Features

* **worker:** boot TYPO3 once per worker behind KOH_PERSISTENT_BOOTSTRAP ([6e79c8c](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/6e79c8cf82025a33856c05d67c7e7986e8ff10e4))

### :bug: Fixes

* **worker:** check the language path only for non-default languages ([8eec8b3](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/8eec8b319fa1c64dd96dab8763dab4578a616027))

### :white_check_mark: Tests

* **worker:** pin the leak response and the reset after the loop ([49309b1](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/49309b16593fbd709d8b6bdbce2991542600e3a1))
* **worker:** cover the persistent bootstrap in the worker loop ([bf04562](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/bf04562b26365fb132645ddf3b72b23be5b9edcf))
* check the request state a shared bootstrap would keep ([1e4d6fe](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/1e4d6fe14fef6534a6f8cec6c369ceb035d0582c))
* prove one worker answers alternating sites and languages correctly ([70328d6](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/70328d60b83873f6a6cf79527dc6820e1b97a632))

## [5.9.0](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/compare/v5.8.0...v5.9.0) (2026-11-09)

### :sparkles: Features

* **worker:** time every frontend request, boot and render apart ([41d2c6b](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/41d2c6bc091aeb9f04ecda2ab626f62f1c50a9e6))

## [5.8.0](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/compare/v5.7.1...v5.8.0) (2026-11-09)

### :sparkles: Features

* **cache:** send edge purges after a save as a message, optionally async ([4fa7681](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/4fa7681074802017a88e60da512929c7f4951748))

### :white_check_mark: Tests

* one fixture class per file, as PSR-4 requires ([782893b](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/782893b38f77497d01ca57139b31207d33ac03c1))

### :repeat: Chores

* **repo-templates:** sync ([5e71fce](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/5e71fcefdfd6ee17b563b99b19aafe1e13805405))
* **deps:** update dependency registry.ole-hartwig.eu/devops/images/franken-php/ci to v3.2.173-8.5 ([2eb4847](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/2eb4847390946af9f37409393076ebd762f1acea))

## [5.7.1](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/compare/v5.7.0...v5.7.1) (2026-11-09)

### :bug: Fixes

* **chash-redirect:** keep tracking parameters the edge removed ([8b73546](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/8b73546f41b13abd571e23d7692872ab81be2455))

### :repeat: Chores

* **repo-templates:** sync ([599085e](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/599085eeb2d63f71b3c3a9474cbc19be53e31e0f))
* **deps:** update dependency registry.ole-hartwig.eu/devops/images/franken-php/ci to v3.2.172-8.5 ([c3e5913](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/c3e591353e7abba86091f36560982f674dbb7cee))
* **repo-templates:** sync ([d0960e7](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/d0960e7cb6a8c89bec85fe1635d283d1de3d2a2e))
* **repo-templates:** sync ([04f3146](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/04f3146cb6f6d0b78a909d91f024c7ecb12f0f08))

## [5.7.0](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/compare/v5.6.2...v5.7.0) (2026-11-08)

### :sparkles: Features

* **middleware:** redirect cHash-less page URLs instead of answering 404 ([a805e51](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/a805e5152a5fde92071ff6b3823c8b094952d061))

## [5.6.2](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/compare/v5.6.1...v5.6.2) (2026-11-08)

### :memo: Documentation

* **readme:** describe cache invalidation as it works ([bc7a1ee](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/bc7a1ee7749134a2ff802b2244631a7dba1575ab))

### :fast_forward: Performance

* **cache:** purge a host's query-string variants once per run ([71e40c2](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/71e40c22e3fceacbc1ade744cf3f107befdefc70))

## [5.6.1](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/compare/v5.6.0...v5.6.1) (2026-11-08)

### :bug: Fixes

* **cache:** purge exact edge keys for the pages a save affects ([8c0cec2](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/8c0cec21549f1436ca6eb62d7ab97b9c6b56a0fe))

## [5.6.0](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/compare/v5.5.0...v5.6.0) (2026-11-08)

### :sparkles: Features

* **cache:** audit edge cache purges behind KOH_CACHE_PURGE_AUDIT ([3bb6f61](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/3bb6f61968a0ace02d5738f249757c70bcb92290))

## [5.5.0](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/compare/v5.4.7...v5.5.0) (2026-11-08)

### :sparkles: Features

* **worker:** keep database connections open between requests ([d7f749c](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/d7f749c38f8ff7ca0dfb55c36e10ad07358b32c9))

### :white_check_mark: Tests

* **worker:** capture the rollback report without error_log ([fda6007](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/fda60073cb0d117f0f284338330c6ddf197acc7c))

### :repeat: Chores

* **repo-templates:** sync ([988e490](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/988e4908de066c18df1dbdb4b766cc02f0073cb8))
* **repo-templates:** sync ([26be084](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/26be084bb32b94a2a1250ebfe352bcfc88d53046))
* **repo-templates:** sync ([f9c434a](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/f9c434a81fe1e50540c6e6bf709c44df8e5217fc))
* **deps:** update dependency registry.ole-hartwig.eu/devops/images/franken-php/ci to v3.2.150-8.5 ([caab53e](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/caab53e18213a496e47523fab9be129359cc8a98))
* **deps:** update dependency registry.ole-hartwig.eu/devops/images/franken-php/ci to v3.2.149-8.5 ([c2186f5](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/c2186f5915d79a4f7d5b3ae6f12e72f6d6f0d322))
* **deps:** update dependency registry.ole-hartwig.eu/devops/images/franken-php/ci to v3.2.148-8.5 ([b59b128](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/b59b128a1cc61e78cd1f98f8c6324b75760b3525))
* **repo-templates:** sync ([d79f767](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/d79f767233a54633871230a35a342393a594c0ac))
* **repo-templates:** sync ([79a5f84](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/79a5f842462746b748e988da0cfc928495958169))
* **deps:** update dependency registry.ole-hartwig.eu/devops/images/franken-php/ci to v3.2.144-8.5 ([1bcbf1f](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/1bcbf1f5a53d2354942fd8e9a36ce57f25eddaf1))
* **deps:** update dependency registry.ole-hartwig.eu/devops/images/franken-php/ci to v3.2.143-8.5 ([f97156a](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/f97156acbfedbbcacdbe20d7193d9dc72cc28858))
* **deps:** update dependency registry.ole-hartwig.eu/devops/images/franken-php/ci to v3.2.142-8.5 ([15c6dfe](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/15c6dfe7bb19406a1e764c403b4c3b988681abad))
* **deps:** update dependency registry.ole-hartwig.eu/devops/images/franken-php/ci to v3.2.139-8.5 ([b9b0de2](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/b9b0de2ce9c63b14f907d63cad8968d424fcdcf0))
* **deps:** update dependency registry.ole-hartwig.eu/devops/images/franken-php/ci to v3.2.138-8.5 ([a12d6ca](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/a12d6ca2ed39e0c553e63d7349b643d3681dba0b))
* **deps:** update dependency registry.ole-hartwig.eu/devops/images/franken-php/ci to v3.2.137-8.5 ([9e9eeed](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/9e9eeed2fbc246bcaa2a2e6fe1fd805bedc5b2ac))
* **repo-templates:** sync ([4820b28](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/4820b28c100378ac0f4b5333a4e43027d1915840))

## [5.4.7](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/compare/v5.4.6...v5.4.7) (2026-11-04)

### :bug: Fixes

* **worker:** reset request state between requests ([03e470b](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/03e470b7e294e8717a05704273a07995443b83cd))

### :white_check_mark: Tests

* **worker:** pin each reset and simplify handler restore ([4147894](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/4147894716d04d2728ead6e14ad1b9246d425a0e))

### :repeat: Chores

* **repo-templates:** sync ([d767ebc](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/d767ebc1b7a8ff1f92e867630b5a6d32686cbd43))

## [5.4.6](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/compare/v5.4.5...v5.4.6) (2026-11-04)

### :bug: Fixes

* **security:** AUD-062 F01 — Surrogate-Key tags and honest flush results ([8449b6b](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/8449b6b97984b0bd4aadca9ad3eadace393e18f2))

### :memo: Documentation

* **readme:** avoid a space-only code span (MD038) ([4f4e1e8](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/4f4e1e89b282eb5d06cbf714dcd17e4e16fd12a5))

### :white_check_mark: Tests

* accept PHPUnit 13 executionOrder so test:infection runs ([583be97](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/583be97466d61016427417c32e35474025fe39e1))

### :repeat: Chores

* **repo-templates:** sync ([f0790f2](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/f0790f2daf82e495f274bff62f0862ace68341be))

## [5.4.5](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/compare/v5.4.4...v5.4.5) (2026-11-03)

### :bug: Fixes

* **security:** AUD-062 F01 — drop redundant empty-string check (phpstan) ([bbd75b9](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/bbd75b9e5e4fb89c7d5c1c4882d1976f2c0fce7f))
* **security:** AUD-062 F01 — purge deleted/replaced/renamed/moved fileadmin files from the edge ([2a4939c](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/2a4939cb0949b515dc3505a2ab96aa08786df3d2))

### :repeat: Chores

* **repo-templates:** sync ([039b3e0](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/039b3e0778a67c5db51bd385543b143bf7971fee))

## [5.4.4](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/compare/v5.4.3...v5.4.4) (2026-10-30)

### :white_check_mark: Tests

* build the DI container in this repository's pipeline ([90f13f6](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/90f13f6238edea85e6868b6648f1825c1294a46c))

### :repeat: Chores

* **repo-templates:** sync ([150c635](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/150c635b81c557903fd36580864fc1ae99a06af4))

## [5.4.3](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/compare/v5.4.2...v5.4.3) (2026-10-18)

### :bug: Fixes

* admit PHP 8.3 and test the floor on it ([5f116e7](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/5f116e760f28f280b5de4c7cb119d1f3e64f9ce3))
* **docs:** write the README in English throughout ([8d49533](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/8d495336a457fe5b1d2cca033c8c7e75368fbb5d))

## [5.4.2](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/compare/v5.4.1...v5.4.2) (2026-10-16)

### :repeat: Chores

* **repo-templates:** sync ([18d9d11](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/18d9d1130474a388ce78f89459ef6884dad13107))

## [5.4.1](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/compare/v5.4.0...v5.4.1) (2026-10-14)

### :repeat: Chores

* **repo-templates:** sync ([25e0158](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/25e0158739d4819eb8b99cad1e74e05f4d0c63e6))

## [5.4.0] (2026-10-13)

### :boom: BREAKING CHANGES

* continue moselwal/frankenphp as koh/frankenphp

### :sparkles: Features

* continue moselwal/frankenphp as koh/frankenphp ([a7500f6](https://git.ole-hartwig.eu/ai-ready-platform/platform/frankenphp/commit/a7500f62c3e32b10cfab1436ebc62dbde42acfca))

