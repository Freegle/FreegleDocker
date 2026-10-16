package ux

import (
	"hash/crc32"
	"strconv"
)

// Server arms: Go code that decides what a member gets from an experiment's arm. The arm is a pure
// function of the member's id and the weights the batch publishes in the `ux_experiments` config
// key, the same function the browser (iznik-nuxt3/composables/useExperiment.js) and the batch
// (iznik-batch/app/Support/ExperimentBucket.php) use. docs/developers/reference/ux-experiments.md,
// "Experiments in mail, push and the server".

// ExperimentBucket is the member's bucket, 0 to 99, for an experiment: the CRC-32 of the UTF-8 bytes
// of "<userid>|<name>", or "<userid>|<name>|<salt>" when the experiment has a salt, modulo 100. A
// salt gives a re-run of an experiment different buckets from the run before it. The three runtimes
// pin the same vectors in their tests.
func ExperimentBucket(userid uint64, name, salt string) int {
	key := strconv.FormatUint(userid, 10) + "|" + name
	if salt != "" {
		key += "|" + salt
	}

	return int(crc32.ChecksumIEEE([]byte(key)) % 100)
}
