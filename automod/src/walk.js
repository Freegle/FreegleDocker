// Loads chart.json, validates it, and walks it for one review request at a
// time. The chart itself decides the questions; this file only knows how to
// read a 'check' object and turn it into a yes/no answer, then advance the
// ai-flower engine to whichever transition matches that answer.
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { WorkflowEngine, TransitionValidator, MemoryStorage } from 'ai-flower';
import { postText, keywordFlags } from './prompt.js';
import { newAccount } from './backend.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DEFAULT_CHART_PATH = path.join(__dirname, '..', 'chart.json');

/** How much of an error message is kept on a step and in the log. */
const ERROR_CHARS = 200;

/**
 * Read chart.json (or an explicit path, used by tests) and validate it.
 * Throws on any structural problem rather than returning a broken chart.
 */
export function loadChart(chartPath = DEFAULT_CHART_PATH) {
  const raw = readFileSync(chartPath, 'utf8');
  const workflow = JSON.parse(raw);
  validateChart(workflow);
  return workflow;
}

/**
 * ai-flower's own validateDefinition() checks states/transitions reference
 * each other correctly. It does not know about this chart's own convention
 * (a 'check' object on every tool node, exactly one yes and one no
 * transition per tool node) or that every path must actually end, so those
 * are checked here as well.
 */
export function validateChart(workflow) {
  const validator = new TransitionValidator(workflow);
  const result = validator.validateDefinition();
  if (!result.valid) {
    throw new Error(`Invalid chart: ${result.errors.map((e) => e.message).join('; ')}`);
  }

  for (const [id, state] of Object.entries(workflow.states)) {
    if (state.nodeType !== 'tool') continue;

    if (!state.check || (state.check.kind !== 'fact' && state.check.kind !== 'text')) {
      throw new Error(`Invalid chart: tool node '${id}' has no valid check.kind`);
    }
    if (state.check.kind === 'fact' && !state.check.fact) {
      throw new Error(`Invalid chart: fact node '${id}' has no check.fact`);
    }
    if (state.check.kind === 'text' && !state.check.question) {
      throw new Error(`Invalid chart: text node '${id}' has no check.question`);
    }

    const outgoing = workflow.transitions.filter((t) => t.from === id);
    const yes = outgoing.filter((t) => t.metadata?.answer === 'yes');
    const no = outgoing.filter((t) => t.metadata?.answer === 'no');
    if (yes.length !== 1 || no.length !== 1) {
      throw new Error(
        `Invalid chart: tool node '${id}' must have exactly one yes and one no transition ` +
          `(found ${yes.length} yes, ${no.length} no)`,
      );
    }
  }

  // A state is only usable if the walk can actually get to it from the
  // start (reachableFromStart) and, once there, can get from it to an end
  // (canReachEnd). Checking canReachEnd alone misses a state that has been
  // cut off upstream: its own outgoing edges may still lead to an end, but
  // nothing walking the chart from START will ever arrive at it.
  const reachableFromStart = reachableFrom(workflow, workflow.initialState);
  for (const id of Object.keys(workflow.states)) {
    if (!reachableFromStart.has(id) || !canReachEnd(workflow, id, new Set())) {
      throw new Error(`Invalid chart: state '${id}' has no path to an end node`);
    }
  }
}

function reachableFrom(workflow, startId) {
  const seen = new Set();
  const stack = [startId];
  while (stack.length > 0) {
    const id = stack.pop();
    if (seen.has(id)) continue;
    seen.add(id);
    for (const t of workflow.transitions.filter((t) => t.from === id)) {
      stack.push(t.to);
    }
  }
  return seen;
}

function canReachEnd(workflow, stateId, seenOnThisPath) {
  const state = workflow.states[stateId];
  if (!state) return false;
  if (state.nodeType === 'end') return true;
  if (seenOnThisPath.has(stateId)) return false; // looped back without reaching an end
  const nextSeen = new Set(seenOnThisPath).add(stateId);
  const outgoing = workflow.transitions.filter((t) => t.from === stateId);
  return outgoing.some((t) => canReachEnd(workflow, t.to, nextSeen));
}

