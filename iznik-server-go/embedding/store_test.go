package embedding

import (
	"encoding/binary"
	"math"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
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

func vecPtr(v [EmbeddingDim]float32) *[EmbeddingDim]float32 {
	c := v
	return &c
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
		{Msgid: 1, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "OFFER: Sofa", Arrival: time.Now(), SubjectVec: sofaVec},
		{Msgid: 2, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "OFFER: Chair", Arrival: time.Now(), SubjectVec: chairVec},
		{Msgid: 3, Groupid: 200, Msgtype: "Wanted", Lat: 52.0, Lng: 0.0, Subject: "WANTED: Bike", Arrival: time.Now(), SubjectVec: bikeVec},
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
	vec2 := makeVec(5.0)  // very different from vec1
	vec3 := makeVec(1.01) // similar to vec1

	s.entries = []Entry{
		{Msgid: 1, Groupid: 100, Msgtype: "Offer", Subject: "A", SubjectVec: vec2}, // low similarity to vec1
		{Msgid: 2, Groupid: 100, Msgtype: "Offer", Subject: "B", SubjectVec: vec3}, // high similarity
		{Msgid: 3, Groupid: 100, Msgtype: "Offer", Subject: "C", SubjectVec: vec1}, // exact match
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

func TestStoreSetEntries(t *testing.T) {
	s := &Store{}
	assert.Equal(t, 0, s.Count())

	entries := []Entry{
		{Msgid: 10, Groupid: 1, Msgtype: "Offer", Subject: "a"},
		{Msgid: 11, Groupid: 2, Msgtype: "Wanted", Subject: "b"},
	}
	s.SetEntries(entries)
	assert.Equal(t, 2, s.Count())

	// Overwrite with an empty slice — Count must drop back to zero.
	s.SetEntries([]Entry{})
	assert.Equal(t, 0, s.Count())
}

func TestStoreLoadWithoutDB(t *testing.T) {
	// When database.DBConn is nil, Load must fail fast with a clear error
	// rather than panicking. This is the path taken before InitDatabase runs
	// (e.g. in tests that never initialise the DB).
	orig := database.DBConn
	database.DBConn = nil
	defer func() { database.DBConn = orig }()

	s := &Store{}
	err := s.Load()
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "database not initialized")
}

func TestStoreSearchMsgtypeAllReturnsAll(t *testing.T) {
	// An empty msgtype filter ("" — i.e. "any") must NOT filter out anything.
	s := &Store{}
	v := makeVec(1.0)
	s.entries = []Entry{
		{Msgid: 1, Groupid: 100, Msgtype: "Offer", SubjectVec: v},
		{Msgid: 2, Groupid: 100, Msgtype: "Wanted", SubjectVec: v},
		{Msgid: 3, Groupid: 100, Msgtype: "Taken", SubjectVec: v},
	}

	results := s.Search(v[:], 10, "", nil, 0, 0, 0, 0)
	assert.Len(t, results, 3)
}

func TestStoreSearchBoundingBoxTolerance(t *testing.T) {
	// The box filter allows 0.02 degrees of slack on each edge — points just
	// outside the requested box must still come back.
	s := &Store{}
	v := makeVec(1.0)
	s.entries = []Entry{
		{Msgid: 1, Msgtype: "Offer", Lat: 51.515, Lng: -0.105, SubjectVec: v}, // 0.015 outside
		{Msgid: 2, Msgtype: "Offer", Lat: 51.55, Lng: -0.1, SubjectVec: v},    // inside
		{Msgid: 3, Msgtype: "Offer", Lat: 51.6, Lng: 0.1, SubjectVec: v},      // well outside (>0.02)
	}

	// Request box: swlat=51.52 swlng=-0.10 nelat=51.58 nelng=0.00
	results := s.Search(v[:], 10, "", nil, 51.52, -0.10, 51.58, 0.00)
	ids := make(map[uint64]bool, len(results))
	for _, r := range results {
		ids[r.Msgid] = true
	}
	assert.True(t, ids[1], "msgid 1 should be within 0.02 slack")
	assert.True(t, ids[2], "msgid 2 is inside the box")
	assert.False(t, ids[3], "msgid 3 is well outside the slack")
}

func TestStoreSearchEmptyStore(t *testing.T) {
	// Searching an empty store must return an empty slice, not panic.
	s := &Store{}
	v := makeVec(1.0)
	results := s.Search(v[:], 10, "", nil, 0, 0, 0, 0)
	assert.Empty(t, results)
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

// --- Hybrid subject+body vector search ---

func TestStoreSearchCombinesSubjectAndBodyWithWeights(t *testing.T) {
	// Query perfectly matches subjectA, and body vector happens to match query
	// exactly too. Combined score should be ~1.0 (= w_s*1 + w_b*1).
	// Another entry has subject=query exactly but body=noise. Combined should
	// be lower than the first.
	s := &Store{}
	query := makeVec(1.0)
	noise := makeVec(10.0)

	s.entries = []Entry{
		{Msgid: 1, Msgtype: "Offer", Subject: "match with helpful body",
			SubjectVec: query, BodyVec: vecPtr(query)},
		{Msgid: 2, Msgtype: "Offer", Subject: "match with noisy body",
			SubjectVec: query, BodyVec: vecPtr(noise)},
	}

	results := s.Search(query[:], 10, "", nil, 0, 0, 0, 0)
	assert.Len(t, results, 2)
	assert.Equal(t, uint64(1), results[0].Msgid, "helpful body should outrank noisy body")
	assert.Greater(t, results[0].Score, results[1].Score)
}

func TestStoreSearchUsesSubjectOnlyWhenBodyAbsent(t *testing.T) {
	// Body absent → score should equal the pure subject cosine, not get
	// penalised by the BodyWeight coefficient.
	s := &Store{}
	query := makeVec(1.0)

	s.entries = []Entry{
		{Msgid: 1, Msgtype: "Offer", Subject: "subject only", SubjectVec: query, BodyVec: nil},
	}

	results := s.Search(query[:], 10, "", nil, 0, 0, 0, 0)
	assert.Len(t, results, 1)
	// Dot product of normalised vector with itself = 1.0
	assert.InDelta(t, float32(1.0), results[0].Score, 1e-5,
		"subject-only entry must score at the pure subject cosine (no weight penalty)")
}

func TestStoreSearchBodyBoostsWeakSubjectMatch(t *testing.T) {
	// Subject cosine is weak (0.6) but body matches the query exactly (1.0).
	// Combined = 0.7*0.6 + 0.3*1.0 = 0.72 → above MinVectorScore threshold.
	// A subject-only entry with the same 0.6 would score 0.6 and be cut by the
	// threshold in vectorsearch.go. This is the whole point of the hybrid.
	s := &Store{}

	// Build a subject vector that gives dot(query)=0.6 approximately.
	// Mix of query direction and orthogonal noise.
	query := makeVec(1.0)
	orthogonal := makeVec(100.0)
	var weakSubject [EmbeddingDim]float32
	var norm float32
	for i := 0; i < EmbeddingDim; i++ {
		weakSubject[i] = 0.6*query[i] + 0.8*orthogonal[i]
		norm += weakSubject[i] * weakSubject[i]
	}
	norm = float32(math.Sqrt(float64(norm)))
	for i := 0; i < EmbeddingDim; i++ {
		weakSubject[i] /= norm
	}

	s.entries = []Entry{
		{Msgid: 1, Msgtype: "Offer", SubjectVec: weakSubject, BodyVec: vecPtr(query)},
		{Msgid: 2, Msgtype: "Offer", SubjectVec: weakSubject, BodyVec: nil},
	}

	results := s.Search(query[:], 10, "", nil, 0, 0, 0, 0)
	assert.Len(t, results, 2)
	assert.Equal(t, uint64(1), results[0].Msgid, "body match should boost entry 1 above entry 2")
	assert.Greater(t, results[0].Score, results[1].Score)
}

func TestStoreLoadReadsSubjectAndBodyColumns(t *testing.T) {
	// This is a lightweight guard that the Row struct and SQL used by Load()
	// name the new columns. Full integration coverage lives in iznik-batch
	// tests (the Laravel command writes rows, Go reads them back).
	//
	// We can't easily mock GORM DB here without wiring a full sqlmock, so we
	// exercise the parse path via the Load's inner decoder: build a row with
	// both vectors packed, decode, and compare.
	subject := makeVec(1.0)
	body := makeVec(2.0)

	subjectBytes := vecToBytes(subject)
	bodyBytes := vecToBytes(body)

	e, err := decodeEntry(42, 100, "Offer", 51.5, -0.1, "OFFER: Thing", time.Now(), subjectBytes, bodyBytes)
	assert.NoError(t, err)
	assert.Equal(t, uint64(42), e.Msgid)
	assert.Equal(t, subject, e.SubjectVec)
	assert.NotNil(t, e.BodyVec)
	assert.Equal(t, body, *e.BodyVec)

	// NULL body → BodyVec nil
	e2, err := decodeEntry(43, 100, "Offer", 51.5, -0.1, "OFFER: Bare", time.Now(), subjectBytes, nil)
	assert.NoError(t, err)
	assert.Nil(t, e2.BodyVec)
}
