package test

import (
	"fmt"
	"testing"

	"github.com/freegle/iznik-server-go/ux"
	"github.com/stretchr/testify/assert"
)

// Server arms: the assignment function shared by the browser, the batch and this API. The vectors
// are pinned in iznik-nuxt3/tests/unit/composables/useExperiment.spec.js and
// iznik-batch/tests/Unit/Support/ExperimentBucketTest.php too, so a change that moves a bucket in
// one runtime fails all three suites.

func TestServerArmBucketMatchesTheSharedVectors(t *testing.T) {
	ids := []uint64{1, 2, 3, 5, 12345, 999999}
	vectors := []struct {
		name string
		salt string
		want []int
	}{
		{"confirm-reply", "", []int{44, 73, 77, 55, 1, 38}},
		{"digest-subject", "", []int{87, 77, 72, 88, 29, 68}},
		{"digest-subject", "r2", []int{88, 97, 5, 2, 11, 58}},
	}

	for _, v := range vectors {
		got := make([]int, len(ids))
		for i, id := range ids {
			got[i] = ux.ExperimentBucket(id, v.name, v.salt)
		}
		assert.Equal(t, v.want, got, fmt.Sprintf("%s with salt %q", v.name, v.salt))
	}
}

func TestServerArmBucketAgreesWithTheBrowserAndTheBatchOnTheirOwnVectors(t *testing.T) {
	// The values useExperiment.spec.js and ExperimentBucketTest.php pin for the older web names.
	assert.Equal(t, 89, ux.ExperimentBucket(123, "exp", ""))
	assert.Equal(t, 40, ux.ExperimentBucket(42, "give-hint", ""))
}

func TestServerArmBucketMovesWithTheSaltAndNotWithAnEmptyOne(t *testing.T) {
	// Member 5 on digest-subject: bucket 88 with no salt, 2 with the salt r2.
	assert.Equal(t, 88, ux.ExperimentBucket(5, "digest-subject", ""))
	assert.Equal(t, 2, ux.ExperimentBucket(5, "digest-subject", "r2"))
}
