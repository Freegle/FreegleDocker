package microvolunteering

import (
	"testing"

	"github.com/stretchr/testify/assert"
)

// The earned-reach switch is read by the batch as `(bool) env('RIPPLE_EARNED_REACH_ENABLED')`
// (config/freegle.php), so it must be read the same way here or the reviewer pool and the reach
// gate disagree. That is Laravel's truthiness (utils.EnvBool), not the stricter "true" or "1" the
// post-moderation switches use: "yes" turns the gate on in the batch, so it turns this on too.
func TestEarnedReachEnabledIsReadLikeTheBatchReadsIt(t *testing.T) {
	for _, v := range []string{"true", "True", "TRUE", "1", "(true)", "yes"} {
		t.Setenv("RIPPLE_EARNED_REACH_ENABLED", v)
		assert.True(t, earnedReachEnabled(), "%q turns it on", v)
	}

	for _, v := range []string{"", "false", "False", "0", "(false)", "null", "empty"} {
		t.Setenv("RIPPLE_EARNED_REACH_ENABLED", v)
		assert.False(t, earnedReachEnabled(), "%q leaves it off", v)
	}
}
