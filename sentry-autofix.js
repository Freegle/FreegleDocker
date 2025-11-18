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

    // Store original branch so we can restore it after processing
    const originalBranch = execSync(`cd ${project.repoPath} && git rev-parse --abbrev-ref HEAD`, {
      encoding: 'utf8',
      timeout: 5000,
    }).trim();

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

      if (!analysis.canFix) {
        console.log(`Skipping - cannot fix: ${analysis.reason}`);
        this.recordProcessedIssue(issue.id, moduleKey, issue.title, 'skipped', null, analysis.reason);
        return;
      }

      if (analysis.confidence !== 'high') {
        console.log(`Skipping - confidence too low (${analysis.confidence}): ${analysis.reason}`);
        this.recordProcessedIssue(issue.id, moduleKey, issue.title, 'skipped', null, `Low confidence: ${analysis.reason}`);
        return;
      }

      if (analysis.fixType !== 'simple') {
        console.log(`Skipping - fix is not simple (${analysis.fixType}): ${analysis.reason}`);
        this.recordProcessedIssue(issue.id, moduleKey, issue.title, 'skipped', null, `Complex fix: ${analysis.reason}`);
        return;
      }

      console.log("Fix is simple and high confidence. Proceeding...");
      console.log("Checking for existing PRs...");

      // Check for existing PRs that might already fix this
      const existingPR = await this.checkForExistingPR(issue, project, analysis);

      // Apply the fix (no tests, just the code change)
      const fixResult = await this.applyFix(analysis, project, moduleKey, existingPR, issue);

      // Check if fix was applied successfully
      if (!fixResult.success) {
        console.log(`❌ Fix failed to apply: ${fixResult.error}`);
        this.recordProcessedIssue(issue.id, moduleKey, issue.title, 'error', null, `Failed to apply fix: ${fixResult.error}`);
        return;
      }

      // Create or update PR
      if (existingPR) {
        console.log(`✅ Updated existing PR #${existingPR.number}: ${existingPR.url}`);
        this.recordProcessedIssue(issue.id, moduleKey, issue.title, 'updated', existingPR.url, 'Updated existing PR with suggested fix');
      } else {
        console.log("Creating PR with suggested fix.");
        await this.createPR(analysis, project, moduleKey, fixResult);
        this.recordProcessedIssue(issue.id, moduleKey, issue.title, 'success', fixResult.prUrl);
      }

      console.log(`✅ Successfully processed issue ${issue.id}`);

    } catch (error) {
      console.error(`Error processing issue ${issue.id}:`, error);
      this.recordProcessedIssue(issue.id, moduleKey, issue.title, 'error', null, error.message);
    } finally {
      // Always restore original branch
      try {
        console.log(`Restoring original branch: ${originalBranch}`);
        execSync(`cd ${project.repoPath} && git checkout ${originalBranch}`, {
          encoding: 'utf8',
          timeout: 5000,
        });
      } catch (error) {
        console.error(`Warning: Could not restore original branch ${originalBranch}:`, error.message);
      }
    }
  }

  /**
   * Check for existing PRs that might already fix this issue
   */
  async checkForExistingPR(issue, project, analysis) {
    try {
      console.log("Checking for existing PRs...");

      // Only get OPEN PRs (we don't want to update closed ones)
      const openPRsJson = execSync(`cd ${project.repoPath} && gh pr list --json number,title,url,body,headRefName,state --limit 50`, {
        encoding: 'utf8',
        timeout: 30000,
      });

      const openPRs = JSON.parse(openPRsJson);

      // Extract keywords from Sentry issue for matching
      const keywords = this.extractKeywords(issue, analysis);

      // Search for OPEN PRs that might be related
      for (const pr of openPRs) {
        const prText = `${pr.title} ${pr.body || ''}`.toLowerCase();

        // Check if PR mentions similar keywords or error messages
        for (const keyword of keywords) {
          if (prText.includes(keyword.toLowerCase())) {
            console.log(`Found potential match: OPEN PR #${pr.number} - ${pr.title}`);
            return {
              number: pr.number,
              title: pr.title,
              url: pr.url,
              branchName: pr.headRefName,
              state: pr.state,
              matchedKeyword: keyword,
              isOpen: true
            };
          }
        }
      }

      // No open PRs found - check closed PRs for reference only (won't update them)
      const closedPRsJson = execSync(`cd ${project.repoPath} && gh pr list --state closed --json number,title,url,body,closedAt --limit 50`, {
        encoding: 'utf8',
        timeout: 30000,
      });

      const closedPRs = JSON.parse(closedPRsJson).filter(pr => {
        // Only check PRs closed in last 30 days
        const closedDate = new Date(pr.closedAt);
        const thirtyDaysAgo = new Date(Date.now() - 30 * 24 * 60 * 60 * 1000);
        return closedDate > thirtyDaysAgo;
      });

      for (const pr of closedPRs) {
        const prText = `${pr.title} ${pr.body || ''}`.toLowerCase();

        for (const keyword of keywords) {
          if (prText.includes(keyword.toLowerCase())) {
            console.log(`Found CLOSED PR #${pr.number} (will create new PR instead): ${pr.title}`);
            // Return null so we create a new PR instead of updating the closed one
            return null;
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
    const prompt = `IMPORTANT: Use Task agents to diagnose this Sentry production error. Use the Explore agent to find relevant code in the repository.

⏱️ **TIME CONSTRAINT: Complete this analysis within 10 minutes. Work efficiently.**

**Your task:**
1. Use Task tool with Explore agent to find code related to this error
2. Analyze the root cause
3. Determine if this is a SIMPLE fix (missing parameter, typo, null check within a single method)
4. If simple, propose a fix. If complex, skip.

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

**ONLY PROCEED WITH FIX IF:**
- The error is caused by something obvious: missing parameter, typo, missing null check
- The fix is within a SINGLE METHOD (no multi-file refactoring)
- You are 90%+ confident the fix will work
- Examples: array_key_exists() null parameter → add null check, missing function argument → add default, typo in variable name → fix typo

**SKIP IF:**
- Error requires understanding complex business logic
- Fix needs changes across multiple methods or files
- Root cause is unclear or requires deep investigation
- You're less than 90% confident

**Output Format (JSON only, no markdown):**
{
  "rootCause": "Brief explanation of what's causing the error",
  "canFix": true/false,
  "confidence": "high/low (high = 90%+ confident fix will work)",
  "fixType": "simple/complex (simple = single method, obvious fix)",
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
  "reason": "Explanation if canFix=false or confidence=low"
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
      console.log("Claude analysis complete:",
        analysis.canFix ? `Can fix (confidence: ${analysis.confidence}, type: ${analysis.fixType})` : `Cannot fix (${analysis.reason})`);

      return analysis;
    } catch (error) {
      console.error("Error invoking Claude Code CLI:", error.message);

      // If Claude Code CLI fails, provide a fallback response
      throw new Error(`Claude Code CLI failed: ${error.message}. Make sure 'claude' CLI is installed and configured.`);
    }
  }


  /**
   * Apply code changes for the fix and create PR
   */
  async applyFix(analysis, project, moduleKey, existingPR = null, issue = null) {
    console.log("Applying suggested fix...");

    let branchName;

    try {
      if (existingPR) {
        console.log(`Found existing PR #${existingPR.number}, resetting branch to master: ${existingPR.branchName}`);
        branchName = existingPR.branchName;
        execSync(`cd ${project.repoPath} && git fetch origin ${branchName} && git checkout ${branchName} && git reset --hard master`, {
          encoding: "utf8",
          timeout: 10000,
        });
      } else {
        branchName = `sentry-auto-fix-${Date.now()}`;
        // Create branch from origin/master to avoid including local uncommitted work
        execSync(`cd ${project.repoPath} && git fetch origin master && git checkout -b ${branchName} origin/master`, {
          encoding: "utf8",
          timeout: 10000,
        });
      }

      // Apply the fix
      console.log("Applying fix...");
      for (const fileChange of analysis.fixFiles) {
        // Normalize the path - remove any duplicate repo name prefix
        // (Claude sometimes includes the repo name in the path)
        let normalizedPath = fileChange.path;
        const repoName = path.basename(project.repoPath);
        if (normalizedPath.startsWith(repoName + '/') || normalizedPath.startsWith(repoName + '\\')) {
          normalizedPath = normalizedPath.substring(repoName.length + 1);
        }

        const filePath = path.join(project.repoPath, normalizedPath);

        if (!fs.existsSync(filePath)) {
          throw new Error(`File not found: ${filePath}`);
        }

        let fileContent = fs.readFileSync(filePath, 'utf8');

        for (const change of fileChange.changes) {
          if (change.type === 'replace') {
            const oldCode = change.old;
            const newCode = change.new;

            if (!fileContent.includes(oldCode)) {
              console.warn(`⚠ Could not find exact match for old code in ${fileChange.path} at lines ${change.lines}`);
              console.warn(`Looking for:\n${oldCode}`);
              throw new Error(`Could not find exact code to replace in ${fileChange.path}`);
            }

            fileContent = fileContent.replace(oldCode, newCode);
            console.log(`  ✓ Applied change at lines ${change.lines}`);
          }
        }

        fs.writeFileSync(filePath, fileContent);
        console.log(`  ✓ File updated: ${fileChange.path}`);
      }

      // Commit and push
      const commitMessage = `Suggested fix for Sentry issue: ${issue.title}\n\nRoot cause: ${analysis.rootCause}\nFix: ${analysis.fix}\n\nConfidence: ${analysis.confidence}\nFix type: ${analysis.fixType}\n\nSentry issue: ${issue.permalink}`;

      execSync(`cd ${project.repoPath} && git add .`, {
        encoding: "utf8",
        timeout: 10000,
      });

      execSync(`cd ${project.repoPath} && git commit -m "${commitMessage.replace(/"/g, '\\"')}"`, {
        encoding: "utf8",
        timeout: 10000,
      });

      execSync(`cd ${project.repoPath} && git push -u origin ${branchName}`, {
        encoding: "utf8",
        timeout: 30000,
      });

      console.log(`✓ Pushed fix to branch: ${branchName}`);

      return {
        success: true,
        branchName,
        existingPR: existingPR,
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
