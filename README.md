# HMnet Configurator

HMnet Configurator adds a configurable option matrix to Shopware product detail pages. Customers can choose additional options with individual pricing logic, and every selection is reflected directly in the cart totals.

## Features

- Net-price focused storefront configurator with live price breakdown per option.
- Setup and film surcharges per option, calculated against the current quantity.
- Individual child line items per selected possibility for clear order summaries.
- Cart integration that keeps differently configured products separated while still stacking identical selections.
- Admin extensions for managing configurator fields and per-product assignments.

## Requirements

- Shopware 6.7 or newer (matches the plugin `composer.json` requirement).
- Sales channels that operate in **net** tax display mode; gross mode displays a storefront warning and is not supported.

## Installation

1. Copy the plugin into `custom/plugins/HMnetConfigurator` or install it via composer.
2. Refresh the plugin list:
   ```bash
   bin/console plugin:refresh
   ```
3. Install and activate the plugin:
   ```bash
   bin/console plugin:install --activate HMnetConfigurator
   ```
4. Recompile the storefront theme so the configurator assets are available:
   ```bash
   bin/console theme:compile
   ```

## Usage

1. Maintain configurator fields and options in the administration (the plugin adds a dedicated tab to the product detail module).
2. Ensure the product is assigned to the desired configurator fields.
3. On the storefront product detail page, customers select options before adding the product to the cart.
4. Cart and checkout pages display the parent product together with each chosen option and its surcharge.

When a customer adds the same product with different option combinations, the plugin hashes the configuration payload and generates distinct cart line item IDs. This keeps each unique configuration separate while allowing identical selections to merge.

## Development Notes

- Storefront assets live in `src/Resources/app/storefront`. Use the Shopware build tooling (for example `bin/build-storefront.sh` or the standard administration build process) after modifying the JavaScript or SCSS sources.
- Cart behaviour is implemented through custom collector, processor, and a `BeforeLineItemAddedEvent` subscriber inside `src/Core/Checkout/Cart/`.
- Tests are located in the `tests/` directory and can be executed with the project-wide PHPUnit configuration.
- The prices are calculated in two places: in the storefront using js in the configurator, and in the backend using PHP. Ensure that both implementations stay in sync when modifying the pricing logic.

## Legacy Configurator Migrator

The repository ships with a standalone migration utility in `src/Migrator` that copies configurator data from the legacy Shopware 5 tables into the Shopware 6 entities shipped by this plugin.

### Setup

1. Navigate to the utility directory:
   ```bash
   cd custom/plugins/HMnetConfigurator/src/Migrator
   ```
2. Install the Node.js dependencies:
   ```bash
   npm install
   ```
3. Adjust `config.js` to point to the legacy and target databases. You can also set `eraseTargetTables: true` if you want the import to wipe the Shopware 6 configurator tables before writing new data.

### Usage

```bash
node main.js [options]
```

Available options:

- `--dry-run` – execute the full migration pipeline without touching the target database.
- `--limit <number>` – only fetch the first _n_ rows per legacy entity (useful for smoke tests).
- `--chunk-size <number>` – control how many rows are inserted per SQL statement (defaults to 200).
- `--erase-target` – truncate the target configurator tables before writing (can also be enabled via `eraseTargetTables` in `config.js`).
- `--truncate` – legacy alias for `--erase-target`.
- `--no-spinner` / `--no-snapshots` – disable terminal spinners or JSON snapshots respectively.

During execution the tool prints stage-based progress output and writes JSON snapshots into `output/snapshots/<entity>/` so you can inspect the raw source rows, mapped payload, and generated translation rows.

For questions or contributions, open an issue or submit a pull request in the repository.

## Known bugs

- When adding the same product with different options to the cart, the amount on some options in neighbour line items may update and not be equal the quantity of the parent
- Removing and changing quantity in the cart trigger an exception
- Bad ui on the checkout page when showing line items, as their ui is not optimized for wide screen
