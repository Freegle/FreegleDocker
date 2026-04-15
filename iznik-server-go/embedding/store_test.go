package embedding

import (
	"encoding/binary"
	"math"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
)

func makeVec(seed float32) [EmbeddingDim]float32 {
	var v [EmbeddingDim]float32
	var norm float32
	for i := 0; i < EmbeddingDim; i++ {
		v[i] = seed + float32(i)*0.01
		norm += v[i] * v[i]
	}
	norm = float32(math.Sqrt(float64(norm)))
	for i := 0; i < EmbeddingDim; i++ {
		v[i] /= norm
	}
	return v
}

func vecToBytes(v [EmbeddingDim]float32) []byte {
	b := make([]byte, EmbeddingDim*4)
	for i := 0; i < EmbeddingDim; i++ {
		binary.LittleEndian.PutUint32(b[i*4:], math.Float32bits(v[i]))
	}
	return b
}

func TestStoreSearch(t *testing.T) {
	s := &Store{}

	sofaVec := makeVec(0.5)
	chairVec := makeVec(0.51) // similar to sofa
	bikeVec := makeVec(5.0)   // different

	s.entries = []Entry{
		{Msgid: 1, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "OFFER: Sofa", Arrival: time.Now(), Vec: sofaVec},
		{Msgid: 2, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "OFFER: Chair", Arrival: time.Now(), Vec: chairVec},
		{Msgid: 3, Groupid: 200, Msgtype: "Wanted", Lat: 52.0, Lng: 0.0, Subject: "WANTED: Bike", Arrival: time.Now(), Vec: bikeVec},
	}

	// Search with sofa-like query should return sofa and chair first
	results := s.Search(sofaVec[:], 10, "", nil, 0, 0, 0, 0)
	assert.Len(t, results, 3)
	// Sofa should be first (highest similarity to itself)
	assert.Equal(t, uint64(1), results[0].Msgid)

	// Filter by msgtype
	results = s.Search(sofaVec[:], 10, "Offer", nil, 0, 0, 0, 0)
	assert.Len(t, results, 2)
	for _, r := range results {
		assert.Equal(t, "Offer", r.Msgtype)
	}

	// Filter by groupid
	results = s.Search(sofaVec[:], 10, "", []uint64{200}, 0, 0, 0, 0)
	assert.Len(t, results, 1)
	assert.Equal(t, uint64(3), results[0].Msgid)

	// Filter by bounding box (only London area, excludes bike at 52.0,0.0)
	results = s.Search(sofaVec[:], 10, "", nil, 51.0, -0.5, 51.9, 0.5)
	assert.Len(t, results, 2) // sofa and chair are at 51.5,-0.1

	// Limit results
	results = s.Search(sofaVec[:], 1, "", nil, 0, 0, 0, 0)
	assert.Len(t, results, 1)
	assert.Equal(t, uint64(1), results[0].Msgid) // sofa
}

func TestStoreSearchSortOrder(t *testing.T) {
	// Verify results are sorted by score even when count <= limit
	s := &Store{}

	vec1 := makeVec(1.0)
	vec2 := makeVec(5.0) // very different from vec1
	vec3 := makeVec(1.01) // similar to vec1

	s.entries = []Entry{
		{Msgid: 1, Groupid: 100, Msgtype: "Offer", Subject: "A", Vec: vec2}, // low similarity to vec1
		{Msgid: 2, Groupid: 100, Msgtype: "Offer", Subject: "B", Vec: vec3}, // high similarity
		{Msgid: 3, Groupid: 100, Msgtype: "Offer", Subject: "C", Vec: vec1}, // exact match
	}

	// Request limit=10 (more than 3 entries) — all results returned, must still be sorted
	results := s.Search(vec1[:], 10, "", nil, 0, 0, 0, 0)
	assert.Len(t, results, 3)
	// Exact match (msgid 3) should be first
	assert.Equal(t, uint64(3), results[0].Msgid)
	// Similar (msgid 2) should be second
	assert.Equal(t, uint64(2), results[1].Msgid)
	// Different (msgid 1) should be last
	assert.Equal(t, uint64(1), results[2].Msgid)
	// Scores should be descending
	assert.Greater(t, results[0].Score, results[1].Score)
	assert.Greater(t, results[1].Score, results[2].Score)
}

func TestStoreCount(t *testing.T) {
	s := &Store{}
	assert.Equal(t, 0, s.Count())

	s.entries = make([]Entry, 5)
	assert.Equal(t, 5, s.Count())
}

func TestVecToBytes(t *testing.T) {
	// Verify our encoding matches what the Go store expects
	vec := makeVec(1.0)
	b := vecToBytes(vec)
	assert.Equal(t, EmbeddingDim*4, len(b))

	// Decode back
	for i := 0; i < EmbeddingDim; i++ {
		bits := binary.LittleEndian.Uint32(b[i*4 : (i+1)*4])
		decoded := math.Float32frombits(bits)
		assert.InDelta(t, vec[i], decoded, 1e-7)
	}
}
