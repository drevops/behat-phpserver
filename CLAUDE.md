# Claude Configuration

## Important Commands
- Run tests: `composer test`
- Run code style checks and static analysis: `composer lint`
- Run code style fixes: `composer lint-fix`

## Coding Standards
- This project follows Drupal coding standards

## Workflow
- Use `feature/<name>` branches for PRs

## Maintenance
For detailed maintenance information, including linting, testing, and configuration options, please refer to the README.md file.

## Known Issues
- Behat tests for the API server may fail on some environments due to connection issues. This is typically related to port binding and process handling in different OS environments.
