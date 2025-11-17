const { execSync } = require("child_process");
const fs = require("fs");
const path = require("path");
const Database = require("better-sqlite3");

class SentryIntegration {
  constructor(config) {
    this.sentryOrgSlug = config.sentryOrgSlug;
    this.sentryAuthToken = config.sentryAuthToken;
    this.pollIntervalMs = config.pollIntervalMs || 15 * 60 * 1000; // 15 minutes default
    this.activeProcessing = new Map();
    this.ignoreAlreadyProcessing = config.ignoreAlreadyProcessing || false; // Flag to skip duplicate check
    this.maxIssuesPerPoll = config.maxIssuesPerPoll || null; // Limit number of issues to process

    // Load Sentry project configurations from environment variables
    this.projects = this.loadProjectsFromEnv();

    // Initialize SQLite database for tracking processed issues
    this.initDatabase();

    console.log("Sentry Integration initialized with projects:", Object.keys(this.projects));
    console.log("Using Claude Code CLI for analysis (no separate API costs)");
    if (this.ignoreAlreadyProcessing) {
      console.log("⚠️ Ignoring 'already processing' checks");
    }
    if (this.maxIssuesPerPoll) {
      console.log(`⚠️ Limited to ${this.maxIssuesPerPoll} issue(s) per poll`);
    }
  }

  /**
   * Initialize SQLite database for tracking processed issues
   */
  initDatabase() {
    const dbPath = process.env.SENTRY_DB_PATH || 'sentry-issues.db';
    this.db = new Database(dbPath);

    // Create table if it doesn't exist
    this.db.exec(`
      CREATE TABLE IF NOT EXISTS processed_issues (
        issue_id TEXT PRIMARY KEY,
        module TEXT NOT NULL,
        title TEXT,
        status TEXT NOT NULL,
        attempts INTEGER DEFAULT 1,
        pr_url TEXT,
        error_message TEXT,
        first_processed_at INTEGER NOT NULL,
        last_processed_at INTEGER NOT NULL
      )
    `);

    console.log(`SQLite database initialized at ${dbPath}`);
  }

  /**
   * Check if an issue has been processed before
   */
  hasBeenProcessed(issueId) {
    const stmt = this.db.prepare('SELECT status, attempts FROM processed_issues WHERE issue_id = ?');
    const result = stmt.get(issueId);

    if (!result) {
      return { processed: false };
    }

    // Allow retrying if:
    // 1. Status is 'failed' and attempts < 3
    // 2. Status is 'skipped' (couldn't reproduce) - don't retry
    if (result.status === 'failed' && result.attempts < 3) {
      return { processed: false, shouldRetry: true, attempts: result.attempts };
    }

    return {
      processed: true,
      status: result.status,
      attempts: result.attempts
    };
  }

  /**
   * Record that an issue has been processed
   */
  recordProcessedIssue(issueId, moduleKey, title, status, prUrl = null, errorMessage = null) {
    const now = Date.now();
    const existing = this.db.prepare('SELECT attempts, first_processed_at FROM processed_issues WHERE issue_id = ?').get(issueId);

    if (existing) {
      // Update existing record
      this.db.prepare(`
        UPDATE processed_issues
        SET status = ?,
            attempts = attempts + 1,
            pr_url = ?,
            error_message = ?,
            last_processed_at = ?
        WHERE issue_id = ?
      `).run(status, prUrl, errorMessage, now, issueId);
    } else {
      // Insert new record
      this.db.prepare(`
        INSERT INTO processed_issues
        (issue_id, module, title, status, pr_url, error_message, first_processed_at, last_processed_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
      `).run(issueId, moduleKey, title, status, prUrl, errorMessage, now, now);
    }
  }

  /**
   * Get statistics about processed issues
   */
  getProcessedStats() {
    const stats = this.db.prepare(`
      SELECT
        status,
        COUNT(*) as count
      FROM processed_issues
      GROUP BY status
    `).all();

    const total = this.db.prepare('SELECT COUNT(*) as count FROM processed_issues').get();

    return {
      total: total.count,
      byStatus: stats.reduce((acc, row) => {
        acc[row.status] = row.count;
        return acc;
      }, {}),
    };
  }

  /**
   * Clear processed issues (for testing or reset)
   */
  clearProcessedIssues() {
    this.db.prepare('DELETE FROM processed_issues').run();
    console.log('Cleared all processed issues from database');
  }

