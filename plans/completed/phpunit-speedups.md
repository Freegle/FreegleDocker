# PHPUnit Parallel Test Execution Plan

## Goal
Speed up PHPUnit test suite by running tests in parallel where safe, without modifying any test or production code logic.

## Background
Currently all PHPUnit tests run sequentially. Many tests are likely independent and could run concurrently, but some tests will "step on each other's toes" by:
- Using the same database records (shared IDs, unique constraints)
- Relying on global state (sessions, static variables)
- Expecting specific database state at start of test
- Using hardcoded email addresses or other unique identifiers

## Approach Overview

### Phase 1: Static Analysis - Identify Likely Conflicts

Analyze test files without running them to categorize risk levels.

#### High-Risk Indicators (likely conflicts):
1. **Hardcoded IDs/values** - Tests using specific numeric IDs (e.g., `userid = 1`, `groupid = 123`)
2. **Shared unique constraints** - Tests using same email addresses, subscription endpoints
3. **Sequence-dependent tests** - Tests that depend on auto-increment values
4. **Global state manipulation** - Tests modifying `$_SESSION`, static class properties
5. **Same table heavy writes** - Multiple tests doing INSERT/UPDATE on same tables without isolation

#### Medium-Risk Indicators:
1. **Same domain/table** - Tests in same test file often share setup
2. **Complex setUp/tearDown** - Tests with elaborate fixtures
3. **Transaction usage** - Tests that rollback vs commit

#### Low-Risk Indicators (likely safe):
1. **Read-only tests** - Tests that only SELECT data
2. **Isolated test data** - Tests using `createTestUser()` with unique generated data
3. **Mocked dependencies** - Tests that mock database/external services
4. **Utility/unit tests** - Pure function tests with no DB interaction

### Phase 2: Categorization Script

Create a script to scan test files and categorize them:

```bash
# Location: scripts/analyze-test-parallelism.php
```

The script will:
1. Parse each test file
2. Look for patterns indicating conflicts
3. Output a JSON manifest categorizing each test class

#### Patterns to detect:

```php
// HIGH RISK - hardcoded values
$this->dbhm->preExec("INSERT INTO users (id, ...) VALUES (1, ...)");
$userid = 1;
$groupid = 123;

// HIGH RISK - shared unique values
'test@test.com'  // Many tests use this exact email
'subscription123'

// MEDIUM RISK - heavy table writes
INSERT INTO messages
INSERT INTO chat_messages
UPDATE users SET

// LOW RISK - uses test utilities
$this->createTestUser()  // Generates unique data
$this->createTestGroup()
```

### Phase 3: Generate Test Groups

Based on analysis, create test groups:

```
Group A: Safe to run in parallel (isolated tests)
Group B: Must run sequentially (share resources)
Group C: Can run in parallel but not with Group B
```

#### Output format (test-groups.json):
```json
{
  "parallel_safe": [
    "AddressTest",
    "UtilsTest",
    "LocationTest"
  ],
  "sequential_required": [
    "PushNotificationsTest",
    "ChatRoomsTest",
    "SessionTest"
  ],
  "conflict_groups": {
    "user_tests": ["UserTest", "MembershipTest"],
    "message_tests": ["MessageTest", "DigestTest"]
  }
}
```

### Phase 4: Empirical Validation

Run tests repeatedly to discover hidden conflicts:

#### Step 1: Baseline
```bash
# Run full suite 10 times sequentially, record any failures
for i in {1..10}; do
  phpunit --configuration test/ut/php/phpunit.xml test/ut/php/include 2>&1 | tee "run_$i.log"
done
```

#### Step 2: Parallel candidate testing
```bash
# Run "safe" tests in parallel using paratest
vendor/bin/paratest -p 4 --phpunit vendor/bin/phpunit \
  --configuration test/ut/php/phpunit.xml \
  --filter "AddressTest|UtilsTest|LocationTest"
```

#### Step 3: Failure analysis
- If parallel run fails but sequential passes → tests conflict
- Track which test combinations fail
- Update groupings accordingly

#### Step 4: Iterative refinement
- Start with most conservative groupings
- Gradually expand parallel group as confidence grows
- Document any discovered conflicts

### Phase 5: Implementation Options

#### Option A: PHPUnit Groups (simplest)
Add `@group` annotations to phpunit.xml to control execution order:

```xml
<testsuites>
  <testsuite name="parallel-safe">
    <file>test/ut/php/include/AddressTest.php</file>
    <file>test/ut/php/include/UtilsTest.php</file>
  </testsuite>
  <testsuite name="sequential">
    <file>test/ut/php/include/SessionTest.php</file>
  </testsuite>
</testsuites>
```

Then run:
```bash
# Parallel safe tests
paratest -p 4 --testsuite parallel-safe

# Sequential tests
phpunit --testsuite sequential
```

#### Option B: Paratest with exclusions
```bash
# Run most tests in parallel, exclude known conflicts
paratest -p 4 --exclude-group conflicts

# Then run conflicts sequentially
phpunit --group conflicts
```

#### Option C: Custom runner script
```bash
#!/bin/bash
# scripts/run-tests-optimized.sh

# Phase 1: Run parallel-safe tests (4 workers)
paratest -p 4 --filter "AddressTest|UtilsTest|..." &
PARALLEL_PID=$!

# Phase 2: Run sequential tests
phpunit --filter "SessionTest|PushNotificationsTest|..."

# Wait for parallel tests
wait $PARALLEL_PID
```

### Phase 6: CI Integration

Update CircleCI config to use optimized test running:

```yaml
jobs:
  php-tests:
    steps:
      - run:
          name: Run parallel-safe PHPUnit tests
          command: |
            paratest -p 4 --testsuite parallel-safe
      - run:
          name: Run sequential PHPUnit tests
          command: |
            phpunit --testsuite sequential
```

## Implementation Steps

### Step 1: Install paratest
```bash
composer require --dev brianium/paratest
```

### Step 2: Create analysis script
- Scan test files for conflict indicators
- Generate initial categorization

### Step 3: Baseline measurement
- Record current test suite runtime
- Run 10x to establish failure baseline

### Step 4: Initial grouping
- Start with very conservative "safe" list
- Only include tests with clear isolation

### Step 5: Validation runs
- Run parallel tests 20+ times
- Any failures → move test to sequential

### Step 6: Expand and iterate
- Gradually add tests to parallel group
- Validate each addition

### Step 7: Document findings
- Create manifest of all test categorizations
- Document known conflicts and reasons

## Expected Outcomes

### Conservative estimate:
- 30% of tests safely parallelizable
- 4 workers = ~25% total time reduction

### Optimistic estimate:
- 60% of tests safely parallelizable
- 4 workers = ~40% total time reduction

## Risks and Mitigations

| Risk | Mitigation |
|------|------------|
| Flaky tests appear | Run validation many times before finalizing |
| Hidden state sharing | Start conservative, expand slowly |
| CI environment differences | Test in CI environment, not just local |
| Database connection limits | Limit worker count based on DB pool size |

## Success Criteria

1. No increase in test failures (flakiness)
2. Measurable reduction in test suite runtime
3. Clear documentation of which tests can/cannot parallelize
4. Reproducible results across environments

## Future Improvements

Once initial parallelization is stable:
1. Add parallelization info to test file headers
2. Create pre-commit hook to validate new tests
3. Consider database-per-worker isolation for more parallelism
4. Profile slowest tests for optimization opportunities