/**
 * Build a reviewer bound to one backend. `backend.ask(question, text, opts)` must resolve to
 * `{ p, model }`, and may add `answer` (a verdict backend's own decision, which wins over the
 * threshold) and `evidence` (see backend.js). Everything else about a check - fact lookup,
 * rule gating, when-skips, keyword flags, hints from Freegle's own checks - is decided here,
 * so a backend only ever has to answer the narrow text questions.
 *
 * A request may carry `deadline_ms`: how long the caller will wait. No model call starts after
 * that and one in flight is cut off, so the caller's timeout and the service's agree.
 *
 * The response carries, beside the verdict and the path, what the decision cost and how it
 * may be reproduced: `features` (what the model extracted from the post), `ms`, `calls`
 * (model calls made), `model`, `tokens`, `cost_usd` when a backend knows it, `cache_hit`, and
 * `prompt_sha`/`reply_sha` of the batched call. Each step carries `ms`, and `error` when the
 * backend could not answer it.
 */
export function createReviewer({ backend, chartPath } = {}) {
  const chart = loadChart(chartPath);
  const textNodes = Object.entries(chart.states).filter(([, s]) => s.check?.kind === 'text');
  backend?.setQuestions?.(textNodes.map(([, s]) => ({ question: s.check.question, flags: s.check.flags || [] })));
  const engine = new WorkflowEngine({
    workflow: chart,
    storageAdapter: new MemoryStorage(),
  });

  async function review(request) {
    const started = Date.now();
    const { msgid, groupid, subject, body, type, facts = {}, rules = {}, backend: backendName, deadline_ms } = request;
    const deadline = Number.isFinite(deadline_ms) && deadline_ms > 0 ? started + deadline_ms : undefined;
    const acct = newAccount();
    const text = postText({ type, subject, body });
    const flags = keywordFlags(text);
    // Findings from Freegle's own checks that bear on a text question, told to the model once.
    const hints = textNodes
      .filter(([, s]) => s.check.hint && facts[s.check.hint] === true)
      .map(([, s]) => `${s.description} ${facts[`${s.check.hint}_detail`] ? `(${facts[`${s.check.hint}_detail`]})` : ''}`.trim());

    let instance = await engine.createInstance({ msgid, groupid, subject, body, type, facts, rules });

    // START has a single unconditional transition to the first real node.
    // createInstance() does not auto-follow unconditional transitions (only
    // triggerTransition does, once it has moved somewhere), so take it
    // explicitly before the walk begins.
    if (instance.currentState === chart.initialState) {
      const startTransition = chart.transitions.find((t) => t.from === chart.initialState);
      const result = await engine.triggerTransition(instance.id, startTransition.to);
      instance = result.instance;
    }

    const path = [];
    const finish = (fields) => ({
      chart: chart.id,
      version: chart.version,
      ...fields,
      path,
      features: acct.features,
      ms: Date.now() - started,
      calls: acct.calls,
      model: acct.model,
      tokens: acct.tokens,
      cost_usd: acct.cost_usd,
      cache_hit: acct.cache_hit,
      prompt_sha: acct.prompt_sha,
      reply_sha: acct.reply_sha,
    });

    while (chart.states[instance.currentState].nodeType === 'tool') {
      const nodeId = instance.currentState;
      const node = chart.states[nodeId];
      const step = await evaluateCheck(nodeId, node, { facts, rules, text, flags, hints, request, backend, backendName, acct, deadline });
      path.push(step);

      // A model that could not answer holds the post, and says so rather than borrowing the
      // question's own hold reason.
      if (step.model === 'unavailable') {
        return finish({
          verdict: 'hold',
          end: 'UNAVAILABLE',
          reason: 'Automated review could not answer a question, so the post waits for a moderator.',
        });
      }

      const transition = chart.transitions.find(
        (t) => t.from === nodeId && t.metadata?.answer === step.answer,
      );
      const result = await engine.triggerTransition(instance.id, transition.to);
      instance = result.instance;
    }

    const endId = instance.currentState;
    const endState = chart.states[endId];

    return finish({
      verdict: endId === 'APPROVE' ? 'approve' : 'hold',
      end: endId,
      reason: endState.description,
    });
  }

  return { review, chart, engine };
}

