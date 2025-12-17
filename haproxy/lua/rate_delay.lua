-- HAProxy Lua script for per-user rate limiting with graduated delays
-- Instead of rejecting requests, delays responses for heavy users

-- Configuration
local config = {
    -- Requests per second thresholds
    soft_limit = 5,      -- Start delaying above this
    hard_limit = 20,     -- Maximum delay at this rate

    -- Delay settings (milliseconds)
    min_delay_ms = 100,  -- Minimum delay when over soft limit
    max_delay_ms = 2000, -- Maximum delay for worst offenders

    -- Safety limits
    max_concurrent_delays = 500,  -- Don't delay more than this many at once
}

-- Track concurrent delays (approximate - not perfectly thread-safe but good enough)
local concurrent_delays = 0

-- Get user identifier from request
-- Priority: X-User-Id header > JWT user ID > IP address
local function get_user_id(txn)
    -- Try X-User-Id header (set by backend or auth)
    local user_id = txn.sf:req_hdr("X-User-Id")
    if user_id and user_id ~= "" then
        return "user:" .. user_id
    end

    -- Fall back to source IP
    local ip = txn.sf:src()
    return "ip:" .. ip
end

-- Calculate delay based on request rate
-- Returns delay in milliseconds, or 0 if no delay needed
local function calculate_delay(rate)
    -- Ensure rate is a number
    rate = tonumber(rate) or 0

    if rate <= config.soft_limit then
        return 0
    end

    -- Linear interpolation between soft and hard limits
    local excess = rate - config.soft_limit
    local range = config.hard_limit - config.soft_limit
    local ratio = math.min(excess / range, 1.0)

    local delay = config.min_delay_ms + (config.max_delay_ms - config.min_delay_ms) * ratio
    return math.floor(delay)
end

-- Main rate limiting function - call from http-request
-- Returns: sets variable txn.rate_delay_ms with delay amount
core.register_action("rate_check", { "http-req" }, function(txn)
    local user_id = get_user_id(txn)

    -- Get current request rate from stick table (set via track-sc)
    -- sc0_http_req_rate returns requests per period defined in stick-table
    -- Note: HAProxy returns this as a string, so convert to number
    local rate_str = txn.sf:sc0_http_req_rate()
    local rate = tonumber(rate_str) or 0

    local delay_ms = calculate_delay(rate)

    -- Safety check - don't delay if too many already waiting
    if delay_ms > 0 and concurrent_delays >= config.max_concurrent_delays then
        delay_ms = 0  -- Skip delay, let request through at full speed
    end

    -- Store for use in response phase or logging
    txn:set_var("txn.rate_delay_ms", delay_ms)
    txn:set_var("txn.rate_current", rate)
    txn:set_var("txn.rate_user_id", user_id)
end, 0)

-- Response delay function - call from http-response
-- Actually applies the delay before sending response to client
core.register_action("rate_delay", { "http-res" }, function(txn)
    local delay_ms = tonumber(txn:get_var("txn.rate_delay_ms")) or 0

    if delay_ms > 0 then
        concurrent_delays = concurrent_delays + 1

        -- Convert to seconds for core.sleep (must be true integer, not float)
        -- Bitwise OR with 0 forces integer type in Lua 5.3+
        local delay_sec = (math.floor(delay_ms / 1000) | 0) + 1
        core.sleep(delay_sec)

        concurrent_delays = concurrent_delays - 1

        -- Add header so client knows they were throttled (optional, for debugging)
        txn.http:res_add_header("X-Rate-Delayed", tostring(delay_sec) .. "s")
    end
end, 0)

-- Fetch for logging/debugging - returns current delay for this request
core.register_fetches("rate_delay_ms", function(txn)
    return txn:get_var("txn.rate_delay_ms") or 0
end)

core.register_fetches("rate_current", function(txn)
    return txn:get_var("txn.rate_current") or 0
end)
