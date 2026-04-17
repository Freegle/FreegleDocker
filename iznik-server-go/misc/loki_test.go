package misc

import (
	"strings"
	"testing"

	"github.com/stretchr/testify/assert"
)

func TestTruncateStringShort(t *testing.T) {
	// Strings at or below maxStringLength are returned unchanged.
	s := strings.Repeat("a", maxStringLength)
	assert.Equal(t, s, truncateString(s))
	assert.Equal(t, "hello", truncateString("hello"))
	assert.Equal(t, "", truncateString(""))
}

func TestTruncateStringLong(t *testing.T) {
	// Over-length strings are truncated to maxStringLength + "..." suffix.
	s := strings.Repeat("a", maxStringLength+10)
	out := truncateString(s)
	assert.True(t, strings.HasSuffix(out, "..."))
	assert.Equal(t, maxStringLength+3, len(out))
}

func TestTruncateValueString(t *testing.T) {
	out := truncateValue(strings.Repeat("x", 100))
	s, ok := out.(string)
	assert.True(t, ok)
	assert.True(t, strings.HasSuffix(s, "..."))
}

func TestTruncateValueNonString(t *testing.T) {
	// Non-string scalars pass through unchanged.
	assert.Equal(t, 42, truncateValue(42))
	assert.Equal(t, true, truncateValue(true))
	assert.Equal(t, 3.14, truncateValue(3.14))
	assert.Nil(t, truncateValue(nil))
}

func TestTruncateValueSlice(t *testing.T) {
	// Slices of strings have their elements truncated recursively.
	long := strings.Repeat("a", 100)
	out := truncateValue([]interface{}{"short", long, 42})
	slice, ok := out.([]interface{})
	assert.True(t, ok)
	assert.Len(t, slice, 3)
	assert.Equal(t, "short", slice[0])
	s, _ := slice[1].(string)
	assert.True(t, strings.HasSuffix(s, "..."))
	assert.Equal(t, 42, slice[2])
}

func TestTruncateMapRecursive(t *testing.T) {
	long := strings.Repeat("q", 100)
	input := map[string]interface{}{
		"short": "ok",
		"long":  long,
		"nested": map[string]interface{}{
			"inner": long,
		},
	}
	out := truncateMap(input)

	assert.Equal(t, "ok", out["short"])
	s, _ := out["long"].(string)
	assert.True(t, strings.HasSuffix(s, "..."))

	nested, _ := out["nested"].(map[string]interface{})
	assert.NotNil(t, nested)
	ns, _ := nested["inner"].(string)
	assert.True(t, strings.HasSuffix(ns, "..."))

	// The original input must not be mutated — truncateMap returns a new map.
	assert.Equal(t, long, input["long"])
}

func TestFilterHeadersResponseKeepsNonSensitive(t *testing.T) {
	// Response headers (useAllowlist=false) include everything except
	// sensitive patterns.
	headers := map[string]string{
		"Content-Type":  "application/json",
		"X-Custom":      "yes",
		"Authorization": "Bearer secret",
		"Set-Cookie":    "session=abc",
	}
	out := filterHeaders(headers, false)
	assert.Equal(t, "application/json", out["Content-Type"])
	assert.Equal(t, "yes", out["X-Custom"])
	_, hasAuth := out["Authorization"]
	assert.False(t, hasAuth, "Authorization must be filtered")
	_, hasCookie := out["Set-Cookie"]
	assert.False(t, hasCookie, "Set-Cookie must be filtered")
}

func TestFilterHeadersRequestUsesAllowlist(t *testing.T) {
	// Request headers (useAllowlist=true) keep only the allowlisted set.
	headers := map[string]string{
		"User-Agent":        "test/1.0",
		"Content-Type":      "application/json",
		"X-Random":          "nope",
		"Cookie":            "whatever",
		"X-Freegle-Session": "sess-1",
	}
	out := filterHeaders(headers, true)
	assert.Equal(t, "test/1.0", out["User-Agent"])
	assert.Equal(t, "application/json", out["Content-Type"])
	assert.Equal(t, "sess-1", out["X-Freegle-Session"])
	_, hasRandom := out["X-Random"]
	assert.False(t, hasRandom, "X-Random is not on the allowlist")
	_, hasCookie := out["Cookie"]
	assert.False(t, hasCookie, "Cookie must be filtered")
}

func TestFilterHeadersSensitiveCaseInsensitive(t *testing.T) {
	// The sensitive-pattern match is case-insensitive.
	headers := map[string]string{
		"AUTHORIZATION": "Bearer secret",
		"cookie":        "session=abc",
		"X-API-Key":     "topsecret",
	}
	out := filterHeaders(headers, false)
	assert.Empty(t, out, "all three should be filtered regardless of case")
}