// Each step records the node's short description as its question: that is what a moderator
// reads in ModTools. check.question is the fuller wording, with Freegle's rule, that the model
// is asked.
async function evaluateCheck(nodeId, node, { facts, rules, text, flags, hints, request, backend, backendName, acct, deadline }) {
  const started = Date.now();
  const check = node.check;
  const base = { node: nodeId, question: node.description, kind: check.kind };
  const timed = (step) => ({ ...step, ms: Date.now() - started });

  // The backend could not answer: hold, keep the cause on the step, and say so on stderr, since
  // a service that holds every post with no word of why cannot be put right.
  const unavailable = (err) => {
    const error = String(err?.message || err || 'unknown error').slice(0, ERROR_CHARS);
    console.error(JSON.stringify({
      ts: new Date().toISOString(),
      event: 'automod_backend_error',
      msgid: request.msgid,
      groupid: request.groupid,
      node: nodeId,
      backend: backendName || null,
      error,
    }));
    return timed({ ...base, answer: 'yes', p: 1, threshold: check.threshold, model: 'unavailable', evidence: '', error });
  };

  // A question that only applies where the community has the rule switched on.
  if (check.requiresRule && rules[check.requiresRule] !== true) {
    return timed({ ...base, answer: 'no', threshold: check.threshold, model: 'rule', evidence: 'This community does not restrict it' });
  }

  if (check.kind === 'fact') {
    const value = facts[check.fact] === true;
    return timed({
      ...base,
      answer: value ? 'yes' : 'no',
      model: 'fact',
      // The batch's own detail when it has one (which note, which finding); else none.
      evidence: facts[`${check.fact}_detail`] || '',
    });
  }

  // check.kind === 'text'
  if (check.rule && rules[check.rule] === true) {
    return timed({ ...base, answer: 'no', threshold: check.threshold, model: 'rule', evidence: 'This community allows it' });
  }

  if (check.when && facts[check.when] !== true) {
    return timed({ ...base, answer: 'no', threshold: check.threshold, model: 'skipped', evidence: 'Does not apply to this post' });
  }

  const flagged = (check.flags || []).flatMap((category) => flags[category] || []);
  const context = check.context && request[check.context] ? contextText(check.context, request[check.context]) : undefined;

  let result;
  try {
    result = await backend.ask(check.question, text, { flags: check.flags, flagged, hints, context, backendName, acct, deadline });
  } catch (err) {
    return unavailable(err);
  }

  const { p, model, evidence } = result || {};
  let answer = result?.answer;
  if (answer !== 'yes' && answer !== 'no') {
    // No stated answer and no probability is not a "no": a reply the backend could not
    // make sense of must hold, never let a post through.
    if (typeof p !== 'number' || Number.isNaN(p)) {
      return unavailable(new Error('backend gave neither an answer nor a probability'));
    }
    answer = p >= check.threshold ? 'yes' : 'no';
  }
  const flagNote = flagged.length ? `Contains ${flagged.map((w) => `"${w}"`).join(', ')}` : '';

  return timed({
    ...base,
    answer,
    p,
    threshold: check.threshold,
    model,
    evidence: [evidence, flagNote].filter(Boolean).join('. '),
  });
}

// Extra text a question needs, from the request, put into words for the model.
function contextText(field, value) {
  if (field === 'other_posts') {
    const list = Array.isArray(value) ? value : [value];
    return `The member's other open posts on this community:\n${list.map((p) => `- ${p}`).join('\n')}`;
  }
  return String(value);
}