  /**
   * Load project configurations from environment variables
   * Format: SENTRY_PROJECTS=php:6119406:php-api:/project/iznik-server,go:4505568012730368:go-api:/project/iznik-server-go,...
   */
  loadProjectsFromEnv() {
    const projectsEnv = process.env.SENTRY_PROJECTS;

    if (!projectsEnv) {
      console.warn("SENTRY_PROJECTS not configured, using default configuration");
      // Return default configuration
      return {
        php: {
          projectId: "6119406",
          projectSlug: "php",
          repoPath: "/home/edward/FreegleDockerWSL/iznik-server",
          testCommand: "curl -X POST http://localhost:8081/api/tests/php"
        },
        go: {
          projectId: "4505568012730368",
          projectSlug: "go",
          repoPath: "/home/edward/FreegleDockerWSL/iznik-server-go",
          testCommand: "curl -X POST http://localhost:8081/api/tests/go"
        },
        nuxt3: {
          projectId: "4504083802226688",
          projectSlug: "nuxt3",
          repoPath: "/home/edward/FreegleDockerWSL/iznik-nuxt3",
          testCommand: "curl -X POST http://localhost:8081/api/tests/playwright"
        },
        capacitor: {
          projectId: "4506643536609280",
          projectSlug: "capacitor",
          repoPath: "/home/edward/FreegleDockerWSL/iznik-nuxt3",
          testCommand: "curl -X POST http://localhost:8081/api/tests/playwright"
        },
        modtools: {
          projectId: "4506712427855872",
          projectSlug: "modtools",
          repoPath: "/home/edward/FreegleDockerWSL/iznik-nuxt3-modtools",
          testCommand: "curl -X POST http://localhost:8081/api/tests/playwright"
        },
      };
    }

    // Parse environment variable
    const projects = {};
    const projectConfigs = projectsEnv.split(',');

    for (const configStr of projectConfigs) {
      const [key, projectId, projectSlug, repoPath] = configStr.trim().split(':');

      if (!key || !projectId || !projectSlug || !repoPath) {
        console.warn(`Invalid project configuration: ${configStr}`);
        continue;
      }

      // Determine test command based on repository
      let testCommand;
      if (repoPath.includes('iznik-server-go')) {
        testCommand = "curl -X POST http://localhost:8081/api/tests/go";
      } else if (repoPath.includes('iznik-server')) {
        testCommand = "curl -X POST http://localhost:8081/api/tests/php";
      } else {
        testCommand = "curl -X POST http://localhost:8081/api/tests/playwright";
      }

      projects[key] = {
        projectId,
        projectSlug,
        repoPath,
        testCommand,
      };
    }

    return projects;
  }

  /**
   * Start polling Sentry for issues
   */
  start() {
    console.log(`Starting Sentry integration with ${this.pollIntervalMs}ms poll interval`);

    // Do an initial poll after 30 seconds
    setTimeout(() => {
      this.pollSentryIssues();
    }, 30000);

    // Then poll at regular intervals
    setInterval(() => {
      this.pollSentryIssues();
    }, this.pollIntervalMs);
  }

  /**
   * Poll Sentry API for new high-priority issues
   */
  async pollSentryIssues() {
    try {
      console.log("Polling Sentry for high-priority issues...");

      let totalProcessedCount = 0;

      for (const [moduleKey, project] of Object.entries(this.projects)) {
        try {
          const issues = await this.fetchSentryIssues(project.projectSlug);
          console.log(`Found ${issues.length} unresolved issues in ${project.projectSlug}`);

          // Add delay between projects to avoid rate limits
          await this.sleep(2000);

          for (const issue of issues) {
            // Check if we've hit the max issues limit
            if (this.maxIssuesPerPoll && totalProcessedCount >= this.maxIssuesPerPoll) {
              console.log(`Reached max issues limit (${this.maxIssuesPerPoll}), stopping poll`);
              return;
            }

            // Check if already processed using database (unless flag set to ignore)
            if (!this.ignoreAlreadyProcessing) {
              const processedCheck = this.hasBeenProcessed(issue.id);

              if (processedCheck.processed) {
                console.log(`Skipping issue ${issue.id} - already processed (${processedCheck.status}, ${processedCheck.attempts} attempts)`);
                continue;
              }
            }

            // Skip if currently processing
            if (this.activeProcessing.has(issue.id)) {
              continue;
            }

            // Check if issue meets criteria (high priority/frequent)
            if (this.shouldProcessIssue(issue)) {
              if (!this.ignoreAlreadyProcessing) {
                const processedCheck = this.hasBeenProcessed(issue.id);
                if (processedCheck.shouldRetry) {
                  console.log(`Retrying issue ${issue.id} (attempt ${processedCheck.attempts + 1}/3): ${issue.title}`);
                } else {
                  console.log(`Processing issue ${issue.id}: ${issue.title}`);
                }
              } else {
                console.log(`Processing issue ${issue.id}: ${issue.title}`);
              }

              this.activeProcessing.set(issue.id, { module: moduleKey, startTime: Date.now() });

              // Process sequentially (not in parallel)
              try {
                await this.processIssue(issue, moduleKey, project);
                this.activeProcessing.delete(issue.id);
                totalProcessedCount++;
              } catch (error) {
                console.error(`Failed to process issue ${issue.id}:`, error);
                this.activeProcessing.delete(issue.id);
              }

              // Add delay between processing issues to avoid rate limits (3 seconds)
              await this.sleep(3000);
            }
          }
        } catch (error) {
          console.error(`Error fetching issues for ${project.projectSlug}:`, error.message);
        }
      }
    } catch (error) {
      console.error("Error polling Sentry:", error);
    }
  }

