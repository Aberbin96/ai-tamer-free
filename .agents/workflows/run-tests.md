---
description: How to run unit tests in AI Tamer
---

# Running Unit Tests

In this project, only the USER can execute the unit tests due to environment restrictions (sandbox/vendor path issues).

## Command
Run the following command from the plugin root:

```bash
php vendor/bin/phpunit --colors=always
```

## Rules
- **Do not attempt to run tests as the AI agent** using `run_command`; it will likely fail or be blocked.
- **Request the user** to run the command and provide the output.
- **Always verify** that all tests pass after making changes to core logic.
