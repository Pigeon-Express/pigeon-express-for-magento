# Developer documentation

Internal. Never ships to clients, see [RELEASE.md](RELEASE.md).

| Document | Read it when |
|----------|--------------|
| [PRICING-AND-SERVICES.md](PRICING-AND-SERVICES.md) | Touching anything to do with money, fees, or additional services. **Read this before adding any number to the codebase.** |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Getting oriented, or working out which layer a change belongs in |
| [WOOCOMMERCE-REFERENCE.md](WOOCOMMERCE-REFERENCE.md) | Deciding how a feature should behave. The WooCommerce plugin is the behavioural reference |
| [TODO.md](TODO.md) | Picking up work, or checking whether a bug is already known |
| [TESTING.md](TESTING.md) | Running the test suites, or adding a test |
| [RELEASE.md](RELEASE.md) | Building a zip for a client |
| [CHANGELOG-DEV.md](CHANGELOG-DEV.md) | The full engineering history. The shipped `CHANGELOG.md` is the client facing summary |

Raw material sits in `.ai/`:

- `magento-module-analysis.md`, full audit of this module
- `woocommerce-plugin-analysis.md`, full audit of the WooCommerce plugin
- `pigeonexpress-openapi.yaml`, the published API spec, fetched from
  `https://api-docs.pigeonexpress.com/openapi.yaml`

## The short version

- Run CLI from `magento_app/`: `php8.3 -d memory_limit=-1 bin/magento <cmd>`
- Mode is developer, no `di:compile` needed. Flush after config changes.
- Logs: `var/log/pigeonexpress.log`, gated on the carrier's logging setting
- **No price, fee or percentage may be hardcoded.** Everything comes from the API.
- Tests: `vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/PigeonExpress/Shipping/Test/Unit`
- Analysis: `../../../../vendor/bin/phpstan analyse -c phpstan.neon --memory-limit=1G`