  /**
   * Sleep for specified milliseconds
   */
  sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
  }

  /**
   * Fetch issues from Sentry REST API
   */
  async fetchSentryIssues(projectSlug) {
    const url = `https://sentry.io/api/0/projects/${this.sentryOrgSlug}/${projectSlug}/issues/`;
    const params = new URLSearchParams({
      query: 'is:unresolved',
      statsPeriod: '24h',
    });

    const response = await fetch(`${url}?${params}`, {
      headers: {
        'Authorization': `Bearer ${this.sentryAuthToken}`,
      },
    });

    if (!response.ok) {
      throw new Error(`Sentry API error: ${response.status} ${response.statusText}`);
    }

    return await response.json();
  }

  /**
   * Determine if issue should be processed (high priority/frequent)
   */
  shouldProcessIssue(issue) {
    // Check event count in last 24h (frequent errors)
    const eventCount = issue.count || 0;
    if (eventCount >= 10) {
      return true;
    }

    // Check priority/level
    if (issue.level === 'error' || issue.level === 'fatal') {
      return true;
    }

    // Check if marked as high priority
    if (issue.priority === 'high') {
      return true;
    }

    return false;
  }

  /**
   * Process a single Sentry issue
   */
  async processIssue(issue, moduleKey, project) {
    console.log(`\n=== Processing Sentry Issue ===`);
    console.log(`Module: ${moduleKey}`);
    console.log(`Issue: ${issue.title}`);
    console.log(`Events (24h): ${issue.count}`);
    console.log(`Level: ${issue.level}`);

    try {
      // Check if another instance is already processing this (unless flag set to ignore)
      if (!this.ignoreAlreadyProcessing) {
        const alreadyProcessing = await this.checkIfAlreadyProcessing(issue.id);
        if (alreadyProcessing) {
          console.log(`Issue ${issue.id} is already being processed by another instance (started ${alreadyProcessing.timeAgo})`);
          return;
        }
      } else {
        console.log(`Bypassing 'already processing' check (ignoreAlreadyProcessing=true)`);
      }

      // Processing issue
      console.log(`Processing issue ${issue.id}...`);

      // Fetch detailed issue information
      const issueDetails = await this.fetchIssueDetails(issue);

      // Get relevant code context
      const codeContext = await this.getCodeContext(issue, project);

      // Analyze with Claude
      const analysis = await this.analyzeWithClaude(issue, issueDetails, codeContext, moduleKey);

      if (!analysis.canReproduce) {
        console.log("Claude could not create a reproducing test.");
        this.recordProcessedIssue(issue.id, moduleKey, issue.title, 'skipped', null, analysis.reason);
        return;
      }

      console.log("Checking for existing PRs...");

      // Check for existing PRs that might already fix this
      const existingPR = await this.checkForExistingPR(issue, project, analysis);
      if (existingPR) {
        console.log(`Found existing PR that may fix this issue: ${existingPR.url}`);
        this.recordProcessedIssue(issue.id, moduleKey, issue.title, 'skipped', existingPR.url, 'Existing PR found');
        return;
      }

      // Apply the fix
      const fixResult = await this.applyFix(analysis, project, moduleKey);

      // Create PR based on test results
      if (!fixResult.success) {
        console.log("Generated test failed to validate fix. Creating draft PR.");
        await this.createDraftPR(analysis, project, moduleKey, fixResult);
        this.recordProcessedIssue(issue.id, moduleKey, issue.title, 'failed', fixResult.prUrl, fixResult.error || fixResult.testOutput);
      } else {
        console.log("Generated test passed. Creating PR.");
        await this.createPR(analysis, project, moduleKey, fixResult);
        this.recordProcessedIssue(issue.id, moduleKey, issue.title, 'success', fixResult.prUrl);
      }

      console.log(`✅ Successfully processed issue ${issue.id}`);

    } catch (error) {
      console.error(`Error processing issue ${issue.id}:`, error);
      this.recordProcessedIssue(issue.id, moduleKey, issue.title, 'error', null, error.message);
    }
  }

  /**
   * Check for existing PRs that might already fix this issue
   */
  async checkForExistingPR(issue, project, analysis) {
    try {
      console.log("Checking for existing PRs...");

      // Get open PRs
      const openPRs = execSync(`cd ${project.repoPath} && gh pr list --json number,title,url,body --limit 50`, {
        encoding: 'utf8',
        timeout: 30000,
      });

      // Get recently closed PRs (last 30 days)
      const closedPRs = execSync(`cd ${project.repoPath} && gh pr list --state closed --json number,title,url,body,closedAt --limit 50`, {
        encoding: 'utf8',
        timeout: 30000,
      });

      const allPRs = [
        ...JSON.parse(openPRs),
        ...JSON.parse(closedPRs).filter(pr => {
          // Only include PRs closed in last 30 days
          const closedDate = new Date(pr.closedAt);
          const thirtyDaysAgo = new Date(Date.now() - 30 * 24 * 60 * 60 * 1000);
          return closedDate > thirtyDaysAgo;
        })
      ];

      // Extract keywords from Sentry issue for matching
      const keywords = this.extractKeywords(issue, analysis);

      // Search for PRs that might be related
      for (const pr of allPRs) {
        const prText = `${pr.title} ${pr.body || ''}`.toLowerCase();

        // Check if PR mentions similar keywords or error messages
        for (const keyword of keywords) {
          if (prText.includes(keyword.toLowerCase())) {
            console.log(`Found potential match: PR #${pr.number} - ${pr.title}`);
            return {
              number: pr.number,
              title: pr.title,
              url: pr.url,
              matchedKeyword: keyword
            };
          }
        }
      }

      return null;

    } catch (error) {
      console.error("Error checking for existing PRs:", error.message);
      // Don't fail the whole process if PR check fails
      return null;
    }
  }

  /**
   * Extract keywords from Sentry issue for PR matching
   */
  extractKeywords(issue, analysis) {
    const keywords = [];

    // Add issue title words (longer than 4 chars)
    const titleWords = issue.title.split(/\s+/).filter(w => w.length > 4);
    keywords.push(...titleWords);

    // Add root cause keywords
    if (analysis.rootCause) {
      const causeWords = analysis.rootCause.split(/\s+/).filter(w => w.length > 4);
      keywords.push(...causeWords.slice(0, 3)); // Top 3 words
    }

    // Add error type
    if (issue.metadata?.type) {
      keywords.push(issue.metadata.type);
    }

    // Add file paths from fix
    if (analysis.fixFiles) {
      analysis.fixFiles.forEach(f => {
        const filename = f.path.split('/').pop();
        if (filename) keywords.push(filename);
      });
    }

    // Remove duplicates and common words
    const commonWords = ['error', 'undefined', 'null', 'function', 'issue', 'fixed'];
    return [...new Set(keywords)].filter(k => !commonWords.includes(k.toLowerCase()));
  }

  /**
   * Fetch detailed issue information from Sentry
   */
  async fetchIssueDetails(issue) {
    const url = `https://sentry.io/api/0/issues/${issue.id}/`;
    const response = await fetch(url, {
      headers: {
        'Authorization': `Bearer ${this.sentryAuthToken}`,
      },
    });

    if (!response.ok) {
      throw new Error(`Failed to fetch issue details: ${response.status}`);
    }

    const details = await response.json();

    // Add delay after Sentry API call
    await this.sleep(500);

    // Also fetch latest event for more context
    const eventsUrl = `https://sentry.io/api/0/issues/${issue.id}/events/latest/`;
    const eventResponse = await fetch(eventsUrl, {
      headers: {
        'Authorization': `Bearer ${this.sentryAuthToken}`,
      },
    });

    if (eventResponse.ok) {
      details.latestEvent = await eventResponse.json();
      // Add delay after Sentry API call
      await this.sleep(500);
    }

    return details;
  }

  /**
   * Get code context for the issue
   */
  async getCodeContext(issue, project) {
    // Extract file paths from stack trace
    const stackFrames = issue.culprit ? [issue.culprit] : [];

    // Try to read relevant files
    const context = {
      files: [],
      stackTrace: issue.metadata?.value || '',
    };

    // Read files mentioned in stack trace (if accessible)
    for (const frame of stackFrames) {
      try {
        const filePath = path.join(project.repoPath, frame);
        if (fs.existsSync(filePath)) {
          const content = fs.readFileSync(filePath, 'utf8');
          context.files.push({
            path: frame,
            content: content.split('\n').slice(0, 200).join('\n'), // First 200 lines
          });
        }
      } catch (error) {
        // File might not be accessible
      }
    }

    return context;
  }

  /**
   * Retry wrapper for operations that might timeout
   */
  async retryWithTimeout(operation, operationName, maxRetries = 2) {
    for (let attempt = 1; attempt <= maxRetries; attempt++) {
      try {
        console.log(`${operationName} (attempt ${attempt}/${maxRetries})...`);
        return await operation();
      } catch (error) {
        const isTimeout = error.message && (error.message.includes('ETIMEDOUT') || error.message.includes('timeout'));
        const isLastAttempt = attempt === maxRetries;

        if (isTimeout && !isLastAttempt) {
          console.warn(`${operationName} timed out, retrying in 10 seconds...`);
          await new Promise(resolve => setTimeout(resolve, 10000)); // Wait 10s before retry
          continue;
        }

        throw error; // Re-throw on last attempt or non-timeout errors
      }
    }
  }

  /**
   * Analyze issue with Claude Code CLI
   */
  async analyzeWithClaude(issue, details, codeContext, moduleKey) {
    console.log("Analyzing issue with Claude Code CLI...");

    return this.retryWithTimeout(async () => {
      return await this.analyzeWithClaudeInternal(issue, details, codeContext, moduleKey);
    }, 'Claude analysis', 2);
  }

  /**
   * Internal Claude analysis (retryable) - uses Task agents
   */
  async analyzeWithClaudeInternal(issue, details, codeContext, moduleKey) {
    const prompt = `IMPORTANT: Use Task agents to thoroughly analyze this Sentry production error. Use the Explore agent to find relevant code in the repository.

⏱️ **TIME CONSTRAINT: Complete this analysis within 10 minutes. Prioritize accuracy and completeness, but work efficiently.**

**Your task:**
1. Use Task tool with Explore agent to find code related to this error (be focused, not exhaustive)
2. Analyze the root cause using all available context
3. Create a reproducing test case
4. Propose a fix with specific file changes

**Module:** ${moduleKey}
**Repository Path:** Based on module (php: iznik-server, go: iznik-server-go, nuxt3/capacitor/modtools: iznik-nuxt3*)
**Issue:** ${issue.title}
**Event Count (24h):** ${issue.count}
**Level:** ${issue.level}

**Error Message:**
${details.metadata?.value || issue.title}

**Stack Trace:**
${JSON.stringify(details.latestEvent?.entries?.find(e => e.type === 'exception'), null, 2)}

**Initial Code Context:**
${JSON.stringify(codeContext, null, 2)}

**Instructions:**
- Use Task tool to launch Explore agent for finding relevant code (focus on files in stack trace)
- Search for specific files mentioned in stack trace first
- Examine related code efficiently - prioritize depth over breadth
- Analyze the error deeply but quickly
- Work within the 10-minute time constraint

**Output Format (JSON only, no markdown):**
{
  "rootCause": "Brief explanation of what's causing the error",
  "canReproduce": true/false,
  "testCase": "Complete test code that reproduces the issue (if canReproduce=true)",
  "testFile": "Path where test should be created (e.g., 'test/ut/php/include/BugTest.php')",
  "fix": "High-level explanation of the fix",
  "fixFiles": [
    {
      "path": "relative/path/to/file.php",
      "changes": [
        {
          "type": "replace",
          "lines": "111-111",
          "old": "exact code to find and replace (including whitespace)",
          "new": "exact replacement code (including whitespace)"
        }
      ]
    }
  ],
  "reason": "Explanation if canReproduce=false"
}

**IMPORTANT for fixFiles format:**
- Each change must have type "replace" with exact "old" and "new" code snippets
- Include enough context in "old" to make it unique in the file
- Preserve exact indentation and whitespace in both "old" and "new"
- The "lines" field is for reference only - actual replacement uses "old" code matching

CRITICAL: Your final message MUST be valid JSON only (no markdown, no explanation).`;

    // Write prompt to temp file to avoid shell escaping issues
    const tempPromptFile = `/tmp/sentry-prompt-${Date.now()}.txt`;
    fs.writeFileSync(tempPromptFile, prompt);

    try {
      // Invoke Claude Code CLI with -p flag to enable Task agents
      console.log("Invoking Claude Code with Task agents (max 10 minutes)...");

      // Only use --dangerously-skip-permissions if not running as root
      const isRoot = process.getuid && process.getuid() === 0;
      const skipPermissionsFlag = isRoot ? '' : '--dangerously-skip-permissions';
      if (isRoot) {
        console.log("Running as root - Claude will prompt for permissions");
      }

      const response = execSync(`claude -p "$(cat ${tempPromptFile})" ${skipPermissionsFlag}`, {
        encoding: 'utf8',
        maxBuffer: 20 * 1024 * 1024, // 20MB buffer for Task agent outputs
        timeout: 900000, // 15 minute timeout (Task agents need more time)
        killSignal: 'SIGTERM',
        cwd: process.cwd(),
      });

      // Clean up temp file
      fs.unlinkSync(tempPromptFile);

      console.log("Claude Code response received (with Task agent analysis)");

      // Extract JSON from response (handle markdown code blocks)
      const jsonMatch = response.match(/```json\n([\s\S]+?)\n```/) || response.match(/\{[\s\S]+\}/);
      if (!jsonMatch) {
        throw new Error("Could not parse Claude response as JSON");
      }

      const analysis = JSON.parse(jsonMatch[1] || jsonMatch[0]);
      console.log("Claude analysis complete:", analysis.canReproduce ? "Can reproduce" : "Cannot reproduce");

      return analysis;
    } catch (error) {
      console.error("Error invoking Claude Code CLI:", error.message);

      // If Claude Code CLI fails, provide a fallback response
      throw new Error(`Claude Code CLI failed: ${error.message}. Make sure 'claude' CLI is installed and configured.`);
    }
  }

  /**
   * Apply fix and validate with generated test case
   */
  async applyFix(analysis, project, moduleKey) {
    console.log("Applying fix and running generated test case locally...");

    const branchName = `sentry-auto-fix-${Date.now()}`;

    try {
      // Create branch in the repository
      execSync(`cd ${project.repoPath} && git checkout -b ${branchName}`, {
        encoding: "utf8",
        timeout: 10000,
      });

      // Apply the test case first
      if (analysis.testCase && analysis.testFile) {
        const testPath = path.join(project.repoPath, analysis.testFile);
        const testDir = path.dirname(testPath);

        // Ensure directory exists
        if (!fs.existsSync(testDir)) {
          fs.mkdirSync(testDir, { recursive: true });
        }

        fs.writeFileSync(testPath, analysis.testCase);
        console.log(`Created test file: ${analysis.testFile}`);

        // Run just the generated test case to verify it reproduces the issue
        console.log("Running generated test case to verify reproduction...");
        const testResult = await this.runSingleTest(analysis.testFile, project, moduleKey);

        if (!testResult.reproduced) {
          console.warn("Generated test does not reproduce the issue");
          return {
            success: false,
            branchName,
            testOutput: testResult.output,
            error: "Test case does not reproduce the issue",
          };
        }

        console.log("✓ Test case successfully reproduces the issue");
      }

      // Apply the fix files
      for (const fixFile of analysis.fixFiles || []) {
        // Strip repository name prefix if present (e.g., "iznik-server/include/..." -> "include/...")
        let cleanPath = fixFile.path;
        const repoNamePrefixes = ['iznik-server/', 'iznik-server-go/', 'iznik-nuxt3/'];
        for (const prefix of repoNamePrefixes) {
          if (cleanPath.startsWith(prefix)) {
            cleanPath = cleanPath.substring(prefix.length);
            break;
          }
        }

        const filePath = path.join(project.repoPath, cleanPath);

        // Read current content
        if (fs.existsSync(filePath)) {
          console.log(`Applying fix to: ${cleanPath}`);
          let content = fs.readFileSync(filePath, 'utf8');

          // Apply each change in the fix file
          for (const change of fixFile.changes || []) {
            if (change.type === 'replace') {
              // Replace old code with new code
              if (content.includes(change.old)) {
                content = content.replace(change.old, change.new);
                console.log(`  ✓ Applied change at lines ${change.lines}`);
              } else {
                console.warn(`  ⚠ Could not find code to replace at lines ${change.lines}`);
              }
            } else if (change.type === 'insert') {
              // Insert new code at specified location
              const lines = content.split('\n');
              const lineNumber = parseInt(change.line) - 1; // Convert to 0-indexed
              if (lineNumber >= 0 && lineNumber < lines.length) {
                lines.splice(lineNumber, 0, change.code);
                content = lines.join('\n');
                console.log(`  ✓ Inserted code at line ${change.line}`);
              } else {
                console.warn(`  ⚠ Invalid line number ${change.line} for insertion`);
              }
            }
          }

          // Write the modified content back
          fs.writeFileSync(filePath, content);
          console.log(`  ✓ File updated: ${cleanPath}`);
        } else {
          console.warn(`  ⚠ File not found: ${cleanPath}`);
        }
      }

      // Run the test again to verify the fix works
      if (analysis.testFile) {
        console.log("Running test case again to verify fix...");
        const fixTestResult = await this.runSingleTest(analysis.testFile, project, moduleKey);

        return {
          success: fixTestResult.passed,
          branchName,
          testOutput: fixTestResult.output,
          testPassed: fixTestResult.passed,
        };
      }

      return {
        success: true,
        branchName,
        testOutput: "No test case to validate",
      };

    } catch (error) {
      console.error("Error applying fix:", error);
      return {
        success: false,
        branchName,
        error: error.message,
      };
    }
  }

  /**
   * Run a single test file in Docker
   */
  async runSingleTest(testFile, project, moduleKey) {
    try {
      let command;
      let containerName;

      // Determine test command based on module
      if (moduleKey === 'php') {
        containerName = 'freegle-apiv1';
        command = `docker exec ${containerName} vendor/bin/phpunit ${testFile}`;
      } else if (moduleKey === 'go') {
        containerName = 'freegle-apiv2';
        command = `docker exec ${containerName} go test -run ${path.basename(testFile, path.extname(testFile))}`;
      } else {
        // Playwright tests for nuxt3/modtools
        containerName = 'freegle-playwright';
        command = `docker exec ${containerName} npx playwright test ${testFile}`;
      }

      console.log(`Running: ${command}`);

      const output = execSync(command, {
        encoding: 'utf8',
        timeout: 120000, // 2 minute timeout for single test
      });

      // Check if test passed or failed
      const passed = !output.toLowerCase().includes('fail') && !output.toLowerCase().includes('error');
      const reproduced = !passed; // If test fails, it reproduced the issue

      return {
        passed,
        reproduced,
        output,
      };

    } catch (error) {
      // Test failed (which means it reproduced the issue before fix)
      return {
        passed: false,
        reproduced: true,
        output: error.stdout || error.message,
      };
    }
  }

  /**
   * Run tests via status API
   */
  async runTests(testCommand) {
    try {
      const output = execSync(testCommand, {
        encoding: "utf8",
        timeout: 300000, // 5 minute timeout
      });

      // Poll for test results
      // This is simplified - in reality you'd poll the appropriate status endpoint

      return {
        success: true,
        output,
      };
    } catch (error) {
      return {
        success: false,
        output: error.stdout || error.message,
      };
    }
  }

  /**
   * Create PR via GitHub CLI
   */
  async createPR(analysis, project, moduleKey, fixResult) {
    console.log("Creating PR...");

    const prBody = `## Automated Fix for Sentry Issue

**Root Cause:** ${analysis.rootCause}

**Changes:**
${analysis.fixFiles.map(f => `- ${f.path}: ${f.changes}`).join('\n')}

**Test Case:** ${analysis.testFile || 'Included'}

**Note:** This fix was generated automatically. Tests will run on CircleCI - please review results before merging.

**Sentry Issue:** [View on Sentry](#)

---
🤖 This PR was automatically generated by the Sentry integration system.`;

    try {
      // Push branch to remote first (required for PR creation)
      const currentBranch = execSync(`cd ${project.repoPath} && git branch --show-current`, {
        encoding: "utf8",
      }).trim();

      execSync(`cd ${project.repoPath} && git push -u origin ${currentBranch}`, {
        encoding: "utf8",
        timeout: 60000,
      });

      // Write PR body to temp file to avoid shell escaping issues
      const tempPrBodyFile = `/tmp/sentry-pr-body-${Date.now()}.txt`;
      fs.writeFileSync(tempPrBodyFile, prBody);

      const prUrl = execSync(`cd ${project.repoPath} && gh pr create --title "Fix: ${analysis.rootCause.substring(0, 60)}" --body-file "${tempPrBodyFile}"`, {
        encoding: "utf8",
        timeout: 30000,
      }).trim();

      // Clean up temp file
      fs.unlinkSync(tempPrBodyFile);

      console.log(`PR created: ${prUrl}`);
      fixResult.prUrl = prUrl;

      return prUrl;
    } catch (error) {
      console.error("Failed to create PR:", error);
      throw error;
    }
  }

  /**
   * Create draft PR when tests fail
   */
  async createDraftPR(analysis, project, moduleKey, fixResult) {
    console.log("Creating draft PR (tests failed)...");

    const prBody = `## Automated Fix Attempt for Sentry Issue (⚠️ Tests Failed)

**Root Cause:** ${analysis.rootCause}

**Attempted Changes:**
${analysis.fixFiles.map(f => `- ${f.path}: ${f.changes}`).join('\n')}

**Test Results:** ❌ Tests failed
\`\`\`
${fixResult.testOutput || fixResult.error}
\`\`\`

**Note:** This is an automated fix attempt. The reproducing test case was created successfully,
but the proposed fix did not pass all tests. Please review and adjust.

---
🤖 This draft PR was automatically generated by the Sentry integration system.`;

    try {
      // Push branch to remote first (required for PR creation)
      const currentBranch = execSync(`cd ${project.repoPath} && git branch --show-current`, {
        encoding: "utf8",
      }).trim();

      execSync(`cd ${project.repoPath} && git push -u origin ${currentBranch}`, {
        encoding: "utf8",
        timeout: 60000,
      });

      // Write PR body to temp file to avoid shell escaping issues
      const tempPrBodyFile = `/tmp/sentry-pr-body-draft-${Date.now()}.txt`;
      fs.writeFileSync(tempPrBodyFile, prBody);

      const prUrl = execSync(`cd ${project.repoPath} && gh pr create --draft --title "[DRAFT] Fix attempt: ${analysis.rootCause.substring(0, 50)}" --body-file "${tempPrBodyFile}"`, {
        encoding: "utf8",
        timeout: 30000,
      }).trim();

      // Clean up temp file
      fs.unlinkSync(tempPrBodyFile);

      console.log(`Draft PR created: ${prUrl}`);
      fixResult.prUrl = prUrl;

      return prUrl;
    } catch (error) {
      console.error("Failed to create draft PR:", error);
      throw error;
    }
  }

  /**
   * Check if issue is already being processed by another instance
   */
  async checkIfAlreadyProcessing(issueId) {
    try {
      // Fetch existing notes
      const url = `https://sentry.io/api/0/issues/${issueId}/notes/`;
      const response = await fetch(url, {
        headers: {
          'Authorization': `Bearer ${this.sentryAuthToken}`,
        },
      });

      if (!response.ok) {
        console.warn(`Could not fetch notes for issue ${issueId}: ${response.status}`);
        return false;
      }

      const notes = await response.json();

      // Add delay after Sentry API call
      await this.sleep(500);

      // Look for recent automation markers
      const automationMarker = '🤖 **Automated fix in progress**';
      const staleThresholdMinutes = 30; // Consider stale after 30 minutes

      for (const note of notes) {
        if (note.data && note.data.text && note.data.text.includes(automationMarker)) {
          // Found an automation marker - check if it's stale
          const noteDate = new Date(note.dateCreated);
          const now = new Date();
          const ageMinutes = (now - noteDate) / 1000 / 60;

          if (ageMinutes < staleThresholdMinutes) {
            // Fresh marker - another instance is processing
            return {
              fresh: true,
              timeAgo: `${Math.floor(ageMinutes)} minutes ago`,
              noteDate: note.dateCreated
            };
          } else {
            // Stale marker - previous instance probably crashed
            console.log(`Found stale automation marker (${Math.floor(ageMinutes)} minutes old) - proceeding`);
            return false;
          }
        }
      }

      return false;

    } catch (error) {
      console.error(`Error checking for existing processing: ${error.message}`);
      // If we can't check, proceed anyway to avoid blocking
      return false;
    }
  }


  /**
   * Get status of currently processing issues
   */
  getStatus() {
    const active = Array.from(this.activeProcessing.entries()).map(([id, info]) => ({
      issueId: id,
      module: info.module,
      duration: Math.floor((Date.now() - info.startTime) / 1000),
    }));

    const stats = this.getProcessedStats();

    return {
      processed: stats.total,
      processedByStatus: stats.byStatus,
      activeProcessing: active,
    };
  }

  /**
   * Get recent errors (last 10) with details
   */
  getRecentErrors() {
    const errors = this.db.prepare(`
      SELECT
        issue_id,
        module,
        title,
        error_message,
        attempts,
        last_processed_at
      FROM processed_issues
      WHERE status = 'error' OR status = 'failed'
      ORDER BY last_processed_at DESC
      LIMIT 10
    `).all();

    return errors;
  }
}

module.exports = SentryIntegration;
