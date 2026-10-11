package utils

import (
	"os"
	"strconv"
	"strings"
)

// AutoapproveTrialGroup mirrors the PHP rollout gate (AutoApproveCleanService::enabledGroupIds
// and AutomodMode): FREEGLE_AUTOAPPROVE_ENABLED truthy enables post-moderation everywhere;
// otherwise only the groups listed in FREEGLE_AUTOAPPROVE_TRIAL_GROUPS (comma-separated ids)
// take part. A group in FREEGLE_AUTOMOD_SHADOW_GROUPS is never in the trial, whatever the
// global switch says: shadow is the more cautious phase, chosen for that community on purpose,
// and an approve-everywhere flag must not silently promote it. Everything post-moderation
// shows to moderators (the countdown, the Check queue and its badge) is gated on this, so a
// community outside the trial looks as it did before.
func AutoapproveTrialGroup(gid uint64) bool {
	if AutomodShadowGroup(gid) {
		return false
	}

	v := os.Getenv("FREEGLE_AUTOAPPROVE_ENABLED")
	if v == "true" || v == "1" {
		return true
	}

	return envListHasGroup("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", gid)
}

// AutoapproveTrialGroups returns the subset of gids that take part in post-moderation.
func AutoapproveTrialGroups(gids []uint64) []uint64 {
	var ret []uint64
	for _, gid := range gids {
		if AutoapproveTrialGroup(gid) {
			ret = append(ret, gid)
		}
	}

	return ret
}

// AutoapproveDelayMinutes is how long a clean post waits before it publishes itself. It is
// the same for every community and mirrors the batch's freegle.autoapprove.delay_minutes
// (FREEGLE_AUTOAPPROVE_DELAY_MINUTES, default 20).
func AutoapproveDelayMinutes() int {
	if n, err := strconv.Atoi(strings.TrimSpace(os.Getenv("FREEGLE_AUTOAPPROVE_DELAY_MINUTES"))); err == nil && n > 0 {
		return n
	}

	return 20
}

// envListHasGroup reports whether the comma-separated list of group ids in the named
// environment variable contains gid.
func envListHasGroup(name string, gid uint64) bool {
	for _, part := range strings.Split(os.Getenv(name), ",") {
		if id, err := strconv.ParseUint(strings.TrimSpace(part), 10, 64); err == nil && id == gid {
			return true
		}
	}

	return false
}
